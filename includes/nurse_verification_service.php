<?php
/**
 * includes/nurse_verification_service.php
 * ------------------------------------------------------------------
 * Doctor-Led Nurse Clinical Verification Service.
 * Only verified Doctor / Clinical Co-Admin can review and authorize
 * nurse professional credentials and clinical scope.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/permission_profiles.php';
require_once __DIR__ . '/clinical_diagnostics.php';

final class NurseVerificationService
{
    private bool $hasColumns = false;
    private bool $hasReviewTable = false;
    private array $missingSchema = [];

    public function __construct(private PDO $db, private ?array $actor = null)
    {
        date_default_timezone_set('Asia/Manila');
        $this->detectSchema();
    }

    private function detectSchema(): void
    {
        $this->missingSchema=[];
        foreach([
            'nurses'=>['clinical_authorization_status','clinical_reviewed_by','clinical_reviewed_at','clinical_review_notes'],
            'users'=>['credential_version'],
            'nurse_clinical_reviews'=>['id','nurse_user_id','doctor_user_id','action','previous_status','new_status','notes','created_at']
        ] as $table=>$required) {
            try {
                $columns=$this->db->query('SHOW COLUMNS FROM `'.$table.'`')->fetchAll(PDO::FETCH_COLUMN);
                foreach(array_diff($required,$columns) as $column)$this->missingSchema[]=$table.'.'.$column;
            } catch(PDOException $e) {
                clinical_database_failure('nurse_verification.schema.'.$table,$e);
                $this->missingSchema[]=$table;
            }
        }
        $this->hasColumns=!array_filter($this->missingSchema,static fn($name)=>str_starts_with($name,'nurses'));
        $this->hasReviewTable=!array_filter($this->missingSchema,static fn($name)=>str_starts_with($name,'nurse_clinical_reviews'));
        if($this->missingSchema)error_log('Clinical review schema incomplete missing='.implode(',',$this->missingSchema));
    }

    /**
     * Evidence checklist for clinical approval. Pure function: it never changes data.
     * Institutional appointment verification is a Doctor attestation that cannot be
     * derived from the database, so it is reported as pending (ok = null) here.
     *
     * @return array{checks: array<string,array{label:string,ok:?bool,detail:string}>, blockers: string[], eligible: bool}
     */
    public static function evaluateEligibility(array $n, bool $institutionalAttested = false): array
    {
        $checks = [];
        $blockers = [];
        $add = static function (string $key, string $label, ?bool $ok, string $detail, ?string $blocker) use (&$checks, &$blockers): void {
            $checks[$key] = ['label' => $label, 'ok' => $ok, 'detail' => $detail];
            if ($blocker !== null) $blockers[] = $blocker;
        };

        $status = (string)($n['account_status'] ?? $n['status'] ?? '');
        $active = $status === 'active';
        $add('account', 'Account status', $active, $active ? 'Active' : 'Account is "' . ($status !== '' ? $status : 'unknown') . '"',
            $active ? null : 'Account must be active (currently ' . ($status !== '' ? $status : 'unknown') . ')');

        $verified = !empty($n['email_verified']);
        $add('email', 'Email verification', $verified, $verified ? 'Verified by one-time code' : 'Email address is not verified',
            $verified ? null : 'Email address has not been verified by the Nurse');

        $license = trim((string)($n['license_number'] ?? ''));
        if ($license === '') {
            $add('prc', 'PRC registration', false, 'No PRC registration number on file (nurses.license_number is empty)', 'PRC registration number is missing');
        } elseif (!preg_match('/^(?=.*\d)[A-Za-z0-9][A-Za-z0-9\-\/ ]{3,29}$/', $license)) {
            $add('prc', 'PRC registration', false, 'Recorded value is not a valid registration number format', 'PRC registration number format is invalid');
        } else {
            $add('prc', 'PRC registration', true, 'Number recorded: ' . $license . ' (authenticity is verified by the Doctor)', null);
        }

        $position = trim((string)($n['position'] ?? ''));
        $add('position', 'Appointment position', $position !== '', $position !== '' ? $position : 'No school appointment position on file',
            $position !== '' ? null : 'School appointment position is missing');

        $path = str_replace('\\', '/', trim((string)($n['valid_id_path'] ?? '')));
        if ($path === '') {
            $add('document', 'Supporting document', false, 'No supporting credential uploaded', 'Supporting credential document is missing');
        } elseif (str_contains($path, '..') || str_contains($path, "\0") || !preg_match('#^(storage/registration_documents|assets/images)/#', ltrim($path, '/'))) {
            $add('document', 'Supporting document', false, 'Stored document path is not in an approved location', 'Supporting credential path is invalid');
        } elseif (defined('BASE_PATH') && !is_file(BASE_PATH . '/' . ltrim($path, '/'))) {
            $add('document', 'Supporting document', false, 'Document record exists but the file is not found on the server', 'Supporting credential file is missing from storage');
        } else {
            $add('document', 'Supporting document', true, 'Document on file', null);
        }

        if ($institutionalAttested) {
            $add('institutional', 'Institutional verification', true, 'Doctor attested appointment verification', null);
        } else {
            $add('institutional', 'Institutional verification', null, 'Doctor must confirm employment/assignment against school records at review', 'Institutional appointment verification not confirmed by the Doctor');
        }
        return ['checks' => $checks, 'blockers' => $blockers, 'eligible' => !$blockers];
    }

    private function requireDoctorActor(): array
    {
        $actor = $this->actor ?? current_user();
        if (!$actor) {
            throw new RuntimeException('Authenticated session required.');
        }
        if (permission_profile($actor, $this->db) !== 'doctor') {
            throw new RuntimeException('Only an authorized Doctor / Clinical Co-Admin can verify or modify nurse clinical access.');
        }
        return $actor;
    }

    /**
     * Compute effective status for backward compatibility.
     */
    public static function normalizeStatus(?string $explicitStatus, int $selfServiceOnly, string $accountStatus): string
    {
        if ($explicitStatus && in_array($explicitStatus, ['pending', 'needs_information', 'authorized', 'rejected', 'revoked'], true)) {
            return $explicitStatus;
        }
        if ($accountStatus === 'rejected') {
            return 'rejected';
        }
        if ($selfServiceOnly === 0) {
            return 'authorized';
        }
        return 'pending';
    }

    /**
     * List nurses for Doctor verification dashboard.
     */
    public function listNurses(string $filter = 'pending', string $search = '', int $limit = 50, int $offset = 0): array
    {
        $this->requireDoctorActor();

        $where = ["u.role = 'nurse'"];
        $params = [];

        if ($search !== '') {
            $term = '%' . mb_substr(trim($search), 0, 100) . '%';
            $where[] = "(CONCAT(u.first_name, ' ', u.last_name) LIKE ? OR u.email LIKE ? OR n.employee_id LIKE ? OR n.license_number LIKE ?)";
            $params[] = $term;
            $params[] = $term;
            $params[] = $term;
            $params[] = $term;
        }

        if ($this->hasColumns) {
            if ($filter === 'pending') {
                $where[] = "n.clinical_authorization_status = 'pending'";
            } elseif ($filter === 'needs_information') {
                $where[] = "n.clinical_authorization_status = 'needs_information'";
            } elseif ($filter === 'authorized') {
                $where[] = "n.clinical_authorization_status = 'authorized'";
            } elseif ($filter === 'rejected') {
                $where[] = "n.clinical_authorization_status = 'rejected'";
            } elseif ($filter === 'revoked') {
                $where[] = "n.clinical_authorization_status = 'revoked'";
            }
        } else {
            // Fallback
            if ($filter === 'pending') {
                $where[] = "u.self_service_only = 1 AND u.status = 'active'";
            } elseif ($filter === 'authorized') {
                $where[] = "u.self_service_only = 0";
            }
        }

        $whereSql = implode(' AND ', $where);

        $statusCol = $this->hasColumns ? 'n.clinical_authorization_status' : 'NULL AS clinical_authorization_status';
        $revByCol  = $this->hasColumns ? 'n.clinical_reviewed_by' : 'NULL AS clinical_reviewed_by';
        $revAtCol  = $this->hasColumns ? 'n.clinical_reviewed_at' : 'NULL AS clinical_reviewed_at';
        $notesCol  = $this->hasColumns ? 'n.clinical_review_notes' : 'NULL AS clinical_review_notes';

        $sql = "
            SELECT 
                u.id AS user_id, u.first_name, u.middle_name, u.last_name, u.email, u.phone,
                u.gender AS user_gender, u.date_of_birth AS user_dob, u.status AS account_status,
                u.email_verified, u.email_verified_at, u.self_service_only, u.created_at AS registered_at,
                u.profile_picture AS user_photo,
                n.id AS nurse_record_id, n.employee_id, n.license_number, n.position,
                n.emergency_contact_name, n.emergency_contact_number, n.valid_id_path,
                n.specialization, n.shift_schedule,
                $statusCol, $revByCol, $revAtCol, $notesCol,
                doc.first_name AS reviewer_first_name, doc.last_name AS reviewer_last_name
            FROM users u
            LEFT JOIN nurses n ON n.user_id = u.id
            LEFT JOIN users doc ON doc.id = " . ($this->hasColumns ? "n.clinical_reviewed_by" : "NULL") . "
            WHERE $whereSql
            ORDER BY 
                CASE 
                    WHEN " . ($this->hasColumns ? "n.clinical_authorization_status" : "IF(u.self_service_only=1,'pending','authorized')") . " = 'pending' THEN 1
                    WHEN " . ($this->hasColumns ? "n.clinical_authorization_status" : "''") . " = 'needs_information' THEN 2
                    WHEN " . ($this->hasColumns ? "n.clinical_authorization_status" : "''") . " = 'authorized' THEN 3
                    ELSE 4
                END,
                u.id DESC
            LIMIT ? OFFSET ?
        ";

        $stmt = $this->db->prepare($sql);
        $bound = 1;
        foreach ($params as $p) {
            $stmt->bindValue($bound++, $p);
        }
        $stmt->bindValue($bound++, (int)$limit, PDO::PARAM_INT);
        $stmt->bindValue($bound++, (int)$offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$r) {
            $r['effective_status'] = self::normalizeStatus(
                $r['clinical_authorization_status'] ?? null,
                (int)($r['self_service_only'] ?? 0),
                $r['account_status'] ?? 'pending'
            );
            $r['eligibility'] = self::evaluateEligibility($r);
            $r['_last'] = true;
        }
        unset($r);

        return $rows;
    }

    /**
     * Get nurse application detail.
     */
    public function getNurse(int $userId): ?array
    {
        $this->requireReader($userId);
        $statusCol = $this->hasColumns ? 'n.clinical_authorization_status' : 'NULL AS clinical_authorization_status';
        $revByCol  = $this->hasColumns ? 'n.clinical_reviewed_by' : 'NULL AS clinical_reviewed_by';
        $revAtCol  = $this->hasColumns ? 'n.clinical_reviewed_at' : 'NULL AS clinical_reviewed_at';
        $notesCol  = $this->hasColumns ? 'n.clinical_review_notes' : 'NULL AS clinical_review_notes';

        $sql = "
            SELECT 
                u.id AS user_id, u.username, u.first_name, u.middle_name, u.last_name, u.email, u.phone,
                u.gender AS user_gender, u.date_of_birth AS user_dob, u.status AS account_status,
                u.email_verified, u.email_verified_at, u.self_service_only, u.credential_version, u.created_at AS registered_at,
                u.profile_picture AS user_photo, u.last_login,
                n.id AS nurse_record_id, n.employee_id, n.license_number, n.position,
                n.emergency_contact_name, n.emergency_contact_number, n.valid_id_path,
                n.specialization, n.shift_schedule,
                $statusCol, $revByCol, $revAtCol, $notesCol,
                doc.first_name AS reviewer_first_name, doc.last_name AS reviewer_last_name
            FROM users u
            LEFT JOIN nurses n ON n.user_id = u.id
            LEFT JOIN users doc ON doc.id = " . ($this->hasColumns ? "n.clinical_reviewed_by" : "NULL") . "
            WHERE u.id = ? AND u.role = 'nurse'
            LIMIT 1
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;

        $row['effective_status'] = self::normalizeStatus(
            $row['clinical_authorization_status'] ?? null,
            (int)($row['self_service_only'] ?? 0),
            $row['account_status'] ?? 'pending'
        );

        $row['eligibility'] = self::evaluateEligibility($row);
        $row['history'] = $this->getHistory($userId);
        return $row;
    }

    /**
     * Get review history for a nurse.
     */
    public function getHistory(int $userId): array
    {
        $this->requireReader($userId);
        if ($this->hasReviewTable) {
            $stmt = $this->db->prepare("
                SELECT r.*, u.first_name AS doctor_first_name, u.last_name AS doctor_last_name
                FROM nurse_clinical_reviews r
                LEFT JOIN users u ON u.id = r.doctor_user_id
                WHERE r.nurse_user_id = ?
                ORDER BY r.id DESC
            ");
            $stmt->execute([$userId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (!empty($rows)) return $rows;
        }

        // Fallback to activity_logs
        $stmt = $this->db->prepare("
            SELECT id, action, details AS notes, created_at, user_id AS doctor_user_id,
                   'activity_log' AS source
            FROM activity_logs
            WHERE module = 'nurse_clinical_authorization' AND details REGEXP ?
            ORDER BY id DESC
        ");
        $stmt->execute(['Account #' . $userId . '([^0-9]|$)']);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Summary counts for Doctor dashboard.
     */
    public function summaryCounts(): array
    {
        $this->requireDoctorActor();
        $this->detectSchema();
        if ($this->hasColumns) {
            // Same population as the listing: every nurse account LEFT JOINed to its nurses row.
            $res = $this->db->query("
                SELECT
                    SUM(n.clinical_authorization_status = 'pending') AS pending_count,
                    SUM(n.clinical_authorization_status = 'needs_information') AS needs_info_count,
                    SUM(n.clinical_authorization_status = 'authorized') AS authorized_count,
                    SUM(n.clinical_authorization_status = 'rejected') AS rejected_count,
                    SUM(n.clinical_authorization_status = 'revoked') AS revoked_count,
                    SUM(n.id IS NULL OR n.clinical_authorization_status IS NULL
                        OR n.clinical_authorization_status NOT IN ('pending','needs_information','authorized','rejected','revoked')) AS unclassified_count,
                    COUNT(*) AS total_nurses
                FROM users u
                LEFT JOIN nurses n ON n.user_id = u.id
                WHERE u.role = 'nurse'
            ")->fetch(PDO::FETCH_ASSOC) ?: [];
            return [
                'pending' => (int)($res['pending_count'] ?? 0),
                'needs_information' => (int)($res['needs_info_count'] ?? 0),
                'authorized' => (int)($res['authorized_count'] ?? 0),
                'rejected' => (int)($res['rejected_count'] ?? 0),
                'revoked' => (int)($res['revoked_count'] ?? 0),
                'unclassified' => (int)($res['unclassified_count'] ?? 0),
                'total' => (int)($res['total_nurses'] ?? 0),
            ];
        }

        // Fallback
        $pending = (int)$this->db->query("SELECT COUNT(*) FROM users WHERE role='nurse' AND self_service_only=1 AND status='active'")->fetchColumn();
        $authorized = (int)$this->db->query("SELECT COUNT(*) FROM users WHERE role='nurse' AND self_service_only=0 AND status='active'")->fetchColumn();
        $total = (int)$this->db->query("SELECT COUNT(*) FROM users WHERE role='nurse'")->fetchColumn();
        return [
            'pending' => $pending,
            'needs_information' => 0,
            'authorized' => $authorized,
            'rejected' => 0,
            'revoked' => 0,
            'total' => $total,
        ];
    }

    private function requireReader(int $id): void {
        $actor = $this->actor ?? current_user();
        if (!$actor || ($actor['role'] !== 'nurse' || (int)$actor['id'] !== $id) && permission_profile($actor,$this->db) !== 'doctor') throw new RuntimeException('Credential review access denied.');
    }

    public function ready(): bool { return !$this->missingSchema; }

    /** Schema names only; no applicant or credential contents. */
    public function missingSchema(): array { return $this->missingSchema; }

    /** A restricted Nurse may update and resubmit their own professional details. */
    public function submitCredentials(string $license, string $position, ?string $document): void {
        $actor=$this->actor ?? current_user();
        if(!$this->ready() || !$actor || $actor['role']!=='nurse')throw new RuntimeException('Nurse credential submission unavailable.');
        $license=trim($license); $position=trim($position);
        if($license==='' || mb_strlen($license)>100 || $position==='' || mb_strlen($position)>120)throw new InvalidArgumentException('Provide professional registration and school appointment position.');
        $this->db->beginTransaction();
        try {
            $q=$this->db->prepare("SELECT u.status,u.email_verified,u.self_service_only,n.* FROM users u JOIN nurses n ON n.user_id=u.id WHERE u.id=? AND u.role='nurse' FOR UPDATE");$q->execute([$actor['id']]);$nurse=$q->fetch(PDO::FETCH_ASSOC);
            if($nurse && ($nurse['status']!=='active' || !$nurse['email_verified']))throw new RuntimeException($nurse['status']!=='active' ? 'Your account is '.$nurse['status'].'. Credentials can be submitted only on an active account; contact the Clinic Head.' : 'Verify your email address with your one-time code before submitting credentials.');
            if(!$nurse || $nurse['status']!=='active' || !$nurse['email_verified'] || !$nurse['self_service_only'] || $nurse['clinical_authorization_status']==='authorized' || $nurse['clinical_authorization_status']==='revoked')throw new RuntimeException('This account is not eligible for credential resubmission. Contact the Clinic Head.');
            $document=$document ?: $nurse['valid_id_path'];
            if(!$document)throw new InvalidArgumentException('Upload a supporting professional credential.');
            $this->db->prepare("UPDATE nurses SET license_number=?,position=?,valid_id_path=?,clinical_authorization_status='pending' WHERE user_id=?")->execute([$license,$position,$document,$actor['id']]);
            // Invalidates any review form opened before the new submission.
            $this->db->prepare('UPDATE users SET credential_version=COALESCE(credential_version,0)+1 WHERE id=?')->execute([$actor['id']]);
            $this->db->prepare("INSERT INTO activity_logs(user_id,action,module,details) VALUES (?,'Nurse credentials submitted','nurse_clinical_authorization',?)")->execute([$actor['id'],'Account #'.$actor['id'].' submitted credentials for physician review.']);
            $this->db->prepare("INSERT INTO notifications(user_id,type,title,message,link) SELECT u.id,'clinical_review','Nurse credentials awaiting review','A Nurse submitted professional credentials for your review.',? FROM users u JOIN admin_profiles p ON p.user_id=u.id WHERE u.status='active' AND p.profile='doctor'")->execute([app_url('pages/doctor/nurse_verification.php')]);
            $this->db->commit();
        } catch(Throwable $e) { if($this->db->inTransaction())$this->db->rollBack(); throw $e; }
    }

    public function authorize(int $id, string $notes = '', string $password = '', int $version = -1, bool $institutionalVerified = false): void { $this->decide($id, 'authorize', $notes, $password, $version, $institutionalVerified); }
    public function requestInformation(int $id, string $notes, string $password = '', int $version = -1): void { $this->decide($id, 'request_info', $notes, $password, $version); }
    public function reject(int $id, string $notes, string $password = '', int $version = -1): void { $this->decide($id, 'reject', $notes, $password, $version); }
    public function revoke(int $id, string $notes, string $password = '', int $version = -1): void { $this->decide($id, 'revoke', $notes, $password, $version); }

    private function decide(int $id, string $action, string $notes, string $password, int $version, bool $institutionalVerified = false): void
    {
        if (!$this->ready()) throw new RuntimeException('Clinical authorization schema is unavailable. Ask MIS to apply the reviewed migration.');
        $actor = $this->requireDoctorActor();
        $notes = trim($notes);
        if (mb_strlen($notes) < 5 || mb_strlen($notes) > 2000) throw new InvalidArgumentException('Record the verification reference and review notes (5-2000 characters).');
        if ($id === (int)$actor['id']) throw new RuntimeException('Self approval is prohibited.');
        if ($this->db->inTransaction()) throw new RuntimeException('A separate review transaction is required.');
        $this->db->beginTransaction();
        try {
            $q = $this->db->prepare('SELECT * FROM users WHERE id=? FOR UPDATE');
            $q->execute([$actor['id']]); $fresh = $q->fetch(PDO::FETCH_ASSOC);
            if (!$fresh || $fresh['status'] !== 'active' || permission_profile($fresh, $this->db) !== 'doctor' || !password_verify($password, $fresh['password_hash'])) throw new RuntimeException('Doctor password reauthentication failed.');
            $q = $this->db->prepare("SELECT u.*, n.clinical_authorization_status, n.license_number, n.position, n.valid_id_path FROM users u JOIN nurses n ON n.user_id=u.id WHERE u.id=? AND u.role='nurse' FOR UPDATE");
            $q->execute([$id]); $nurse = $q->fetch(PDO::FETCH_ASSOC);
            if (!$nurse || (int)$nurse['credential_version'] !== $version) throw new RuntimeException('Application changed or is unavailable. Reload before reviewing.');
            $previous = $nurse['clinical_authorization_status'];
            $next = ['authorize'=>'authorized','request_info'=>'needs_information','reject'=>'rejected','revoke'=>'revoked'][$action];
            if ($previous === $next || ($action === 'revoke' && $previous !== 'authorized') || ($action !== 'revoke' && $previous === 'authorized')) throw new RuntimeException('This decision is no longer applicable. Reload the application.');
            if ($action === 'authorize') {
                $nurse['account_status'] = $nurse['status'];
                $eligibility = self::evaluateEligibility($nurse, $institutionalVerified);
                if (!$eligibility['eligible']) throw new RuntimeException('Clinical approval blocked: ' . implode('; ', $eligibility['blockers']) . '.');
            }
            $this->db->prepare('UPDATE users SET self_service_only=?, credential_version=COALESCE(credential_version,0)+1, reviewed_by=?, reviewed_at=NOW(), validation_reason=? WHERE id=?')->execute([$action === 'authorize' ? 0 : 1, $actor['id'], $notes, $id]);
            $this->db->prepare('UPDATE nurses SET clinical_authorization_status=?, clinical_reviewed_by=?, clinical_reviewed_at=NOW(), clinical_review_notes=? WHERE user_id=?')->execute([$next,$actor['id'],$notes,$id]);
            $this->db->prepare('INSERT INTO nurse_clinical_reviews(nurse_user_id,doctor_user_id,action,previous_status,new_status,notes) VALUES (?,?,?,?,?,?)')->execute([$id,$actor['id'],$action,$previous,$next,$notes]);
            $this->db->prepare("INSERT INTO activity_logs(user_id,action,module,details) VALUES (?,'Nurse clinical review','nurse_clinical_authorization',?)")->execute([$actor['id'],'Account #'.$id.'; decision '.$next.'; reference retained in restricted review history.']);
            $this->db->prepare('INSERT INTO notifications(user_id,type,title,message,link) VALUES (?,?,?,?,?)')->execute([$id,'clinical_review','Clinical staff review updated','Your clinical authorization status is '.str_replace('_',' ',$next).'. Sign in to review the decision.',app_url('pages/nurse/account.php')]);
            $this->db->commit();
        } catch (Throwable $e) { if ($this->db->inTransaction()) $this->db->rollBack(); throw $e; }
    }
}