<?php
/**
 * auth/register.php
 * ------------------------------------------------------------------
 * Unified Self-Registration System for BPC School Clinic.
 * Supports public self-registration for:
 *   1. Student
 *   2. Nurse
 *   3. Teacher / Adviser
 *   4. Guidance Counselor
 *
 * Admin role is STRICTLY prohibited from public self-registration.
 * Server-side validation enforces allowed public roles only.
 * ------------------------------------------------------------------
 */
require_once __DIR__ . '/../config/config.php';

// Prevent caching of authentication pages
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');

if (is_logged_in()) {
    redirect(dashboard_url_for(current_user()['role']));
}

$db = getDB();
$errors = [];
$old = $_POST ?? [];

// Determine default role selection from GET/POST
$rawRoleReq = trim($_REQUEST['role'] ?? $_REQUEST['type'] ?? $_REQUEST['account_type'] ?? 'student');
$roleNormMap = [
    'student'            => 'student',
    'nurse'              => 'nurse',
    'teacher'            => 'adviser',
    'adviser'            => 'adviser',
    'guidance'           => 'guidance',
    'guidance_counselor' => 'guidance',
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

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_die();

    $rawRole = trim($_POST['account_type'] ?? $_POST['role'] ?? 'student');

    // STRICT SERVER-SIDE ROLE VALIDATION
    // Never trust client role input for admin or privileged role creation.
    if (!isset($roleNormMap[$rawRole]) || $rawRole === 'admin') {
        $errors[] = 'Invalid or unauthorized account type selected.';
        $role = 'student';
    } else {
        $role = $roleNormMap[$rawRole]; // Internal role name: student, nurse, adviser, guidance
    }

    $selectedRole = $role;

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
    $certify     = isset($_POST['certify']);

    // Common validations
    if (!$firstName || !$lastName || !$email || !$username || !$password) {
        $errors[] = 'Please fill in all required fields.';
    }
    if ($email && !validate_email($email)) {
        $errors[] = 'Please enter a valid email address.';
    }
    if ($username && !validate_username($username)) {
        $errors[] = 'Username must be 3-50 characters (letters, numbers, underscores only).';
    }
    foreach (validate_password_strength($password) as $pwErr) {
        $errors[] = $pwErr;
    }
    if ($password !== $confirm) {
        $errors[] = 'Passwords do not match.';
    }
    if (!$certify) {
        $errors[] = 'You must certify that the information above is true.';
    }

    // Role-specific field extractions & validations
    if ($role === 'student') {
        $studentNo = trim($_POST['student_number'] ?? '');
        $course    = trim($_POST['course'] ?? '');
        $yearLevel = trim($_POST['year_level'] ?? '');
        $section   = trim($_POST['section'] ?? '');

        if (!$studentNo) {
            $errors[] = 'Student Number is required.';
        }
        if ($dob !== '') {
            if (!validate_date($dob)) {
                $errors[] = 'Please enter a valid birthdate (YYYY-MM-DD).';
            } elseif ($dob > date('Y-m-d')) {
                $errors[] = 'Birthdate cannot be a future date.';
            }
        }
        if (!$errors && $studentNo !== '') {
            $stmt = $db->prepare("SELECT id FROM students WHERE student_number = ? LIMIT 1");
            $stmt->execute([$studentNo]);
            if ($stmt->fetch()) {
                $errors[] = 'Student number is already registered.';
            }
        }
    } elseif ($role === 'nurse') {
        $employeeId    = trim($_POST['employee_id'] ?? '');
        $licenseNumber = trim($_POST['license_number'] ?? '');

        if (!$gender) {
            $errors[] = 'Gender is required for Nurse registration.';
        }
        if (!$dob) {
            $errors[] = 'Date of Birth is required for Nurse registration.';
        } elseif (!validate_date($dob)) {
            $errors[] = 'Please enter a valid birthdate (YYYY-MM-DD).';
        } elseif ($dob > date('Y-m-d')) {
            $errors[] = 'Birthdate cannot be a future date.';
        }
        if (!$contact) {
            $errors[] = 'Contact Number is required for Nurse registration.';
        }
    } elseif ($role === 'adviser' || $role === 'guidance') {
        $employeeId = trim($_POST['employee_id'] ?? '');
        $department = trim($_POST['department'] ?? '');

        if (!$employeeId) {
            $errors[] = 'Employee / Staff ID is required.';
        }
        if (!$department) {
            $errors[] = 'Department is required.';
        }

        if (!$errors && $employeeId !== '') {
            $stmt = $db->prepare("SELECT id FROM teachers WHERE employee_id = ? LIMIT 1");
            $stmt->execute([$employeeId]);
            if ($stmt->fetch()) {
                $errors[] = 'This Employee/Staff ID is already registered.';
            } else {
                $stmt = $db->prepare("SELECT id FROM teacher_registration_requests WHERE teacher_id_number = ? AND status IN ('pending','approved') LIMIT 1");
                $stmt->execute([$employeeId]);
                if ($stmt->fetch()) {
                    $errors[] = 'A registration using this Employee/Staff ID is already underway.';
                }
            }
        }

        if (!$errors) {
            $stmt = $db->prepare("
                SELECT r.id FROM teacher_registration_requests r
                JOIN users u ON u.id = r.user_id
                WHERE u.email = ? AND r.status = 'pending' LIMIT 1
            ");
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                $errors[] = 'You already have a pending registration. Please wait for admin review.';
            }
        }
    }

    // Common duplicate check across ALL user accounts
    if (!$errors) {
        $stmt = $db->prepare("SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1");
        $stmt->execute([$username, $email]);
        if ($stmt->fetch()) {
            $errors[] = 'Username or email is already registered.';
        }
    }

    if (!$errors) {
        try {
            $db->beginTransaction();
            $hash = password_hash($password, PASSWORD_DEFAULT);

            $profilePicRel = upload_registration_file('profile_picture', 'assets/images/profiles', 'user', ['image/jpeg','image/png','image/webp'])
                             ?: 'assets/images/default-avatar.png';
            $validIdRel = upload_registration_file('valid_id', 'assets/images/student_ids', 'valid_id', ['image/jpeg','image/png','image/webp','application/pdf']);

            $stmt = $db->prepare("
                INSERT INTO users (role, username, email, password_hash, first_name, middle_name, last_name, gender, date_of_birth, phone, profile_picture, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
            ");
            $stmt->execute([
                $role,
                $username,
                $email,
                $hash,
                $firstName,
                $middleName ?: null,
                $lastName,
                $gender ?: null,
                $dob ?: null,
                $contact ?: null,
                $profilePicRel
            ]);
            $userId = (int)$db->lastInsertId();

            if ($role === 'student') {
                $stmt = $db->prepare("
                    INSERT INTO students (user_id, student_number, course, year_level, section, valid_id_path)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$userId, $studentNo, $course ?: null, $yearLevel ?: null, $section ?: null, $validIdRel]);
                $studentId = (int)$db->lastInsertId();

                try {
                    ensure_student_qr($db, $studentId, $userId);
                } catch (Exception $qrE) {
                    error_log('QR generation on registration failed: ' . $qrE->getMessage());
                }

                log_activity($userId, 'Registered new student account', 'auth');
                set_flash('success', 'Student registration submitted! Your account is pending admin approval.');

            } elseif ($role === 'nurse') {
                $stmt = $db->prepare("
                    INSERT INTO nurses (user_id, employee_id, license_number, gender, date_of_birth, contact_number, valid_id_path, profile_picture)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $userId,
                    $employeeId ?: null,
                    $licenseNumber ?: null,
                    $gender ?: null,
                    $dob ?: null,
                    $contact ?: null,
                    $validIdRel,
                    $profilePicRel
                ]);

                log_activity($userId, 'Registered new nurse account', 'auth');
                set_flash('success', 'Nurse registration submitted! Your account is pending admin approval.');

            } elseif ($role === 'adviser') {
                $stmt = $db->prepare("
                    INSERT INTO teacher_registration_requests (user_id, requested_role, teacher_id_number, requested_sections, status)
                    VALUES (?, 'adviser', ?, NULL, 'pending')
                ");
                $stmt->execute([$userId, $employeeId ?: null]);

                log_activity($userId, 'Submitted teacher account request', 'auth');
                set_flash('success', 'Teacher account request submitted! You can log in after the administrator approves your account.');

            } elseif ($role === 'guidance') {
                $stmt = $db->prepare("
                    INSERT INTO teacher_registration_requests (user_id, requested_role, teacher_id_number, requested_sections, status)
                    VALUES (?, 'guidance', ?, NULL, 'pending')
                ");
                $stmt->execute([$userId, $employeeId ?: null]);

                log_activity($userId, 'Submitted guidance counselor account request', 'auth');
                set_flash('success', 'Guidance Counselor account request submitted! You can log in after the administrator approves your account.');
            }

            $db->commit();
            redirect('login.php');

        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('Registration error: ' . $e->getMessage());
            $errors[] = 'Something went wrong while creating your account. Please try again.';
        }
    }
}

$pageTitle = 'Create an Account';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php include __DIR__ . '/../includes/head.php'; ?>
<style>
  .role-btn {
    border-radius: 12px;
    font-size: 0.88rem;
    font-weight: 600;
    transition: all 0.2s ease;
    border: 2px solid var(--border, #d1d5db);
    background: #fff;
    color: var(--text-dark, #1f2937);
  }
  .role-btn:hover {
    border-color: var(--primary, #0b6b3a);
    color: var(--primary, #0b6b3a);
    background: var(--primary-light, #EAF7EF);
  }
  .role-btn.active {
    background: var(--primary, #0b6b3a) !important;
    border-color: var(--primary, #0b6b3a) !important;
    color: #ffffff !important;
    box-shadow: 0 4px 12px rgba(11, 107, 58, 0.25);
  }
  .role-btn i {
    font-size: 1.25rem;
  }
</style>
</head>
<body>
<div class="auth-wrapper">
  <div class="auth-card" style="max-width:1080px;" data-aos="fade-up" data-aos-duration="800">
    <div class="auth-side">
      <div class="auth-icon" id="sideIcon"><i class="bi bi-person-plus-fill"></i></div>
      <h2 class="mb-2" id="sideTitle">BPC School Clinic</h2>
      <p class="mb-0" id="sideDesc">Create your account to access clinic services, health records, and support.</p>
    </div>
    <div class="auth-form-side">
      <h4 class="mb-1" id="formHeaderTitle">CREATE AN ACCOUNT</h4>
      <p class="text-muted mb-3" id="formHeaderSub">All fields marked * are required</p>

      <?php if ($errors): ?>
        <div class="alert alert-danger py-2 small d-flex align-items-center gap-2 mb-3">
          <i class="bi bi-exclamation-triangle-fill fs-5"></i>
          <span><?= implode('<br>', array_map('e', $errors)) ?></span>
        </div>
      <?php endif; ?>

      <form method="POST" novalidate enctype="multipart/form-data" id="unifiedRegForm">
        <?= csrf_field() ?>

        <!-- Account Type Selector -->
        <div class="mb-3">
          <label class="form-label fw-bold small text-dark d-block mb-2">
            <i class="bi bi-person-badge me-1 text-primary"></i> Select Account Type:
          </label>
          <div class="row g-2" id="roleSelectorGroup">
            <div class="col-6 col-sm-3">
              <button type="button" class="btn role-btn w-100 py-2 text-center <?= $selectedRole === 'student' ? 'active' : '' ?>" data-role="student">
                <i class="bi bi-mortarboard-fill d-block mb-1"></i>
                <span>Student</span>
              </button>
            </div>
            <div class="col-6 col-sm-3">
              <button type="button" class="btn role-btn w-100 py-2 text-center <?= $selectedRole === 'nurse' ? 'active' : '' ?>" data-role="nurse">
                <i class="bi bi-person-badge-fill d-block mb-1"></i>
                <span>Nurse</span>
              </button>
            </div>
            <div class="col-6 col-sm-3">
              <button type="button" class="btn role-btn w-100 py-2 text-center <?= $selectedRole === 'adviser' ? 'active' : '' ?>" data-role="teacher">
                <i class="bi bi-person-video3 d-block mb-1"></i>
                <span>Teacher</span>
              </button>
            </div>
            <div class="col-6 col-sm-3">
              <button type="button" class="btn role-btn w-100 py-2 text-center <?= $selectedRole === 'guidance' ? 'active' : '' ?>" data-role="guidance_counselor">
                <i class="bi bi-heart-pulse-fill d-block mb-1"></i>
                <span>Counselor</span>
              </button>
            </div>
          </div>
          <input type="hidden" name="account_type" id="accountTypeInput" value="<?= e($selectedRole === 'adviser' ? 'teacher' : ($selectedRole === 'guidance' ? 'guidance_counselor' : $selectedRole)) ?>">
        </div>

        <!-- Section: Personal Information -->
        <h6 class="fw-semibold mb-2" style="font-size:0.85rem;color:var(--text-secondary)"><i class="bi bi-person-vcard me-1"></i> Personal Information</h6>
        <div class="row g-2 mb-2">
          <div class="col-md-4">
            <label class="form-label small fw-semibold">First Name *</label>
            <input type="text" name="first_name" class="form-control" required maxlength="80" value="<?= e($old['first_name'] ?? '') ?>">
          </div>
          <div class="col-md-4">
            <label class="form-label small fw-semibold">Middle Name</label>
            <input type="text" name="middle_name" class="form-control" maxlength="80" value="<?= e($old['middle_name'] ?? '') ?>">
          </div>
          <div class="col-md-4">
            <label class="form-label small fw-semibold">Last Name *</label>
            <input type="text" name="last_name" class="form-control" required maxlength="80" value="<?= e($old['last_name'] ?? '') ?>">
          </div>
          <div class="col-md-6 role-field field-gender field-student field-nurse">
            <label class="form-label small fw-semibold" id="genderLabel">Gender</label>
            <select name="gender" id="genderInput" class="form-select">
              <option value="">Select</option>
              <?php foreach (['Male','Female','Other'] as $g): ?>
                <option <?= (($old['gender'] ?? '') === $g) ? 'selected' : '' ?>><?= $g ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6 role-field field-dob field-student field-nurse">
            <label class="form-label small fw-semibold" id="dobLabel">Date of Birth</label>
            <input type="date" name="date_of_birth" id="dobInput" class="form-control" max="<?= date('Y-m-d') ?>" value="<?= e($old['date_of_birth'] ?? '') ?>">
          </div>
          <div class="col-md-6 role-field field-contact field-student field-nurse field-teacher field-guidance_counselor">
            <label class="form-label small fw-semibold" id="contactLabel">Contact Number</label>
            <input type="text" name="contact_number" id="contactInput" class="form-control" maxlength="30" value="<?= e($old['contact_number'] ?? '') ?>">
          </div>
        </div>

        <!-- Section: Student Details (Student Only) -->
        <div class="role-section section-student">
          <h6 class="fw-semibold mb-2" style="font-size:0.85rem;color:var(--text-secondary);margin-top:16px;"><i class="bi bi-mortarboard-fill me-1"></i> School Information</h6>
          <div class="row g-2 mb-2">
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Student Number *</label>
              <input type="text" name="student_number" id="studentNumberInput" class="form-control" placeholder="e.g. 2024-00123" value="<?= e($old['student_number'] ?? '') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Course</label>
              <input type="text" name="course" class="form-control" placeholder="e.g. BSIT" value="<?= e($old['course'] ?? '') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Year Level</label>
              <select name="year_level" class="form-select">
                <option value="">Select</option>
                <?php foreach (['1st Year','2nd Year','3rd Year','4th Year'] as $y): ?>
                  <option <?= (($old['year_level'] ?? '') === $y) ? 'selected' : '' ?>><?= $y ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Section</label>
              <input type="text" name="section" class="form-control" placeholder="e.g. A" value="<?= e($old['section'] ?? '') ?>">
            </div>
          </div>
        </div>

        <!-- Section: Professional / Employment Info (Nurse, Teacher, Guidance) -->
        <div class="role-section section-staff">
          <h6 class="fw-semibold mb-2" style="font-size:0.85rem;color:var(--text-secondary);margin-top:16px;"><i class="bi bi-briefcase-fill me-1"></i> Employment Information</h6>
          <div class="row g-2 mb-2">
            <div class="col-md-6 role-field field-employee_id field-nurse field-teacher field-guidance_counselor">
              <label class="form-label small fw-semibold" id="employeeIdLabel">Nurse / Employee ID *</label>
              <input type="text" name="employee_id" id="employeeIdInput" class="form-control" maxlength="50" value="<?= e($old['employee_id'] ?? '') ?>">
            </div>
            <div class="col-md-6 role-field field-license field-nurse">
              <label class="form-label small fw-semibold">PRC License Number</label>
              <input type="text" name="license_number" class="form-control" placeholder="Optional" value="<?= e($old['license_number'] ?? '') ?>">
            </div>
            <div class="col-md-6 role-field field-department field-teacher field-guidance_counselor">
              <label class="form-label small fw-semibold" id="deptLabel">Department *</label>
              <input type="text" name="department" id="deptInput" class="form-control" maxlength="120" value="<?= e($old['department'] ?? '') ?>">
            </div>
          </div>

          <div class="card p-2 mb-2 small section-teacher-note" style="background:var(--primary-light,#EAF7EF);border:none;display:none;">
            <div class="fw-semibold mb-1"><i class="bi bi-info-circle me-1"></i> Advisory Class Assignment</div>
            <div class="text-muted">As a teacher, you will select your advisory class inside the Adviser Portal after your account is approved by the administrator.</div>
          </div>
        </div>

        <!-- Section: Account Credentials -->
        <h6 class="fw-semibold mb-2" style="font-size:0.85rem;color:var(--text-secondary);margin-top:16px;"><i class="bi bi-shield-lock-fill me-1"></i> Account Credentials</h6>
        <div class="row g-2 mb-2">
          <div class="col-md-6">
            <label class="form-label small fw-semibold">Email Address *</label>
            <input type="email" name="email" class="form-control" required value="<?= e($old['email'] ?? '') ?>">
          </div>
          <div class="col-md-6">
            <label class="form-label small fw-semibold">Username *</label>
            <input type="text" name="username" class="form-control" required minlength="3" maxlength="50" pattern="[A-Za-z0-9_]+" value="<?= e($old['username'] ?? '') ?>">
            <div class="form-text small">Letters, numbers, underscores only.</div>
          </div>
          <div class="col-md-6">
            <label class="form-label small fw-semibold">Password *</label>
            <input type="password" name="password" id="regPw" class="form-control" required minlength="8">
          </div>
          <div class="col-md-6">
            <label class="form-label small fw-semibold">Confirm Password *</label>
            <input type="password" name="confirm_password" id="regConfirmPw" class="form-control" required minlength="8">
          </div>
        </div>

        <!-- Section: Uploads -->
        <h6 class="fw-semibold mb-2" style="font-size:0.85rem;color:var(--text-secondary);margin-top:16px;"><i class="bi bi-card-image me-1"></i> Photo &amp; Documents</h6>
        <div class="row g-2 mb-2">
          <div class="col-md-6">
            <label class="form-label small fw-semibold">Profile Picture</label>
            <input type="file" name="profile_picture" class="form-control" accept="image/*">
          </div>
          <div class="col-md-6 role-field field-valid_id field-student field-nurse">
            <label class="form-label small fw-semibold" id="validIdLabel">Student ID / Valid ID *</label>
            <input type="file" name="valid_id" id="validIdInput" class="form-control" accept="image/*,.pdf">
          </div>
        </div>

        <div class="form-check mt-3">
          <input class="form-check-input" type="checkbox" name="certify" id="certify" required>
          <label class="form-check-label small" for="certify">I certify that the information above is true and accurate.</label>
        </div>

        <button type="submit" class="btn btn-primary w-100 mt-3 mb-2 py-2 fw-semibold" id="submitBtn">
          Create Account
        </button>

        <p class="text-center small text-muted mb-0">
          Already have an account? <a href="login.php" class="fw-medium">Sign in</a>
        </p>
      </form>
    </div>
  </div>
</div>

<script>
(function () {
  'use strict';

  const roleMeta = {
    student: {
      title: 'Student Registration',
      sideTitle: 'Join BPC Clinic',
      sideDesc: 'Register as a student to book clinic appointments, track your medical history, and get your clinic QR code.',
      sideIcon: '<i class="bi bi-mortarboard-fill"></i>',
      btnText: 'Create Student Account',
      genderReq: false,
      dobReq: false,
      contactReq: false,
      studentNumReq: true,
      empIdReq: false,
      deptReq: false,
      validIdReq: true,
      validIdLabel: 'Student ID / Valid ID *',
      empIdLabel: 'Nurse / Employee ID'
    },
    nurse: {
      title: 'Nurse Registration',
      sideTitle: 'Nurse Station',
      sideDesc: 'Register as a school nurse to manage health records, consultations, and clinic operations.',
      sideIcon: '<i class="bi bi-person-badge-fill"></i>',
      btnText: 'Register Nurse Account',
      genderReq: true,
      dobReq: true,
      contactReq: true,
      studentNumReq: false,
      empIdReq: false,
      deptReq: false,
      validIdReq: false,
      validIdLabel: 'Valid ID / PRC ID',
      empIdLabel: 'Nurse / Employee ID'
    },
    teacher: {
      title: 'Teacher Registration',
      sideTitle: 'Faculty Portal',
      sideDesc: 'Register as a teacher. After administrator approval, set your advisory class and monitor student well-being.',
      sideIcon: '<i class="bi bi-person-video3"></i>',
      btnText: 'Submit Teacher Request',
      genderReq: false,
      dobReq: false,
      contactReq: false,
      studentNumReq: false,
      empIdReq: true,
      deptReq: true,
      deptPlaceholder: 'e.g. College of Engineering',
      deptLabel: 'Department *',
      empIdLabel: 'Employee / Faculty ID *',
      empIdPlaceholder: 'e.g. T-2026-001',
      validIdReq: false
    },
    guidance_counselor: {
      title: 'Guidance Counselor Registration',
      sideTitle: 'Counseling Office',
      sideDesc: 'Register as a guidance counselor to receive student support referrals and coordinate student well-being.',
      sideIcon: '<i class="bi bi-heart-pulse-fill"></i>',
      btnText: 'Submit Counselor Request',
      genderReq: false,
      dobReq: false,
      contactReq: false,
      studentNumReq: false,
      empIdReq: true,
      deptReq: true,
      deptPlaceholder: 'e.g. Guidance & Counseling Office',
      deptLabel: 'Department / Office *',
      empIdLabel: 'Employee / Staff ID *',
      empIdPlaceholder: 'e.g. GC-2026-001',
      validIdReq: false
    }
  };

  const form = document.getElementById('unifiedRegForm');
  const accountTypeInput = document.getElementById('accountTypeInput');
  const roleBtns = document.querySelectorAll('.role-btn');

  // Input references
  const genderInput = document.getElementById('genderInput');
  const dobInput = document.getElementById('dobInput');
  const contactInput = document.getElementById('contactInput');
  const studentNumInput = document.getElementById('studentNumberInput');
  const empIdInput = document.getElementById('employeeIdInput');
  const deptInput = document.getElementById('deptInput');
  const validIdInput = document.getElementById('validIdInput');

  // Label references
  const genderLabel = document.getElementById('genderLabel');
  const dobLabel = document.getElementById('dobLabel');
  const empIdLabel = document.getElementById('employeeIdLabel');
  const deptLabel = document.getElementById('deptLabel');
  const validIdLabel = document.getElementById('validIdLabel');

  function switchRole(roleKey) {
    if (!roleMeta[roleKey]) roleKey = 'student';

    const meta = roleMeta[roleKey];
    accountTypeInput.value = roleKey;

    // Update buttons UI
    roleBtns.forEach(btn => {
      if (btn.dataset.role === roleKey) {
        btn.classList.add('active');
      } else {
        btn.classList.remove('active');
      }
    });

    // Update Header and Side text
    document.getElementById('formHeaderTitle').textContent = meta.title.toUpperCase();
    document.getElementById('sideTitle').textContent = meta.sideTitle;
    document.getElementById('sideDesc').textContent = meta.sideDesc;
    document.getElementById('sideIcon').innerHTML = meta.sideIcon;
    document.getElementById('submitBtn').textContent = meta.btnText;

    // Toggle Role Sections
    document.querySelector('.section-student').style.display = (roleKey === 'student') ? 'block' : 'none';
    document.querySelector('.section-staff').style.display = (roleKey !== 'student') ? 'block' : 'none';
    document.querySelector('.section-teacher-note').style.display = (roleKey === 'teacher') ? 'block' : 'none';

    // Toggle Role Fields
    document.querySelectorAll('.role-field').forEach(el => {
      if (el.classList.contains('field-' + roleKey)) {
        el.style.display = 'block';
      } else {
        el.style.display = 'none';
      }
    });

    // Labels & Placeholders
    if (meta.genderReq) genderLabel.textContent = 'Gender *';
    else genderLabel.textContent = 'Gender';

    if (meta.dobReq) dobLabel.textContent = 'Date of Birth *';
    else dobLabel.textContent = 'Date of Birth';

    if (empIdLabel) empIdLabel.textContent = meta.empIdLabel || 'Employee ID';
    if (empIdInput && meta.empIdPlaceholder) empIdInput.placeholder = meta.empIdPlaceholder;

    if (deptLabel && meta.deptLabel) deptLabel.textContent = meta.deptLabel;
    if (deptInput && meta.deptPlaceholder) deptInput.placeholder = meta.deptPlaceholder;

    if (validIdLabel && meta.validIdLabel) validIdLabel.textContent = meta.validIdLabel;

    // Required Attributes Mapping
    if (genderInput) genderInput.required = meta.genderReq;
    if (dobInput) dobInput.required = meta.dobReq;
    if (contactInput) contactInput.required = meta.contactReq;
    if (studentNumInput) studentNumInput.required = meta.studentNumReq;
    if (empIdInput) empIdInput.required = meta.empIdReq;
    if (deptInput) deptInput.required = meta.deptReq;
    if (validIdInput) validIdInput.required = meta.validIdReq;
  }

  // Bind click handlers to role buttons
  roleBtns.forEach(btn => {
    btn.addEventListener('click', function () {
      switchRole(this.dataset.role);
    });
  });

  // Initial setup based on current value
  const initialRole = accountTypeInput.value || 'student';
  switchRole(initialRole);

  // Form submit handler with password complexity validation
  const pw = document.getElementById('regPw');
  const confirmPw = document.getElementById('regConfirmPw');
  const submitBtn = document.getElementById('submitBtn');

  function validPassword(v) {
    return v.length >= 8 && /[A-Z]/.test(v) && /[a-z]/.test(v) && /[0-9]/.test(v) && /[^A-Za-z0-9]/.test(v);
  }

  form.addEventListener('submit', function (e) {
    if (!validPassword(pw.value)) {
      e.preventDefault();
      alert('Password must be at least 8 characters and contain an uppercase letter, lowercase letter, number, and special character.');
      pw.focus();
      return false;
    }
    if (pw.value !== confirmPw.value) {
      e.preventDefault();
      alert('Passwords do not match.');
      confirmPw.focus();
      return false;
    }

    submitBtn.disabled = true;
    submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Submitting...';
  });

})();
</script>
<?php include __DIR__ . '/../components/footer.php'; ?>
</body>
</html>

      <form method="POST" novalidate enctype="multipart/form-data">
        <?= csrf_field() ?>
        <h6 class="fw-semibold mb-2" style="font-size:0.85rem;color:var(--text-secondary)"><i class="bi bi-person-vcard me-1"></i> Personal Information</h6>
        <div class="row g-2 mb-2">
          <div class="col-md-4">
            <label class="form-label small fw-semibold">First Name *</label>
            <input type="text" name="first_name" class="form-control" required value="<?= e($old['first_name'] ?? '') ?>">
          </div>
          <div class="col-md-4">
            <label class="form-label small fw-semibold">Middle Name</label>
            <input type="text" name="middle_name" class="form-control" value="<?= e($old['middle_name'] ?? '') ?>">
          </div>
          <div class="col-md-4">
            <label class="form-label small fw-semibold">Last Name *</label>
            <input type="text" name="last_name" class="form-control" required value="<?= e($old['last_name'] ?? '') ?>">
          </div>
          <div class="col-md-6">
            <label class="form-label small fw-semibold">Gender</label>
            <select name="gender" class="form-select">
              <option value="">Select</option>
              <?php foreach (['Male','Female','Other'] as $g): ?>
                <option <?= (($old['gender'] ?? '') === $g) ? 'selected' : '' ?>><?= $g ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label small fw-semibold">Date of Birth</label>
            <input type="date" name="date_of_birth" class="form-control" max="<?= date('Y-m-d') ?>" value="<?= e($old['date_of_birth'] ?? '') ?>">
          </div>
          <div class="col-md-6">
            <label class="form-label small fw-semibold">Contact Number</label>
            <input type="text" name="contact_number" class="form-control" value="<?= e($old['contact_number'] ?? '') ?>">
          </div>
        </div>

        <h6 class="fw-semibold mb-2" style="font-size:0.85rem;color:var(--text-secondary);margin-top:16px;"><i class="bi bi-mortarboard-fill me-1"></i> School Information</h6>
        <div class="row g-2 mb-2">
          <div class="col-md-6">
            <label class="form-label small fw-semibold">Student Number *</label>
            <input type="text" name="student_number" class="form-control" required value="<?= e($old['student_number'] ?? '') ?>">
          </div>
          <div class="col-md-6">
            <label class="form-label small fw-semibold">Course</label>
            <input type="text" name="course" class="form-control" placeholder="e.g. BSIT" value="<?= e($old['course'] ?? '') ?>">
          </div>
          <div class="col-md-6">
            <label class="form-label small fw-semibold">Year Level</label>
            <select name="year_level" class="form-select">
              <option value="">Select</option>
              <?php foreach (['1st Year','2nd Year','3rd Year','4th Year'] as $y): ?>
                <option <?= (($old['year_level'] ?? '') === $y) ? 'selected' : '' ?>><?= $y ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label small fw-semibold">Section</label>
            <input type="text" name="section" class="form-control" placeholder="e.g. A" value="<?= e($old['section'] ?? '') ?>">
          </div>
        </div>

        <h6 class="fw-semibold mb-2" style="font-size:0.85rem;color:var(--text-secondary);margin-top:16px;"><i class="bi bi-shield-lock-fill me-1"></i> Account Credentials</h6>
        <div class="row g-2 mb-2">
          <div class="col-md-6">
            <label class="form-label small fw-semibold">Email *</label>
            <input type="email" name="email" class="form-control" required value="<?= e($old['email'] ?? '') ?>">
          </div>
          <div class="col-md-6">
            <label class="form-label small fw-semibold">Username *</label>
            <input type="text" name="username" class="form-control" required value="<?= e($old['username'] ?? '') ?>">
          </div>
          <div class="col-md-6">
            <label class="form-label small fw-semibold">Password *</label>
            <input type="password" name="password" class="form-control" required minlength="8">
          </div>
          <div class="col-md-6">
            <label class="form-label small fw-semibold">Confirm Password *</label>
            <input type="password" name="confirm_password" class="form-control" required minlength="8">
          </div>
        </div>

        <h6 class="fw-semibold mb-2" style="font-size:0.85rem;color:var(--text-secondary);margin-top:16px;"><i class="bi bi-card-image me-1"></i> Student Information & Uploads</h6>
        <div class="row g-2 mb-2">
          <div class="col-md-6">
            <label class="form-label small fw-semibold">Profile Picture *</label>
            <input type="file" name="profile_picture" class="form-control" accept="image/*" required>
          </div>
          <div class="col-md-6">
            <label class="form-label small fw-semibold">Student ID / Valid ID *</label>
            <input type="file" name="valid_id" class="form-control" accept="image/*,.pdf" required>
          </div>
        </div>

        <div class="form-check mt-3">
          <input class="form-check-input" type="checkbox" name="certify" id="certify" required>
          <label class="form-check-label small" for="certify">I certify that the information above is true and accurate.</label>
        </div>

        <button type="submit" class="btn btn-primary w-100 mt-3 mb-2 py-2 fw-semibold">Create Account</button>
        <p class="text-center small text-muted mb-0">Not a student? <a href="register_hub.php" class="fw-medium">See other account types</a> &nbsp;·&nbsp; Already have an account? <a href="login.php" class="fw-medium">Sign in</a></p>
      </form>
    </div>
  </div>
</div>
  <script>
  // Client-side password validation + strength & prevent duplicate submits
  (function () {
    const form = document.querySelector('form');
    const pw = form.querySelector('input[name="password"]');
    const confirm = form.querySelector('input[name="confirm_password"]');
    const submitBtn = form.querySelector('button[type="submit"]');

    function validPassword(v) {
      return v.length >= 8 && /[A-Z]/.test(v) && /[a-z]/.test(v) && /[0-9]/.test(v) && /[^A-Za-z0-9]/.test(v);
    }

    form.addEventListener('submit', function (e) {
      if (!validPassword(pw.value)) {
        e.preventDefault();
        alert('Password does not meet complexity requirements. Please follow the password rules.');
        pw.focus();
        return false;
      }
      if (pw.value !== confirm.value) {
        e.preventDefault();
        alert('Passwords do not match.');
        confirm.focus();
        return false;
      }
      // Prevent double submit
      submitBtn.disabled = true;
      submitBtn.textContent = 'Creating...';
    });
  })();
  </script>
<?php include __DIR__ . '/../components/footer.php'; ?>
</body>
</html>
