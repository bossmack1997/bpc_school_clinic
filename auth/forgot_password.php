<?php
require_once __DIR__ . '/../config/config.php';

// Prevent caching of authentication pages
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');

if (is_logged_in()) {
    redirect(dashboard_url_for(current_user()['role']));
}

$sent = false;
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_die();

    // Password reset rate limiting — prevent abuse without revealing email existence.
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $resetLockout = reset_rate_limit_check($clientIp);
    if ($resetLockout > 0) {
        $minutes = ceil($resetLockout / 60);
        $errors[] = "Too many password reset requests. Please try again in {$minutes} minute(s).";
    } else {
        $email = trim($_POST['email'] ?? '');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please enter a valid email address.';
        } else {
            $db = getDB();
            $stmt = $db->prepare("SELECT id, email FROM users WHERE email = ? LIMIT 1");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if ($user) {
                // Generate a secure random token (256-bit)
                $token = bin2hex(random_bytes(32));
                // Store the SHA-256 hash of the token (never the raw token)
                $tokenHash = hash('sha256', $token);
               // Token expires 30 minutes from now.
                  // Use MySQL UTC so token generation and validation use the same clock.
                  $expires = $db->query(
                      "SELECT DATE_ADD(UTC_TIMESTAMP(), INTERVAL 30 MINUTE)"
                  )->fetchColumn();

                $db->prepare("UPDATE users SET reset_token = ?, reset_token_expires = ? WHERE id = ?")
                   ->execute([$tokenHash, $expires, $user['id']]);

                // Reset link contains ONLY the token — no uid (user is found server-side)
                $resetLink = APP_URL . '/auth/reset_password.php?token=' . $token;
                log_activity($user['id'], 'Requested password reset', 'auth');

                // Attempt to send the reset link via email.
                $emailSent = send_password_reset_email($user['email'], $resetLink);
                if (!$emailSent) {
                    // Email is not configured. In local development, store the link
                    // in session so the developer/user can use it for testing.
                    // In production, NEVER expose the raw reset token in HTML.
                    if (defined('APP_ENV') && APP_ENV === 'local') {
                        $_SESSION['demo_reset_link'] = $resetLink;
                    }
                }
            }
            // Always show the generic response — do NOT reveal whether the email exists.
            $sent = true;
            // Record this attempt for rate limiting (prevents flooding).
            reset_rate_limit_record_failure($clientIp);
        }
    }
}

$pageTitle = 'Forgot Password';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php include __DIR__ . '/../includes/head.php'; ?>
</head>
<body>
<div class="auth-wrapper" style="min-height:100vh;">
  <div class="auth-card" style="max-width:480px; grid-template-columns:1fr;" data-aos="fade-up" data-aos-duration="800">
    <div class="auth-form-side">
      <div class="text-center mb-4">
        <div class="auth-icon d-inline-flex mb-3" style="font-size:2rem;color:var(--primary)"><i class="bi bi-key-fill"></i></div>
        <h4 class="mb-1">Forgot Password</h4>
        <p class="text-muted mb-0">Enter your account email and we'll send you a reset link.</p>
      </div>

      <?php if ($errors): ?>
        <div class="alert alert-danger py-2 small d-flex align-items-center gap-2">
          <i class="bi bi-exclamation-triangle-fill"></i>
          <span><?= implode('<br>', array_map('e', $errors)) ?></span>
        </div>
      <?php endif; ?>

      <?php if ($sent): ?>
        <div class="alert alert-success py-2 small d-flex align-items-center gap-2">
          <i class="bi bi-check-circle-fill"></i>
          <span>If an account exists for that email, a password reset link has been sent.</span>
        </div>
        <?php if (defined('APP_ENV') && APP_ENV === 'local' && !empty($_SESSION['demo_reset_link'])): ?>
          <div class="alert alert-warning py-2 small">
            <strong>Demo mode</strong> (email not yet configured): <br>
            <a href="<?= e($_SESSION['demo_reset_link']) ?>" class="text-break"><?= e($_SESSION['demo_reset_link']) ?></a>
          </div>
          <?php unset($_SESSION['demo_reset_link']); ?>
        <?php endif; ?>
        <a href="login.php" class="btn btn-primary w-100 py-2 fw-semibold"><i class="bi bi-arrow-left me-1"></i> Back to Login</a>
      <?php else: ?>
        <form method="POST">
          <?= csrf_field() ?>
          <div class="mb-3">
            <label class="form-label fw-semibold">Email Address</label>
            <div class="input-group">
              <span class="input-group-text bg-light border-end-0"><i class="bi bi-envelope"></i></span>
              <input type="email" name="email" class="form-control border-start-0 ps-0" required autofocus placeholder="Enter your email">
            </div>
          </div>
          <button type="submit" class="btn btn-primary w-100 mb-2 py-2 fw-semibold">Send Reset Link</button>
          <a href="login.php" class="btn btn-link w-100 text-decoration-none">Back to Login</a>
        </form>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../components/footer.php'; ?>
</body>
</html>