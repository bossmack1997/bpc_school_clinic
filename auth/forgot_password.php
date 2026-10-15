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
        $email = strtolower(trim($_POST['email'] ?? ''));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please enter a valid email address.';
        } else {
            $db = getDB();
            $stmt = $db->prepare("SELECT id, email FROM users WHERE LOWER(email) = LOWER(?) LIMIT 1");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if ($user) {
                // Ensure only one valid reset link is active per user at a time.
                $db->beginTransaction();
                try {
                    $db->prepare("UPDATE users SET reset_token = NULL, reset_token_expires = NULL WHERE id = ?")
                        ->execute([$user['id']]);

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

                    $db->commit();

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
                } catch (Throwable $e) {
                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }
                    error_log('Password reset request failed for ' . $email . ': ' . $e->getMessage());
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
<div class="auth-wrapper">
  <div class="auth-card" data-aos="fade-up" data-aos-duration="800">
    <div class="auth-side">
      <div class="auth-icon"><img src="<?= APP_URL ?>/assets/images/bpc_logo.png" alt="BPC Clinic Logo"></div>
      <h2 class="mb-2">Secure access recovery for your clinic account</h2>
      <p class="mb-0">We’ll send a protected reset link to the email address on file so you can regain access to your school clinic portal safely.</p>
    </div>

    <div class="auth-form-side">
      <h4 class="mb-1">Forgot Password</h4>
      <p class="text-muted mb-4">Enter your account email and we’ll send you a reset link.</p>

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
        <div class="d-grid gap-2 mt-3">
          <a href="login.php" class="btn btn-primary py-2 fw-semibold"><i class="bi bi-arrow-left me-1"></i> Back to Login</a>
        </div>
      <?php else: ?>
        <form method="POST">
          <?= csrf_field() ?>
          <div class="mb-3">
            <label class="form-label fw-semibold" for="email">Email Address</label>
            <div class="input-group">
              <span class="input-group-text bg-light border-end-0"><i class="bi bi-envelope"></i></span>
              <input type="email" id="email" name="email" class="form-control border-start-0 ps-0" required autofocus placeholder="Enter your email" value="<?= e($_POST['email'] ?? '') ?>">
            </div>
          </div>
          <button type="submit" class="btn btn-primary w-100 mb-3 py-2 fw-semibold">Send Reset Link</button>
          <div class="text-center pt-2 border-top mt-2">
            <a href="login.php" class="small text-primary fw-medium text-decoration-none">Return to Sign In</a>
          </div>
        </form>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../components/footer.php'; ?>
</body>
</html>