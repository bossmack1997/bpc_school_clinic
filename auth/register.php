<?php
/**
 * auth/register.php
 * ------------------------------------------------------------------
 * Unified Medical Self-Registration System for BPC School Clinic.
 * Supports dedicated self-registration for EXACTLY 4 roles:
 *   1. Student
 *   2. Teacher
 *   3. Nurse
 *   4. Administrator
 * ------------------------------------------------------------------
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/ai/MICARegistrationValidator.php';

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
$rawRoleReq = strtolower(trim($_REQUEST['role'] ?? $_REQUEST['type'] ?? $_REQUEST['account_type'] ?? 'student'));
$roleNormMap = [
    'student'       => 'student',
    'teacher'       => 'teacher',
    'nurse'         => 'nurse',
    'admin'         => 'admin',
    'administrator' => 'admin',
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

    $rawRole = strtolower(trim($_POST['account_type'] ?? $_POST['role'] ?? 'student'));

    if (!isset($roleNormMap[$rawRole])) {
        $errors[] = 'Invalid account type selected. Please choose Student, Teacher, Nurse, or Administration.';
        $role = 'student';
    } else {
        $role = $roleNormMap[$rawRole];
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

    // Process file uploads first so validator can evaluate uploaded ID
    $profilePicRel = upload_registration_file('profile_picture', 'assets/images/profiles', 'user', ['image/jpeg','image/png','image/webp'])
                     ?: 'assets/images/default-avatar.png';
    $validIdRel = upload_registration_file('valid_id', 'assets/images/student_ids', 'valid_id', ['image/jpeg','image/png','image/webp','application/pdf']);

    // MICA Intelligent Registration Validation
    $validator = new MICARegistrationValidator($db);
    $validation = $validator->validateRegistration($_POST, !empty($validIdRel));

    if (!empty($validation['errors'])) {
        $errors = $validation['errors'];
    } elseif ($validation['decision'] === 'rejected') {
        $errors[] = $validation['reason'] ?: 'Registration rejected based on automated credential validation.';
    }

    if (!$errors) {
        try {
            $db->beginTransaction();
            $hash = password_hash($password, PASSWORD_DEFAULT);

            $decision      = $validation['decision']; // 'approved' or 'review'
            $accountStatus = ($decision === 'approved') ? 'active' : 'pending';
            $regStatus     = ($decision === 'approved') ? 'approved' : 'review';
            $valReason     = $validation['reason'];
            $valConfidence = $validation['confidence'];

            $stmt = $db->prepare("
                INSERT INTO users (role, username, email, password_hash, first_name, middle_name, last_name, gender, date_of_birth, phone, profile_picture, status, registration_status, validation_reason, validation_confidence)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
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
                $profilePicRel,
                $accountStatus,
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

                $stmt = $db->prepare("
                    INSERT INTO students (user_id, student_number, course, year_level, section, valid_id_path)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$userId, $studentNo, $course ?: null, $yearLevel ?: null, $section ?: null, $validIdRel]);
                $studentId = (int)$db->lastInsertId();

                if ($accountStatus === 'active') {
                    try {
                        ensure_student_qr($db, $studentId, $userId);
                    } catch (Exception $qrE) {
                        error_log('QR generation on registration failed: ' . $qrE->getMessage());
                    }
                }

                log_activity($userId, 'Registered new student account (' . $regStatus . ')', 'auth');

            } elseif ($role === 'teacher') {
                $employeeId = $validation['metadata']['employee_id'] ?? trim($_POST['employee_id'] ?? '');
                $department = $validation['metadata']['department'] ?? trim($_POST['department'] ?? '');

                $stmt = $db->prepare("
                    INSERT INTO teachers (user_id, employee_id, first_name, last_name, department, email, phone)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $userId,
                    $employeeId,
                    $firstName,
                    $lastName,
                    $department ?: null,
                    $email,
                    $contact ?: null
                ]);

                log_activity($userId, 'Registered new teacher account (' . $regStatus . ')', 'auth');

            } elseif ($role === 'nurse') {
                $employeeId    = $validation['metadata']['employee_id'] ?? trim($_POST['employee_id'] ?? '');
                $licenseNumber = $validation['metadata']['license_number'] ?? trim($_POST['license_number'] ?? '');

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

                log_activity($userId, 'Registered new nurse account (' . $regStatus . ')', 'auth');

            } elseif ($role === 'admin') {
                log_activity($userId, 'Registered administration account request (' . $regStatus . ')', 'auth', 'Administrator: ' . $username);
            }

            // If account is flagged for review, create an AI action item for administrators
            if ($decision === 'review') {
                $validator->logReviewAction($userId, $validation);
                set_flash('info', 'Registration submitted! Your account is undergoing clinic administrative verification (' . $valReason . '). You will be able to log in once approved.');
            } else {
                set_flash('success', 'Registration approved! Your account is active. You can now sign in.');
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
  .role-btn.btn-doctor.active {
    background: #0284c7 !important;
    border-color: #0284c7 !important;
    color: #ffffff !important;
    box-shadow: 0 4px 12px rgba(2, 132, 199, 0.25);
  }
  .role-btn.btn-nurse.active {
    background: #059669 !important;
    border-color: #059669 !important;
    color: #ffffff !important;
    box-shadow: 0 4px 12px rgba(5, 150, 105, 0.25);
  }
  .role-btn.btn-admin.active {
    background: #0f172a !important;
    border-color: #0f172a !important;
    color: #ffffff !important;
    box-shadow: 0 4px 12px rgba(15, 23, 42, 0.3);
  }
  .role-btn i {
    font-size: 1.25rem;
  }
</style>
</head>
<body>
<div class="auth-wrapper">
  <div class="auth-card" style="max-width:1080px;" data-aos="fade-up" data-aos-duration="800">
    <div class="auth-side" id="authSideBanner">
      <div class="auth-icon" id="sideIcon"><i class="bi bi-person-plus-fill"></i></div>
      <h2 class="mb-2" id="sideTitle">BPC School Clinic</h2>
      <p class="mb-0" id="sideDesc">Create your account to access clinic services, records, or administration.</p>
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

        <!-- Account Type Selector (Student, Teacher, Nurse, Administration) -->
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
              <button type="button" class="btn role-btn btn-doctor w-100 py-2 text-center <?= $selectedRole === 'teacher' ? 'active' : '' ?>" data-role="teacher">
                <i class="bi bi-person-workspace d-block mb-1"></i>
                <span>Teacher</span>
              </button>
            </div>
            <div class="col-6 col-sm-3">
              <button type="button" class="btn role-btn btn-nurse w-100 py-2 text-center <?= $selectedRole === 'nurse' ? 'active' : '' ?>" data-role="nurse">
                <i class="bi bi-person-badge-fill d-block mb-1"></i>
                <span>Nurse</span>
              </button>
            </div>
            <div class="col-6 col-sm-3">
              <button type="button" class="btn role-btn btn-admin w-100 py-2 text-center <?= $selectedRole === 'admin' ? 'active' : '' ?>" data-role="admin">
                <i class="bi bi-shield-lock-fill d-block mb-1"></i>
                <span>Admin</span>
              </button>
            </div>
          </div>
          <input type="hidden" name="account_type" id="accountTypeInput" value="<?= e($selectedRole) ?>">
        </div>

        <!-- Section: Personal Information -->
        <h6 class="fw-semibold mb-2" style="font-size:0.85rem;color:var(--text-secondary)"><i class="bi bi-person-vcard me-1"></i> Personal Information</h6>
        <div class="row g-2 mb-2">
          <div class="col-md-4">
            <label class="form-label small fw-semibold">First Name *</label>
            <input type="text" name="first_name" class="form-control" required maxlength="80" value="<?= e($old['first_name'] ?? '') ?>" placeholder="First name">
          </div>
          <div class="col-md-4">
            <label class="form-label small fw-semibold">Middle Name</label>
            <input type="text" name="middle_name" class="form-control" maxlength="80" value="<?= e($old['middle_name'] ?? '') ?>" placeholder="Middle name (optional)">
          </div>
          <div class="col-md-4">
            <label class="form-label small fw-semibold">Last Name *</label>
            <input type="text" name="last_name" class="form-control" required maxlength="80" value="<?= e($old['last_name'] ?? '') ?>" placeholder="Last name">
          </div>
          <div class="col-md-6 role-field field-gender field-student field-nurse field-teacher">
            <label class="form-label small fw-semibold" id="genderLabel">Gender</label>
            <select name="gender" id="genderInput" class="form-select">
              <option value="">Select</option>
              <?php foreach (['Male','Female','Other'] as $g): ?>
                <option <?= (($old['gender'] ?? '') === $g) ? 'selected' : '' ?>><?= $g ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6 role-field field-dob field-student field-nurse field-teacher">
            <label class="form-label small fw-semibold" id="dobLabel">Date of Birth</label>
            <input type="date" name="date_of_birth" id="dobInput" class="form-control" max="<?= date('Y-m-d') ?>" value="<?= e($old['date_of_birth'] ?? '') ?>">
          </div>
          <div class="col-md-6 role-field field-contact field-student field-teacher field-nurse field-admin">
            <label class="form-label small fw-semibold" id="contactLabel">Contact Number *</label>
            <input type="text" name="contact_number" id="contactInput" class="form-control" maxlength="30" placeholder="e.g. 09171234567" value="<?= e($old['contact_number'] ?? '') ?>">
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

        <!-- Section: Teacher Details (Teacher Only) -->
        <div class="role-section section-teacher">
          <h6 class="fw-semibold mb-2" style="font-size:0.85rem;color:var(--text-secondary);margin-top:16px;"><i class="bi bi-person-workspace me-1 text-primary"></i> Teacher Details</h6>
          <div class="row g-2 mb-2">
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Teacher Employee ID *</label>
              <input type="text" name="employee_id" id="teacherEmployeeIdInput" class="form-control" maxlength="50" placeholder="e.g. TCH-2026-001" value="<?= e($old['employee_id'] ?? '') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Department</label>
              <input type="text" name="department" id="teacherDepartmentInput" class="form-control" maxlength="100" placeholder="e.g. College Department" value="<?= e($old['department'] ?? '') ?>">
            </div>
            <div class="col-md-12">
              <label class="form-label small fw-semibold">Assigned Section (optional)</label>
              <input type="text" name="section" class="form-control" placeholder="e.g. BSIT 1-A" value="<?= e($old['section'] ?? '') ?>">
            </div>
          </div>
          <div class="card p-2 mb-2 small" style="background:#e0f2fe;border:none;color:#0369a1;">
            <div class="fw-semibold mb-1"><i class="bi bi-info-circle me-1"></i> Privacy-limited access</div>
            <div>Teachers can submit clinic referrals and emergency reports. They cannot access confidential medical records or medicines.</div>
          </div>
        </div>

        <!-- Section: Nurse Employment Info (Nurse Only) -->
        <div class="role-section section-nurse">
          <h6 class="fw-semibold mb-2" style="font-size:0.85rem;color:var(--text-secondary);margin-top:16px;"><i class="bi bi-hospital-fill me-1"></i> Nurse Credentials</h6>
          <div class="row g-2 mb-2">
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Nurse / Employee ID *</label>
              <input type="text" name="employee_id" id="nurseEmployeeIdInput" class="form-control" maxlength="50" placeholder="e.g. NURSE-2026-001" value="<?= e($old['employee_id'] ?? '') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold">PRC License Number</label>
              <input type="text" name="license_number" class="form-control" placeholder="Optional" value="<?= e($old['license_number'] ?? '') ?>">
            </div>
          </div>
        </div>

        <!-- Section: Administrator Info (Admin Only) -->
        <div class="role-section section-admin">
          <h6 class="fw-semibold mb-2" style="font-size:0.85rem;color:var(--text-secondary);margin-top:16px;"><i class="bi bi-shield-check me-1"></i> Administrative Office</h6>
          <div class="row g-2 mb-2">
            <div class="col-md-12">
              <label class="form-label small fw-semibold">Administrative Department / Unit</label>
              <input type="text" name="admin_office" class="form-control" placeholder="e.g. Clinic Administration, MIS Office, Office of Student Affairs" value="<?= e($old['admin_office'] ?? '') ?>">
            </div>
          </div>
          <div class="card p-2 mb-2 small bg-dark text-white border-0 rounded-3">
            <div class="fw-semibold mb-1"><i class="bi bi-shield-lock-fill me-1 text-warning"></i> Administrator Privileges</div>
            <div class="text-light opacity-75">Administration accounts manage users, clinic configuration, reports, and system settings. Approval is required before access is granted.</div>
          </div>
        </div>

        <!-- Section: Account Credentials -->
        <h6 class="fw-semibold mb-2" style="font-size:0.85rem;color:var(--text-secondary);margin-top:16px;"><i class="bi bi-shield-lock-fill me-1"></i> Account Credentials</h6>
        <div class="row g-2 mb-2">
          <div class="col-md-6">
            <label class="form-label small fw-semibold">Email Address *</label>
            <input type="email" name="email" class="form-control" required placeholder="name@domain.com" value="<?= e($old['email'] ?? '') ?>">
          </div>
          <div class="col-md-6">
            <label class="form-label small fw-semibold">Username *</label>
            <input type="text" name="username" class="form-control" required minlength="3" maxlength="50" pattern="[A-Za-z0-9_]+" placeholder="Choose a username" value="<?= e($old['username'] ?? '') ?>">
            <div class="form-text small">Letters, numbers, underscores only (3-50 chars).</div>
          </div>
          <div class="col-md-6">
            <label class="form-label small fw-semibold">Password *</label>
            <input type="password" name="password" id="regPw" class="form-control" required minlength="8" placeholder="Min. 8 characters">
          </div>
          <div class="col-md-6">
            <label class="form-label small fw-semibold">Confirm Password *</label>
            <input type="password" name="confirm_password" id="regConfirmPw" class="form-control" required minlength="8" placeholder="Re-enter password">
          </div>
        </div>

        <!-- Section: Uploads -->
        <h6 class="fw-semibold mb-2" style="font-size:0.85rem;color:var(--text-secondary);margin-top:16px;"><i class="bi bi-card-image me-1"></i> Photo &amp; Documents</h6>
        <div class="row g-2 mb-2">
          <div class="col-md-6">
            <label class="form-label small fw-semibold">Profile Picture</label>
            <input type="file" name="profile_picture" class="form-control" accept="image/*">
          </div>
          <div class="col-md-6 role-field field-valid_id field-student field-nurse field-teacher">
            <label class="form-label small fw-semibold" id="validIdLabel">Valid ID / PRC ID</label>
            <input type="file" name="valid_id" id="validIdInput" class="form-control" accept="image/*,.pdf">
          </div>
        </div>

        <div class="form-check mt-3">
          <input class="form-check-input" type="checkbox" name="certify" id="certify" required>
          <label class="form-check-label small" for="certify">I certify that all information provided above is true, accurate, and complete.</label>
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
      btnClass: 'btn-primary',
      genderReq: false,
      dobReq: false,
      contactReq: false,
      studentNumReq: true,
      empIdReq: false,
      validIdReq: true,
      validIdLabel: 'Student ID / Valid ID *'
    },
    teacher: {
      title: 'Teacher Registration',
      sideTitle: 'Teacher Portal',
      sideDesc: 'Register as a teacher to submit authorized clinic referrals and emergency reports.',
      sideIcon: '<i class="bi bi-person-workspace"></i>',
      btnText: 'Register Teacher Account',
      btnClass: 'btn-info text-white',
      genderReq: false,
      dobReq: false,
      contactReq: true,
      studentNumReq: false,
      empIdReq: true,
      validIdReq: false,
      validIdLabel: 'School ID / Valid ID'
    },
    nurse: {
      title: 'Nurse Registration',
      sideTitle: 'Nurse Station',
      sideDesc: 'Register as a school nurse to manage health records, patient consultations, vital signs, and clinic operations.',
      sideIcon: '<i class="bi bi-person-badge-fill"></i>',
      btnText: 'Register Nurse Account',
      btnClass: 'btn-success',
      genderReq: true,
      dobReq: true,
      contactReq: true,
      studentNumReq: false,
      empIdReq: true,
      validIdReq: false,
      validIdLabel: 'PRC ID / Valid ID'
    },
    admin: {
      title: 'Administrator Registration',
      sideTitle: 'System Administration',
      sideDesc: 'Register as an Administrator to oversee clinic operations, doctor & nurse accounts, inventory, and reporting.',
      sideIcon: '<i class="bi bi-shield-lock-fill"></i>',
      btnText: 'Register Administrator Account',
      btnClass: 'btn-dark',
      genderReq: false,
      dobReq: false,
      contactReq: true,
      studentNumReq: false,
      empIdReq: false,
      validIdReq: false,
      validIdLabel: 'Valid ID (Optional)'
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
  const teacherEmpIdInput = document.getElementById('teacherEmployeeIdInput');
  const teacherDepartmentInput = document.getElementById('teacherDepartmentInput');
  const nurseEmpIdInput = document.getElementById('nurseEmployeeIdInput');
  const validIdInput = document.getElementById('validIdInput');
  const submitBtn = document.getElementById('submitBtn');

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
    submitBtn.textContent = meta.btnText;

    submitBtn.className = 'btn ' + (meta.btnClass || 'btn-primary') + ' w-100 mt-3 mb-2 py-2 fw-semibold';

    // Toggle Role Sections
    document.querySelector('.section-student').style.display = (roleKey === 'student') ? 'block' : 'none';
    document.querySelector('.section-teacher').style.display = (roleKey === 'teacher') ? 'block' : 'none';
    document.querySelector('.section-nurse').style.display = (roleKey === 'nurse') ? 'block' : 'none';
    document.querySelector('.section-admin').style.display = (roleKey === 'admin') ? 'block' : 'none';

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
    if (teacherEmpIdInput) teacherEmpIdInput.required = (roleKey === 'teacher');
    if (teacherDepartmentInput) teacherDepartmentInput.required = false;
    if (nurseEmpIdInput) nurseEmpIdInput.required = (roleKey === 'nurse');
    if (validIdInput) validIdInput.required = meta.validIdReq;
    const vLabel = document.getElementById('validIdLabel');
    if (vLabel && meta.validIdLabel) vLabel.textContent = meta.validIdLabel;
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
    submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Creating Account...';
  });

})();
</script>
<?php include __DIR__ . '/../components/footer.php'; ?>
</body>
</html>
