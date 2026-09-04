<?php
/**
 * auth/register.php
 * ------------------------------------------------------------------
 * Unified Self-Registration System for BPC School Clinic.
 * Supports dedicated self-registration for:
 *   1. Student
 *   2. Nurse
 *   3. Teacher / Adviser
 *   4. Administrator
 *
 * Each role has a distinct registration process and assigned role.
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
    'student'       => 'student',
    'nurse'         => 'nurse',
    'teacher'       => 'adviser',
    'adviser'       => 'adviser',
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

    $rawRole = trim($_POST['account_type'] ?? $_POST['role'] ?? 'student');

    if (!isset($roleNormMap[$rawRole])) {
        $errors[] = 'Invalid account type selected. Please choose Student, Nurse, Teacher, or Administrator.';
        $role = 'student';
    } else {
        $role = $roleNormMap[$rawRole]; // Internal role: student, nurse, adviser, admin
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
        $errors[] = 'You must certify that the information provided is true and accurate.';
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

        if (!$employeeId) {
            $errors[] = 'Nurse / Employee ID is required.';
        }
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
        if (!$errors && $employeeId !== '') {
            $stmt = $db->prepare("SELECT id FROM nurses WHERE employee_id = ? LIMIT 1");
            $stmt->execute([$employeeId]);
            if ($stmt->fetch()) {
                $errors[] = 'This Nurse Employee ID is already registered.';
            }
        }
    } elseif ($role === 'adviser') {
        $employeeId = trim($_POST['employee_id'] ?? '');
        $department = trim($_POST['department'] ?? '');

        if (!$employeeId) {
            $errors[] = 'Employee / Faculty ID is required.';
        }
        if (!$department) {
            $errors[] = 'Department / College is required.';
        }

        if (!$errors && $employeeId !== '') {
            $stmt = $db->prepare("SELECT id FROM teachers WHERE employee_id = ? LIMIT 1");
            $stmt->execute([$employeeId]);
            if ($stmt->fetch()) {
                $errors[] = 'This Employee/Faculty ID is already registered.';
            } else {
                $stmt = $db->prepare("SELECT id FROM teacher_registration_requests WHERE teacher_id_number = ? AND status IN ('pending','approved') LIMIT 1");
                $stmt->execute([$employeeId]);
                if ($stmt->fetch()) {
                    $errors[] = 'A registration using this Employee/Faculty ID is already underway.';
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
                $errors[] = 'You already have a pending teacher registration. Please wait for administrator review.';
            }
        }
    } elseif ($role === 'admin') {
        $adminOffice = trim($_POST['admin_office'] ?? '');
        if (!$contact) {
            $errors[] = 'Contact number is required for Administrator registration.';
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

            // Admin accounts activate immediately; Student, Nurse, Teacher enter pending verification
            $accountStatus = ($role === 'admin') ? 'active' : 'pending';

            $stmt = $db->prepare("
                INSERT INTO users (role, username, email, password_hash, first_name, middle_name, last_name, gender, date_of_birth, phone, profile_picture, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
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
                $accountStatus
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
                set_flash('success', 'Student registration submitted! Your account is pending administrator approval.');

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
                set_flash('success', 'Nurse registration submitted! Your account is pending administrator approval.');

            } elseif ($role === 'adviser') {
                $stmt = $db->prepare("
                    INSERT INTO teacher_registration_requests (user_id, requested_role, teacher_id_number, requested_sections, status)
                    VALUES (?, 'adviser', ?, NULL, 'pending')
                ");
                $stmt->execute([$userId, $employeeId ?: null]);

                log_activity($userId, 'Submitted teacher account request', 'auth');
                set_flash('success', 'Teacher account request submitted! You can log in after the administrator approves your account.');

            } elseif ($role === 'admin') {
                log_activity($userId, 'Self-registered new administrator account', 'auth', 'Administrator: ' . $username);
                set_flash('success', 'Administrator account created successfully! You can now sign in with your credentials.');
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

        <!-- Account Type Selector (Student, Nurse, Teacher, Administrator) -->
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
              <button type="button" class="btn role-btn btn-admin w-100 py-2 text-center <?= $selectedRole === 'admin' ? 'active' : '' ?>" data-role="admin">
                <i class="bi bi-shield-lock-fill d-block mb-1"></i>
                <span>Admin</span>
              </button>
            </div>
          </div>
          <input type="hidden" name="account_type" id="accountTypeInput" value="<?= e($selectedRole === 'adviser' ? 'teacher' : $selectedRole) ?>">
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
          <div class="col-md-6 role-field field-contact field-student field-nurse field-teacher field-admin">
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

        <!-- Section: Teacher Employment Info (Teacher Only) -->
        <div class="role-section section-teacher">
          <h6 class="fw-semibold mb-2" style="font-size:0.85rem;color:var(--text-secondary);margin-top:16px;"><i class="bi bi-briefcase-fill me-1"></i> Faculty Information</h6>
          <div class="row g-2 mb-2">
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Employee / Faculty ID *</label>
              <input type="text" name="employee_id" id="teacherEmployeeIdInput" class="form-control" maxlength="50" placeholder="e.g. T-2026-001" value="<?= e($old['employee_id'] ?? '') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Department / College *</label>
              <input type="text" name="department" id="deptInput" class="form-control" maxlength="120" placeholder="e.g. College of Computer Studies" value="<?= e($old['department'] ?? '') ?>">
            </div>
          </div>
          <div class="card p-2 mb-2 small" style="background:var(--primary-light,#EAF7EF);border:none;">
            <div class="fw-semibold mb-1"><i class="bi bi-info-circle me-1"></i> Advisory Section Assignment</div>
            <div class="text-muted">You will be able to select and manage your advisory class inside the Adviser Portal once your account is approved.</div>
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
            <div class="text-light opacity-75">Administrator accounts hold system-wide management authority (user approvals, course &amp; section management, reports, and system settings).</div>
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
          <div class="col-md-6 role-field field-valid_id field-student field-nurse">
            <label class="form-label small fw-semibold" id="validIdLabel">Student ID / Valid ID *</label>
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
      deptReq: false,
      validIdReq: true,
      validIdLabel: 'Student ID / Valid ID *'
    },
    nurse: {
      title: 'Nurse Registration',
      sideTitle: 'Nurse Station',
      sideDesc: 'Register as a school nurse to manage health records, patient consultations, and clinic operations.',
      sideIcon: '<i class="bi bi-person-badge-fill"></i>',
      btnText: 'Register Nurse Account',
      btnClass: 'btn-primary',
      genderReq: true,
      dobReq: true,
      contactReq: true,
      studentNumReq: false,
      empIdReq: true,
      deptReq: false,
      validIdReq: false,
      validIdLabel: 'Valid ID / PRC ID'
    },
    teacher: {
      title: 'Teacher Registration',
      sideTitle: 'Faculty Portal',
      sideDesc: 'Register as a teacher. After administrator review, manage your advisory section and student clinic care.',
      sideIcon: '<i class="bi bi-person-video3"></i>',
      btnText: 'Submit Teacher Request',
      btnClass: 'btn-primary',
      genderReq: false,
      dobReq: false,
      contactReq: false,
      studentNumReq: false,
      empIdReq: true,
      deptReq: true,
      validIdReq: false
    },
    admin: {
      title: 'Administrator Registration',
      sideTitle: 'System Administration',
      sideDesc: 'Register as an Administrator to oversee clinic operations, user accounts, sections, and reporting.',
      sideIcon: '<i class="bi bi-shield-lock-fill"></i>',
      btnText: 'Register Administrator Account',
      btnClass: 'btn-dark',
      genderReq: false,
      dobReq: false,
      contactReq: true,
      studentNumReq: false,
      empIdReq: false,
      deptReq: false,
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
  const nurseEmpIdInput = document.getElementById('nurseEmployeeIdInput');
  const teacherEmpIdInput = document.getElementById('teacherEmployeeIdInput');
  const deptInput = document.getElementById('deptInput');
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
    document.querySelector('.section-nurse').style.display = (roleKey === 'nurse') ? 'block' : 'none';
    document.querySelector('.section-teacher').style.display = (roleKey === 'teacher') ? 'block' : 'none';
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
    if (nurseEmpIdInput) nurseEmpIdInput.required = (roleKey === 'nurse');
    if (teacherEmpIdInput) teacherEmpIdInput.required = (roleKey === 'teacher');
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