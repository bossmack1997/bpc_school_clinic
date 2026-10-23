<?php
/**
 * auth/register.php
 * ------------------------------------------------------------------
 * Unified Medical Self-Registration System for BPC School Clinic.
 * Public registration creates basic accounts or restricted staff requests:
 *   1. Student
 *   2. Teacher
 *   3. Nurse (basic account; clinical access restricted)
 *   Administrator accounts use restricted provisioning, not public registration.
 * ------------------------------------------------------------------
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/ai/MICARegistrationValidator.php';
require_once __DIR__ . '/../includes/mail.php';
require_once __DIR__ . '/../includes/account_monitoring.php';

// Prevent caching of authentication pages
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');

if (is_logged_in()) {
    redirect(dashboard_url_for(current_user()['role']));
}

// Keep infrastructure failures distinct from applicant validation failures.
set_exception_handler(function (Throwable $e) {
    $reference = bin2hex(random_bytes(6));
    error_log('Registration failure ref='.$reference.' type='.get_class($e).' code='.(string)$e->getCode().' driver='.(string)($e instanceof PDOException?($e->errorInfo[1]??'unknown'):'none'));
    http_response_code(503);
    $message = 'Registration could not be completed. Please try again later. Reference: '.$reference;
    if (strtolower($_SERVER['HTTP_X_REQUESTED_WITH']??'')==='xmlhttprequest') json_response(false,['error_code'=>$e instanceof PDOException?'database_error':'server_error','reference'=>$reference],$message);
    echo htmlspecialchars($message,ENT_QUOTES,'UTF-8');
});
$db = getDB();
$errors = [];
$fieldErrors = [];
$registrationErrorCode = 'validation_error';
$old = $_POST ?? [];
$registrationAjax=strtolower($_SERVER['HTTP_X_REQUESTED_WITH']??'')==='xmlhttprequest';
$registrationStatus=422;
function registration_next(bool $ajax): never {
    if($ajax)json_response(true,['redirect'=>'verify_registration.php'],'Continue to email verification.');
    redirect('verify_registration.php');
}
// Reject malformed scalar fields before trim(), rendering, or validation.
foreach($_POST as $key=>$value)if($key!=='teacher_sections'&&!is_scalar($value)){
    http_response_code(400);
    if($registrationAjax)json_response(false,[],'Invalid registration information.');
    exit('Invalid registration information.');
}

// Determine default role selection from GET/POST
$roleRequest=$_POST['role']??$_POST['account_type']??$_GET['role']??$_GET['type']??'student';
$rawRoleReq = is_scalar($roleRequest)?strtolower(trim((string)$roleRequest)):'student';
// Admin accounts are NOT available via public registration.
// Admin accounts are created exclusively from the Admin Dashboard.
$roleNormMap = [
    'student'       => 'student',
    'teacher'       => 'teacher',
    'nurse'         => 'nurse',
];
$selectedRole = $roleNormMap[$rawRoleReq] ?? 'student';

/** Helper function for safe registration file uploads */
if (!function_exists('upload_registration_file')) {
    function upload_registration_file($fileKey, $destFolder, $prefix, $allowedMimes = ['image/jpeg','image/png','image/webp']) {
        if (empty($_FILES[$fileKey]) || $_FILES[$fileKey]['error'] !== UPLOAD_ERR_OK) {
            return null;
        }
        $file = $_FILES[$fileKey];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!in_array($mimeType, $allowedMimes, true)) {
            return null;
        }

        $ext = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','application/pdf'=>'pdf'][$mimeType];
        $filename = $prefix . '_' . time() . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
        $destRel = $destFolder . '/' . $filename;
        $destAbs = BASE_PATH . '/' . $destRel;
        $dir = dirname($destAbs);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        if (move_uploaded_file($file['tmp_name'], $destAbs)) {
            return $destRel;
        }
        return null;
    }
}

// Fetch active sections grouped for teacher advisory selection and student course selection
$sectionsQuery = $db->query("SELECT id, name, course, year_level FROM sections WHERE status = 'active' AND name != 'Unassigned' ORDER BY course, year_level, name");
$allSections = $sectionsQuery ? $sectionsQuery->fetchAll(PDO::FETCH_ASSOC) : [];
$sectionsByCourse = [];
foreach ($allSections as $s) {
    $c = !empty($s['course']) ? $s['course'] : 'General';
    $sectionsByCourse[$c][] = $s;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if(!is_string($_POST['csrf_token']??null)||!csrf_verify($_POST['csrf_token'])){
        http_response_code(403);
        if($registrationAjax)json_response(false,['error_code'=>'csrf_error'],'Your registration form expired. Refresh the page and try again.');
        exit('Your registration form expired. Refresh the page and try again.');
    }

    $rawRole = strtolower(trim($_POST['account_type'] ?? $_POST['role'] ?? 'student'));

    if (!isset($roleNormMap[$rawRole])) {
        $errors[] = 'Invalid account type selected. Please choose Student, Teacher, or Nurse.';
        $role = 'student';
    } else {
        $role = $roleNormMap[$rawRole];
    }

    $selectedRole = $role;
    $_POST['role'] = $role;
    $_POST['account_type'] = $role;

    // Common fields
    $firstName   = trim($_POST['first_name'] ?? '');
    $middleName  = trim($_POST['middle_name'] ?? '');
    $lastName    = trim($_POST['last_name'] ?? '');
    $email       = trim($_POST['email'] ?? '');
    $username    = trim($_POST['username'] ?? '');
    $password    = $_POST['password'] ?? '';
    $confirm     = $_POST['confirm_password'] ?? '';
    $contact     = trim($_POST['contact_number'] ?? '');
    $gender      = trim($_POST['gender'] ?? '');
    $dob         = trim($_POST['date_of_birth'] ?? '');

    // Resume only with both matching identity fields and the saved password.
    // This never verifies email, changes role, or activates the account.
    if(!$errors&&$username&&$email&&$password){
        $resume=$db->prepare("SELECT id,password_hash FROM users WHERE username=? AND email=? AND role=? AND status='pending' AND email_verified=0 LIMIT 1");
        $resume->execute([$username,$email,$role]);$pending=$resume->fetch(PDO::FETCH_ASSOC);
        if($pending&&password_verify($password,$pending['password_hash'])){
            session_regenerate_id(true);$_SESSION['pending_verification_user_id']=(int)$pending['id'];
            set_flash('info','Your account already exists and is awaiting email verification. Use Resend Code if you need another OTP.');
            registration_next($registrationAjax);
        }
    }
    $columns=$db->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN);
    if(array_diff(['email_verified_at','self_service_only'],$columns)){
        error_log('Registration schema incomplete; missing='.implode(',',array_diff(['email_verified_at','self_service_only'],$columns)).'; apply database/migration_basic_otp_activation.php via authorized deployment.');
        $errors[]='Registration is temporarily unavailable while a system update is completed. Please try again later.';
        $registrationStatus=503;
        $registrationErrorCode='schema_update_required';
    }

    // Stop on deployment failures; never append unrelated applicant errors.
    if (!$errors) {
        // Process file uploads first so validator can evaluate uploaded ID
        $profilePicRel = upload_registration_file('profile_picture', 'assets/images/profiles', 'user', ['image/jpeg','image/png','image/webp'])
                         ?: 'assets/images/default-avatar.png';
        $validIdRel = upload_registration_file('valid_id', 'storage/registration_documents', 'valid_id', ['image/jpeg','image/png','image/webp','application/pdf']);

        // MICA Intelligent Registration Validation
        $validator = new MICARegistrationValidator($db);
        $validation = $validator->validateRegistration($_POST, !empty($validIdRel));

        if (!empty($validation['errors'])) {
            $errors = array_merge($errors,$validation['errors']);
        } elseif ($validation['decision'] === 'rejected') {
            $errors[] = $validation['reason'] ?: 'Registration rejected based on automated credential validation.';
        }

        $fieldErrors = $validation['field_errors'] ?? [];
        if ($fieldErrors && preg_match('/already|exists/i',implode(' ',$errors))) {
            $registrationStatus=409; $registrationErrorCode='duplicate_account';
        }
    }
    if ($errors && $registrationStatus!==503) {
        $kind=preg_match('/already|duplicate/i',implode(' ',$errors))?'duplicate_indicator':(preg_match('/identity|ownership|eligib|roster/i',implode(' ',$errors))?'identity_failure':'invalid_registration');
        AccountMonitoringService::event($db,$kind,$email?:$username,'Registration rejected by deterministic validation.');
    }
    if (!$errors) {
        try {
            $db->beginTransaction();
            $hash = password_hash($password, PASSWORD_DEFAULT);

            // Generate 6-Digit Cryptographic OTP for institutional email ownership verification
            $otpCode      = str_pad((string)random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
            $otpHash      = hash('sha256', $otpCode);
            $otpExpiresAt = date('Y-m-d H:i:s', time() + 600); // 10 minutes

            $adminType = null;
            $decision = $validation['decision'];
            $accountStatus = 'pending';
            $regStatus     = ($decision === 'approved') ? 'approved' : 'review';
            $valReason     = $validation['reason'];
            $valConfidence = $validation['confidence'];
            $riskLevel     = $validation['risk_level'] ?? 'low';

            // All accounts are saved with email_verified = 0 and status = 'pending' until OTP verification completes
            $stmt = $db->prepare("
                INSERT INTO users (
                    role, admin_type, username, email, password_hash, first_name, middle_name, last_name, 
                    gender, date_of_birth, phone, profile_picture, status, email_verified,
                    otp_hash, otp_expires_at, otp_attempts, otp_last_sent_at, risk_level,
                    registration_status, validation_reason, validation_confidence, self_service_only
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?, ?, 
                    ?, ?, ?, ?, 'pending', 0,
                    ?, ?, 0, NOW(), ?,
                    ?, ?, ?, 1
                )
            ");
            $stmt->execute([
                $role,
                $adminType,
                $username,
                $email,
                $hash,
                $firstName,
                $middleName ?: null,
                $lastName,
                $gender ?: null,
                $dob ?: null,
                $contact ?: null,
                $profilePicRel,
                $otpHash,
                $otpExpiresAt,
                $riskLevel,
                $regStatus,
                $valReason,
                $valConfidence
            ]);
            $userId = (int)$db->lastInsertId();

            if ($role === 'student') {
                $studentNo = $validation['metadata']['student_number'] ?? trim($_POST['student_number'] ?? '');
                $course    = $validation['metadata']['course'] ?? trim($_POST['course'] ?? '');
                $yearLevel = $validation['metadata']['year_level'] ?? trim($_POST['year_level'] ?? '');
                $section   = $validation['metadata']['section'] ?? trim($_POST['section'] ?? '');
                $emergName = trim($_POST['emergency_contact_name'] ?? '');
                $emergNum  = trim($_POST['emergency_contact_number'] ?? '');
                $bloodType = trim($_POST['blood_type'] ?? '');
                $allergies = trim($_POST['allergies'] ?? '');

                $stmt = $db->prepare("
                    INSERT INTO students (
                        user_id, student_number, course, year_level, section,
                        emergency_contact_name, emergency_contact_number, blood_type, allergies, valid_id_path
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $userId,
                    $studentNo,
                    $course ?: null,
                    $yearLevel ?: null,
                    $section ?: null,
                    $emergName ?: null,
                    $emergNum ?: null,
                    $bloodType ?: null,
                    $allergies ?: null,
                    $validIdRel
                ]);
                $studentId = (int)$db->lastInsertId();

                log_activity($userId, 'Initiated student registration (pending OTP verification)', 'auth');

            } elseif ($role === 'teacher') {
                $employeeId = $validation['metadata']['employee_id'] ?? trim($_POST['employee_id'] ?? '');
                $department = $validation['metadata']['department'] ?? trim($_POST['department'] ?? '');
                $emergName = trim($_POST['teacher_emergency_contact_name'] ?? '');
                $emergNum = trim($_POST['teacher_emergency_contact_number'] ?? '');
                $bloodType = trim($_POST['teacher_blood_type'] ?? '');
                $allergies = trim($_POST['teacher_allergies'] ?? '');

                $stmt = $db->prepare("
                    INSERT INTO teachers (user_id, employee_id, first_name, last_name, department, email, phone, emergency_contact_name, emergency_contact_number, blood_type, allergies)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $userId,
                    $employeeId,
                    $firstName,
                    $lastName,
                    $department ?: null,
                    $email,
                    $contact ?: null,
                    $emergName ?: null,
                    $emergNum ?: null,
                    $bloodType ?: null,
                    $allergies ?: null
                ]);

                // Store requested advisory sections (up to 3) as inactive until admin approval
                $requestedSections = $_POST['teacher_sections'] ?? [];
                if (is_array($requestedSections)) {
                    $requestedSections = array_slice(array_map('intval', $requestedSections), 0, 3);
                    $secStmt = $db->prepare("
                        INSERT INTO advisory_assignments (teacher_id, section_id, school_year, status, self_managed)
                        VALUES (?, ?, '2026-2027', 'inactive', 1)
                    ");
                    foreach ($requestedSections as $secId) {
                        if ($secId > 0) {
                            $secStmt->execute([$userId, $secId]);
                        }
                    }
                }

                log_activity($userId, 'Initiated teacher registration (pending email OTP for basic self-service access)', 'auth');

            } elseif ($role === 'nurse') {
                $employeeId    = $validation['metadata']['employee_id'] ?? trim($_POST['employee_id'] ?? '');
                $licenseNumber = $validation['metadata']['license_number'] ?? trim($_POST['license_number'] ?? '');
                $position = trim($_POST['nurse_position'] ?? '');
                $emergName = trim($_POST['nurse_emergency_contact_name'] ?? '');
                $emergNum = trim($_POST['nurse_emergency_contact_number'] ?? '');

                $stmt = $db->prepare("
                    INSERT INTO nurses (user_id, employee_id, license_number, position, emergency_contact_name, emergency_contact_number, gender, date_of_birth, contact_number, valid_id_path, profile_picture)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $userId,
                    $employeeId ?: null,
                    $licenseNumber ?: null,
                    $position ?: null,
                    $emergName ?: null,
                    $emergNum ?: null,
                    $gender ?: null,
                    $dob ?: null,
                    $contact ?: null,
                    $validIdRel,
                    $profilePicRel
                ]);

                log_activity($userId, 'Initiated nurse registration (pending OTP verification)', 'auth');

            } elseif ($role === 'admin') {
                log_activity($userId, 'Initiated administrator registration (pending OTP verification)', 'auth', 'Administrator: ' . $username);
            }

            // Account decision is 'review'; MICA Hub scan reconciles pending_review
            // alerts against live AccountMonitoringService counts on the next scan.

            // Dispatch One-Time Password to authorized email
            $fullName = trim($firstName . ' ' . $lastName);
            // Email is dispatched only after the account transaction commits.

            $db->commit();
            $_SESSION['pending_verification_user_id'] = $userId;
            try{$delivered=send_registration_otp_email($email,$otpCode,$fullName,$role);}
            catch(Throwable $e){$delivered=false;error_log('Registration email transport failed after account commit.');}
            if(!$delivered)set_flash('warning',"We couldn't send your verification email. Your account was saved; please try resending the OTP.");
            registration_next($registrationAjax);

        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('Registration transaction failed; code='.(string)$e->getCode().'; driver='.(string)($e instanceof PDOException?($e->errorInfo[1]??'unknown'):'none'));
            $registrationStatus=$e instanceof PDOException&&(int)($e->errorInfo[1]??0)===1062?409:503;
            $registrationErrorCode=$registrationStatus===409?'duplicate_account':'database_error';
            $errors[]=$registrationStatus===409?'An account with these details already exists. Sign in or choose another username and email.':'Registration could not be completed. Please try again later.';
        }
    }
}
if($errors){http_response_code($registrationStatus);if($registrationAjax)json_response(false,['error_code'=>$registrationErrorCode,'field_errors'=>$fieldErrors,'errors'=>$errors],$fieldErrors?'Please correct the highlighted registration fields.':implode(' ',$errors));}

$pageTitle = 'Create an Account';
?>
<!DOCTYPE html>
<html lang="en" class="registration-page">
<head>
<?php $loadRegistrationStyles = true; ?>
<?php include __DIR__ . '/../includes/head.php'; ?>
</head>
<body class="registration-page">
<div class="auth-wrapper auth-wrapper--register">
  <div class="auth-card auth-card--register" data-aos="fade-up" data-aos-duration="700">
    <!-- Left Branded Sidebar -->
    <div class="auth-side" id="authSideBanner">
      <div class="auth-side__brand">
        <div class="auth-icon">
          <img class="auth-logo" src="<?= APP_URL ?>/assets/images/bpc_logo.png" alt="BPC Clinic Logo">
        </div>
        <div class="auth-side__brand-copy">
          <div class="auth-side__brand-name">BPC Clinic System</div>
          <div class="auth-side__brand-tag">Healthy Students, Brighter Future</div>
        </div>
      </div>

      <h1 class="auth-side__title" id="sideTitle">Create Your Account</h1>
      <p class="auth-side__subtitle" id="sideDesc">Join the BPC Clinic System and be part of a healthier and safer campus community. Register now to access clinic services, view your records, and more.</p>

      <div class="auth-side__feature-list">
        <div class="auth-side__feature-item">
          <span class="auth-side__feature-icon"><i class="bi bi-shield-check"></i></span>
          <span>Quality Healthcare</span>
        </div>
        <div class="auth-side__feature-item">
          <span class="auth-side__feature-icon"><i class="bi bi-heart-pulse"></i></span>
          <span>Safe Environment</span>
        </div>
        <div class="auth-side__feature-item">
          <span class="auth-side__feature-icon"><i class="bi bi-people"></i></span>
          <span>Trusted by BPC</span>
        </div>
      </div>
    </div>

    <!-- Right Form Section -->
    <div class="auth-form-side">
      <!-- Mobile Header (Visible on tablet & mobile) -->
      <div class="d-flex align-items-center gap-2.5 mb-3 pb-2.5 border-bottom d-lg-none">
        <img src="<?= APP_URL ?>/assets/images/bpc_logo.png" alt="BPC Logo" width="38" height="38">
        <div>
          <div class="fw-bold text-dark lh-sm" style="font-size:0.95rem;">Bulacan Polytechnic College</div>
          <div class="text-muted" style="font-size:0.75rem;">School Clinic Management System</div>
        </div>
      </div>

      <!-- Page Title & Subtitle -->
      <div class="auth-form-header">
        <div>
          <h4 class="mb-1 fw-bold text-dark" id="formHeaderTitle">Student Registration</h4>
          <p class="text-muted mb-0 small" id="formHeaderSub">Please complete all required fields below to create your account.</p>
        </div>
        <a href="login.php" class="btn btn-light btn-sm auth-login-link">
          Already have an account? <span>Login</span>
        </a>
      </div>
      <div class="reg-progress" role="progressbar" aria-label="Registration form completion" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"><span id="regProgressFill"></span></div>

      <?php if ($errors): ?>
        <div class="alert alert-danger py-2.5 px-3 small d-flex align-items-start gap-2 mb-3 rounded-3" role="alert">
          <i class="bi bi-exclamation-triangle-fill fs-6 mt-0.5 text-danger flex-shrink-0"></i>
          <div><?= implode('<br>',array_map('e',array_values(array_diff($errors,array_values($fieldErrors))))) ?: 'Please correct the highlighted registration fields.' ?></div>
        </div>
      <?php endif; ?>

      <form method="POST" novalidate enctype="multipart/form-data" id="unifiedRegForm">
        <?= csrf_field() ?>

        <!-- Role Selector Group -->
        <div class="mb-3">
          <label class="form-label d-block mb-2">
            <i class="bi bi-person-badge me-1 text-primary"></i> Select Account Type:
          </label>
          <div class="reg-role-selector" id="roleSelectorGroup" role="radiogroup" aria-label="Account Type">
            <button type="button" class="reg-role-btn <?= $selectedRole === 'student' ? 'is-active' : '' ?>" data-role="student" role="radio" aria-checked="<?= $selectedRole === 'student' ? 'true' : 'false' ?>">
              <span class="reg-role-btn__icon"><i class="bi bi-mortarboard-fill"></i></span>
              <span class="reg-role-btn__text"><strong>Student</strong><small>For BPC students</small></span>
            </button>
            <button type="button" class="reg-role-btn <?= $selectedRole === 'teacher' ? 'is-active' : '' ?>" data-role="teacher" role="radio" aria-checked="<?= $selectedRole === 'teacher' ? 'true' : 'false' ?>">
              <span class="reg-role-btn__icon"><i class="bi bi-person-workspace"></i></span>
              <span class="reg-role-btn__text"><strong>Teacher / Adviser</strong><small>For faculty and advisers</small></span>
            </button>
            <button type="button" class="reg-role-btn <?= $selectedRole === 'nurse' ? 'is-active' : '' ?>" data-role="nurse" role="radio" aria-checked="<?= $selectedRole === 'nurse' ? 'true' : 'false' ?>">
              <span class="reg-role-btn__icon"><i class="bi bi-person-badge-fill"></i></span>
              <span class="reg-role-btn__text"><strong>Nurse</strong><small>Email OTP activates basic account</small></span>
            </button>
          </div>
          <input type="hidden" name="account_type" id="accountTypeInput" value="<?= e($selectedRole) ?>">
        </div>

        <!-- Section 1: Personal Information -->
        <div class="reg-section">
          <div class="reg-section-header mt-0">
            <div class="reg-section-header__icon"><i class="bi bi-person-vcard"></i></div>
            <h6 class="reg-section-header__title">Personal Information</h6>
            <span class="reg-section-header__sub">Basic identifying details</span>
          </div>

          <div class="row g-2.5 mb-2">
            <div class="col-12 col-sm-6 col-xl-4">
              <label class="form-label" for="firstNameInput">First Name <span class="req-star">*</span></label>
              <input type="text" name="first_name" id="firstNameInput" class="form-control" required maxlength="80" autocomplete="given-name" value="<?= e($old['first_name'] ?? '') ?>" placeholder="e.g. Juan">
            </div>
            <div class="col-12 col-sm-6 col-xl-4">
              <label class="form-label" for="middleNameInput">Middle Name</label>
              <input type="text" name="middle_name" id="middleNameInput" class="form-control" maxlength="80" autocomplete="additional-name" value="<?= e($old['middle_name'] ?? '') ?>" placeholder="Optional">
            </div>
            <div class="col-12 col-sm-6 col-xl-4">
              <label class="form-label" for="lastNameInput">Last Name <span class="req-star">*</span></label>
              <input type="text" name="last_name" id="lastNameInput" class="form-control" required maxlength="80" autocomplete="family-name" value="<?= e($old['last_name'] ?? '') ?>" placeholder="e.g. Dela Cruz">
            </div>

            <div class="col-12 col-sm-6 col-xl-4 role-field field-gender field-student field-nurse field-teacher">
              <label class="form-label" for="genderInput" id="genderLabel">Gender</label>
              <select name="gender" id="genderInput" class="form-select" autocomplete="sex">
                <option value="">Select Gender</option>
                <?php foreach (['Male','Female','Other'] as $g): ?>
                  <option value="<?= $g ?>" <?= (($old['gender'] ?? '') === $g) ? 'selected' : '' ?>><?= $g ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-12 col-sm-6 col-xl-4 role-field field-dob field-student field-nurse field-teacher">
              <label class="form-label" for="dobInput" id="dobLabel">Date of Birth</label>
              <input type="date" name="date_of_birth" id="dobInput" class="form-control" max="<?= date('Y-m-d') ?>" autocomplete="bday" value="<?= e($old['date_of_birth'] ?? '') ?>">
            </div>
            <div class="col-12 col-sm-6 col-xl-4 role-field field-contact field-student field-teacher field-nurse field-admin">
              <label class="form-label" for="contactInput" id="contactLabel">Contact Number <span class="req-star">*</span></label>
              <div class="input-group">
                <span class="input-group-text"><i class="bi bi-telephone"></i></span>
                <input type="tel" name="contact_number" id="contactInput" class="form-control" maxlength="30" autocomplete="tel" placeholder="09171234567" value="<?= e($old['contact_number'] ?? '') ?>">
              </div>
            </div>
          </div>
        </div>

        <!-- Section 2A: Student Details -->
        <div class="role-section section-student">
          <div class="reg-section-header">
            <div class="reg-section-header__icon"><i class="bi bi-mortarboard-fill"></i></div>
            <h6 class="reg-section-header__title">Academic &amp; Emergency Details</h6>
            <span class="reg-section-header__sub">Student profile</span>
          </div>

          <div class="row g-2.5 mb-2">
            <div class="col-12 col-sm-6">
              <label class="form-label" for="studentNumberInput">Student Number <span class="req-star">*</span></label>
              <div class="input-group">
                <span class="input-group-text"><i class="bi bi-hash"></i></span>
                <input type="text" name="student_number" id="studentNumberInput" class="form-control" placeholder="e.g. 2024-00123" value="<?= e($old['student_number'] ?? '') ?>">
              </div>
            </div>
            <div class="col-12 col-sm-6">
              <label class="form-label" for="studentCourseInput">Course / Program <span class="req-star">*</span></label>
              <select name="course" id="studentCourseInput" class="form-select">
                <option value="">Select Course</option>
                <option value="BSIS" <?= (($old['course'] ?? '') === 'BSIS') ? 'selected' : '' ?>>BSIS (Information Systems)</option>
                <option value="BSOM" <?= (($old['course'] ?? '') === 'BSOM') ? 'selected' : '' ?>>BSOM (Office Management)</option>
                <option value="ACT" <?= (($old['course'] ?? '') === 'ACT') ? 'selected' : '' ?>>ACT (Computer Technology)</option>
                <option value="BSAIS" <?= (($old['course'] ?? '') === 'BSAIS') ? 'selected' : '' ?>>BSAIS (Accounting Info Systems)</option>
              </select>
            </div>
            <div class="col-12 col-sm-6">
              <label class="form-label" for="studentYearInput">Year Level <span class="req-star">*</span></label>
              <select name="year_level" id="studentYearInput" class="form-select">
                <option value="">Select Year Level</option>
                <?php foreach (['1st Year','2nd Year','3rd Year','4th Year'] as $y): ?>
                  <option value="<?= $y ?>" <?= (($old['year_level'] ?? '') === $y) ? 'selected' : '' ?>><?= $y ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-12 col-sm-6">
              <label class="form-label" for="studentSectionInput">Section <span class="req-star">*</span></label>
              <select name="section" id="studentSectionInput" class="form-select">
                <option value="">Select Section</option>
                <?php foreach (['A','B','C','D'] as $secL): ?>
                  <option value="<?= $secL ?>" <?= (($old['section'] ?? '') === $secL) ? 'selected' : '' ?>>Section <?= $secL ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-12 col-sm-6">
              <label class="form-label" for="emergPersonInput">Emergency Contact Person</label>
              <div class="input-group">
                <span class="input-group-text"><i class="bi bi-person-heart"></i></span>
                <input type="text" name="emergency_contact_name" id="emergPersonInput" class="form-control" placeholder="Parent / Guardian full name" value="<?= e($old['emergency_contact_name'] ?? '') ?>">
              </div>
            </div>
            <div class="col-12 col-sm-6">
              <label class="form-label" for="emergNumberInput">Emergency Contact Number</label>
              <div class="input-group">
                <span class="input-group-text"><i class="bi bi-telephone-plus"></i></span>
                <input type="tel" name="emergency_contact_number" id="emergNumberInput" class="form-control" placeholder="e.g. 09171234567" value="<?= e($old['emergency_contact_number'] ?? '') ?>">
              </div>
            </div>
            <div class="col-12 col-sm-6">
              <label class="form-label" for="bloodTypeInput">Blood Type (Optional)</label>
              <div class="input-group">
                <span class="input-group-text"><i class="bi bi-droplet-half text-danger"></i></span>
                <select name="blood_type" id="bloodTypeInput" class="form-select">
                  <option value="">Select Blood Type</option>
                  <?php foreach (['A+','A-','B+','B-','AB+','AB-','O+','O-','Unknown'] as $bt): ?>
                    <option value="<?= $bt ?>" <?= (($old['blood_type'] ?? '') === $bt) ? 'selected' : '' ?>><?= $bt ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
            <div class="col-12 col-sm-6">
              <label class="form-label" for="allergiesInput">Known Allergies / Medical Notes</label>
              <div class="input-group">
                <span class="input-group-text"><i class="bi bi-bandaid"></i></span>
                <input type="text" name="allergies" id="allergiesInput" class="form-control" placeholder="e.g. Penicillin, Asthma, None" value="<?= e($old['allergies'] ?? '') ?>">
              </div>
            </div>
          </div>
        </div>

        <!-- Section 2B: Teacher Details -->
        <div class="role-section section-teacher" style="display:none;">
          <div class="reg-section-header">
            <div class="reg-section-header__icon"><i class="bi bi-person-workspace"></i></div>
            <h6 class="reg-section-header__title">Teacher Credentials &amp; Advisory Assignment</h6>
            <span class="reg-section-header__sub">Faculty record</span>
          </div>

          <div class="row g-2.5 mb-2">
            <div class="col-12 col-sm-6">
              <label class="form-label" for="teacherEmployeeIdInput">Teacher Employee ID <span class="req-star">*</span></label>
              <div class="input-group">
                <span class="input-group-text"><i class="bi bi-card-text"></i></span>
                <input type="text" name="employee_id" id="teacherEmployeeIdInput" class="form-control" maxlength="50" placeholder="e.g. TCH-2026-001" value="<?= e($old['employee_id'] ?? '') ?>">
              </div>
            </div>
            <div class="col-12 col-sm-6">
              <label class="form-label" for="teacherDepartmentInput">Department / Faculty</label>
              <div class="input-group">
                <span class="input-group-text"><i class="bi bi-building"></i></span>
                <input type="text" name="department" id="teacherDepartmentInput" class="form-control" maxlength="100" placeholder="e.g. College of Computer Studies" value="<?= e($old['department'] ?? '') ?>">
              </div>
            </div>
            <div class="col-12 col-sm-6">
              <label class="form-label" for="teacherEmergencyNameInput">Emergency Contact Person</label>
              <input type="text" name="teacher_emergency_contact_name" id="teacherEmergencyNameInput" class="form-control" maxlength="120" value="<?= e($old['teacher_emergency_contact_name'] ?? '') ?>" autocomplete="off">
            </div>
            <div class="col-12 col-sm-6">
              <label class="form-label" for="teacherEmergencyNumberInput">Emergency Contact Number</label>
              <input type="tel" name="teacher_emergency_contact_number" id="teacherEmergencyNumberInput" class="form-control" maxlength="11" inputmode="numeric" placeholder="09XXXXXXXXX" value="<?= e($old['teacher_emergency_contact_number'] ?? '') ?>">
            </div>
            <div class="col-12 col-sm-6">
              <label class="form-label" for="teacherBloodTypeInput">Blood Type</label>
              <select name="teacher_blood_type" id="teacherBloodTypeInput" class="form-select">
                <option value="">Select Blood Type</option>
                <?php foreach (['A+','A-','B+','B-','AB+','AB-','O+','O-','Unknown'] as $bt): ?>
                  <option value="<?= $bt ?>" <?= (($old['teacher_blood_type'] ?? '') === $bt) ? 'selected' : '' ?>><?= $bt ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-12 col-sm-6">
              <label class="form-label" for="teacherAllergiesInput">Allergies / Medical Notes</label>
              <input type="text" name="teacher_allergies" id="teacherAllergiesInput" class="form-control" maxlength="1000" value="<?= e($old['teacher_allergies'] ?? '') ?>" placeholder="None, or list known allergies">
            </div>
            <div class="col-12 mt-2">
              <div class="d-flex justify-content-between align-items-center mb-1.5">
                <label class="form-label mb-0"><i class="bi bi-diagram-3 me-1 text-primary"></i> Select Advisory Sections (Max 3)</label>
                <span class="badge bg-primary rounded-pill small" id="teacherSecCountBadge">0 / 3 selected</span>
              </div>
              <div class="border rounded-3 p-3 bg-light" style="max-height: 220px; overflow-y: auto;">
                <?php if (empty($sectionsByCourse)): ?>
                  <div class="text-muted small p-2 text-center">No active sections configured.</div>
                <?php else: ?>
                  <?php foreach ($sectionsByCourse as $courseName => $sList): ?>
                    <div class="fw-bold small text-dark border-bottom pb-1 mb-2 mt-1 d-flex align-items-center gap-1.5">
                      <i class="bi bi-mortarboard text-success"></i> <?= e($courseName) ?>
                    </div>
                    <div class="row g-2 mb-2.5">
                      <?php foreach ($sList as $sec): ?>
                        <div class="col-6 col-sm-4">
                          <label class="advisory-sec-card" for="sec_<?= (int)$sec['id'] ?>">
                            <input class="form-check-input teacher-sec-cb" type="checkbox" name="teacher_sections[]" value="<?= (int)$sec['id'] ?>" id="sec_<?= (int)$sec['id'] ?>">
                            <span class="small fw-semibold text-dark d-block"><?= e($sec['name']) ?></span>
                            <span class="text-muted d-block" style="font-size:0.72rem;"><?= e($sec['year_level'] ?? '') ?></span>
                          </label>
                        </div>
                      <?php endforeach; ?>
                    </div>
                  <?php endforeach; ?>
                <?php endif; ?>
              </div>
              <div class="form-text small text-muted mt-1">Select up to 3 sections where you are the official designated class adviser.</div>
            </div>
          </div>
          <div class="p-2.5 rounded-3 mb-2 small d-flex align-items-center gap-2" style="background:#EBF5FF; border:1px solid #BFDBFE; color:#1E40AF;">
            <i class="bi bi-info-circle-fill fs-6 flex-shrink-0"></i>
            <div>Email OTP activates your personal clinic account. Advisory access is separate and requires staff authorization.</div>
          </div>
        </div>

        <!-- Section 2C: Nurse Details -->
        <div class="role-section section-nurse" style="display:none;">
          <div class="reg-section-header">
            <div class="reg-section-header__icon"><i class="bi bi-hospital-fill"></i></div>
            <h6 class="reg-section-header__title">Nurse Credentials</h6>
            <span class="reg-section-header__sub">Medical staff</span>
          </div>

          <div class="row g-2.5 mb-2">
            <div class="col-12 col-sm-6">
              <label class="form-label" for="nurseEmployeeIdInput">Nurse / Employee ID <span class="req-star">*</span></label>
              <div class="input-group">
                <span class="input-group-text"><i class="bi bi-card-text"></i></span>
                <input type="text" name="employee_id" id="nurseEmployeeIdInput" class="form-control" maxlength="50" placeholder="e.g. NURSE-2026-001" value="<?= e($old['employee_id'] ?? '') ?>">
              </div>
            </div>
            <div class="col-12 col-sm-6">
              <label class="form-label" for="nurseLicenseInput">PRC License Number</label>
              <div class="input-group">
                <span class="input-group-text"><i class="bi bi-award"></i></span>
                <input type="text" name="license_number" id="nurseLicenseInput" class="form-control" placeholder="e.g. 0123456 (Optional)" value="<?= e($old['license_number'] ?? '') ?>">
              </div>
            </div>
            <div class="col-12 col-sm-6">
              <label class="form-label" for="nursePositionInput">Position</label>
              <input type="text" name="nurse_position" id="nursePositionInput" class="form-control" maxlength="120" placeholder="e.g. School Nurse" value="<?= e($old['nurse_position'] ?? '') ?>">
            </div>
            <div class="col-12 col-sm-6">
              <label class="form-label" for="nurseEmergencyNameInput">Emergency Contact Person</label>
              <input type="text" name="nurse_emergency_contact_name" id="nurseEmergencyNameInput" class="form-control" maxlength="120" value="<?= e($old['nurse_emergency_contact_name'] ?? '') ?>">
            </div>
            <div class="col-12 col-sm-6">
              <label class="form-label" for="nurseEmergencyNumberInput">Emergency Contact Number</label>
              <input type="tel" name="nurse_emergency_contact_number" id="nurseEmergencyNumberInput" class="form-control" maxlength="11" inputmode="numeric" placeholder="09XXXXXXXXX" value="<?= e($old['nurse_emergency_contact_number'] ?? '') ?>">
            </div>
          </div>
        </div>

        <!-- Section 2D: Administrator Info (Hidden from public) -->
        <div class="role-section section-admin" style="display:none;">
          <div class="reg-section-header">
            <div class="reg-section-header__icon"><i class="bi bi-shield-check"></i></div>
            <h6 class="reg-section-header__title">Administrative Office</h6>
          </div>
          <div class="row g-2.5 mb-2">
            <div class="col-12">
              <label class="form-label">Administrative Department / Unit</label>
              <input type="text" name="admin_office" class="form-control" placeholder="e.g. Clinic Administration, MIS Office" value="<?= e($old['admin_office'] ?? '') ?>">
            </div>
          </div>
        </div>

        <!-- Section 3: Account Credentials -->
        <div class="reg-section">
          <div class="reg-section-header">
            <div class="reg-section-header__icon"><i class="bi bi-shield-lock-fill"></i></div>
            <h6 class="reg-section-header__title">Account Credentials</h6>
            <span class="reg-section-header__sub">Sign-in credentials</span>
          </div>

          <div class="row g-2.5 mb-2">
            <div class="col-12 col-sm-6">
              <label class="form-label" for="emailInput">Institutional / Personal Email <span class="req-star">*</span></label>
              <div class="input-group">
                <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                <input type="email" name="email" id="emailInput" class="form-control" required autocomplete="email" placeholder="name@example.com" value="<?= e($old['email'] ?? '') ?>">
              </div>
                <?php if(isset($fieldErrors['email'])): ?><div role="alert" class="field-error" id="emailInputError"><?= e($fieldErrors['email']) ?></div><?php endif; ?>
              <div class="form-text small text-muted">A 6-digit OTP will be dispatched to this address.</div>
            </div>
            <div class="col-12 col-sm-6">
              <label class="form-label" for="usernameInput">Username <span class="req-star">*</span></label>
              <div class="input-group">
                <span class="input-group-text"><i class="bi bi-at"></i></span>
                <input type="text" name="username" id="usernameInput" class="form-control" required minlength="3" maxlength="50" pattern="[A-Za-z0-9_]+" autocomplete="username" placeholder="Choose a username" value="<?= e($old['username'] ?? '') ?>">
              </div>
                <?php if(isset($fieldErrors['username'])): ?><div role="alert" class="field-error" id="usernameInputError"><?= e($fieldErrors['username']) ?></div><?php endif; ?>
              <div class="form-text small text-muted">Letters, numbers, underscores only (3-50 chars).</div>
            </div>
            <div class="col-12 col-sm-6">
              <label class="form-label" for="regPw">Password <span class="req-star">*</span></label>
              <div class="input-group">
                <span class="input-group-text"><i class="bi bi-lock"></i></span>
                <input type="password" name="password" id="regPw" class="form-control border-end-0" required minlength="8" autocomplete="new-password" placeholder="Min. 8 characters" aria-label="Password">
                <button class="btn btn-outline-secondary px-3" type="button" id="regPwToggle" aria-label="Show password" aria-pressed="false">
                  <i class="bi bi-eye" id="regPwIcon"></i>
                </button>
              </div>
              <div class="pw-hint-text">
                <i class="bi bi-info-circle me-1 text-primary"></i>Min. 8 characters with uppercase, lowercase, number &amp; symbol.
              </div>
              <div class="pw-strength mt-2" role="progressbar" aria-label="Password strength" aria-valuemin="0" aria-valuemax="4" aria-valuenow="0"><span id="pwStrengthFill"></span></div>
              <div id="pwStrengthText" class="form-text" aria-live="polite">Password strength: not entered</div>
            </div>
            <div class="col-12 col-sm-6">
              <label class="form-label" for="regConfirmPw">Confirm Password <span class="req-star">*</span></label>
              <div class="input-group">
                <span class="input-group-text"><i class="bi bi-lock-fill"></i></span>
                <input type="password" name="confirm_password" id="regConfirmPw" class="form-control border-end-0" required minlength="8" autocomplete="new-password" placeholder="Re-enter password" aria-label="Confirm Password">
                <button class="btn btn-outline-secondary px-3" type="button" id="regConfirmPwToggle" aria-label="Show confirm password" aria-pressed="false">
                  <i class="bi bi-eye" id="regConfirmPwIcon"></i>
                </button>
              </div>
              <div class="pw-hint-text" id="pwMatchHint">
                Must match the password entered above.
              </div>
            </div>
          </div>
        </div>

        <!-- Section 4: Photo & Documents -->
        <div class="reg-section">
          <div class="reg-section-header">
            <div class="reg-section-header__icon"><i class="bi bi-card-image"></i></div>
            <h6 class="reg-section-header__title">Photo &amp; Document Verification</h6>
            <span class="reg-section-header__sub">Identity check</span>
          </div>

          <div class="row g-2.5 mb-2">
            <div class="col-12 col-sm-6">
              <label class="form-label" for="profilePicInput">Profile Picture</label>
              <div class="reg-file-box">
                <input type="file" name="profile_picture" id="profilePicInput" class="form-control form-control-sm" accept="image/jpeg,image/png,image/webp">
                <div class="form-text small text-muted mt-1"><i class="bi bi-image me-1"></i>JPG, PNG, or WebP photo (square recommended).</div>
              </div>
            </div>
            <div class="col-12 col-sm-6 role-field field-valid_id field-student field-nurse field-teacher">
              <label class="form-label" for="validIdInput" id="validIdLabel">Valid ID / School ID <span class="req-star">*</span></label>
              <div class="reg-file-box">
                <input type="file" name="valid_id" id="validIdInput" class="form-control form-control-sm" accept="image/jpeg,image/png,image/webp,application/pdf">
                <div class="form-text small text-muted mt-1"><i class="bi bi-file-earmark-arrow-up me-1"></i>Clear image or PDF copy of student/faculty/PRC ID.</div>
              </div>
            </div>
          </div>
        </div>

        <div class="reg-actions">
        <!-- Section 5: Certification -->
        <div class="reg-certify-box">
          <input class="form-check-input" type="checkbox" name="certify" id="certify" required aria-required="true">
          <label class="form-check-label" for="certify">
            I hereby certify that all information provided in this registration form is true, correct, and complete to the best of my knowledge, and I consent to the collection and processing of my health records by the Bulacan Polytechnic College School Clinic.
          </label>
        </div>

        <!-- Submit Button -->
        <button type="submit" class="btn btn-primary w-100 fw-semibold" id="submitBtn">
          <span class="btn-text">Create Student Account</span>
          <span class="spinner-border spinner-border-sm d-none ms-2" role="status" aria-hidden="true"></span>
        </button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
(function () {
  'use strict';

  // Role metadata definition with UNIFIED theme colors (standard primary BPC action)
  const roleMeta = {
    student: {
      title: 'Student Registration',
      sideTitle: 'Join BPC School Clinic',
      sideDesc: 'Register as a student to access clinic consultations, track your medical history, and get your clinic QR code.',
      btnText: 'Create Student Account',
      genderReq: false,
      dobReq: false,
      contactReq: false,
      studentNumReq: true,
      empIdReq: false,
      validIdReq: false,
      validIdLabel: 'Student ID / Valid ID (Optional)'
    },
    teacher: {
      title: 'Teacher Registration',
      sideTitle: 'Teacher / Adviser Portal',
      sideDesc: 'Verify your email to access your own clinic services. Advisory permissions require separate staff authorization.',
      btnText: 'Register Teacher Account',
      genderReq: false,
      dobReq: false,
      contactReq: true,
      studentNumReq: false,
      empIdReq: true,
      validIdReq: false,
      validIdLabel: 'Faculty ID / Valid ID'
    },
    nurse: {
      title: 'Nurse Registration',
      sideTitle: 'Clinic Nurse Station',
      sideDesc: 'Create your Nurse account with email OTP. Confidential clinical functions require separate trusted staff authorization.',
      btnText: 'Register Nurse Account',
      genderReq: true,
      dobReq: true,
      contactReq: true,
      studentNumReq: false,
      empIdReq: true,
      validIdReq: false,
      validIdLabel: 'PRC ID / Valid ID'
    }
  };

  const form = document.getElementById('unifiedRegForm');
  const accountTypeInput = document.getElementById('accountTypeInput');
  const roleBtns = document.querySelectorAll('#roleSelectorGroup .reg-role-btn');

  // Input references
  const genderInput = document.getElementById('genderInput');
  const dobInput = document.getElementById('dobInput');
  const contactInput = document.getElementById('contactInput');
  const studentNumInput = document.getElementById('studentNumberInput');
  const teacherEmpIdInput = document.getElementById('teacherEmployeeIdInput');
  const nurseEmpIdInput = document.getElementById('nurseEmployeeIdInput');
  const validIdInput = document.getElementById('validIdInput');
  const submitBtn = document.getElementById('submitBtn');
  const btnText = submitBtn.querySelector('.btn-text');

  function switchRole(roleKey) {
    if (!roleMeta[roleKey]) roleKey = 'student';

    const meta = roleMeta[roleKey];
    accountTypeInput.value = roleKey;

    // Update role buttons UI
    roleBtns.forEach(btn => {
      const isActive = btn.dataset.role === roleKey;
      btn.classList.toggle('is-active', isActive);
      btn.setAttribute('aria-checked', isActive ? 'true' : 'false');
    });

    // Update Header and Side text
    const titleEl = document.getElementById('formHeaderTitle');
    const sideTitleEl = document.getElementById('sideTitle');
    const sideDescEl = document.getElementById('sideDesc');

    if (titleEl) titleEl.textContent = meta.title;
    if (sideTitleEl) sideTitleEl.textContent = meta.sideTitle;
    if (sideDescEl) sideDescEl.textContent = meta.sideDesc;
    if (btnText) btnText.textContent = meta.btnText;

    // Toggle Role Sections
    const secStudent = document.querySelector('.section-student');
    const secTeacher = document.querySelector('.section-teacher');
    const secNurse   = document.querySelector('.section-nurse');
    const secAdmin   = document.querySelector('.section-admin');

    if (secStudent) secStudent.style.display = (roleKey === 'student') ? 'block' : 'none';
    if (secTeacher) secTeacher.style.display = (roleKey === 'teacher') ? 'block' : 'none';
    if (secNurse)   secNurse.style.display   = (roleKey === 'nurse') ? 'block' : 'none';
    if (secAdmin)   secAdmin.style.display   = (roleKey === 'admin') ? 'block' : 'none';
    document.querySelectorAll('.role-section').forEach(section => {
      const active = section.classList.contains('section-' + roleKey);
      section.querySelectorAll('input, select, textarea, button').forEach(control => { control.disabled = !active; });
    });

    // Toggle Role Fields
    document.querySelectorAll('.role-field').forEach(el => {
      if (el.classList.contains('field-' + roleKey)) {
        el.style.display = 'block';
      } else {
        el.style.display = 'none';
      }
    });

    // Required Attributes Mapping
    if (genderInput) genderInput.required = meta.genderReq;
    if (dobInput) dobInput.required = meta.dobReq;
    if (contactInput) contactInput.required = meta.contactReq;
    if (studentNumInput) studentNumInput.required = meta.studentNumReq;
    ['studentCourseInput','studentYearInput','studentSectionInput'].forEach(id => {
      const input = document.getElementById(id);
      if (input) input.required = roleKey === 'student';
    });
    if (teacherEmpIdInput) teacherEmpIdInput.required = (roleKey === 'teacher');
    if (nurseEmpIdInput) nurseEmpIdInput.required = (roleKey === 'nurse');
    if (validIdInput) validIdInput.required = meta.validIdReq;

    const vLabel = document.getElementById('validIdLabel');
    if (vLabel && meta.validIdLabel) {
      vLabel.innerHTML = meta.validIdReq ? 'Valid ID / School ID <span class="req-star">*</span>' : 'Valid ID / School ID';
    }
  }

  // Bind click handlers to role buttons
  roleBtns.forEach(btn => {
    btn.addEventListener('click', function () {
      switchRole(this.dataset.role);
      updateProgress();
    });
    btn.addEventListener('keydown', function (event) {
      if (!['ArrowLeft', 'ArrowRight'].includes(event.key)) return;
      event.preventDefault();
      const list = Array.from(roleBtns);
      const current = list.indexOf(this);
      const offset = event.key === 'ArrowRight' ? 1 : -1;
      const next = list[(current + offset + list.length) % list.length];
      next.focus();
      switchRole(next.dataset.role);
      updateProgress();
    });
  });

  // Password Visibility Toggles
  function setupPwToggle(inputEl, toggleBtn, iconEl) {
    if (!inputEl || !toggleBtn || !iconEl) return;
    toggleBtn.addEventListener('click', function () {
      const isPw = inputEl.type === 'password';
      inputEl.type = isPw ? 'text' : 'password';
      iconEl.classList.toggle('bi-eye', !isPw);
      iconEl.classList.toggle('bi-eye-slash', isPw);
      toggleBtn.setAttribute('aria-pressed', isPw ? 'true' : 'false');
      const label=inputEl.name==='confirm_password'?'confirm password':'password';
      toggleBtn.setAttribute('aria-label', (isPw?'Hide ':'Show ')+label);
      inputEl.focus();
    });
  }

  setupPwToggle(
    document.getElementById('regPw'),
    document.getElementById('regPwToggle'),
    document.getElementById('regPwIcon')
  );

  setupPwToggle(
    document.getElementById('regConfirmPw'),
    document.getElementById('regConfirmPwToggle'),
    document.getElementById('regConfirmPwIcon')
  );

  // Live password matching indicator
  const pwInput = document.getElementById('regPw');
  const confirmPwInput = document.getElementById('regConfirmPw');
  const matchHint = document.getElementById('pwMatchHint');

  function checkPwMatch() {
    if (!pwInput.value || !confirmPwInput.value) {
      if (matchHint) {
        matchHint.innerHTML = 'Must match the password entered above.';
        matchHint.className = 'pw-hint-text';
      }
      return;
    }
    if (pwInput.value === confirmPwInput.value) {
      if (matchHint) {
        matchHint.innerHTML = '<span class="text-success"><i class="bi bi-check-circle-fill me-1"></i>Passwords match</span>';
      }
    } else {
      if (matchHint) {
        matchHint.innerHTML = '<span class="text-danger"><i class="bi bi-x-circle-fill me-1"></i>Passwords do not match</span>';
      }
    }
  }

  if (pwInput && confirmPwInput) {
    pwInput.addEventListener('input', checkPwMatch);
    confirmPwInput.addEventListener('input', checkPwMatch);
  }

  // Teacher advisory section checkboxes counter and max 3 limit
  const teacherSecCbs = document.querySelectorAll('.teacher-sec-cb');
  const teacherSecCountBadge = document.getElementById('teacherSecCountBadge');

  function updateSecBadge() {
    const checked = document.querySelectorAll('.teacher-sec-cb:checked');
    if (teacherSecCountBadge) {
      teacherSecCountBadge.textContent = checked.length + ' / 3 selected';
      if (checked.length > 0) {
        teacherSecCountBadge.className = 'badge bg-success rounded-pill small';
      } else {
        teacherSecCountBadge.className = 'badge bg-primary rounded-pill small';
      }
    }
  }

  teacherSecCbs.forEach(cb => {
    cb.addEventListener('change', function () {
      const card = this.closest('.advisory-sec-card');
      if (card) card.classList.toggle('is-selected', this.checked);

      const checked = document.querySelectorAll('.teacher-sec-cb:checked');
      if (checked.length > 3) {
        this.checked = false;
        if (card) card.classList.remove('is-selected');
        if (typeof Swal !== 'undefined') {
          Swal.fire({
            icon: 'warning',
            title: 'Advisory Limit',
            text: 'You can select a maximum of 3 advisory sections.',
            confirmButtonColor: '#0E7A3E'
          });
        } else {
          alert('You can select a maximum of 3 advisory sections.');
        }
        return;
      }
      updateSecBadge();
    });
  });

  // Initial setup based on current value
  const initialRole = accountTypeInput ? (accountTypeInput.value || 'student') : 'student';
  switchRole(initialRole);
  updateSecBadge();

  // Registration Consent Certification Setup
  const certifyBox = document.querySelector('.reg-certify-box');
  const certifyInput = document.getElementById('certify');
  if (certifyBox && certifyInput) {
    if (certifyInput.checked) certifyBox.classList.add('is-checked');
    certifyBox.addEventListener('click', function (e) {
      if (e.target === certifyInput || e.target.closest('label[for="certify"]')) return;
      certifyInput.checked = !certifyInput.checked;
      certifyInput.dispatchEvent(new Event('change', { bubbles: true }));
    });
    certifyInput.addEventListener('change', function () {
      certifyBox.classList.toggle('is-checked', this.checked);
      if (this.checked) clearFieldError(this);
    });
  }

  // Helper alert dialog
  function showWarning(title, msg, focusTarget) {
    if (typeof Swal !== 'undefined') {
      Swal.fire({
        icon: 'warning',
        title: title,
        text: msg,
        confirmButtonColor: '#0E7A3E'
      }).then(() => {
        if (focusTarget) focusTarget.focus();
      });
    } else {
      alert(msg);
      if (focusTarget) focusTarget.focus();
    }
  }

  function validPassword(v) {
    return v.length >= 8 && /[A-Z]/.test(v) && /[a-z]/.test(v) && /[0-9]/.test(v) && /[^A-Za-z0-9]/.test(v);
  }

  const phMobile = /^09\d{9}$/;
  const mobileFields = ['contactInput','emergNumberInput','teacherEmergencyNumberInput','nurseEmergencyNumberInput'];
  const progressBar = document.querySelector('.reg-progress');
  const progressFill = document.getElementById('regProgressFill');
  const strengthFill = document.getElementById('pwStrengthFill');
  const strengthText = document.getElementById('pwStrengthText');

  function updateProgress() {
    const active = Array.from(form.querySelectorAll('input:not([type="hidden"]):not([type="file"]), select, textarea'))
      .filter(el => !el.disabled && el.offsetParent !== null);
    const complete = active.filter(el => el.type === 'checkbox' ? el.checked : el.value.trim() !== '').length;
    const pct = active.length ? Math.round(complete / active.length * 100) : 0;
    if (progressFill) progressFill.style.width = pct + '%';
    if (progressBar) progressBar.setAttribute('aria-valuenow', String(pct));
  }

  function updateStrength() {
    const value = pwInput.value;
    const score = value ? [value.length >= 8, /[A-Z]/.test(value), /[a-z]/.test(value), /[0-9]/.test(value) && /[^A-Za-z0-9]/.test(value)].filter(Boolean).length : 0;
    const labels = ['not entered','weak','fair','good','strong'];
    if (strengthFill) { strengthFill.style.width = (score * 25) + '%'; strengthFill.style.backgroundColor = ['#d1d5db','#dc2626','#d97706','#65a30d','#15803d'][score]; }
    const meter = strengthFill && strengthFill.parentElement;
    if (meter) meter.setAttribute('aria-valuenow', String(score));
    if (strengthText) strengthText.textContent = 'Password strength: ' + labels[score];
  }

  function clearFieldError(input) {
    input.removeAttribute('aria-invalid');
    const error = document.getElementById(input.id + 'Error');
    if (error) {
      error.remove();
      const describedBy = (input.getAttribute('aria-describedby') || '').split(/\s+/).filter(id => id && id !== error.id);
      if (describedBy.length) input.setAttribute('aria-describedby', describedBy.join(' '));
      else input.removeAttribute('aria-describedby');
    }
  }

  function setFieldError(input, message) {
    clearFieldError(input);
    input.setAttribute('aria-invalid', 'true');
    const error = document.createElement('div');
    error.id = input.id + 'Error';
    error.className = 'field-error';
    error.setAttribute('role', 'alert');
    error.textContent = message;
    const group = input.closest('.input-group');
    (group || input).insertAdjacentElement('afterend', error);
    const describedBy = (input.getAttribute('aria-describedby') || '').split(/\s+/).filter(Boolean);
    describedBy.push(error.id);
    input.setAttribute('aria-describedby', describedBy.join(' '));
  }

  form.querySelectorAll('input, select, textarea').forEach(input => {
    input.addEventListener('input', updateProgress);
    input.addEventListener('change', updateProgress);
    input.addEventListener('input', () => { if (input.getAttribute('aria-invalid') === 'true' || document.getElementById(input.id + 'Error')) clearFieldError(input); });
  });
  if (pwInput) pwInput.addEventListener('input', updateStrength);

  form.addEventListener('submit', function (e) {
    let firstInvalid = null;
    const usernameInput = document.getElementById('usernameInput');
    usernameInput.value = usernameInput.value.trim();
    form.querySelectorAll('input, select, textarea').forEach(clearFieldError);
    const visibleRequired = Array.from(form.querySelectorAll('[required]')).filter(input => input.offsetParent !== null && !input.disabled);
    visibleRequired.forEach(input => {
      let message = '';
      if (input.type === 'checkbox' && !input.checked) message = 'Please check this box to continue.';
      else if (!input.value.trim()) message = 'This field is required.';
      else if (input.type === 'email' && !input.validity.valid) message = 'Enter a valid email address.';
      else if (input.type === 'file' && !input.files.length) message = 'Please choose a file.';
      if (message) { setFieldError(input, message); if (!firstInvalid) firstInvalid = input; }
    });
    if (!/^[A-Za-z0-9_]{3,50}$/.test(usernameInput.value)) {
      setFieldError(usernameInput, 'Username must be 3-50 characters containing only letters, numbers, and underscores.');
      if (!firstInvalid) firstInvalid = usernameInput;
    }
    mobileFields.forEach(id => {
      const input = document.getElementById(id);
      if (!input || input.offsetParent === null || !input.value.trim()) return;
      if (!phMobile.test(input.value.trim())) {
        setFieldError(input, 'Use a Philippine mobile number in this format: 09XXXXXXXXX.');
        if (!firstInvalid) firstInvalid = input;
      }
    });
    if (firstInvalid) {
      e.preventDefault();
      firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
      firstInvalid.focus({ preventScroll: true });
      return false;
    }
    if (!validPassword(pwInput.value)) {
      e.preventDefault();
      setFieldError(pwInput, 'Use at least 8 characters with uppercase, lowercase, number, and symbol.');
      pwInput.scrollIntoView({ behavior: 'smooth', block: 'center' }); pwInput.focus({ preventScroll: true });
      return false;
    }
    if (pwInput.value !== confirmPwInput.value) {
      e.preventDefault();
      setFieldError(confirmPwInput, 'Passwords do not match.');
      confirmPwInput.scrollIntoView({ behavior: 'smooth', block: 'center' }); confirmPwInput.focus({ preventScroll: true });
      return false;
    }

    if (accountTypeInput.value === 'student') {
      const course = document.getElementById('studentCourseInput');
      const year = document.getElementById('studentYearInput');
      const sec = document.getElementById('studentSectionInput');
      if (course && !course.value) {
        e.preventDefault();
        showWarning('Course Required', 'Please select your course.', course);
        return false;
      }
      if (year && !year.value) {
        e.preventDefault();
        showWarning('Year Level Required', 'Please select your year level.', year);
        return false;
      }
      if (sec && !sec.value) {
        e.preventDefault();
        showWarning('Section Required', 'Please select your section.', sec);
        return false;
      }
    }

    // Double submission prevention & loading state
    submitBtn.disabled = true;
    if (btnText) btnText.textContent = 'Creating Account...';
    const spinner = submitBtn.querySelector('.spinner-border');
    if (spinner) spinner.classList.remove('d-none');
  });

  updateProgress();
  updateStrength();

})();
</script>
<?php include __DIR__ . '/../components/footer.php'; ?>
</body>
</html>
