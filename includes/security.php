<?php
/**
 * Centralized Security Helpers
 * BPC School Clinic Management System
 * AI-Enhanced School Clinic Management System for Bulacan Polytechnic College Healthcare Service Improvement
 *
 * Provides:
 *  - Login rate limiting
 *  - Input validation
 *  - Security headers
 *  - Session security enhancements
 */

// ================================================================
// LOGIN RATE LIMITING
// ================================================================

/**
 * Maximum failed login attempts before temporary lockout.
 */
require_once __DIR__.'/security_policy.php';
const MAX_LOGIN_ATTEMPTS = 5;

/**
 * Lockout duration in seconds (15 minutes).
 */
const LOGIN_LOCKOUT_SECONDS = 900;

/**
 * Maximum password reset requests per IP before temporary block.
 */
const MAX_RESET_ATTEMPTS = 3;

/**
 * Password reset lockout duration in seconds (15 minutes).
 */
const RESET_LOCKOUT_SECONDS = 900;

/**
 * Check if the current IP is rate-limited from login attempts.
 * Returns the remaining lockout seconds, or 0 if not locked out.
 */
function login_rate_limit_check(string $ip, ?string $identifier = null): int {
    try {
        $db = getDB();
        $now = time();
        $release=$db->prepare("SELECT COALESCE(MAX(CAST(setting_value AS UNSIGNED)),0) FROM system_settings WHERE setting_key IN (?,?)");
        $release->execute(['login_release_'.hash('sha256',$ip.'|*'),'login_release_'.hash('sha256',$ip.'|'.($identifier??'*'))]);
        $released=(int)$release->fetchColumn();

        // Count recent failed attempts within the lockout window
        // If identifier is provided, check per (IP, identifier) to avoid
        // locking out unrelated users behind the same NAT/public IP.
        if ($identifier !== null) {
            $stmt = $db->prepare("
                SELECT COUNT(*) FROM login_attempts
                WHERE ip_address = ? AND username = ?
                  AND id > ? AND UNIX_TIMESTAMP(attempted_at) >= ?
            ");
            $stmt->execute([$ip, $identifier, $released, $now - (security_policy('lockout_minutes') * 60)]);
            $attempts = (int)$stmt->fetchColumn();

            if ($attempts >= security_policy('max_login_attempts')) {
                $stmt = $db->prepare("
                    SELECT MAX(UNIX_TIMESTAMP(attempted_at)) FROM login_attempts
                    WHERE ip_address = ? AND username = ?
                      AND id > ? AND UNIX_TIMESTAMP(attempted_at) >= ?
                ");
                $stmt->execute([$ip, $identifier, $released, $now - (security_policy('lockout_minutes') * 60)]);
                $lastAttempt = $stmt->fetchColumn();

                if ($lastAttempt) {
                    $unlockTs = (int)$lastAttempt + (security_policy('lockout_minutes') * 60);
                    return max(0, $unlockTs - $now);
                }
            }
        } else {
            $stmt = $db->prepare("
                SELECT COUNT(*) FROM login_attempts
                WHERE ip_address = ?
                  AND id > ? AND UNIX_TIMESTAMP(attempted_at) >= ?
            ");
            $stmt->execute([$ip, $released, $now - (security_policy('lockout_minutes') * 60)]);
            $attempts = (int)$stmt->fetchColumn();

            if ($attempts >= security_policy('max_login_attempts')) {
                $stmt = $db->prepare("
                    SELECT MAX(UNIX_TIMESTAMP(attempted_at)) FROM login_attempts
                    WHERE ip_address = ?
                      AND id > ? AND UNIX_TIMESTAMP(attempted_at) >= ?
                ");
                $stmt->execute([$ip, $released, $now - (security_policy('lockout_minutes') * 60)]);
                $lastAttempt = $stmt->fetchColumn();

                if ($lastAttempt) {
                    $unlockTs = (int)$lastAttempt + (security_policy('lockout_minutes') * 60);
                    return max(0, $unlockTs - $now);
                }
            }
        }

        return 0;
    } catch (Exception $e) {
        error_log('login_rate_limit_check failed: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Record a failed login attempt.
 */
function login_rate_limit_record_failure(string $ip, ?string $username = null): void {
    try {
        $db = getDB();
        $stmt = $db->prepare("
            INSERT INTO login_attempts (ip_address, username, attempted_at)
            VALUES (?, ?, NOW())
        ");
        $stmt->execute([$ip, $username]);
    } catch (Exception $e) {
        error_log('login_rate_limit_record_failure failed: ' . $e->getMessage());
    }
}

/**
 * Clear failed login attempts for an IP after successful login.
 */
function login_rate_limit_clear(string $ip, ?string $identifier = null): void {
    try {
        $db = getDB();
        $q=$db->prepare('SELECT COALESCE(MAX(id),0) FROM login_attempts WHERE ip_address=?');$q->execute([$ip]);
        $db->prepare('INSERT INTO system_settings(setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)')->execute(['login_release_'.hash('sha256',$ip.'|'.($identifier??'*')),(string)$q->fetchColumn()]);
    } catch (Exception $e) {
        error_log('login_rate_limit_clear failed: ' . $e->getMessage());
    }
}

/**
 * Reset the failed login counter for a specific user.
 */
function login_rate_limit_reset_user(int $userId): void {
    try {
        $db = getDB();
        $db->prepare("UPDATE users SET failed_login_count = 0, last_failed_login = NULL WHERE id = ?")
           ->execute([$userId]);
    } catch (Exception $e) {
        error_log('login_rate_limit_reset_user failed: ' . $e->getMessage());
    }
}

/**
 * Increment the failed login counter for a specific user.
 */
function login_rate_limit_increment_user(int $userId): void {
    try {
        $db = getDB();
        $db->prepare("UPDATE users SET failed_login_count = failed_login_count + 1, last_failed_login = NOW() WHERE id = ?")
           ->execute([$userId]);
    } catch (Exception $e) {
        error_log('login_rate_limit_increment_user failed: ' . $e->getMessage());
    }
}

// ================================================================
// PASSWORD RESET RATE LIMITING
// ================================================================

/**
 * Check if the current IP is rate-limited from password reset requests.
 * Returns the remaining lockout seconds, or 0 if not locked out.
 */
function reset_rate_limit_check(string $ip): int {
    try {
        $db = getDB();
        $now = time();

        $stmt = $db->prepare("
            SELECT COUNT(*) FROM password_reset_attempts
            WHERE ip_address = ?
              AND UNIX_TIMESTAMP(attempted_at) >= ?
        ");
        $stmt->execute([$ip, $now - RESET_LOCKOUT_SECONDS]);
        $attempts = (int)$stmt->fetchColumn();

        if ($attempts >= MAX_RESET_ATTEMPTS) {
            $stmt = $db->prepare("
                SELECT MAX(UNIX_TIMESTAMP(attempted_at)) FROM password_reset_attempts
                WHERE ip_address = ?
                  AND UNIX_TIMESTAMP(attempted_at) >= ?
            ");
            $stmt->execute([$ip, $now - RESET_LOCKOUT_SECONDS]);
            $lastAttempt = $stmt->fetchColumn();

            if ($lastAttempt) {
                $unlockTs = (int)$lastAttempt + RESET_LOCKOUT_SECONDS;
                return max(0, $unlockTs - $now);
            }
        }

        return 0;
    } catch (Exception $e) {
        error_log('reset_rate_limit_check failed: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Record a password reset request attempt.
 */
function reset_rate_limit_record_failure(string $ip): void {
    try {
        $db = getDB();
        $db->prepare("INSERT INTO password_reset_attempts (ip_address) VALUES (?)")
           ->execute([$ip]);
    } catch (Exception $e) {
        error_log('reset_rate_limit_record_failure failed: ' . $e->getMessage());
    }
}

/**
 * Clear password reset attempts for an IP after a successful reset.
 */
function reset_rate_limit_clear(string $ip): void {
    try {
        $db = getDB();
        $db->prepare("DELETE FROM password_reset_attempts WHERE ip_address = ?")->execute([$ip]);
    } catch (Exception $e) {
        error_log('reset_rate_limit_clear failed: ' . $e->getMessage());
    }
}

// ================================================================
// INPUT VALIDATION
// ================================================================
// INPUT VALIDATION
// ================================================================

/**
 * Validate an email address.
 */
function validate_email(string $email): bool {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Validate a username (alphanumeric, underscores, 3-50 chars).
 */
function validate_username(string $username): bool {
    return preg_match('/^[a-zA-Z0-9_]{3,50}$/', $username) === 1;
}

/**
 * Validate password strength.
 * Returns an array of error messages (empty if valid).
 */
function validate_password_strength(string $password): array {
    $errors = [];

    if (strlen($password) < security_policy('password_min_length')) {
        $errors[] = 'Password must be at least '.security_policy('password_min_length').' characters.';
    }
    if (!preg_match('/[A-Z]/', $password)) {
        $errors[] = 'Password must contain at least one uppercase letter.';
    }
    if (!preg_match('/[a-z]/', $password)) {
        $errors[] = 'Password must contain at least one lowercase letter.';
    }
    if (!preg_match('/[0-9]/', $password)) {
        $errors[] = 'Password must contain at least one number.';
    }
    if (!preg_match('/[^A-Za-z0-9]/', $password)) {
        $errors[] = 'Password must contain at least one special character.';
    }

    return $errors;
}

/**
 * Validate that a value is a non-negative integer.
 */
function validate_non_negative_int($value): bool {
    return filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) !== false;
}

/**
 * Validate a date string (YYYY-MM-DD).
 */
function validate_date(string $date): bool {
    $d = DateTime::createFromFormat('Y-m-d', $date);
    return $d && $d->format('Y-m-d') === $date;
}

/**
 * Validate a role value.
 */
function validate_role(string $role): bool {
    return in_array(canonical_role($role), ['student', 'nurse', 'teacher', 'admin'], true);
}

/**
 * Sanitize a string for safe output (alias for e()).
 */
function clean_output($value): string {
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

// ================================================================
// SECURITY HEADERS
// ================================================================

/**
 * Send security headers appropriate for the current environment.
 * Does NOT set HSTS for localhost HTTP.
 */
function send_security_headers(): void {
    if (headers_sent()) {
        return;
    }

    // Prevent MIME type sniffing
    header('X-Content-Type-Options: nosniff');

    // Prevent clickjacking
    header('X-Frame-Options: SAMEORIGIN');

    // Referrer policy
    header('Referrer-Policy: strict-origin-when-cross-origin');

    // Content Security Policy — restrictive but allows required external resources
    // for Bootstrap, icons, DataTables, QR libraries, etc.
    $scheme = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off')
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
        ? 'https' : 'http';

    header("Content-Security-Policy: default-src 'self' {$scheme}:; " .
        "script-src 'self' {$scheme}: 'unsafe-inline' 'unsafe-eval' " .
        "https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://unpkg.com https://code.jquery.com; " .
        "style-src 'self' {$scheme}: 'unsafe-inline' " .
        "https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://unpkg.com; " .
        "img-src 'self' {$scheme}: data: https:; " .
        "font-src 'self' {$scheme}: data:; " .
        "connect-src 'self' {$scheme}:; " .
        "media-src 'self' {$scheme}: blob:; " .
        "frame-src 'self' {$scheme}:; " .
        "object-src 'none'; " .
        "base-uri 'self';");

    // Permissions policy
    // Allows the application on the same origin (self) to use:
    // - microphone (for MICA voice speech recognition)
    // - camera (for QR scanner camera access)
    // - geolocation (for emergency SOS real-time location sharing)
    header('Permissions-Policy: geolocation=(self), microphone=(self), camera=(self)');
}

// ================================================================
// SESSION SECURITY
// ================================================================

/**
 * Configure session cookie parameters with environment-aware security.
 * For localhost HTTP: httponly=true, secure=false, samesite=Lax
 * For HTTPS production: httponly=true, secure=true, samesite=Lax
 */
function configure_secure_session(): void {
    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }

    $isHttps = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off')
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

/**
 * Regenerate session ID to prevent session fixation.
 * Preserves session data.
 */
function secure_session_regenerate_id(): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
}

/**
 * Validate that the session has not expired.
 * Returns true if session is still valid.
 */
function validate_session(): bool {
    if (!isset($_SESSION['created']) || !isset($_SESSION['last_activity'])) {
        return false;
    }

    $uId = (int)($_SESSION['user']['id'] ?? 0);
    if ($uId <= 0) {
        return false;
    }

    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT id, role, status, admin_type, credential_version FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$uId]);
        $userRow = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($userRow && (int)($_SESSION['credential_version'] ?? 0) !== (int)$userRow['credential_version']) return false;
        if (!$userRow || ($userRow['status'] ?? '') !== 'active' || !is_supported_role($userRow['role'] ?? '')) {
            return false;
        }

        $_SESSION['user']['role'] = canonical_role($userRow['role']);
        $_SESSION['user']['admin_type'] = $userRow['admin_type'] ?? null;
    } catch (Throwable $e) {
        error_log('validate_session user verification failed: ' . $e->getMessage());
        return false;
    }

    $timeout = security_policy('session_timeout');
    if (time() - $_SESSION['last_activity'] > $timeout) {
        return false;
    }

    $_SESSION['last_activity'] = time();

    // Throttled database activity timestamp update (at most once every 30 seconds per session)
    if (!isset($_SESSION['last_db_activity_update']) || (time() - (int)$_SESSION['last_db_activity_update']) > 30) {
        $_SESSION['last_db_activity_update'] = time();
        if ($uId > 0) {
            try {
                $db = getDB();
                $db->prepare("UPDATE users SET last_activity = NOW() WHERE id = ?")->execute([$uId]);
            } catch (Throwable $e) {
                // Best-effort, ignore
            }
        }
    }

    return true;
}
