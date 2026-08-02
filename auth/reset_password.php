<?php
require_once __DIR__ . '/../config/config.php';

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

    if (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    } elseif ($password !== $confirm) {
        $errors[] = 'Passwords do not match.';
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
          <button type="submit" class="btn btn-primary w-100">Reset Password</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../components/footer.php'; ?>
</body>
</html>
