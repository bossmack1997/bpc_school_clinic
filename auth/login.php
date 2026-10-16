<?php
require_once __DIR__ . '/../config/config.php';

// Prevent caching of login page
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');

if (is_logged_in()) {
    redirect(dashboard_url_for(current_user()['role']));
}

$db = getDB();

// If no active Primary Administrator exists in database, direct to initial setup wizard
if (!has_active_primary_admin($db)) {
    set_flash('info', 'No active Primary Administrator found. Please complete the initial system setup.');
    redirect(APP_URL . '/setup/initial_admin.php');
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_die();

    $identifier = trim($_POST['identifier'] ?? '');
    $password   = $_POST['password'] ?? '';
    $remember   = isset($_POST['remember']);

    if ($identifier === '' || $password === '') {
        $errors[] = 'Please enter your username/email and password.';
    } else {
        $clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $lockoutRemaining = login_rate_limit_check($clientIp);

        if ($lockoutRemaining > 0) {
            $minutes = ceil($lockoutRemaining / 60);
            $errors[] = "Too many failed attempts. Please try again in {$minutes} minute(s).";
        } else {
            $db = getDB();
            $stmt = $db->prepare("SELECT * FROM users WHERE (username = ? OR email = ?) LIMIT 1");
            $stmt->execute([$identifier, $identifier]);
            $user = $stmt->fetch();

            if (!$user || !password_verify($password, $user['password_hash'])) {
                login_rate_limit_record_failure($clientIp, $identifier);
                $errors[] = 'Invalid username or password.';
            } elseif ($user['status'] !== 'active') {
                login_rate_limit_clear($clientIp);
                $errors[] = 'Your account is not active yet. Please contact the system administrator.';
            } elseif (!is_supported_role($user['role'])) {
                // Never permit retired or un-migrated roles to receive a session.
                $errors[] = 'This account role is not available. Please contact the system administrator.';
            } else {
                login_user($user);
                login_rate_limit_clear($clientIp);
                login_rate_limit_reset_user($user['id']);
                $db->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")->execute([$user['id']]);
                log_activity($user['id'], 'Logged in', 'auth');

                if ($remember) {
                    set_remember_me_cookie($user['id']);
                }

                $_SESSION['login_success'] = true;
                redirect(dashboard_url_for($user['role']));
            }
        }
    }
}

$pageTitle = 'Login';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php include __DIR__ . '/../includes/head.php'; ?>
</head>
<body>
<div class="auth-wrapper">
  <div class="auth-card" data-aos="fade-up" data-aos-duration="800">
    <div class="auth-side">
      <div class="auth-icon"><img class="auth-logo" src="<?= APP_URL ?>/assets/images/bpc_logo.png" alt="BPC Clinic Logo"></div>
      <h1 class="mb-2">AI-Enhanced School Clinic Management System for Bulacan Polytechnic College Healthcare Service Improvement</h1>
      <p class="mb-0">Secure digital health records, clinic services, emergency response, and health monitoring for Bulacan Polytechnic College.</p>
    </div>
    <div class="auth-form-side">
      <h4 class="mb-1">Welcome back</h4>
      <p class="text-muted mb-4">Sign in to your clinic account</p>

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

      <form method="POST" novalidate id="loginForm">
        <?= csrf_field() ?>
        <div class="mb-3">
          <label class="form-label fw-semibold" for="identifier">Username or Email</label>
          <div class="input-group has-validation">
            <span class="input-group-text bg-light border-end-0"><i class="bi bi-person"></i></span>
            <input type="text" name="identifier" id="identifier" class="form-control border-start-0 ps-0" required autofocus value="<?= e($_POST['identifier'] ?? '') ?>" placeholder="Enter your username or email" aria-label="Username or Email" aria-describedby="identifierError">
            <div class="invalid-feedback" id="identifierError">Please enter your username or email.</div>
          </div>
        </div>
        <div class="mb-3">
          <label class="form-label fw-semibold" for="password">Password</label>
          <div class="input-group has-validation">
            <span class="input-group-text bg-light border-end-0"><i class="bi bi-lock"></i></span>
            <input type="password" name="password" id="password" class="form-control border-start-0 ps-0" required placeholder="Enter your password" aria-label="Password" aria-describedby="passwordError">
            <button class="btn btn-outline-secondary px-3" type="button" id="pwToggle" aria-label="Show password" aria-pressed="false"><i class="bi bi-eye" id="pwIcon"></i></button>
            <div class="invalid-feedback" id="passwordError">Please enter your password.</div>
          </div>
        </div>
        <div class="text-muted small mt-1 mb-2" id="capsLockHint" style="display:none;color:var(--warning);"><i class="bi bi-exclamation-circle-fill me-1"></i>Caps Lock is ON</div>
        <div class="d-flex justify-content-between align-items-center mb-3">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="remember" id="remember">
            <label class="form-check-label small" for="remember">Remember me</label>
          </div>
          <a href="forgot_password.php" class="small text-primary fw-medium">Forgot password?</a>
        </div>
        <button type="submit" class="btn btn-primary w-100 mb-3 py-2 fw-semibold" id="loginBtn">
          <span class="btn-label">Sign In</span>
          <span class="spinner-border spinner-border-sm d-none" role="status" aria-hidden="true"></span>
        </button>
        <div class="text-center mt-3 pt-2 border-top">
          <p class="small text-muted mb-2">Don't have an account?</p>
          <div class="d-flex justify-content-center gap-2 flex-wrap mb-2">
            <a href="register.php?role=student" class="btn btn-sm btn-outline-primary"><i class="bi bi-mortarboard me-1"></i> Student</a>
            <a href="register.php?role=teacher" class="btn btn-sm btn-outline-info"><i class="bi bi-person-workspace me-1"></i> Teacher</a>
            <a href="register.php?role=nurse" class="btn btn-sm btn-outline-success"><i class="bi bi-person-badge-fill me-1"></i> Nurse</a>
          </div>
          <p class="small text-muted mb-0"><a href="register.php" class="text-secondary text-decoration-none fw-medium"><i class="bi bi-person-plus-fill me-1"></i>Create New Account</a></p>
        </div>
      </form>
    </div>
  </div>
</div>
<script>
(function () {
  'use strict';

  // ---- Password Toggle (single handler) ----
  const pwInput = document.getElementById('password');
  const pwBtn = document.getElementById('pwToggle');
  const pwIcon = document.getElementById('pwIcon');

  pwBtn.addEventListener('click', function () {
    const type = pwInput.type === 'password' ? 'text' : 'password';
    pwInput.type = type;
    pwIcon.classList.toggle('bi-eye', type === 'password');
    pwIcon.classList.toggle('bi-eye-slash', type === 'text');
    pwBtn.setAttribute('aria-pressed', type === 'text');
    pwBtn.setAttribute('aria-label', type === 'text' ? 'Hide password' : 'Show password');
    pwInput.focus();
  });

  // ---- Client-side validation with loading state ----
  const form = document.getElementById('loginForm');
  const loginBtn = document.getElementById('loginBtn');
  const btnLabel = loginBtn.querySelector('.btn-label');
  const spinner = loginBtn.querySelector('.spinner-border');

  form.addEventListener('submit', function (e) {
    const identifier = document.getElementById('identifier');
    const password = document.getElementById('password');
    let valid = true;

    [identifier, password].forEach(function (el) {
      el.classList.remove('is-invalid');
    });

    if (!identifier.value.trim()) {
      identifier.classList.add('is-invalid');
      valid = false;
    }
    if (!password.value) {
      password.classList.add('is-invalid');
      valid = false;
    }

    if (!valid) {
      e.preventDefault();
      e.stopPropagation();
      const firstInvalid = form.querySelector('.is-invalid');
      if (firstInvalid) firstInvalid.focus();
      return;
    }

    // Loading state
    loginBtn.disabled = true;
    btnLabel.textContent = 'Signing in...';
    spinner.classList.remove('d-none');
    loginBtn.classList.add('btn-loading');
  });

  // Clear invalid state when user types
  [pwInput, document.getElementById('identifier')].forEach(function (el) {
    el.addEventListener('input', function () {
      el.classList.remove('is-invalid');
    });
  });
})();
</script>
<script>
// Caps lock hint for login password field
(function () {
  const pw = document.getElementById('password');
  const capsHint = document.getElementById('capsLockHint');

  if (pw && capsHint) {
    pw.addEventListener('keyup', function (e) {
      if (e.getModifierState && e.getModifierState('CapsLock')) {
        capsHint.style.display = '';
      } else {
        capsHint.style.display = 'none';
      }
    });
    pw.addEventListener('blur', function () {
      capsHint.style.display = 'none';
    });
  }
})();
</script>
<?php include __DIR__ . '/../components/footer.php'; ?>
</body>
</html>
