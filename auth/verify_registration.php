<?php
/**
 * auth/verify_registration.php
 * ------------------------------------------------------------------
 * Institutional Email Ownership Verification (OTP)
 * AI-Enhanced School Clinic Management System for Bulacan Polytechnic College
 * Healthcare Service Improvement
 * ------------------------------------------------------------------
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/ai/MICARegistrationValidator.php';
require_once __DIR__ . '/../includes/mail.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');

if (is_logged_in()) {
    redirect(dashboard_url_for(current_user()['role']));
}

$db = getDB();
$validator = new MICARegistrationValidator($db);

$userId = (int)($_SESSION['pending_verification_user_id'] ?? $_REQUEST['uid'] ?? 0);
if ($userId <= 0) {
    set_flash('info', 'No pending registration found to verify. Please register or sign in.');
    redirect('login.php');
}

$stmt = $db->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    set_flash('danger', 'User account not found.');
    redirect('login.php');
}

if ($user['email_verified'] && $user['status'] === 'active') {
    set_flash('success', 'Your account is already verified and active. Please sign in.');
    redirect('login.php');
}

$errors = [];
$successMsg = '';

// Handle Resend OTP or Verify OTP
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_die();

    $action = $_POST['action'] ?? 'verify';

    if ($action === 'resend') {
        $resendResult = $validator->resendOtp($userId);
        if ($resendResult['status'] === 'sent') {
            $successMsg = $resendResult['message'];
        } else {
            $errors[] = $resendResult['message'];
        }
    } elseif ($action === 'verify') {
        $otp = trim($_POST['otp'] ?? '');
        if (strlen($otp) !== 6 || !ctype_digit($otp)) {
            $errors[] = 'Please enter a valid 6-digit verification code.';
        } else {  
            $verifyResult = $validator->verifyOtp($userId, $otp);

            if ($verifyResult['status'] === 'verified') {
                // Refresh user record
                $stmt->execute([$userId]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);

                // Auto-approve students after OTP verification (no admin review needed)
                // Staff (nurse, teacher, admin) still require admin review
                $regStatus = $user['registration_status'] ?? 'review';
                $isStudent = ($user['role'] === 'student');

                // Check for Initial Admin Setup: If zero active primary administrators exist, auto-activate as primary admin
                if ($user['role'] === 'admin') {
                    $hasPrimary = has_active_primary_admin($db);
                    if (!$hasPrimary) {
                        $regStatus = 'approved';
                        $db->prepare("UPDATE users SET registration_status = 'approved', admin_type = 'primary_admin' WHERE id = ?")->execute([$userId]);
                    } else {
                        $regStatus = 'review';
                        $db->prepare("UPDATE users SET registration_status = 'review', admin_type = 'co_admin' WHERE id = ?")->execute([$userId]);
                    }
                } elseif ($isStudent) {
                    // Students: Auto-approve after email verification
                    $regStatus = 'approved';
                    $db->prepare("UPDATE users SET registration_status = 'approved' WHERE id = ?")->execute([$userId]);
                }

                if ($regStatus === 'approved') {
                    // Auto-activate account
                    $db->prepare("UPDATE users SET status = 'active' WHERE id = ?")->execute([$userId]);

                    if ($user['role'] === 'student') {
                        try {
                            $stmtSt = $db->prepare("SELECT id FROM students WHERE user_id = ? LIMIT 1");
                            $stmtSt->execute([$userId]);
                            $studentId = $stmtSt->fetchColumn();
                            if ($studentId) {
                                ensure_student_qr($db, (int)$studentId, $userId);
                            }
                        } catch (Exception $e) {
                            error_log('QR generation on verified registration: ' . $e->getMessage());
                        }
                    }

                    $msg = ($user['role'] === 'admin' && isset($hasPrimary) && !$hasPrimary)
                        ? 'Initial System Administrator verified and activated! You may now sign in.'
                        : 'Institutional email verified! Your account is active. You may now sign in.';

                    log_activity($userId, 'Verified institutional email ownership & activated account', 'auth');
                    unset($_SESSION['pending_verification_user_id']);
                    unset($_SESSION['demo_registration_otp']);

                    set_flash('success', $msg);
                    redirect('login.php');

                } elseif ($regStatus === 'review') {
                    // Place into Admin Review Queue
                    $db->prepare("UPDATE users SET status = 'pending' WHERE id = ?")->execute([$userId]);

                    log_activity($userId, 'Verified institutional email ownership (account queued for administrative review)', 'auth');
                    unset($_SESSION['pending_verification_user_id']);
                    unset($_SESSION['demo_registration_otp']);

                    set_flash('info', 'Institutional email verified! Your account registration is undergoing clinic administrative review. You will be able to sign in once approved.');
                    redirect('login.php');

                } else {
                    $rejectionReason = $user['rejection_reason'] ?: 'Account failed institutional verification.';
                    $db->prepare("UPDATE users SET status = 'rejected' WHERE id = ?")->execute([$userId]);
                    unset($_SESSION['pending_verification_user_id']);
                    set_flash('danger', 'Registration rejected: ' . e($rejectionReason));
                    redirect('login.php');
                }
            } else {
                $errors[] = $verifyResult['message'];
            }
        }
    }
}

// Mask email for privacy (e.g. j***z@bpc.edu.ph)
$email = $user['email'];
$parts = explode('@', $email);
$local = $parts[0] ?? '';
$domain = $parts[1] ?? '';
$maskedLocal = strlen($local) <= 2 ? $local . '***' : substr($local, 0, 1) . '***' . substr($local, -1);
$maskedEmail = $maskedLocal . '@' . $domain;

$pageTitle = 'Verify Email Ownership';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php include __DIR__ . '/../includes/head.php'; ?>
</head>
<body>
<div class="auth-wrapper">
  <div class="auth-card auth-card--compact" data-aos="fade-up" data-aos-duration="800">
    <div class="auth-form-side p-4 p-md-5">
      <div class="text-center mb-4">
        <div class="auth-icon auth-icon--compact d-inline-flex mb-3"><i class="bi bi-shield-check"></i></div>
        <h4 class="mb-1 fw-bold">Verify Institutional Email</h4>
        <p class="text-muted small mb-0">We sent a 6-digit One-Time Password (OTP) to:</p>
        <p class="fw-bold text-dark mt-1 mb-0"><i class="bi bi-envelope-check text-success me-1"></i><?= e($maskedEmail) ?></p>
      </div>

      <?php if ($errors): ?>
        <div class="alert alert-danger py-2 small d-flex align-items-center gap-2 mb-3">
          <i class="bi bi-exclamation-triangle-fill fs-5"></i>
          <span><?= implode('<br>', array_map('e', $errors)) ?></span>
        </div>
      <?php endif; ?>

      <?php if ($successMsg): ?>
        <div class="alert alert-success py-2 small d-flex align-items-center gap-2 mb-3">
          <i class="bi bi-check-circle-fill fs-5"></i>
          <span><?= e($successMsg) ?></span>
        </div>
      <?php endif; ?>

      <?php if (defined('APP_ENV') && APP_ENV === 'local' && !empty($_SESSION['demo_registration_otp'])): ?>
        <div class="alert alert-warning py-2 small mb-3">
          <i class="bi bi-info-circle-fill me-1"></i><strong>Local Dev Demo OTP:</strong> <code><?= e($_SESSION['demo_registration_otp']) ?></code>
        </div>
      <?php endif; ?>

      <form method="POST" id="verifyForm">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="verify">
        <input type="hidden" name="uid" value="<?= $userId ?>">

        <div class="mb-4 text-center">
          <label class="form-label small fw-semibold text-muted mb-2">Enter 6-Digit Code</label>
          <input type="text" name="otp" id="otpInput" class="form-control app-otp" required maxlength="6" pattern="[0-9]{6}" inputmode="numeric" autocomplete="one-time-code" autofocus placeholder="------">
          <div class="form-text small mt-2">Code expires in <span id="countdownTimer" class="fw-bold text-danger">10:00</span></div>
        </div>

        <button type="submit" class="btn btn-primary w-100 py-2 fw-semibold mb-3" id="verifyBtn">
          <i class="bi bi-check2-circle me-1"></i> Verify &amp; Complete Registration
        </button>
      </form>

      <div class="d-flex justify-content-between align-items-center pt-3 border-top">
        <form method="POST" id="resendForm">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="resend">
          <input type="hidden" name="uid" value="<?= $userId ?>">
          <button type="submit" class="btn btn-link btn-sm text-decoration-none p-0 text-muted" id="resendBtn">
            <i class="bi bi-arrow-clockwise me-1"></i> Didn't receive code? Resend
          </button>
        </form>
        <a href="login.php" class="small text-secondary text-decoration-none">Back to Login</a>
      </div>
    </div>
  </div>
</div>

<script>
(function() {
  'use strict';
  const otpInput = document.getElementById('otpInput');
  if (otpInput) {
    otpInput.addEventListener('input', function(e) {
      this.value = this.value.replace(/[^0-9]/g, '').slice(0, 6);
    });
  }

  // 10-minute visual countdown timer
  let remaining = 600;
  const timerEl = document.getElementById('countdownTimer');
  const timer = setInterval(() => {
    remaining--;
    if (remaining <= 0) {
      clearInterval(timer);
      if (timerEl) {
        timerEl.textContent = 'Expired';
        timerEl.classList.add('text-danger');
      }
    } else {
      const m = Math.floor(remaining / 60).toString().padStart(2, '0');
      const s = (remaining % 60).toString().padStart(2, '0');
      if (timerEl) timerEl.textContent = `${m}:${s}`;
    }
  }, 1000);
})();
</script>
<?php include __DIR__ . '/../components/footer.php'; ?>
</body>
</html>
