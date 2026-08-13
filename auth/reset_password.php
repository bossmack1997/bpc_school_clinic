<?php
require_once __DIR__ . '/../config/config.php';

// Prevent caching of password reset pages
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');

$token = $_GET['token'] ?? ($_POST['token'] ?? '');
$uid   = $_GET['uid'] ?? ($_POST['uid'] ?? '');
$errors = [];
$valid = false;
$done = false;

if ($token && $uid) {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM users WHERE id = ? AND reset_token IS NOT NULL AND reset_token_expires > NOW() LIMIT 1");
    $stmt->execute([$uid]);
    $user = $stmt->fetch();
    if ($user && hash_equals($user['reset_token'], hash('sha256', $token))) {
        $valid = true;
    }
}

if ($valid && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_die();
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';

    // Enforce strong password policy
    if (strlen($password) < 8) {
      $errors[] = 'Password must be at least 8 characters.';
    } elseif ($password !== $confirm) {
      $errors[] = 'Passwords do not match.';
    } elseif (!preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password) || !preg_match('/[0-9]/', $password) || !preg_match('/[^A-Za-z0-9]/', $password)) {
      $errors[] = 'Password must include uppercase, lowercase, number, and special character.';
    } else {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $db->prepare("UPDATE users SET password_hash = ?, reset_token = NULL, reset_token_expires = NULL WHERE id = ?")
           ->execute([$hash, $uid]);
        log_activity($uid, 'Reset password', 'auth');
        $done = true;
    }
}

$pageTitle = 'Reset Password';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php include __DIR__ . '/../includes/head.php'; ?>
</head>
<body>
<div class="auth-wrapper">
  <div class="auth-card" style="max-width:520px; grid-template-columns:1fr;">
    <div class="auth-form-side">
      <h4 class="mb-3"><i class="bi bi-lock-fill text-success me-1"></i> Reset Password</h4>

      <?php if (!$valid && !$done): ?>
        <div class="alert alert-danger py-2 small">This reset link is invalid or has expired. Please request a new one.</div>
        <a href="forgot_password.php" class="btn btn-primary w-100">Request New Link</a>
      <?php elseif ($done): ?>
        <div class="alert alert-success py-2 small">Your password has been reset successfully.</div>
        <a href="login.php" class="btn btn-primary w-100">Go to Login</a>
      <?php else: ?>
        <?php if ($errors): ?>
          <div class="alert alert-danger py-2 small"><?= implode('<br>', array_map('e', $errors)) ?></div>
        <?php endif; ?>
        <form method="POST">
          <?= csrf_field() ?>
          <input type="hidden" name="token" value="<?= e($token) ?>">
          <input type="hidden" name="uid" value="<?= e($uid) ?>">
          <div class="mb-3">
            <label class="form-label small fw-semibold">New Password</label>
            <input type="password" name="password" class="form-control" required minlength="8">
          </div>
          <div class="mb-3">
            <label class="form-label small fw-semibold">Confirm New Password</label>
            <input type="password" name="confirm_password" class="form-control" required minlength="8">
          </div>
          <button type="submit" class="btn btn-primary w-100" id="resetBtn">Reset Password</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
</div>
<script>
// Enforce client-side password strength on reset
(function () {
  const form = document.querySelector('form');
  if (!form) return;
  const pw = form.querySelector('input[name="password"]');
  const confirm = form.querySelector('input[name="confirm_password"]');
  const btn = document.getElementById('resetBtn');

  function validPassword(v) {
    return v.length >= 8 && /[A-Z]/.test(v) && /[a-z]/.test(v) && /[0-9]/.test(v) && /[^A-Za-z0-9]/.test(v);
  }

  form.addEventListener('submit', function (e) {
    if (!validPassword(pw.value)) {
      e.preventDefault();
      alert('Password must be at least 8 characters and include uppercase, lowercase, number, and special character.');
      pw.focus();
      return false;
    }
    if (pw.value !== confirm.value) {
      e.preventDefault();
      alert('Passwords do not match.');
      confirm.focus();
      return false;
    }
    btn.disabled = true;
    btn.textContent = 'Resetting...';
  });
})();
</script>
<?php include __DIR__ . '/../components/footer.php'; ?>
</body>
</html>
