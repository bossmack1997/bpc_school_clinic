<?php
/**
 * setup/initial_admin.php
 * ------------------------------------------------------------------
 * Initial Primary Administrator Setup
 * AI-Enhanced School Clinic Management System for Bulacan Polytechnic College
 * Healthcare Service Improvement
 * ------------------------------------------------------------------
 */
require_once __DIR__ . '/../config/config.php';

// Prevent caching
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');

$db = getDB();

// Database-driven lock: If an active Primary Admin already exists, lockout setup immediately
if (has_active_primary_admin($db)) {
    set_flash('info', 'Initial administrator setup has already been completed. Please sign in with your credentials.');
    redirect(APP_URL . '/auth/login.php');
}

// Bootstrap requires an operator-held secret; lack of an active account is not authorization.
if ((int)$db->query("SELECT COUNT(*) FROM users WHERE role='admin' AND admin_type='primary_admin'")->fetchColumn()>0) {
    http_response_code(403);exit('An administrator already exists. Use the authorized operator account-recovery procedure.');
}
$setupSecret=(string)getenv('INITIAL_ADMIN_SETUP_TOKEN');
if(strlen($setupSecret)<32){http_response_code(503);exit('Initial provisioning requires an operator-configured INITIAL_ADMIN_SETUP_TOKEN of at least 32 characters.');}

$errors = [];
$old = $_POST ?? [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_die();
    if(!is_string($_POST['setup_token']??null)||!hash_equals($setupSecret,$_POST['setup_token'])){http_response_code(403);exit('Authorized provisioning token required.');}

    // Secondary pre-check
    if (has_active_primary_admin($db)) {
        set_flash('danger', 'Initial setup has already been configured by another administrator.');
        redirect(APP_URL . '/auth/login.php');
    }

    $firstName    = trim($_POST['first_name'] ?? '');
    $middleName   = trim($_POST['middle_name'] ?? '');
    $lastName     = trim($_POST['last_name'] ?? '');
    $email        = strtolower(trim($_POST['email'] ?? ''));
    $employeeId   = trim($_POST['employee_id'] ?? '');
    $username     = trim($_POST['username'] ?? '');
    $password     = $_POST['password'] ?? '';
    $confirmPass  = $_POST['confirm_password'] ?? '';
    $contact      = trim($_POST['contact_number'] ?? '');
    $certified    = isset($_POST['certification_agreement']);

    // Validation
    if ($firstName === '' || $lastName === '') {
        $errors[] = 'First name and Last name are required.';
    }

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'A valid institutional email address is required.';
    }

    if ($username === '' || strlen($username) < 4) {
        $errors[] = 'Username must be at least 4 characters long and contain only letters, numbers, and underscores.';
    } elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
        $errors[] = 'Username may only contain alphanumeric characters and underscores.';
    }

    if (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters long.';
    } elseif ($password !== $confirmPass) {
        $errors[] = 'Passwords do not match.';
    }

    if (!$certified) {
        $errors[] = 'You must certify that you are authorized to configure the primary institutional administrator account.';
    }

    // Check unique email & username
    if (empty($errors)) {
        $stmt = $db->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $errors[] = 'This email address is already in use by another account.';
        }

        $stmt = $db->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
        $stmt->execute([$username]);
        if ($stmt->fetch()) {
            $errors[] = 'This username is already taken. Please choose a different username.';
        }
    }

    if (empty($errors)) {
        try {
            $db->beginTransaction();

            // Atomic race condition lock: Re-check inside locked transaction
            $lockStmt = $db->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND admin_type = 'primary_admin' AND status = 'active' FOR UPDATE");
            $existingPrimary = (int)$lockStmt->fetchColumn();

            if ($existingPrimary > 0) {
                $db->rollBack();
                set_flash('danger', 'Primary administrator setup was just completed by another session.');
                redirect(APP_URL . '/auth/login.php');
            }

            $passwordHash = password_hash($password, PASSWORD_DEFAULT);

            $insertStmt = $db->prepare("
                INSERT INTO users (
                    role, admin_type, username, email, password_hash,
                    first_name, middle_name, last_name, phone,
                    status, email_verified, registration_status,
                    validation_reason, validation_confidence, risk_level
                ) VALUES (
                    'admin', 'primary_admin', ?, ?, ?,
                    ?, ?, ?, ?,
                    'active', 1, 'approved',
                    'Initial Primary Administrator Setup', 1.00, 'low'
                )
            ");

            $insertStmt->execute([
                $username,
                $email,
                $passwordHash,
                $firstName,
                $middleName ?: null,
                $lastName,
                $contact ?: null
            ]);

            $newAdminId = (int)$db->lastInsertId();

            $db->commit();

            // Log initialization in activity audit
            log_activity(
                $newAdminId,
                'Initial Primary Administrator setup completed',
                'setup',
                "Office/ID: " . ($employeeId ?: 'N/A') . " | Primary Admin: $username ($email)"
            );

            set_flash('success', 'Initial Primary Administrator account created successfully! You may now sign in.');
            redirect(APP_URL . '/auth/login.php');

        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log('Initial admin setup transaction error: ' . $e->getMessage());
            $errors[] = 'An error occurred while creating the Primary Administrator account. Please try again.';
        }
    }
}

$pageTitle = 'Initial Primary Administrator Setup';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php include __DIR__ . '/../includes/head.php'; ?>
<style>
  .setup-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: rgba(14, 122, 62, 0.12);
    color: var(--primary, #0E7A3E);
    border: 1px solid rgba(14, 122, 62, 0.25);
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 0.8rem;
    font-weight: 600;
  }
</style>
</head>
<body>
<div class="auth-wrapper">
  <div class="auth-card" style="max-width:980px;" data-aos="fade-up" data-aos-duration="700">
    
    <!-- Branding Side -->
    <div class="auth-side">
      <div class="auth-icon">
        <img src="<?= APP_URL ?>/assets/images/bpc_logo.png" alt="BPC Clinic Logo" style="width:70px;height:70px;object-fit:contain;">
      </div>
      <div class="setup-badge mb-3">
        <i class="bi bi-shield-lock-fill"></i> One-Time System Initialization
      </div>
      <h2 class="mb-2">AI-Enhanced School Clinic Management System for Bulacan Polytechnic College Healthcare Service Improvement</h2>
      <p class="mb-3">Welcome to the initial system setup. This wizard establishes the root <strong>Primary Administrator</strong> account for Bulacan Polytechnic College School Clinic.</p>
      
      <div class="p-3 rounded-3" style="background: rgba(255,255,255,0.12); border: 1px solid rgba(255,255,255,0.2);">
        <h6 class="text-white fw-bold mb-2"><i class="bi bi-info-circle-fill me-1"></i> Security Protocol</h6>
        <ul class="list-unstyled small mb-0 opacity-90">
          <li class="mb-1"><i class="bi bi-check2-circle me-1"></i> Only one Primary Administrator can be established.</li>
          <li class="mb-1"><i class="bi bi-check2-circle me-1"></i> Subsequent administrators register as Co-Admins subject to institutional review.</li>
          <li><i class="bi bi-check2-circle me-1"></i> This setup wizard automatically locks permanently upon completion.</li>
        </ul>
      </div>
    </div>

    <!-- Setup Form Side -->
    <div class="auth-form-side">
      <div class="d-flex justify-content-between align-items-center mb-1">
        <h4 class="mb-0">Primary Administrator Setup</h4>
        <span class="badge bg-primary">Setup Mode</span>
      </div>
      <p class="text-muted small mb-4">Establish the master institutional administrator account</p>

      <?php if (!empty($errors)): ?>
        <div class="alert alert-danger py-2 small d-flex align-items-start gap-2 mb-3">
          <i class="bi bi-exclamation-triangle-fill mt-1 flex-shrink-0"></i>
          <div>
            <?= implode('<br>', array_map('e', $errors)) ?>
          </div>
        </div>
      <?php endif; ?>

      <?php if ($flash = get_flash()): ?>
        <div class="alert alert-<?= e($flash['type']) ?> py-2 small d-flex align-items-center gap-2 mb-3">
          <i class="bi bi-<?= $flash['type'] === 'success' ? 'check-circle-fill' : 'info-circle-fill' ?>"></i>
          <span><?= e($flash['message']) ?></span>
        </div>
      <?php endif; ?>

      <form method="POST" novalidate id="primaryAdminSetupForm">
        <?= csrf_field() ?><div class="mb-3"><label for="setup-token" class="form-label">Operator provisioning token</label><input type="password" id="setup-token" name="setup_token" class="form-control" required autocomplete="off"></div>

        <div class="row g-2 mb-3">
          <div class="col-md-5">
            <label class="form-label small fw-semibold" for="first_name">First Name <span class="text-danger">*</span></label>
            <input type="text" class="form-control form-control-sm" id="first_name" name="first_name" value="<?= e($old['first_name'] ?? '') ?>" required placeholder="e.g. Juan">
          </div>
          <div class="col-md-3">
            <label class="form-label small fw-semibold" for="middle_name">Middle Name</label>
            <input type="text" class="form-control form-control-sm" id="middle_name" name="middle_name" value="<?= e($old['middle_name'] ?? '') ?>" placeholder="Optional">
          </div>
          <div class="col-md-4">
            <label class="form-label small fw-semibold" for="last_name">Last Name <span class="text-danger">*</span></label>
            <input type="text" class="form-control form-control-sm" id="last_name" name="last_name" value="<?= e($old['last_name'] ?? '') ?>" required placeholder="e.g. Dela Cruz">
          </div>
        </div>

        <div class="row g-2 mb-3">
          <div class="col-md-7">
            <label class="form-label small fw-semibold" for="email">Institutional Email Address <span class="text-danger">*</span></label>
            <div class="input-group input-group-sm">
              <span class="input-group-text"><i class="bi bi-envelope-fill"></i></span>
              <input type="email" class="form-control" id="email" name="email" value="<?= e($old['email'] ?? '') ?>" required placeholder="admin@bpc.edu.ph">
            </div>
            <div class="form-text small" style="font-size:0.75rem;">Official administrative email for communications.</div>
          </div>
          <div class="col-md-5">
            <label class="form-label small fw-semibold" for="employee_id">Admin Office / Employee ID</label>
            <div class="input-group input-group-sm">
              <span class="input-group-text"><i class="bi bi-person-badge"></i></span>
              <input type="text" class="form-control" id="employee_id" name="employee_id" value="<?= e($old['employee_id'] ?? '') ?>" placeholder="e.g. ADM-2026-001">
            </div>
          </div>
        </div>

        <div class="row g-2 mb-3">
          <div class="col-md-6">
            <label class="form-label small fw-semibold" for="username">Admin Username <span class="text-danger">*</span></label>
            <div class="input-group input-group-sm">
              <span class="input-group-text"><i class="bi bi-person-fill"></i></span>
              <input type="text" class="form-control" id="username" name="username" value="<?= e($old['username'] ?? '') ?>" required placeholder="primary_admin">
            </div>
          </div>
          <div class="col-md-6">
            <label class="form-label small fw-semibold" for="contact_number">Contact Number</label>
            <div class="input-group input-group-sm">
              <span class="input-group-text"><i class="bi bi-telephone-fill"></i></span>
              <input type="text" class="form-control" id="contact_number" name="contact_number" value="<?= e($old['contact_number'] ?? '') ?>" placeholder="09123456789">
            </div>
          </div>
        </div>

        <div class="row g-2 mb-3">
          <div class="col-md-6">
            <label class="form-label small fw-semibold" for="password">Password <span class="text-danger">*</span></label>
            <div class="input-group input-group-sm">
              <span class="input-group-text"><i class="bi bi-lock-fill"></i></span>
              <input type="password" class="form-control" id="password" name="password" required minlength="8" placeholder="At least 8 characters">
            </div>
          </div>
          <div class="col-md-6">
            <label class="form-label small fw-semibold" for="confirm_password">Confirm Password <span class="text-danger">*</span></label>
            <div class="input-group input-group-sm">
              <span class="input-group-text"><i class="bi bi-lock-fill"></i></span>
              <input type="password" class="form-control" id="confirm_password" name="confirm_password" required minlength="8" placeholder="Repeat password">
            </div>
          </div>
        </div>

        <div class="mb-3 p-2 rounded bg-light border">
          <div class="form-check small">
            <input class="form-check-input" type="checkbox" id="certification_agreement" name="certification_agreement" <?= isset($old['certification_agreement']) ? 'checked' : '' ?> required>
            <label class="form-check-label fw-semibold" for="certification_agreement">
              I certify that I am authorized by Bulacan Polytechnic College to create and manage the primary institutional administrator account.
            </label>
          </div>
        </div>

        <button type="submit" class="btn btn-primary w-100 py-2 fw-semibold">
          <i class="bi bi-shield-check me-1"></i> Complete Primary Admin Setup
        </button>
      </form>

      <div class="text-center mt-3 small text-muted">
        Already have accounts configured? <a href="<?= APP_URL ?>/auth/login.php" class="fw-medium">Go to Sign In</a>
      </div>
    </div>

  </div>
</div>
<script>AOS.init({ once: true, duration: 700 });</script>
<?php include __DIR__ . '/../components/footer.php'; ?>
</body>
</html>

