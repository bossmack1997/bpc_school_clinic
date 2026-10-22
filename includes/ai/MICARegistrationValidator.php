<?php
require_once __DIR__.'/../institutional_eligibility.php';
/**
 * MICARegistrationValidator.php
 * ------------------------------------------------------------------
 * AI-Enhanced School Clinic Management System for Bulacan Polytechnic College
 * Healthcare Service Improvement
 *
 * MICA Intelligent User Registration & Role Validation Engine
 * 
 * Provides:
 *  1. Institutional Identity & Ownership Verification (Roster & Regex)
 *  2. Server-Side Role Enforcement (Prevents Client Privilege Escalation)
 *  3. Cryptographic OTP Verification & Rate Limiting
 *  4. AI-Assisted Consistency & Anomaly Detection
 *  5. Risk Level Assessment (Low, Medium, High)
 *  6. 3-State Final Decision Matrix (VERIFIED / REVIEW / REJECTED)
 * ------------------------------------------------------------------
 */

class MICARegistrationValidator
{
    private ?PDO $db;
    private $sendOtp;

    // Known institutional domains for Bulacan Polytechnic College
    private static array $institutionalDomains = [
        'bpc.edu.ph',
        'bulacanpolytechniccollege.edu.ph',
        'my.bpc.edu.ph',
        'faculty.bpc.edu.ph',
        'staff.bpc.edu.ph',
        'admin.bpc.edu.ph'
    ];

    // Disposable/temporary email domains that trigger automatic rejection
    private static array $disposableDomains = [
        'mailinator.com',
        'tempmail.com',
        '10minutemail.com',
        'guerrillamail.com',
        'sharklasers.com',
        'yopmail.com',
        'trashmail.com',
        'dispostable.com',
        'getairmail.com',
        'throwawaymail.com',
        'temp-mail.org',
        'fakemailgenerator.com'
    ];

    public function __construct(?PDO $db = null, ?callable $sendOtp = null)
    {
        $this->db = $db;
        $this->sendOtp = $sendOtp ?? 'send_registration_otp_email';
        date_default_timezone_set('Asia/Manila');
        if($db)$db->exec("SET time_zone='+08:00'");
    }

    /**
     * Validate registration payload against deterministic rules, institutional identity,
     * role eligibility, and AI consistency heuristics.
     *
     * @param array $data Input form data
     * @param bool $hasUploadedId Whether a valid ID document was attached
     * @return array Result containing decision, confidence, risk_level, reason, anomalies, errors, metadata
     */
    public function validateRegistration(array $data, bool $hasUploadedId = false): array
    {
        $errors = [];
        $anomalies = [];
        $confidence = 0.50; // Base baseline score
        $riskLevel = 'low';
        $reasons = [];

        // 1. Server-Side Role Normalization & Enforcement
        $rawRole = strtolower(trim($data['role'] ?? $data['account_type'] ?? 'student'));
        $roleMap = [
            'student'       => 'student',
            'teacher'       => 'teacher',
            'adviser'       => 'teacher',
            'nurse'         => 'nurse',
            'admin'         => 'admin',
            'administrator' => 'admin',
        ];

        $role = $roleMap[$rawRole] ?? null;
        if (!$role) {
            return [
                'decision'   => 'rejected',
                'confidence' => 0.0,
                'risk_level' => 'high',
                'reason'     => 'Invalid or unsupported role specified.',
                'anomalies'  => ['Unrecognized role attempted: ' . $rawRole],
                'errors'     => ['Invalid account type selected. Please select Student, Teacher, Nurse, or Doctor/Admin.'],
                'metadata'   => []
            ];
        }

        // 2. Common Fields Extraction & Sanitization
        $firstName  = trim($data['first_name'] ?? '');
        $middleName = trim($data['middle_name'] ?? '');
        $lastName   = trim($data['last_name'] ?? '');
        $email      = strtolower(trim($data['email'] ?? ''));
        $username   = trim($data['username'] ?? '');
        $password   = $data['password'] ?? '';
        $confirmPw  = $data['confirm_password'] ?? '';
        $contact    = trim($data['contact_number'] ?? $data['phone'] ?? '');
        $gender     = trim($data['gender'] ?? '');
        $dob        = trim($data['date_of_birth'] ?? $data['birthdate'] ?? '');
        $certify    = !empty($data['certify']);
        $certify    = !empty($data['certify']) || !empty($data['certify_info']) || !empty($data['terms_agreement']);

        // 3. Syntax & Required Field Validations
        if (!$firstName || !$lastName || !$email || !$username || !$password) {
            $errors[] = 'Please fill in all mandatory account fields.';
        }

        if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please enter a valid email address.';
        }

        if ($username && !preg_match('/^[a-zA-Z0-9_]{3,50}$/', $username)) {
            $errors[] = 'Username must be 3-50 characters containing only letters, numbers, and underscores.';
        }

        if ($password && strlen($password) < 8) {
            $errors[] = 'Password must be at least 8 characters long.';
        }

        if ($confirmPw !== '' && $password !== $confirmPw) {
            $errors[] = 'Password confirmation does not match.';
        }

        if (!$certify) {
            $errors[] = 'You must certify that all provided registration information is true and accurate.';
        }

        // 4. Anti-Fraud / Disposable Email Detection
        $emailParts = explode('@', $email);
        $emailDomain = count($emailParts) === 2 ? strtolower($emailParts[1]) : '';

        if (in_array($emailDomain, self::$disposableDomains, true)) {
            $anomalies[] = 'Disposable email provider detected (' . $emailDomain . ').';
            $errors[] = 'Disposable email services are not permitted. Please use your institutional or personal email.';
            $confidence = 0.05;
            $riskLevel = 'high';
        }

        if ($this->isGibberish($firstName) || $this->isGibberish($lastName) || $this->isGibberish($username)) {
            $anomalies[] = 'Synthetic or repetitive character pattern detected in applicant names/username.';
            $confidence -= 0.35;
            $riskLevel = 'high';
        }

        // 5. Institutional Domain Bonus
        $isInstitutionalEmail = false;
        foreach (self::$institutionalDomains as $domain) {
            if ($emailDomain === $domain || str_ends_with($emailDomain, '.' . $domain)) {
                $isInstitutionalEmail = true;
                break;
            }
        }

        if ($isInstitutionalEmail) {
            $confidence += 0.25;
            $reasons[] = 'Recognized institutional email domain (@' . $emailDomain . ')';
        } else {
            $confidence -= 0.05;
            $anomalies[] = 'Non-institutional email domain (@' . $emailDomain . ')';
        }

        // 6. Duplicate Account Check
        if ($this->db && $username && $email) {
            $stmt = $this->db->prepare("SELECT id, username, email, status FROM users WHERE username = ? OR email = ? LIMIT 1");
            $stmt->execute([$username, $email]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                if ($existing['username'] === $username) {
                    $errors[] = 'Username is already registered.';
                }
                if ($existing['email'] === $email) {
                    $errors[] = 'An account is already associated with this email address.';
                }
                $riskLevel = 'high';
            }
        }

        // 7. Role-Specific Institutional Identifier & Ownership Validation
        $metadata = [];
        $identifier = '';

        if ($role === 'student') {
            $studentNo = strtoupper(trim($data['student_number'] ?? ''));
            $course    = trim($data['course'] ?? '');
            $yearLevel = trim($data['year_level'] ?? '');
            $section   = trim($data['section'] ?? '');

            $identifier = $studentNo;
            $metadata['student_number'] = $studentNo;
            $metadata['course']         = $course;
            $metadata['year_level']     = $yearLevel;
            $metadata['section']        = $section;

            if (!$studentNo) {
                $errors[] = 'Student Number is required for student registration.';
            } else {
                // Check format (e.g. 2026-00123, 2024-10492, or standard alphanumeric 6-15 chars)
                if (preg_match('/^(20\d{2})[-_\s]?\d{4,6}$/', $studentNo) || preg_match('/^[A-Z0-9-]{6,15}$/', $studentNo)) {
                    $confidence += 0.15;
                    $reasons[] = 'Valid student ID format pattern (' . $studentNo . ')';
                } else {
                    $anomalies[] = 'Non-standard student number pattern: ' . $studentNo;
                    $confidence -= 0.15;
                    $riskLevel = 'medium';
                }

                // Check duplicate student number
                if ($this->db) {
                    $stmt = $this->db->prepare("SELECT id FROM students WHERE student_number = ? LIMIT 1");
                    $stmt->execute([$studentNo]);
                    if ($stmt->fetch()) {
                        $errors[] = 'This Student Number is already registered in the clinic system.';
                        $riskLevel = 'high';
                    }
                }
            }

        } elseif ($role === 'teacher') {
            $employeeId = strtoupper(trim($data['employee_id'] ?? ''));
            $department = trim($data['department'] ?? '');

            $identifier = $employeeId;
            $metadata['employee_id'] = $employeeId;
            $metadata['department']  = $department;

            if (!$employeeId) {
                $errors[] = 'Teacher / Employee ID is required.';
            } else {
                if (preg_match('/^[A-Z0-9-]{3,20}$/', $employeeId)) {
                    $confidence += 0.15;
                    $reasons[] = 'Valid teacher employee ID pattern (' . $employeeId . ')';
                } else {
                    $anomalies[] = 'Non-standard employee ID format: ' . $employeeId;
                    $confidence -= 0.15;
                    $riskLevel = 'medium';
                }

                if ($this->db) {
                    $stmt = $this->db->prepare("SELECT id FROM teachers WHERE employee_id = ? LIMIT 1");
                    $stmt->execute([$employeeId]);
                    if ($stmt->fetch()) {
                        $errors[] = 'This Teacher Employee ID is already registered.';
                        $riskLevel = 'high';
                    }
                }
            }

        } elseif ($role === 'nurse') {
            $employeeId    = strtoupper(trim($data['employee_id'] ?? ''));
            $licenseNumber = strtoupper(trim($data['license_number'] ?? ''));

            $identifier = $employeeId;
            $metadata['employee_id']    = $employeeId;
            $metadata['license_number'] = $licenseNumber;

            if (!$employeeId) {
                $errors[] = 'Nurse Employee ID is required.';
            }

            if ($licenseNumber) {
                if (preg_match('/^(PRC[-_\s]?)?\d{6,8}$/i', $licenseNumber)) {
                    $confidence += 0.20;
                    $reasons[] = 'Valid PRC Nursing License format (' . $licenseNumber . ')';
                } else {
                    $anomalies[] = 'Non-standard PRC License format: ' . $licenseNumber;
                    $confidence -= 0.05;
                }
            } else {
                $anomalies[] = 'PRC Nursing License number not supplied.';
            }

            if ($this->db && $employeeId) {
                $stmt = $this->db->prepare("SELECT id FROM nurses WHERE employee_id = ? LIMIT 1");
                $stmt->execute([$employeeId]);
                if ($stmt->fetch()) {
                    $errors[] = 'This Nurse Employee ID is already registered.';
                    $riskLevel = 'high';
                }
            }

        } elseif ($role === 'admin') {
            $adminOffice = trim($data['admin_office'] ?? $data['department'] ?? 'Clinic Administration');
            $employeeId  = strtoupper(trim($data['employee_id'] ?? ''));
            $identifier  = $employeeId ?: $username;
            $metadata['admin_office'] = $adminOffice;
            $metadata['employee_id']  = $employeeId;

            // Security constraint: Doctor / Admin is strongly restricted
            if (!$isInstitutionalEmail) {
                $anomalies[] = 'Doctor/Admin registration attempted with public/non-institutional email.';
                $confidence -= 0.25;
                $riskLevel = 'high';
            } else {
                $confidence += 0.20;
                $reasons[] = 'Institutional administrator email confirmed.';
            }
        }

        $trustedIdentity = false;
        // 8. Institutional Roster Verification & Identity-to-Email Ownership Check
        if ($this->db && $identifier) {
            $rosterCheck = $this->checkInstitutionalRoster($identifier, $role, $email, $firstName, $lastName, trim($data['license_number']??''));
            if ($rosterCheck['matched']) {
                $trustedIdentity = true;
                $confidence += 0.30;
                $reasons[] = 'Institutional roster record matched (' . $rosterCheck['details'] . ')';
            } elseif ($rosterCheck['impersonation_flag']) {
                $errors[] = $rosterCheck['error'];
                $anomalies[] = 'Identity ownership conflict: ' . $rosterCheck['error'];
                $riskLevel = 'high';
                $confidence = 0.10;
            } elseif ($rosterCheck['role_mismatch']) {
                $errors[] = $rosterCheck['error'];
                $anomalies[] = 'Role eligibility mismatch: ' . $rosterCheck['error'];
                $riskLevel = 'high';
                $confidence = 0.10;
            } elseif ($rosterCheck['error']!=='') {
                $reasons[]=$rosterCheck['error'];
            }
        }

        // 9. Document Attachment & Phone Number Scoring
        if ($hasUploadedId) {
            $confidence += 0.10;
            $reasons[] = 'Identity verification document attached';
        }

        if ($contact) {
            $cleanContact = preg_replace('/[^0-9+]/', '', $contact);
            if (preg_match('/^(09|\+639)\d{9}$/', $cleanContact)) {
                $confidence += 0.05;
            } else {
                $anomalies[] = 'Non-standard Philippine mobile contact format.';
            }
        }

        // Clamp confidence between 0.00 and 1.00
        $confidence = max(0.0, min(1.0, round($confidence, 2)));

        // Identity and role eligibility decide access; heuristic confidence never grants a role.
        if ($errors) {
            $decision='rejected';$finalReason=implode(' ',$errors);
        } else {
            $decision=$role!=='admin' && $trustedIdentity && $riskLevel!=='high'?'approved':'review';
            $finalReason=implode('; ',array_unique($reasons));
            $finalReason.=$decision==='approved'?'; Trusted roster identity and role matched; OTP ownership still required.':'; Trusted identity and role eligibility require authorized review.';
        }
        return [
            'decision'   => $decision,
            'confidence' => $confidence,
            'risk_level' => $riskLevel,
            'reason'     => $finalReason,
            'anomalies'  => $anomalies,
            'errors'     => $errors,
            'metadata'   => $metadata,
            'role'       => $role
        ];
    }

    /**
     * Cross-reference against institutional_roster table
     */
    private function checkInstitutionalRoster(string $identifier, string $role, string $email, string $firstName, string $lastName, string $license = ''): array
    {
        $result = [
            'matched'            => false,
            'impersonation_flag' => false,
            'role_mismatch'      => false,
            'error'              => '',
            'details'            => ''
        ];

        try {
            $source=InstitutionalEligibility::roster($this->db,$identifier);
            if(!isset($source['record'])) {
                $result['error']=$source['reason'];return $result;
            }
            $check=InstitutionalEligibility::matchIdentity($source['record'],[
                'role'=>$role,'email'=>$email,'first_name'=>$firstName,'last_name'=>$lastName
            ],$license);
            $result['matched']=$check['eligible'];
            $result['impersonation_flag']=$check['code']==='email_mismatch';
            $result['role_mismatch']=$check['code']==='role_mismatch';
            $result['error']=$check['eligible']?'':$check['reason'];
            $result['details']=$check['reason'];
            return $result;

        } catch (Exception $e) {
            error_log('Institutional roster query error: ' . $e->getMessage());
            return $result;
        }
    }

    /**
     * Cryptographically verify submitted OTP
     */
    public function verifyOtp(int $userId, string $enteredOtp): array
    {
        if(!$this->db)return ['status'=>'error','message'=>'Verification is unavailable.'];
        $this->db->beginTransaction();
        try {
            $result=$this->verifyOtpLocked($userId,$enteredOtp);
            if($result['status']==='verified') {
                require_once __DIR__.'/../account_monitoring.php';
                $result['account_status']=(new AccountMonitoringService($this->db))->finishVerification($userId);
            } elseif($result['status']==='already_verified') {
                $result['account_status']=$result['user']['status'];
            }
            $this->db->commit();return $result;
        }
        catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    private function verifyOtpLocked(int $userId, string $enteredOtp): array
    {
        if (!$this->db || $userId <= 0) {
            return ['status' => 'error', 'message' => 'Invalid verification request.'];
        }

        $stmt = $this->db->prepare("SELECT * FROM users WHERE id = ? LIMIT 1 FOR UPDATE");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            return ['status' => 'error', 'message' => 'User not found.'];
        }

        if ($user['status']!=='pending') return ['status'=>'blocked','message'=>'Account verification is unavailable for this account state.'];
        if ($user['email_verified']) {
            return ['status' => 'already_verified', 'message' => 'Email address is already verified.', 'user' => $user];
        }

        // Check maximum verification attempts (max 5)
        if ($user['otp_attempts'] >= 5) {
            $this->db->prepare("UPDATE users SET otp_hash = NULL, status = 'rejected', risk_level = 'high', rejection_reason = 'Exceeded maximum OTP verification attempts.' WHERE id = ?")
                     ->execute([$userId]);
            return [
                'status'  => 'max_attempts_exceeded',
                'message' => 'Maximum verification attempts exceeded. For security, this registration has been locked.'
            ];
        }

        if (!preg_match('/^[0-9]{6}$/D',$enteredOtp) || empty($user['otp_hash']) || empty($user['otp_expires_at'])) return ['status'=>'invalid','message'=>'A valid current verification code is required.'];
        // Check expiration
        if (!empty($user['otp_expires_at']) && strtotime($user['otp_expires_at']) <= time()) {
            return [
                'status'  => 'expired',
                'message' => 'Verification code has expired. Please request a new code.'
            ];
        }

        // Verify OTP hash
        $enteredHash = hash('sha256', trim($enteredOtp));
        if (!hash_equals($user['otp_hash'] ?? '', $enteredHash)) {
            require_once __DIR__.'/../account_monitoring.php';
            AccountMonitoringService::event($this->db,'otp_failure',(string)$userId,'Incorrect OTP submitted.',$userId);
            $newAttempts = $user['otp_attempts'] + 1;
            $this->db->prepare("UPDATE users SET otp_attempts = ?, status=IF(? >= 5,'rejected',status), registration_status=IF(? >= 5,'rejected',registration_status) WHERE id = ?")->execute([$newAttempts, $newAttempts, $newAttempts, $userId]);
            $remaining = 5 - $newAttempts;
            return [
                'status'  => 'invalid',
                'message' => 'Incorrect verification code. ' . ($remaining > 0 ? "You have $remaining attempt(s) remaining." : "No attempts remaining.")
            ];
        }

        // Successful verification
        $this->db->prepare("
            UPDATE users 
            SET email_verified = 1, otp_hash = NULL, otp_expires_at = NULL, otp_attempts = 0
            WHERE id = ?
        ")->execute([$userId]);

        return [
            'status'  => 'verified',
            'message' => 'Institutional email ownership verified successfully.',
            'user'    => $user
        ];
    }

    /**
     * Resend OTP with cooldown rate limiting
     */
    public function resendOtp(int $userId): array
    {
        if(!$this->db)return ['status'=>'error','message'=>'Verification is unavailable.'];
        $this->db->beginTransaction();
        try{$draft=$this->prepareResendOtp($userId);$this->db->commit();}
        catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
        if(($draft['status']??'')!=='ready')return $draft;
        $sent=($this->sendOtp)($draft['email'],$draft['otp'],$draft['name'],$draft['role']);
        return $sent?['status'=>'sent','message'=>'A new verification code has been dispatched.']:['status'=>'delivery_failed','message'=>'Verification email delivery failed. The account remains unverified; retry after the resend cooldown.'];
    }
    private function prepareResendOtp(int $userId): array
    {
        if (!$this->db || $userId <= 0) {
            return ['status' => 'error', 'message' => 'Invalid user request.'];
        }

        $stmt = $this->db->prepare("SELECT * FROM users WHERE id = ? LIMIT 1 FOR UPDATE");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            return ['status' => 'error', 'message' => 'User not found.'];
        }

        if($user['status']!=='pending' || $user['email_verified'] || $user['otp_attempts']>=5)return ['status'=>'blocked','message'=>'A new code is not available for this account state. Contact authorized security personnel if recovery is needed.'];
        // 60-second cooldown check
        if (!empty($user['otp_last_sent_at'])) {
            $lastSent = strtotime($user['otp_last_sent_at']);
            $elapsed = time() - $lastSent;
            if ($elapsed < 60) {
                $waitSec = 60 - $elapsed;
                return ['status' => 'cooldown', 'message' => "Please wait {$waitSec} second(s) before requesting another code."];
            }
        }

        // Generate 6-digit cryptographic OTP
        $otpCode = str_pad((string)random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
        $otpHash = hash('sha256', $otpCode);
        $expiresAt = date('Y-m-d H:i:s', time() + 600); // 10 minutes

        $this->db->prepare("
            UPDATE users 
            SET otp_hash = ?, otp_expires_at = ?, otp_attempts = 0, otp_last_sent_at = NOW() 
            WHERE id = ?
        ")->execute([$otpHash, $expiresAt, $userId]);

        return ['status'=>'ready','otp'=>$otpCode,'email'=>$user['email'],'name'=>trim($user['first_name'].' '.$user['last_name']),'role'=>$user['role']];
    }

    /**
     * Simple gibberish & synthetic pattern heuristic
     */
    private function isGibberish(string $str): bool
    {
        $str = strtolower(trim($str));
        if (strlen($str) < 2) {
            return true;
        }

        // Excessive repeated single characters (e.g. "aaaaa", "zzzzz")
        if (preg_match('/(.)\1{3,}/', $str)) {
            return true;
        }

        // Common test patterns
        $testPatterns = ['asdf', 'qwerty', 'zxcv', '12345', 'testing', 'sample', 'foobar', 'dummy'];
        foreach ($testPatterns as $pat) {
            if ($str === $pat) {
                return true;
            }
        }

        return false;
    }
}
