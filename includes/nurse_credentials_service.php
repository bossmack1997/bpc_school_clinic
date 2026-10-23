<?php
/**
 * includes/nurse_credentials_service.php
 * ------------------------------------------------------------------
 * Nurse-side professional credential correction and resubmission.
 *
 * - Draft values live in nurse_credential_drafts and never touch the live
 *   nurses row, the clinical status or the user's access.
 * - Submission is transactional, audited in nurse_credential_submissions and
 *   activity_logs, and ALWAYS leaves the Nurse awaiting Doctor review. Nothing
 *   here can authorize a Nurse.
 * - A material change by an already-authorized Nurse demotes the account to a
 *   restricted state until a Doctor re-verifies it.
 * - Documents are never deleted or overwritten; superseded files remain for audit.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/nurse_verification_service.php';

final class NurseCredentialsService
{
    public const MAX_UPLOAD_BYTES = 5242880;
    public const UPLOAD_TYPES = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'];
    public const STORAGE_DIR = 'storage/registration_documents';

    private array $missing = [];

    public function __construct(private PDO $db, private array $actor)
    {
        foreach (['nurse_credential_drafts' => ['nurse_user_id', 'license_number', 'employee_id', 'position', 'pending_document_path', 'updated_at'],
                  'nurse_credential_submissions' => ['id', 'nurse_user_id', 'license_number', 'employee_id', 'position', 'document_path', 'material_change', 'previous_status', 'resulting_status', 'created_at']] as $table => $cols) {
            try {
                $have = $this->db->query('SHOW COLUMNS FROM `' . $table . '`')->fetchAll(PDO::FETCH_COLUMN);
                foreach (array_diff($cols, $have) as $c) $this->missing[] = $table . '.' . $c;
            } catch (PDOException $e) {
                $this->missing[] = $table;
            }
        }
    }

    public function ready(): bool { return !$this->missing; }
    public function missingSchema(): array { return $this->missing; }

    // ---------------------------------------------------------------- validation

    /** PRC identifiers have no single fixed length or pattern: only safe-character and sanity rules apply. */
    public static function normalizeLicense(string $v): string { return trim(preg_replace('/\s+/', ' ', $v) ?? ''); }

    public static function validateLicense(string $v): ?string
    {
        $v = self::normalizeLicense($v);
        if ($v === '') return 'Enter your PRC registration number exactly as printed on your PRC license.';
        if (mb_strlen($v) > 30) return 'PRC registration number is too long (maximum 30 characters).';
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9\-\/ ]*$/', $v)) return 'Use only letters, numbers, hyphens, slashes and single spaces.';
        if (!preg_match('/\d/', $v)) return 'A PRC registration number must contain at least one digit.';
        return null;
    }

    /** Institution format comes from configuration (NURSE_EMPLOYEE_ID_PATTERN); nothing is assumed when unset. */
    public static function employeeIdPattern(): ?string
    {
        $p = trim((string)(getenv('NURSE_EMPLOYEE_ID_PATTERN') ?: ''));
        if ($p === '') return null;
        return @preg_match('/' . str_replace('/', '\/', $p) . '/', '') === false ? null : $p;
    }

    public static function employeeIdHint(): string
    {
        $h = trim((string)(getenv('NURSE_EMPLOYEE_ID_HINT') ?: ''));
        return $h !== '' ? $h : (self::employeeIdPattern() !== null ? 'Use the format issued by BPC Human Resources.' : 'Enter the Employee ID issued by BPC Human Resources, exactly as on your records.');
    }

    public static function validateEmployeeId(string $v, ?string $current = null): ?string
    {
        $v = trim($v);
        if ($v === '') return 'Enter your BPC Employee ID.';
        if (mb_strlen($v) > 50) return 'Employee ID is too long (maximum 50 characters).';
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9\-\/_.]*$/', $v)) return 'Employee ID may contain only letters, numbers and - / _ .';
        // An existing stored value that the Nurse has not changed is never rejected retroactively.
        if ($current !== null && $v === trim($current)) return null;
        $p = self::employeeIdPattern();
        if ($p !== null && !preg_match('/^(?:' . str_replace('/', '\/', $p) . ')$/', $v)) return 'Employee ID does not match the institution format. ' . self::employeeIdHint();
        return null;
    }

    public static function validatePosition(string $v): ?string
    {
        $v = trim($v);
        if ($v === '') return 'Enter your official appointment position.';
        if (mb_strlen($v) > 120) return 'Position is too long (maximum 120 characters).';
        if (preg_match('/[\x00-\x1F<>]/', $v)) return 'Position contains invalid characters.';
        return null;
    }

    /** @return array<string,string> field => message */
    public static function validateFields(array $f, array $live = [], bool $requireAll = true): array
    {
        $errors = [];
        $license = (string)($f['license_number'] ?? ''); $emp = (string)($f['employee_id'] ?? ''); $pos = (string)($f['position'] ?? '');
        if ($requireAll || $license !== '') if ($e = self::validateLicense($license)) $errors['license_number'] = $e;
        if ($requireAll || $emp !== '') if ($e = self::validateEmployeeId($emp, $live['employee_id'] ?? null)) $errors['employee_id'] = $e;
        if ($requireAll || $pos !== '') if ($e = self::validatePosition($pos)) $errors['position'] = $e;
        return $errors;
    }

    /**
     * Server-side file verification by content, not by client-declared type or name.
     * @return array{mime:string,ext:string}
     */
    public static function inspectUpload(array $file): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new InvalidArgumentException('The upload failed or was too large. Upload a PDF, JPG or PNG up to 5 MB.');
        $size = (int)($file['size'] ?? 0);
        if ($size <= 0 || $size > self::MAX_UPLOAD_BYTES) throw new InvalidArgumentException('The document must be larger than 0 bytes and no more than 5 MB.');
        $tmp = (string)($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_file($tmp)) throw new InvalidArgumentException('The uploaded file could not be read.');
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
        if (!isset(self::UPLOAD_TYPES[$mime])) throw new InvalidArgumentException('Only PDF, JPG and PNG documents are accepted.');
        $head = (string)file_get_contents($tmp, false, null, 0, 8);
        if ($mime === 'application/pdf' && !str_starts_with($head, '%PDF-')) throw new InvalidArgumentException('The file is not a valid PDF.');
        if ($mime !== 'application/pdf' && @getimagesize($tmp) === false) throw new InvalidArgumentException('The file is not a valid image.');
        return ['mime' => $mime, 'ext' => self::UPLOAD_TYPES[$mime]];
    }

    /** Stores a verified upload under a random server-generated name in protected storage. Returns the relative path. */
    public static function storeUpload(array $file): string
    {
        $info = self::inspectUpload($file);
        $dir = BASE_PATH . '/' . self::STORAGE_DIR;
        if (!is_dir($dir) && !@mkdir($dir, 0750, true)) throw new RuntimeException('Document storage is unavailable. Contact the system administrator.');
        $rel = self::STORAGE_DIR . '/credential_' . bin2hex(random_bytes(16)) . '.' . $info['ext'];
        $moved = is_uploaded_file($file['tmp_name']) ? move_uploaded_file($file['tmp_name'], BASE_PATH . '/' . $rel) : (defined('NURSE_CREDENTIAL_TEST_MODE') && NURSE_CREDENTIAL_TEST_MODE && rename($file['tmp_name'], BASE_PATH . '/' . $rel));
        if (!$moved) throw new RuntimeException('Unable to store the document. Please retry.');
        @chmod(BASE_PATH . '/' . $rel, 0640);
        return $rel;
    }

    public static function documentExists(?string $rel): bool
    {
        $rel = str_replace('\\', '/', trim((string)$rel));
        if ($rel === '' || str_contains($rel, '..') || !str_starts_with($rel, self::STORAGE_DIR . '/')) return false;
        return is_file(BASE_PATH . '/' . $rel);
    }

    // ---------------------------------------------------------------- state

    /** Live record, draft and last submission for the signed-in Nurse. */
    public function load(): array
    {
        $uid = (int)$this->actor['id'];
        $q = $this->db->prepare("SELECT u.status AS account_status, u.email_verified, u.self_service_only, n.employee_id, n.license_number, n.position, n.valid_id_path, n.clinical_authorization_status, n.clinical_review_notes, n.clinical_reviewed_at FROM users u JOIN nurses n ON n.user_id=u.id WHERE u.id=? AND u.role='nurse' LIMIT 1");
        $q->execute([$uid]);
        $live = $q->fetch(PDO::FETCH_ASSOC) ?: [];
        $draft = null; $last = null;
        if ($this->ready()) {
            $d = $this->db->prepare('SELECT * FROM nurse_credential_drafts WHERE nurse_user_id=?'); $d->execute([$uid]); $draft = $d->fetch(PDO::FETCH_ASSOC) ?: null;
            $s = $this->db->prepare('SELECT * FROM nurse_credential_submissions WHERE nurse_user_id=? ORDER BY id DESC LIMIT 1'); $s->execute([$uid]); $last = $s->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        return ['live' => $live, 'draft' => $draft, 'last_submission' => $last, 'eligibility' => $live ? NurseVerificationService::evaluateEligibility($live) : null];
    }

    private function requireNurse(): int
    {
        if (!$this->ready()) throw new RuntimeException('Credential correction is unavailable until the reviewed database migration is applied.');
        if (($this->actor['role'] ?? '') !== 'nurse') throw new RuntimeException('Only the Nurse account owner can edit professional credentials.');
        return (int)$this->actor['id'];
    }

    private function duplicateIdentifier(string $column, string $value, int $uid): bool
    {
        $q = $this->db->prepare("SELECT COUNT(*) FROM nurses WHERE user_id<>? AND LOWER(TRIM($column))=LOWER(?)");
        $q->execute([$uid, $value]);
        return (int)$q->fetchColumn() > 0;
    }

    // ---------------------------------------------------------------- actions

    /** Saves work in progress. Does not change status, live credentials, or access. Partial values allowed. */
    public function saveDraft(array $fields, ?string $newDocumentPath = null): void
    {
        $uid = $this->requireNurse();
        $license = self::normalizeLicense((string)($fields['license_number'] ?? ''));
        $emp = trim((string)($fields['employee_id'] ?? '')); $pos = trim((string)($fields['position'] ?? ''));
        $errors = self::validateFields(['license_number' => $license, 'employee_id' => $emp, 'position' => $pos], $this->load()['live'], false);
        if ($errors) throw new InvalidArgumentException(implode(' ', $errors));
        $this->db->beginTransaction();
        try {
            $q = $this->db->prepare('SELECT u.status FROM users u JOIN nurses n ON n.user_id=u.id WHERE u.id=? AND u.role=\'nurse\' FOR UPDATE'); $q->execute([$uid]);
            if ($q->fetchColumn() === false) throw new RuntimeException('Nurse profile not found.');
            $d = $this->db->prepare('SELECT pending_document_path FROM nurse_credential_drafts WHERE nurse_user_id=? FOR UPDATE'); $d->execute([$uid]); $oldDraftDoc = $d->fetchColumn();
            $doc = $newDocumentPath ?? ($oldDraftDoc ?: null);
            $this->db->prepare('INSERT INTO nurse_credential_drafts(nurse_user_id,license_number,employee_id,position,pending_document_path) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE license_number=VALUES(license_number),employee_id=VALUES(employee_id),position=VALUES(position),pending_document_path=VALUES(pending_document_path)')
                ->execute([$uid, $license !== '' ? $license : null, $emp !== '' ? $emp : null, $pos !== '' ? $pos : null, $doc]);
            $this->db->commit();
        } catch (Throwable $e) { if ($this->db->inTransaction()) $this->db->rollBack(); throw $e; }
        // Only an unsubmitted, replaced draft file is removed; submitted documents are never deleted.
        if ($newDocumentPath && $oldDraftDoc && $oldDraftDoc !== $newDocumentPath) $this->discardUnreferenced((string)$oldDraftDoc);
    }

    private function discardUnreferenced(string $rel): void
    {
        if (!self::documentExists($rel)) return;
        $q = $this->db->prepare('SELECT (SELECT COUNT(*) FROM nurses WHERE valid_id_path=?)+(SELECT COUNT(*) FROM nurse_credential_submissions WHERE document_path=?)+(SELECT COUNT(*) FROM nurse_credential_drafts WHERE pending_document_path=?)');
        $q->execute([$rel, $rel, $rel]);
        if ((int)$q->fetchColumn() === 0) @unlink(BASE_PATH . '/' . $rel);
    }

    /**
     * Submits corrected credentials for Doctor review. Returns the resulting state summary.
     * @return array{previous_status:string,material_change:bool,demoted:bool}
     */
    public function submit(array $fields, ?string $newDocumentPath = null): array
    {
        $uid = $this->requireNurse();
        $license = self::normalizeLicense((string)($fields['license_number'] ?? ''));
        $emp = trim((string)($fields['employee_id'] ?? '')); $pos = trim((string)($fields['position'] ?? ''));
        $this->db->beginTransaction();
        try {
            $q = $this->db->prepare("SELECT u.status AS account_status,u.email_verified,u.self_service_only,n.employee_id,n.license_number,n.position,n.valid_id_path,n.clinical_authorization_status FROM users u JOIN nurses n ON n.user_id=u.id WHERE u.id=? AND u.role='nurse' FOR UPDATE");
            $q->execute([$uid]); $live = $q->fetch(PDO::FETCH_ASSOC);
            if (!$live) throw new RuntimeException('Nurse profile not found.');
            if ($live['account_status'] !== 'active') throw new RuntimeException('Your account is ' . $live['account_status'] . '. Credentials can be submitted only on an active account; contact the Clinic Head.');
            if (empty($live['email_verified'])) throw new RuntimeException('Verify your email address with your one-time code before submitting credentials.');
            $previous = (string)$live['clinical_authorization_status'];
            if ($previous === 'revoked') throw new RuntimeException('Clinical access was revoked by a Doctor. Contact the Clinic Head; credentials cannot be resubmitted here.');

            $errors = self::validateFields(['license_number' => $license, 'employee_id' => $emp, 'position' => $pos], $live, true);
            if ($errors) throw new InvalidArgumentException(implode(' ', $errors));
            if ($license !== self::normalizeLicense((string)$live['license_number']) && $this->duplicateIdentifier('license_number', $license, $uid)) throw new InvalidArgumentException('This PRC registration number is already registered to another account. Contact the Clinic Head if this is an error.');
            if ($emp !== trim((string)$live['employee_id']) && $this->duplicateIdentifier('employee_id', $emp, $uid)) throw new InvalidArgumentException('This Employee ID is already registered to another account. Contact the Clinic Head if this is an error.');

            $d = $this->db->prepare('SELECT pending_document_path FROM nurse_credential_drafts WHERE nurse_user_id=? FOR UPDATE'); $d->execute([$uid]); $draftDoc = $d->fetchColumn() ?: null;
            $document = $newDocumentPath ?? ($draftDoc && self::documentExists($draftDoc) ? $draftDoc : null) ?? $live['valid_id_path'];
            if (!$document) throw new InvalidArgumentException('Upload your supporting professional credential (PDF, JPG or PNG).');
            if ($document !== $live['valid_id_path'] && !self::documentExists($document)) throw new InvalidArgumentException('The supporting document is missing. Upload it again.');

            $material = $license !== self::normalizeLicense((string)$live['license_number']) || $emp !== trim((string)$live['employee_id'])
                || $pos !== trim((string)$live['position']) || $document !== $live['valid_id_path'];
            if ($previous === 'authorized' && !$material) throw new InvalidArgumentException('No professional details were changed, so there is nothing to submit.');
            if ($previous === 'pending' && !$material) {
                $s = $this->db->prepare('SELECT COUNT(*) FROM nurse_credential_submissions WHERE nurse_user_id=? AND license_number=? AND employee_id=? AND position=? AND document_path=? AND id=(SELECT MAX(id) FROM nurse_credential_submissions WHERE nurse_user_id=?)');
                $s->execute([$uid, $license, $emp, $pos, $document, $uid]);
                if ((int)$s->fetchColumn() > 0) throw new InvalidArgumentException('These credentials were already submitted and are awaiting Doctor review. Duplicate submission ignored.');
            }

            $demoted = $previous === 'authorized';
            $this->db->prepare("UPDATE nurses SET license_number=?,employee_id=?,position=?,valid_id_path=?,clinical_authorization_status='pending' WHERE user_id=?")->execute([$license, $emp, $pos, $document, $uid]);
            // Re-verification: unrestricted clinical access is withdrawn until a Doctor decides again.
            $this->db->prepare('UPDATE users SET credential_version=COALESCE(credential_version,0)+1' . ($demoted ? ',self_service_only=1' : '') . ' WHERE id=?')->execute([$uid]);
            $this->db->prepare('INSERT INTO nurse_credential_submissions(nurse_user_id,license_number,employee_id,position,document_path,material_change,previous_status,resulting_status) VALUES (?,?,?,?,?,?,?,\'pending\')')->execute([$uid, $license, $emp, $pos, $document, $material ? 1 : 0, $previous]);
            $this->db->prepare('DELETE FROM nurse_credential_drafts WHERE nurse_user_id=?')->execute([$uid]);
            $this->db->prepare("INSERT INTO activity_logs(user_id,action,module,details) VALUES (?,'Nurse credentials submitted','nurse_clinical_authorization',?)")
                ->execute([$uid, 'Account #' . $uid . ' submitted credentials for physician review' . ($demoted ? '; clinical access withdrawn pending re-verification' : '') . '.']);
            $this->db->prepare("INSERT INTO notifications(user_id,type,title,message,link) SELECT u.id,'clinical_review',?,?,? FROM users u JOIN admin_profiles p ON p.user_id=u.id WHERE u.status='active' AND p.profile='doctor'")
                ->execute([$demoted ? 'Nurse re-verification required' : 'Nurse credentials awaiting review', $demoted ? 'An authorized Nurse changed professional credentials and needs re-verification.' : 'A Nurse submitted professional credentials for your review.', app_url('pages/doctor/nurse_verification.php?view=' . $uid)]);
            $this->db->commit();
            return ['previous_status' => $previous, 'material_change' => $material, 'demoted' => $demoted];
        } catch (Throwable $e) { if ($this->db->inTransaction()) $this->db->rollBack(); throw $e; }
    }
}

