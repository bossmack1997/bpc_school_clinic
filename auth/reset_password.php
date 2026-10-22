<?php
require_once __DIR__ . '/../config/config.php';

// Prevent caching of password reset pages
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');

$errors = [];
$done = false;
$valid = false;

// Get token from URL or POST
$token = trim($_GET['token'] ?? $_POST['token'] ?? '');

$db = getDB();
$user = null;
$tokenHash = null;

if ($token !== '') {

    // Hash the raw token from the URL
    $tokenHash = hash('sha256', $token);

    /*
     * IMPORTANT:
     * Use UTC_TIMESTAMP() so expiration uses the same timezone
     * that was used when the token was created.
     */
    $stmt = $db->prepare("
        SELECT id, email, reset_token, reset_token_expires
        FROM users
        WHERE reset_token = ?
          AND reset_token_expires IS NOT NULL
          AND reset_token_expires > UTC_TIMESTAMP()
        LIMIT 1
    ");

    $stmt->execute([$tokenHash]);
    $user = $stmt->fetch();

    if ($user) {
        $valid = true;
    }
}

/*
 * Handle password reset submission
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    csrf_verify_or_die();

    // Token must still be present
    if ($token === '' || !$tokenHash) {
        $errors[] = 'This reset link is invalid or has expired. Please request a new one.';
        $valid = false;
    } else {

        $password = $_POST['password'] ?? '';
        $confirm  = $_POST['confirm_password'] ?? '';

        // Validate password strength
        $pwErrors = validate_password_strength($password);

        if ($pwErrors) {
            $errors = array_merge($errors, $pwErrors);
        }

        // Confirm password
        if ($password !== $confirm) {
            $errors[] = 'Passwords do not match.';
        }

        /*
         * Re-check the token immediately before changing
         * the password. This prevents expired/reused tokens.
         */
        if (!$errors) {

            $stmt = $db->prepare("
                SELECT id
                FROM users
                WHERE reset_token = ?
                  AND reset_token_expires IS NOT NULL
                  AND reset_token_expires > UTC_TIMESTAMP()
                LIMIT 1
            ");

            $stmt->execute([$tokenHash]);
            $resetUser = $stmt->fetch();

            if (!$resetUser) {

                $errors[] =
                    'This reset link is invalid or has expired. Please request a new one.';

                $valid = false;

            } else {

                $resetUserId = (int)$resetUser['id'];

                // Hash the new password
                $passwordHash = password_hash(
                    $password,
                    PASSWORD_DEFAULT
                );

                /*
                 * Update password and invalidate token
                 * in the same query.
                 */
                $update = $db->prepare("
                    UPDATE users
                    SET
                        password_hash = ?,
                        reset_token = NULL,
                        reset_token_expires = NULL
                    WHERE id = ?
                      AND reset_token = ?
                      AND reset_token_expires > UTC_TIMESTAMP()
                ");

                $update->execute([
                    $passwordHash,
                    $resetUserId,
                    $tokenHash
                ]);

                if ($update->rowCount() === 1) {

                    log_activity(
                        $resetUserId,
                        'Reset password',
                        'auth'
                    );

                    reset_rate_limit_clear($_SERVER['REMOTE_ADDR'] ?? 'unknown');
                    csrf_regenerate();

                    $done = true;
                    $valid = false;

                } else {

                    $errors[] =
                        'This reset link is invalid or has expired. Please request a new one.';
                    
                    $valid = false;
                }
            }
        }
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

    <div class="auth-card" data-aos="fade-up" data-aos-duration="800">

        <div class="auth-side">
            <div class="auth-icon"><img src="<?= APP_URL ?>/assets/images/bpc_logo.png" alt="BPC Clinic Logo"></div>
            <h1 class="mb-2">Recover your secure clinic access</h1>
            <p class="mb-0">Create a new password to regain access to your school clinic dashboard, health records, and account services.</p>
        </div>

        <div class="auth-form-side">

            <?php if ($done): ?>

                <div class="text-center">

                    <div class="auth-icon auth-icon--compact d-inline-flex mb-3">
                        <i class="bi bi-check-circle-fill"></i>
                    </div>

                    <h4 class="mb-2">Password Reset Successful</h4>

                    <p class="text-muted mb-4">
                        Your password has been successfully updated.
                        You can now log in using your new password.
                    </p>

                    <a href="login.php" class="btn btn-primary w-100 py-2 fw-semibold">
                        <i class="bi bi-box-arrow-in-right me-1"></i>
                        Go to Login
                    </a>

                </div>

            <?php elseif (!$valid): ?>

                <div class="text-center">

                    <div class="auth-icon auth-icon--compact d-inline-flex mb-3" style="color:#dc3545;">
                        <i class="bi bi-link-45deg"></i>
                    </div>

                    <h4 class="mb-2">Invalid or Expired Link</h4>

                    <p class="text-muted mb-4">
                        This password reset link is invalid or has expired.
                        Please request a new password reset link.
                    </p>

                    <?php if ($errors): ?>
                        <div class="alert alert-danger py-2 small text-start">
                            <i class="bi bi-exclamation-triangle-fill me-1"></i>
                            <?= implode('<br>', array_map('e', $errors)) ?>
                        </div>
                    <?php endif; ?>

                    <a href="forgot_password.php" class="btn btn-primary w-100 py-2 fw-semibold">
                        <i class="bi bi-envelope me-1"></i>
                        Request New Reset Link
                    </a>

                    <a href="login.php" class="btn btn-link w-100 text-decoration-none mt-2">Back to Login</a>

                </div>

            <?php else: ?>

                <div class="text-center mb-4">
                    <div class="auth-icon auth-icon--compact d-inline-flex mb-3">
                        <i class="bi bi-shield-lock-fill"></i>
                    </div>
                    <h4 class="mb-1">Create New Password</h4>
                    <p class="text-muted mb-0">Enter a strong password for your account.</p>
                </div>

                <?php if ($errors): ?>
                    <div class="alert alert-danger py-2 small">
                        <i class="bi bi-exclamation-triangle-fill me-1"></i>
                        <?= implode('<br>', array_map('e', $errors)) ?>
                    </div>
                <?php endif; ?>

                <form method="POST" id="resetForm">
                    <?= csrf_field() ?>
                    <input type="hidden" name="token" value="<?= e($token) ?>">

                    <div class="mb-3">
                        <label class="form-label fw-semibold" for="newPw">New Password</label>
                        <input type="password" name="password" id="newPw" class="form-control" required minlength="8" autocomplete="new-password" placeholder="Enter your new password">
                        <div class="form-text">Minimum 8 characters with uppercase, lowercase, number, and special character.</div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label fw-semibold" for="confirmPw">Confirm New Password</label>
                        <input type="password" name="confirm_password" id="confirmPw" class="form-control" required minlength="8" autocomplete="new-password" placeholder="Re-enter your new password">
                        <div id="resetPasswordError" class="invalid-feedback d-block" role="alert" aria-live="polite"></div>
                    </div>

                    <button type="submit" class="btn btn-primary w-100 py-2 fw-semibold" id="resetBtn">
                        <i class="bi bi-check2-circle me-1"></i>
                        Reset Password
                    </button>

                    <a href="login.php" class="btn btn-link w-100 text-decoration-none mt-2">Back to Login</a>
                </form>

            <?php endif; ?>

        </div>

    </div>

</div>

<script>

(function () {

    const form = document.getElementById('resetForm');

    if (!form) return;

    const pw = document.getElementById('newPw');
    const confirm = document.getElementById('confirmPw');
    const btn = document.getElementById('resetBtn');
    const error = document.getElementById('resetPasswordError');

    function validPassword(value) {

        return (
            value.length >= 8 &&
            /[A-Z]/.test(value) &&
            /[a-z]/.test(value) &&
            /[0-9]/.test(value) &&
            /[^A-Za-z0-9]/.test(value)
        );

    }

    form.addEventListener('submit', function (event) {

        if (!validPassword(pw.value)) {

            event.preventDefault();
            error.textContent = 'Use at least 8 characters with uppercase, lowercase, a number, and a special character.';
            pw.setAttribute('aria-invalid', 'true');
            pw.focus();

            return;
        }

        if (pw.value !== confirm.value) {

            event.preventDefault();
            error.textContent = 'Passwords do not match.';
            confirm.setAttribute('aria-invalid', 'true');
            confirm.focus();

            return;
        }

        error.textContent = '';
        pw.removeAttribute('aria-invalid');
        confirm.removeAttribute('aria-invalid');
        btn.disabled = true;

        btn.innerHTML =
            '<span class="spinner-border spinner-border-sm me-1"></span> Resetting...';

    });

    [pw, confirm].forEach(function (field) {
        field.addEventListener('input', function () {
            error.textContent = '';
            field.removeAttribute('aria-invalid');
        });
    });

})();

</script>

<?php include __DIR__ . '/../components/footer.php'; ?>

</body>
</html>
