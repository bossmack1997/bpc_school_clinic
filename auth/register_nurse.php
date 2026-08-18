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
    $email        = trim($_POST['email'] ?? '');
    $employeeId   = trim($_POST['employee_id'] ?? '');
    $licenseNumber = trim($_POST['license_number'] ?? '');
    $username     = trim($_POST['username'] ?? '');
    $password     = $_POST['password'] ?? '';
    $confirm      = $_POST['confirm_password'] ?? '';
    $certify      = isset($_POST['certify']);

    if (!$firstName || !$lastName || !$email || !$username || !$password) {
        $errors[] = 'Please fill in all required fields.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }
    if (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    }
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
    if ($password !== $confirm) {
        $errors[] = 'Passwords do not match.';
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

            $stmt = $db->prepare(
                "INSERT INTO users (role, username, email, password_hash, first_name, middle_name, last_name, gender, date_of_birth, phone, profile_picture, status)
                 VALUES ('nurse', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')"
            );
            $stmt->execute([$username, $email, $hash, $firstName, $middleName, $lastName, $gender, $dateOfBirth, $contactNumber, $profilePicture]);
            $userId = $db->lastInsertId();

            $stmt = $db->prepare(
                "INSERT INTO nurses (user_id, employee_id, license_number) VALUES (?, ?, ?)"
            );
            $stmt->execute([$userId, $employeeId ?: null, $licenseNumber ?: null]);

            $db->commit();
            log_activity($userId, 'Registered new nurse account', 'auth');
            set_flash('success', 'Nurse registration submitted! Your account is pending admin approval.');
            redirect('login.php');
        } catch (Exception $e) {
            $db->rollBack();
            error_log($e->getMessage());
            $errors[] = 'Something went wrong. Please try again.';
        }
    }
}

$pageTitle = 'Register as Nurse';
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
      <div class="auth-icon"><i class="bi bi-person-badge-fill"></i></div>
      <h2 class="mb-2">BPC School Clinic</h2>
      <p class="mb-0">Register as a school nurse to manage student health records, appointments, and clinic operations.</p>
    </div>
    <div class="auth-form-side">
      <h4 class="mb-1">Nurse Registration</h4>
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
            <label class="form-label small fw-semibold">Gender *</label>
            <select name="gender" class="form-select" required>
              <option value="">Select</option>
              <?php foreach (['Male','Female','Other'] as $g): ?>
                <option <?= (($old['gender'] ?? '') === $g) ? 'selected' : '' ?>><?= $g ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label small fw-semibold">Date of Birth *</label>
            <input type="date" name="date_of_birth" class="form-control" required max="<?= date('Y-m-d') ?>" value="<?= e($old['date_of_birth'] ?? '') ?>">
          </div>
          <div class="col-md-6">
            <label class="form-label small fw-semibold">Contact Number *</label>
            <input type="text" name="contact_number" class="form-control" required value="<?= e($old['contact_number'] ?? '') ?>">
          </div>
          <div class="col-md-6">
            <label class="form-label small fw-semibold">Email Address *</label>
            <input type="email" name="email" class="form-control" required value="<?= e($old['email'] ?? '') ?>">
          </div>
        </div>

        <h6 class="fw-semibold mb-2" style="font-size:0.85rem;color:var(--text-secondary);margin-top:16px;"><i class="bi bi-shield-lock-fill me-1"></i> Account Information</h6>
        <div class="row g-2 mb-2">
          <div class="col-md-6">
            <label class="form-label small fw-semibold">Employee ID</label>
            <input type="text" name="employee_id" class="form-control" placeholder="e.g. EMP-001" value="<?= e($old['employee_id'] ?? '') ?>">
          </div>
          <div class="col-md-6">
            <label class="form-label small fw-semibold">PRC License Number</label>
            <input type="text" name="license_number" class="form-control" placeholder="Optional" value="<?= e($old['license_number'] ?? '') ?>">
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

        <h6 class="fw-semibold mb-2" style="font-size:0.85rem;color:var(--text-secondary);margin-top:16px;"><i class="bi bi-briefcase-fill me-1"></i> Employment & Uploads</h6>
        <div class="row g-2">
          <div class="col-md-6">
            <label class="form-label small fw-semibold">Position</label>
            <input type="text" class="form-control" value="School Nurse" readonly>
          </div>
          <div class="col-md-6">
            <label class="form-label small fw-semibold">Department</label>
            <input type="text" class="form-control" value="School Clinic" readonly>
          </div>
          <div class="col-md-6">
            <label class="form-label small fw-semibold">Profile Picture</label>
            <input type="file" name="profile_picture" class="form-control" accept="image/*">
          </div>
          <div class="col-md-6">
            <label class="form-label small fw-semibold">Valid ID</label>
            <input type="file" name="valid_id" class="form-control" accept="image/*,.pdf">
          </div>
        </div>

        <div class="form-check mt-3">
          <input class="form-check-input" type="checkbox" name="certify" id="certify" required>
          <label class="form-check-label small" for="certify">I certify that the information above is true and accurate.</label>
        </div>

        <button type="submit" class="btn btn-primary w-100 mt-3 mb-2 py-2 fw-semibold">Register Nurse Account</button>
        <p class="text-center small text-muted mb-0">Already have an account? <a href="login.php" class="fw-medium">Sign in</a></p>
      </form>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../components/footer.php'; ?>
</body>
</html>
