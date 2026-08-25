<?php
/**
 * auth/register_guidance.php
 * ------------------------------------------------------------------
 * Direct access wrapper for Guidance Counselor Registration.
 * Redirects seamlessly to the unified registration interface.
 * ------------------------------------------------------------------
 */
require_once __DIR__ . '/../config/config.php';
header('Location: ' . APP_URL . '/auth/register.php?role=guidance_counselor');
exit;


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_die();

    $firstName   = trim($_POST['first_name'] ?? '');
    $middleName  = trim($_POST['middle_name'] ?? '');
    $lastName    = trim($_POST['last_name'] ?? '');
    $email       = trim($_POST['email'] ?? '');
    $staffId     = trim($_POST['employee_id'] ?? '');
    $department  = trim($_POST['department'] ?? '');
    $contact     = trim($_POST['contact_number'] ?? '');
    $username    = trim($_POST['username'] ?? '');
    $password    = $_POST['password'] ?? '';
    $confirm     = $_POST['confirm_password'] ?? '';
    $certify     = isset($_POST['certify']);

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

    // Duplicate prevention: username/email across ALL accounts
    if (!$errors) {
        $stmt = $db->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
        $stmt->execute([$username, $email]);
        if ($stmt->fetch()) {
            $errors[] = 'Username or email is already registered.';
        }
    }

    // Duplicate staff ID prevention (teachers + pending requests)
    if (!$errors && $staffId !== '') {
        $stmt = $db->prepare("SELECT id FROM teachers WHERE employee_id = ? LIMIT 1");
        $stmt->execute([$staffId]);
        if ($stmt->fetch()) {
            $errors[] = 'This Employee/Staff ID is already registered.';
        } else {
            $stmt = $db->prepare("SELECT id FROM teacher_registration_requests WHERE teacher_id_number = ? AND status IN ('pending','approved') LIMIT 1");
            $stmt->execute([$staffId]);
            if ($stmt->fetch()) {
                $errors[] = 'A registration using this Employee/Staff ID is already underway.';
            }
        }
    }

    // Duplicate pending request prevention for this email
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

    if (!$errors) {
        try {
            $db->beginTransaction();
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $profilePicture = 'assets/images/default-avatar.png';

            if (!empty($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
                $file = $_FILES['profile_picture'];
                $allowed = ['image/jpeg','image/png','image/webp'];
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mimeType = finfo_file($finfo, $file['tmp_name']);
                finfo_close($finfo);
                if (in_array($mimeType, $allowed, true)) {
                    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                    $filename = 'user_' . time() . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
                    $destRel = 'assets/images/profiles/' . $filename;
                    $destAbs = BASE_PATH . '/' . $destRel;
                    $dir = dirname($destAbs);
                    if (!is_dir($dir)) mkdir($dir, 0755, true);
                    if (move_uploaded_file($file['tmp_name'], $destAbs)) {
                        $profilePicture = $destRel;
                    }
                }
            }

            // Role is SERVER-SIDE — never trusted from POST.
            $stmt = $db->prepare(
                "INSERT INTO users (role, username, email, password_hash, first_name, middle_name, last_name, phone, profile_picture, status)
                 VALUES ('guidance', ?, ?, ?, ?, ?, ?, ?, ?, 'pending')"
            );
            $stmt->execute([$username, $email, $hash, $firstName, $middleName ?: null, $lastName, $contact ?: null, $profilePicture]);
            $userId = (int)$db->lastInsertId();

            $stmt = $db->prepare(
                "INSERT INTO teacher_registration_requests (user_id, requested_role, teacher_id_number, requested_sections, status)
                 VALUES (?, 'guidance', ?, NULL, 'pending')"
            );
            $stmt->execute([$userId, $staffId ?: null]);

            $db->commit();
            log_activity($userId, 'Submitted guidance counselor account request', 'auth');
            set_flash('success', 'Guidance Counselor account request submitted! You can log in after the administrator approves your account.');
            redirect('login.php');
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('Guidance registration failed: ' . $e->getMessage());
            $errors[] = 'Something went wrong while creating your request. Please try again.';
        }
    }
}

$pageTitle = 'Guidance Counselor Registration';
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
      <div class="auth-icon"><i class="bi bi-heart-pulse-fill"></i></div>
      <h2 class="mb-2">BPC School Clinic</h2>
      <p class="mb-0">Register as a Guidance Counselor to receive student support referrals and coordinate with the clinic on student well-being.</p>
    </div>
    <div class="auth-form-side">
      <h4 class="mb-1">Guidance Counselor Registration</h4>
      <p class="text-muted mb-3">All fields marked * are required</p>

      <?php if ($errors): ?>
        <div class="alert alert-danger py-2 small d-flex align-items-center gap-2">
          <i class="bi bi-exclamation-triangle-fill"></i>
          <span><?= implode('<br>', array_map('e', $errors)) ?></span>
        </div>
      <?php endif; ?>
      <?php if ($flash = get_flash()): ?>
        <div class="alert alert-<?= e($flash['type']) ?> py-2 small d-flex align-items-center gap-2">
          <i class="bi bi-<?= $flash['type'] === 'success' ? 'check-circle-fill' : 'info-circle-fill' ?>"></i>
          <span><?= e($flash['message']) ?></span>
        </div>
      <?php endif; ?>

      <form method="POST" novalidate enctype="multipart/form-data" id="guidanceRegForm">
        <?= csrf_field() ?>

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
        </div>

        <h6 class="fw-semibold mb-2" style="font-size:0.85rem;color:var(--text-secondary);margin-top:16px;"><i class="bi bi-building me-1"></i> Office Information</h6>
        <div class="row g-2 mb-2">
          <div class="col-md-6">
            <label class="form-label small fw-semibold">Employee / Staff ID *</label>
            <input type="text" name="employee_id" class="form-control" required placeholder="e.g. GC-2026-001" maxlength="50" value="<?= e($old['employee_id'] ?? '') ?>">
          </div>
          <div class="col-md-6">
            <label class="form-label small fw-semibold">Department / Office *</label>
            <input type="text" name="department" class="form-control" required placeholder="e.g. Guidance & Counseling Office" maxlength="120" value="<?= e($old['department'] ?? '') ?>">
          </div>
          <div class="col-md-6">
            <label class="form-label small fw-semibold">Contact Number</label>
            <input type="text" name="contact_number" class="form-control" maxlength="30" value="<?= e($old['contact_number'] ?? '') ?>">
          </div>
          <div class="col-md-6">
            <label class="form-label small fw-semibold">Email Address *</label>
            <input type="email" name="email" class="form-control" required maxlength="120" value="<?= e($old['email'] ?? '') ?>">
          </div>
        </div>

        <h6 class="fw-semibold mb-2" style="font-size:0.85rem;color:var(--text-secondary);margin-top:16px;"><i class="bi bi-shield-lock-fill me-1"></i> Account Information</h6>
        <div class="row g-2 mb-2">
          <div class="col-md-6">
            <label class="form-label small fw-semibold">Username *</label>
            <input type="text" name="username" class="form-control" required minlength="3" maxlength="50" pattern="[A-Za-z0-9_]+" value="<?= e($old['username'] ?? '') ?>">
            <div class="form-text small">Letters, numbers and underscores only.</div>
          </div>
          <div class="col-md-6">
            <label class="form-label small fw-semibold">Profile Picture</label>
            <input type="file" name="profile_picture" class="form-control" accept="image/*">
          </div>
          <div class="col-md-6">
            <label class="form-label small fw-semibold">Password *</label>
            <input type="password" name="password" id="regPassword" class="form-control" required minlength="8">
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

        <button type="submit" class="btn btn-primary w-100 mt-3 mb-2 py-2 fw-semibold" id="submitBtn">Submit Account Request</button>
        <p class="text-center small text-muted mb-0">
          <a href="register_hub.php" class="fw-medium"><i class="bi bi-arrow-left me-1"></i>Back to account types</a>
          &nbsp;·&nbsp; Already have an account? <a href="login.php" class="fw-medium">Sign in</a>
        </p>
      </form>
    </div>
  </div>
</div>
<script>
(function () {
  'use strict';
  const form = document.getElementById('guidanceRegForm');
  const pw = form.querySelector('input[name="password"]');
  const confirmPw = form.querySelector('input[name="confirm_password"]');
  const submitBtn = document.getElementById('submitBtn');

  function validPassword(v) {
    return v.length >= 8 && /[A-Z]/.test(v) && /[a-z]/.test(v) && /[0-9]/.test(v) && /[^A-Za-z0-9]/.test(v);
  }

  form.addEventListener('submit', function (e) {
    if (!validPassword(pw.value)) {
      e.preventDefault();
      pw.classList.add('is-invalid');
      pw.focus();
      return false;
    }
    if (pw.value !== confirmPw.value) {
      e.preventDefault();
      confirmPw.classList.add('is-invalid');
      confirmPw.focus();
      return false;
    }
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Submitting…';
  });

  [pw, confirmPw].forEach(function (el) {
    el.addEventListener('input', function () { el.classList.remove('is-invalid'); });
  });
})();
</script>
<?php include __DIR__ . '/../components/footer.php'; ?>
</body>
</html>
