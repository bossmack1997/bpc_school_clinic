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

final class NurseVerificationService
{
    private bool $hasColumns = false;
    private bool $hasReviewTable = false;

    public function __construct(private PDO $db, private ?array $actor = null)
    {
        date_default_timezone_set('Asia/Manila');
        $this->detectSchema();
    }

    private function detectSchema(): void
    {
        try {
            $cols = $this->db->query("SHOW COLUMNS FROM `nurses` LIKE 'clinical_authorization_status'")->fetch();
            $this->hasColumns = !empty($cols);
        } catch (Throwable) {
            $this->hasColumns = false;
        }

        try {
            $tbl = $this->db->query("SHOW TABLES LIKE 'nurse_clinical_reviews'")->fetch();
            $this->hasReviewTable = !empty($tbl);
        } catch (Throwable) {
            $this->hasReviewTable = false;
        }
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
        }

        return $rows;
    }

    /**
     * Get nurse application detail.
     */
    public function getNurse(int $userId): ?array
    {
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

        $row['history'] = $this->getHistory($userId);
        return $row;
    }

    /**
     * Get review history for a nurse.
     */
    public function getHistory(int $userId): array
    {
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
            WHERE module = 'nurse_clinical_authorization' AND details LIKE ?
            ORDER BY id DESC
        ");
        $stmt->execute(['%Account #' . $userId . '%']);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Summary counts for Doctor dashboard.
     */
    public function summaryCounts(): array
    {
        $this->detectSchema();
        if ($this->hasColumns) {
            $stmt = $this->db->query("
                SELECT 
                    SUM(IF(clinical_authorization_status = 'pending', 1, 0)) AS pending_count,
                    SUM(IF(clinical_authorization_status = 'needs_information', 1, 0)) AS needs_info_count,
                    SUM(IF(clinical_authorization_status = 'authorized', 1, 0)) AS authorized_count,
                    SUM(IF(clinical_authorization_status = 'rejected', 1, 0)) AS rejected_count,
                    SUM(IF(clinical_authorization_status = 'revoked', 1, 0)) AS revoked_count,
                    COUNT(*) AS total_nurses
                FROM nurses
            ");
            $res = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            return [
                'pending' => (int)($res['pending_count'] ?? 0),
                'needs_information' => (int)($res['needs_info_count'] ?? 0),
                'authorized' => (int)($res['authorized_count'] ?? 0),
                'rejected' => (int)($res['rejected_count'] ?? 0),
                'revoked' => (int)($res['revoked_count'] ?? 0),
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

    /**
     * Authorize nurse clinical access.
     */
    public function authorize(int $nurseUserId, string $notes = ''): void
    {
        $actor = $this->requireDoctorActor();
        $actorId = (int)$actor['id'];

        $ownsTx = false;
        if (!$this->db->inTransaction()) {
            $this->db->beginTransaction();
            $ownsTx = true;
        }

        try {
            $stmt = $this->db->prepare("SELECT * FROM users WHERE id = ? AND role = 'nurse' FOR UPDATE");
            $stmt->execute([$nurseUserId]);
            $nurseUser = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$nurseUser) {
                throw new InvalidArgumentException('Nurse account not found.');
            }
            if (empty($nurseUser['email_verified'])) {
                throw new RuntimeException('Cannot authorize clinical access: Nurse email OTP verification is incomplete.');
            }
            if ($nurseUser['status'] !== 'active') {
                throw new RuntimeException('Cannot authorize clinical access: Account status is ' . $nurseUser['status'] . '.');
            }

            $currentStatus = 'pending';
            if ($this->hasColumns) {
                $curStmt = $this->db->prepare("SELECT clinical_authorization_status FROM nurses WHERE user_id = ? FOR UPDATE");
                $curStmt->execute([$nurseUserId]);
                $currentStatus = $curStmt->fetchColumn() ?: 'pending';
            }

            // 1. Grant clinical scope and bump credential_version to refresh active sessions
            $this->db->prepare("
                UPDATE users 
                SET self_service_only = 0,
                    credential_version = COALESCE(credential_version, 0) + 1,
                    reviewed_by = ?,
                    reviewed_at = NOW(),
                    validation_reason = ?
                WHERE id = ?
            ")->execute([$actorId, $notes ?: 'Doctor authorized nurse clinical access', $nurseUserId]);

            // 2. Update nurses record
            if ($this->hasColumns) {
                $this->db->prepare("
                    UPDATE nurses 
                    SET clinical_authorization_status = 'authorized',
                        clinical_reviewed_by = ?,
                        clinical_reviewed_at = NOW(),
                        clinical_review_notes = ?
                    WHERE user_id = ?
                ")->execute([$actorId, $notes, $nurseUserId]);
            }

            // 3. Insert review audit log
            if ($this->hasReviewTable) {
                $this->db->prepare("
                    INSERT INTO nurse_clinical_reviews 
                    (nurse_user_id, doctor_user_id, action, previous_status, new_status, notes)
                    VALUES (?, ?, 'authorize', ?, 'authorized', ?)
                ")->execute([$nurseUserId, $actorId, $currentStatus, $notes]);
            }

            // 4. Activity log
            $this->db->prepare("
                INSERT INTO activity_logs (user_id, action, module, details)
                VALUES (?, 'Doctor authorized nurse clinical access', 'nurse_clinical_authorization', ?)
            ")->execute([$actorId, "Doctor #$actorId authorized nurse Account #$nurseUserId; clinical scope granted. Notes: $notes"]);

            if ($ownsTx && $this->db->inTransaction()) {
                $this->db->commit();
            }
        } catch (Throwable $e) {
            if ($ownsTx && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Request additional information from nurse.
     */
    public function requestInformation(int $nurseUserId, string $notes): void
    {
        $actor = $this->requireDoctorActor();
        $actorId = (int)$actor['id'];

        $notes = trim($notes);
        if (mb_strlen($notes) < 5 || mb_strlen($notes) > 2000) {
            throw new InvalidArgumentException('Please provide specific instructions or required details (5–2000 characters).');
        }

        $ownsTx = false;
        if (!$this->db->inTransaction()) {
            $this->db->beginTransaction();
            $ownsTx = true;
        }

        try {
            $stmt = $this->db->prepare("SELECT * FROM users WHERE id = ? AND role = 'nurse' FOR UPDATE");
            $stmt->execute([$nurseUserId]);
            $nurseUser = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$nurseUser) {
                throw new InvalidArgumentException('Nurse account not found.');
            }

            $currentStatus = 'pending';
            if ($this->hasColumns) {
                $curStmt = $this->db->prepare("SELECT clinical_authorization_status FROM nurses WHERE user_id = ? FOR UPDATE");
                $curStmt->execute([$nurseUserId]);
                $currentStatus = $curStmt->fetchColumn() ?: 'pending';
            }

            // Keep self_service_only = 1
            $this->db->prepare("
                UPDATE users 
                SET self_service_only = 1,
                    validation_reason = ?
                WHERE id = ?
            ")->execute([$notes, $nurseUserId]);

            if ($this->hasColumns) {
                $this->db->prepare("
                    UPDATE nurses 
                    SET clinical_authorization_status = 'needs_information',
                        clinical_reviewed_by = ?,
                        clinical_reviewed_at = NOW(),
                        clinical_review_notes = ?
                    WHERE user_id = ?
                ")->execute([$actorId, $notes, $nurseUserId]);
            }

            if ($this->hasReviewTable) {
                $this->db->prepare("
                    INSERT INTO nurse_clinical_reviews 
                    (nurse_user_id, doctor_user_id, action, previous_status, new_status, notes)
                    VALUES (?, ?, 'request_info', ?, 'needs_information', ?)
                ")->execute([$nurseUserId, $actorId, $currentStatus, $notes]);
            }

            $this->db->prepare("
                INSERT INTO activity_logs (user_id, action, module, details)
                VALUES (?, 'Doctor requested nurse clinical information', 'nurse_clinical_authorization', ?)
            ")->execute([$actorId, "Doctor #$actorId requested additional info for nurse Account #$nurseUserId. Instructions: $notes"]);

            if ($ownsTx && $this->db->inTransaction()) {
                $this->db->commit();
            }
        } catch (Throwable $e) {
            if ($ownsTx && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Reject nurse clinical application.
     */
    public function reject(int $nurseUserId, string $reason): void
    {
        $actor = $this->requireDoctorActor();
        $actorId = (int)$actor['id'];

        $reason = trim($reason);
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 2000) {
            throw new InvalidArgumentException('Please provide a reason for rejecting the clinical application (5–2000 characters).');
        }

        $ownsTx = false;
        if (!$this->db->inTransaction()) {
            $this->db->beginTransaction();
            $ownsTx = true;
        }

        try {
            $stmt = $this->db->prepare("SELECT * FROM users WHERE id = ? AND role = 'nurse' FOR UPDATE");
            $stmt->execute([$nurseUserId]);
            $nurseUser = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$nurseUser) {
                throw new InvalidArgumentException('Nurse account not found.');
            }

            $currentStatus = 'pending';
            if ($this->hasColumns) {
                $curStmt = $this->db->prepare("SELECT clinical_authorization_status FROM nurses WHERE user_id = ? FOR UPDATE");
                $curStmt->execute([$nurseUserId]);
                $currentStatus = $curStmt->fetchColumn() ?: 'pending';
            }

            // Ensure restricted personal-only scope
            $this->db->prepare("
                UPDATE users 
                SET self_service_only = 1,
                    validation_reason = ?,
                    reviewed_by = ?,
                    reviewed_at = NOW()
                WHERE id = ?
            ")->execute([$reason, $actorId, $nurseUserId]);

            if ($this->hasColumns) {
                $this->db->prepare("
                    UPDATE nurses 
                    SET clinical_authorization_status = 'rejected',
                        clinical_reviewed_by = ?,
                        clinical_reviewed_at = NOW(),
                        clinical_review_notes = ?
                    WHERE user_id = ?
                ")->execute([$actorId, $reason, $nurseUserId]);
            }

            if ($this->hasReviewTable) {
                $this->db->prepare("
                    INSERT INTO nurse_clinical_reviews 
                    (nurse_user_id, doctor_user_id, action, previous_status, new_status, notes)
                    VALUES (?, ?, 'reject', ?, 'rejected', ?)
                ")->execute([$nurseUserId, $actorId, $currentStatus, $reason]);
            }

            $this->db->prepare("
                INSERT INTO activity_logs (user_id, action, module, details)
                VALUES (?, 'Doctor rejected nurse clinical authorization', 'nurse_clinical_authorization', ?)
            ")->execute([$actorId, "Doctor #$actorId rejected clinical application for nurse Account #$nurseUserId. Reason: $reason"]);

            if ($ownsTx && $this->db->inTransaction()) {
                $this->db->commit();
            }
        } catch (Throwable $e) {
            if ($ownsTx && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Revoke previously granted nurse clinical access.
     */
    public function revoke(int $nurseUserId, string $reason): void
    {
        $actor = $this->requireDoctorActor();
        $actorId = (int)$actor['id'];

        $reason = trim($reason);
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 2000) {
            throw new InvalidArgumentException('Please provide a reason for revoking clinical access (5–2000 characters).');
        }

        $ownsTx = false;
        if (!$this->db->inTransaction()) {
            $this->db->beginTransaction();
            $ownsTx = true;
        }

        try {
            $stmt = $this->db->prepare("SELECT * FROM users WHERE id = ? AND role = 'nurse' FOR UPDATE");
            $stmt->execute([$nurseUserId]);
            $nurseUser = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$nurseUser) {
                throw new InvalidArgumentException('Nurse account not found.');
            }

            $currentStatus = 'authorized';
            if ($this->hasColumns) {
                $curStmt = $this->db->prepare("SELECT clinical_authorization_status FROM nurses WHERE user_id = ? FOR UPDATE");
                $curStmt->execute([$nurseUserId]);
                $currentStatus = $curStmt->fetchColumn() ?: 'authorized';
            }

            // 1. Immediately revoke clinical scope and bump credential_version to invalidate live sessions!
            $this->db->prepare("
                UPDATE users 
                SET self_service_only = 1,
                    credential_version = COALESCE(credential_version, 0) + 1,
                    validation_reason = ?,
                    reviewed_by = ?,
                    reviewed_at = NOW()
                WHERE id = ?
            ")->execute([$reason, $actorId, $nurseUserId]);

            if ($this->hasColumns) {
                $this->db->prepare("
                    UPDATE nurses 
                    SET clinical_authorization_status = 'revoked',
                        clinical_reviewed_by = ?,
                        clinical_reviewed_at = NOW(),
                        clinical_review_notes = ?
                    WHERE user_id = ?
                ")->execute([$actorId, $reason, $nurseUserId]);
            }

            if ($this->hasReviewTable) {
                $this->db->prepare("
                    INSERT INTO nurse_clinical_reviews 
                    (nurse_user_id, doctor_user_id, action, previous_status, new_status, notes)
                    VALUES (?, ?, 'revoke', ?, 'revoked', ?)
                ")->execute([$nurseUserId, $actorId, $currentStatus, $reason]);
            }

            $this->db->prepare("
                INSERT INTO activity_logs (user_id, action, module, details)
                VALUES (?, 'Doctor revoked nurse clinical access', 'nurse_clinical_authorization', ?)
            ")->execute([$actorId, "Doctor #$actorId revoked clinical access for nurse Account #$nurseUserId. Reason: $reason"]);

            if ($ownsTx && $this->db->inTransaction()) {
                $this->db->commit();
            }
        } catch (Throwable $e) {
            if ($ownsTx && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }
}
