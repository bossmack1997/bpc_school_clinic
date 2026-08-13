<?php
require_once __DIR__ . '/../config/config.php';

// Prevent caching of authentication pages
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');

if (is_logged_in()) {
    redirect(dashboard_url_for(current_user()['role']));
}

$errors = [];
$old = $_POST ?? [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_die();

    $firstName    = trim($_POST['first_name'] ?? '');
    $middleName   = trim($_POST['middle_name'] ?? '');
    $lastName     = trim($_POST['last_name'] ?? '');
    $gender       = trim($_POST['gender'] ?? '');
    $dateOfBirth  = trim($_POST['date_of_birth'] ?? '');
    $contactNumber = trim($_POST['contact_number'] ?? '');
    $studentNo    = trim($_POST['student_number'] ?? '');
    $course       = trim($_POST['course'] ?? '');
    $yearLevel    = trim($_POST['year_level'] ?? '');
    $section      = trim($_POST['section'] ?? '');
    $email        = trim($_POST['email'] ?? '');
    $username     = trim($_POST['username'] ?? '');
    $password     = $_POST['password'] ?? '';
    $confirm      = $_POST['confirm_password'] ?? '';
    $certify      = isset($_POST['certify']);

    if (!$firstName || !$lastName || !$studentNo || !$email || !$username || !$password) {
        $errors[] = 'Please fill in all required fields.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }
    if (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    }
    if ($password !== $confirm) {
        $errors[] = 'Passwords do not match.';
    }
    // Strong password policy: uppercase, lowercase, number, special char
    if ($password && !preg_match('/[A-Z]/', $password)) {
      $errors[] = 'Password must contain at least one uppercase letter.';
    }
    if ($password && !preg_match('/[a-z]/', $password)) {
      $errors[] = 'Password must contain at least one lowercase letter.';
    }
    if ($password && !preg_match('/[0-9]/', $password)) {
      $errors[] = 'Password must contain at least one number.';
    }
    if ($password && !preg_match('/[^A-Za-z0-9]/', $password)) {
      $errors[] = 'Password must contain at least one special character.';
    }
    if (!$certify) {
        $errors[] = 'You must certify that the information above is true.';
    }

    // Birthdate validation: must be a valid date and not in the future.
    if ($dateOfBirth !== '') {
        $bdate = DateTime::createFromFormat('Y-m-d', $dateOfBirth);
        if (!$bdate || $bdate->format('Y-m-d') !== $dateOfBirth) {
            $errors[] = 'Please enter a valid birthdate (YYYY-MM-DD).';
        } elseif ($dateOfBirth > date('Y-m-d')) {
            $errors[] = 'Birthdate cannot be a future date.';
        }
    }

    if (!$errors) {
        $db = getDB();
        $stmt = $db->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
        $stmt->execute([$username, $email]);
        if ($stmt->fetch()) {
            $errors[] = 'Username or email is already registered.';
        }
        $stmt = $db->prepare("SELECT id FROM students WHERE student_number = ?");
        $stmt->execute([$studentNo]);
        if ($stmt->fetch()) {
            $errors[] = 'Student number is already registered.';
        }
    }

    if (!$errors) {
        try {
            $db->beginTransaction();
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $db->prepare(
                "INSERT INTO users (role, username, email, password_hash, first_name, middle_name, last_name, gender, date_of_birth, phone, status)
                 VALUES ('student', ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')"
            );
            $stmt->execute([$username, $email, $hash, $firstName, $middleName, $lastName, $gender, $dateOfBirth, $contactNumber]);
            $userId = $db->lastInsertId();

$stmt = $db->prepare(
                "INSERT INTO students (user_id, student_number, course, year_level, section) VALUES (?, ?, ?, ?, ?)"
            );
            $stmt->execute([$userId, $studentNo, $course, $yearLevel, $section]);
            $studentId = $db->lastInsertId();

            // Auto-generate a permanent, secure QR for the new student.
            try {
                ensure_student_qr($db, $studentId, $userId);
            } catch (Exception $qrE) {
                error_log('QR generation on registration failed: ' . $qrE->getMessage());
            }

            $db->commit();
            log_activity($userId, 'Registered new student account', 'auth');
            set_flash('success', 'Registration submitted! Your account is pending admin approval.');
            redirect('login.php');
        } catch (Exception $e) {
            $db->rollBack();
            error_log($e->getMessage());
            $errors[] = 'Something went wrong. Please try again.';
        }
    }
}

$pageTitle = 'Register';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php include __DIR__ . '/../includes/head.php'; ?>
</head>
<body>
<div class="auth-wrapper">
  <div class="auth-card" style="max-width:1040px;" data-aos="fade-up" data-aos-duration="800">
    <div class="auth-side">
      <div class="auth-icon"><i class="bi bi-person-plus-fill"></i></div>
      <h2 class="mb-2">Create your account</h2>
      <p class="mb-0">Register as a BPC student to book clinic appointments, track your health records, and chat with the AI Health Assistant.</p>
    </div>
    <div class="auth-form-side">
      <h4 class="mb-1">Student Registration</h4>
      <p class="text-muted mb-3">All fields marked * are required</p>

      <?php if ($errors): ?>
        <div class="alert alert-danger py-2 small d-flex align-items-center gap-2">
          <i class="bi bi-exclamation-triangle-fill"></i>
          <span><?= implode('<br>', array_map('e', $errors)) ?></span>
        </div>
      <?php endif; ?>

      <form method="POST" novalidate>
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
        <div class="row g-2">
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

        <div class="form-check mt-3">
          <input class="form-check-input" type="checkbox" name="certify" id="certify" required>
          <label class="form-check-label small" for="certify">I certify that the information above is true and accurate.</label>
        </div>

        <button type="submit" class="btn btn-primary w-100 mt-3 mb-2 py-2 fw-semibold">Create Account</button>
        <p class="text-center small text-muted mb-0">Already have an account? <a href="login.php" class="fw-medium">Sign in</a></p>
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
