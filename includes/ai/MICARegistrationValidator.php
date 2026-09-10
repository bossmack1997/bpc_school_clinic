<?php
/**
 * MICARegistrationValidator.php
 * ------------------------------------------------------------------
 * AI-Enhanced School Clinic Management System for Bulacan Polytechnic College
 * Healthcare Service Improvement
 *
 * MICA Intelligent User Registration & Role Validation Engine
 * 
 * Provides:
 *  1. Deterministic Rule Validation (Format, Constraints, Institutional Patterns)
 *  2. Role Consistency & Anomaly Detection (PRC License, Student No, Employee ID)
 *  3. Confidence Scoring & Decision Matrix (approved, review, rejected)
 *  4. AI Action / Audit Integration for Admin Review Queue
 * ------------------------------------------------------------------
 */

class MICARegistrationValidator
{
    private ?PDO $db;

    // Known institutional domains for Bulacan Polytechnic College
    private static array $institutionalDomains = [
        'bpc.edu.ph',
        'bulacanpolytechniccollege.edu.ph',
        'my.bpc.edu.ph',
        'faculty.bpc.edu.ph',
        'staff.bpc.edu.ph',
        'admin.bpc.edu.ph'
    ];

    // Disposable/temporary email domains that trigger rejection
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
        'throwawaymail.com'
    ];

    public function __construct(?PDO $db = null)
    {
        $this->db = $db;
    }

    /**
     * Validate registration payload and return structured evaluation
     *
     * @param array $data Input form data
     * @param bool $hasUploadedId Whether a valid ID document was uploaded
     * @return array Result containing decision, confidence, reason, anomalies, errors, metadata
     */
    public function validateRegistration(array $data, bool $hasUploadedId = false): array
    {
        $errors = [];
        $anomalies = [];
        $confidence = 0.50; // Base score
        $reasons = [];

        // 1. Role Normalization
        $rawRole = strtolower(trim($data['role'] ?? $data['account_type'] ?? 'student'));
        $roleMap = [
            'student'       => 'student',
            'teacher'       => 'teacher',
            'adviser'       => 'teacher',
            'nurse'         => 'nurse',
            'admin'         => 'admin',
            'administrator' => 'admin',
            'doctor'        => 'doctor',
        ];

        $role = $roleMap[$rawRole] ?? null;
        if (!$role) {
            return [
                'decision'   => 'rejected',
                'confidence' => 0.0,
                'reason'     => 'Invalid or unsupported role specified.',
                'anomalies'  => ['Unrecognized role: ' . $rawRole],
                'errors'     => ['Invalid account type selected. Please select Student, Teacher, Nurse, or Administrator.'],
                'metadata'   => []
            ];
        }

        // 2. Common Fields Extraction
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

        // 3. Syntax & Fundamental Validations
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

        // 4. Spam / Gibberish / Disposable Domain Detection
        $emailParts = explode('@', $email);
        $emailDomain = count($emailParts) === 2 ? strtolower($emailParts[1]) : '';

        if (in_array($emailDomain, self::$disposableDomains, true)) {
            $anomalies[] = 'Disposable email provider detected (' . $emailDomain . ').';
            $errors[] = 'Disposable email services are not permitted. Please use your institutional or personal email.';
            $confidence = 0.05;
        }

        if ($this->isGibberish($firstName) || $this->isGibberish($lastName) || $this->isGibberish($username)) {
            $anomalies[] = 'Synthetic or repetitive character pattern detected in user names/username.';
            $confidence -= 0.35;
        }

        // 5. Duplicate Check in Database (if DB available)
        if ($this->db && $username && $email) {
            $stmt = $this->db->prepare("SELECT id, username, email, status FROM users WHERE username = ? OR email = ? LIMIT 1");
            $stmt->execute([$username, $email]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                if ($existing['username'] === $username) {
                    $errors[] = 'Username is already registered.';
                }
                if ($existing['email'] === $email) {
                    $errors[] = 'Email address is already registered.';
                }
            }
        }

        // 6. Institutional Domain Bonus
        $isInstitutionalEmail = false;
        foreach (self::$institutionalDomains as $domain) {
            if ($emailDomain === $domain || str_ends_with($emailDomain, '.' . $domain)) {
                $isInstitutionalEmail = true;
                break;
            }
        }

        if ($isInstitutionalEmail) {
            $confidence += 0.25;
            $reasons[] = 'Verified institutional email domain (@' . $emailDomain . ')';
        } else {
            $confidence -= 0.05;
            $anomalies[] = 'Non-institutional email domain (@' . $emailDomain . ')';
        }

        // 7. Phone format check (PH standard 09XXXXXXXXX or +639XXXXXXXXX)
        if ($contact) {
            $cleanContact = preg_replace('/[^0-9+]/', '', $contact);
            if (preg_match('/^(09|\+639)\d{9}$/', $cleanContact)) {
                $confidence += 0.05;
            } else {
                $anomalies[] = 'Non-standard Philippine mobile contact format.';
                $confidence -= 0.05;
            }
        }

        // 8. Date of Birth & Age Sanity
        if ($dob) {
            $dobTime = strtotime($dob);
            if (!$dobTime || $dob > date('Y-m-d')) {
                $errors[] = 'Date of birth cannot be in the future or invalid format.';
            } else {
                $age = (int)date('Y') - (int)date('Y', $dobTime);
                if ($role === 'student' && ($age < 14 || $age > 80)) {
                    $anomalies[] = 'Unusual student age (' . $age . ' years old).';
                    $confidence -= 0.15;
                } elseif (in_array($role, ['teacher', 'nurse', 'doctor', 'admin'], true) && ($age < 18 || $age > 80)) {
                    $anomalies[] = 'Unusual staff age (' . $age . ' years old).';
                    $confidence -= 0.15;
                } else {
                    $confidence += 0.05;
                }
            }
        }

        // 9. Uploaded ID bonus
        if ($hasUploadedId) {
            $confidence += 0.10;
            $reasons[] = 'Identity verification document attached';
        }

        // 10. Role-Specific Evaluation
        $metadata = [];
        if ($role === 'student') {
            $studentNo = strtoupper(trim($data['student_number'] ?? ''));
            $course    = trim($data['course'] ?? '');
            $yearLevel = trim($data['year_level'] ?? '');
            $section   = trim($data['section'] ?? '');

            $metadata['student_number'] = $studentNo;
            $metadata['course']         = $course;
            $metadata['year_level']     = $yearLevel;
            $metadata['section']        = $section;

            if (!$studentNo) {
                $errors[] = 'Student Number is required for student registration.';
            } else {
                // BPC Standard Student Number format (e.g. 2021-00123, 2023-10492, or numeric/alphanumeric 6-15 chars)
                if (preg_match('/^(20\d{2})[-_\s]?\d{4,6}$/', $studentNo) || preg_match('/^[A-Z0-9-]{6,15}$/', $studentNo)) {
                    $confidence += 0.15;
                    $reasons[] = 'Valid student ID format pattern (' . $studentNo . ')';
                } else {
                    $anomalies[] = 'Non-standard student number pattern: ' . $studentNo;
                    $confidence -= 0.10;
                }

                // Check duplicate student number
                if ($this->db) {
                    $stmt = $this->db->prepare("SELECT id FROM students WHERE student_number = ? LIMIT 1");
                    $stmt->execute([$studentNo]);
                    if ($stmt->fetch()) {
                        $errors[] = 'This Student Number is already registered in the clinic system.';
                    }
                }
            }

            if ($course) {
                $confidence += 0.05;
            } else {
                $anomalies[] = 'Academic course not specified.';
            }

        } elseif ($role === 'teacher') {
            $employeeId = strtoupper(trim($data['employee_id'] ?? ''));
            $department = trim($data['department'] ?? '');

            $metadata['employee_id'] = $employeeId;
            $metadata['department']  = $department;

            if (!$employeeId) {
                $errors[] = 'Teacher / Employee ID is required.';
            } else {
                // Typical employee ID e.g. T-2023-01, EMP-1029, BPC-FAC-01
                if (preg_match('/^[A-Z0-9-]{4,20}$/', $employeeId)) {
                    $confidence += 0.15;
                    $reasons[] = 'Valid teacher employee ID pattern (' . $employeeId . ')';
                } else {
                    $anomalies[] = 'Non-standard employee ID format: ' . $employeeId;
                    $confidence -= 0.10;
                }

                if ($this->db) {
                    $stmt = $this->db->prepare("SELECT id FROM teachers WHERE employee_id = ? LIMIT 1");
                    $stmt->execute([$employeeId]);
                    if ($stmt->fetch()) {
                        $errors[] = 'This Teacher Employee ID is already registered.';
                    }
                }
            }

            if ($department) {
                $confidence += 0.05;
            } else {
                $anomalies[] = 'Department / Academic unit not specified.';
            }

        } elseif ($role === 'nurse') {
            $employeeId    = strtoupper(trim($data['employee_id'] ?? ''));
            $licenseNumber = strtoupper(trim($data['license_number'] ?? ''));

            $metadata['employee_id']    = $employeeId;
            $metadata['license_number'] = $licenseNumber;

            if (!$employeeId) {
                $errors[] = 'Nurse Employee ID is required.';
            }

            if (!$licenseNumber) {
                $anomalies[] = 'Professional PRC Nursing License Number is missing.';
                $confidence -= 0.15;
            } else {
                // PRC License Format (e.g. 7 digits or PRC-XXXXXXX)
                if (preg_match('/^(PRC[-_\s]?)?\d{6,8}$/i', $licenseNumber)) {
                    $confidence += 0.20;
                    $reasons[] = 'Valid PRC Nursing License format (' . $licenseNumber . ')';
                } else {
                    $anomalies[] = 'Non-standard PRC License format: ' . $licenseNumber;
                    $confidence -= 0.05;
                }
            }

            if ($this->db && $employeeId) {
                $stmt = $this->db->prepare("SELECT id FROM nurses WHERE employee_id = ? LIMIT 1");
                $stmt->execute([$employeeId]);
                if ($stmt->fetch()) {
                    $errors[] = 'This Nurse Employee ID is already registered.';
                }
            }

        } elseif ($role === 'admin' || $role === 'doctor') {
            $adminOffice = trim($data['admin_office'] ?? $data['department'] ?? 'Clinic Administration');
            $metadata['admin_office'] = $adminOffice;

            // Security constraint: Privileged administrative accounts undergo strict verification
            if (!$isInstitutionalEmail) {
                $anomalies[] = 'Administrative account registered using public/non-institutional email.';
                $confidence -= 0.20;
            } else {
                $confidence += 0.20;
                $reasons[] = 'Institutional administration email confirmed.';
            }
        }

        // Clamp confidence between 0.00 and 1.00
        $confidence = max(0.0, min(1.0, round($confidence, 2)));

        // 11. Final Decision Matrix (Approved, Review, Rejected)
        if (!empty($errors)) {
            $decision = 'rejected';
            $finalReason = implode(' ', $errors);
        } else {
            // Decision logic based on role & confidence
            if ($role === 'student') {
                // Students with valid ID format + clean checks are auto-approved
                if ($confidence >= 0.70 && empty($anomalies)) {
                    $decision = 'approved';
                    $reasons[] = 'All student credential criteria met with high consistency.';
                } elseif ($confidence >= 0.50) {
                    $decision = 'approved';
                    $reasons[] = 'Standard student registration verified.';
                } else {
                    $decision = 'review';
                    $reasons[] = 'Student account flagged for administrative verification due to credentials uncertainty.';
                }
            } elseif ($role === 'teacher') {
                if ($isInstitutionalEmail && $confidence >= 0.75) {
                    $decision = 'approved';
                    $reasons[] = 'Institutional faculty credentials verified.';
                } else {
                    $decision = 'review';
                    $reasons[] = 'Faculty account queued for clinic administrative verification.';
                }
            } elseif ($role === 'nurse') {
                // Medical nursing accounts require administrative authorization or auto-approval if full credentials match
                if ($isInstitutionalEmail && !empty($metadata['license_number']) && $confidence >= 0.85) {
                    $decision = 'approved';
                    $reasons[] = 'Verified clinical nurse credentials and license.';
                } else {
                    $decision = 'review';
                    $reasons[] = 'Nurse registration queued for PRC credential verification by Clinic Head.';
                }
            } else { // admin / doctor
                // Strict policy: Administrative role requests always require administrator sign-off
                $decision = 'review';
                $reasons[] = 'Administrative account request requires authorization by the School Clinic Administrator.';
            }

            $finalReason = implode('; ', array_unique($reasons));
            if (empty($finalReason)) {
                $finalReason = 'Account processed via AI consistency validator.';
            }
        }

        return [
            'decision'   => $decision,
            'confidence' => $confidence,
            'reason'     => $finalReason,
            'anomalies'  => $anomalies,
            'errors'     => $errors,
            'metadata'   => $metadata,
            'role'       => $role
        ];
    }

    /**
     * Create an AI Action review item in ai_actions table for accounts needing review
     */
    public function logReviewAction(int $userId, array $validationResult): ?int
    {
        if (!$this->db || $userId <= 0) {
            return null;
        }

        $role = $validationResult['role'] ?? 'user';
        $confidence = $validationResult['confidence'] ?? 0.50;
        $reason = $validationResult['reason'] ?? 'Needs review';
        $anomalies = $validationResult['anomalies'] ?? [];

        $title = 'Review ' . ucfirst($role) . ' Registration: User #' . $userId;
        $desc = "A new {$role} account requires validation.\n"
              . "Confidence: " . round($confidence * 100) . "%\n"
              . "Reason: " . $reason . "\n"
              . (!empty($anomalies) ? "Flags: " . implode(', ', $anomalies) : "");

        try {
            $stmt = $this->db->prepare("
                INSERT INTO ai_actions (
                    user_id, target_role, action_type, target_type, target_id,
                    title, description, reason, priority, status, suggested_data
                ) VALUES (
                    ?, 'admin', 'registration_review', 'users', ?,
                    ?, ?, ?, 'high', 'new', ?
                )
            ");

            $suggestedData = json_encode([
                'user_id'    => $userId,
                'role'       => $role,
                'confidence' => $confidence,
                'reason'     => $reason,
                'anomalies'  => $anomalies,
                'metadata'   => $validationResult['metadata'] ?? []
            ]);

            $stmt->execute([
                $userId,
                $userId,
                $title,
                $desc,
                $reason,
                $suggestedData
            ]);

            return (int)$this->db->lastInsertId();
        } catch (Exception $e) {
            error_log('Failed to log AI registration review action: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Simple gibberish & spam pattern heuristic
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
