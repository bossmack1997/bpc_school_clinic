<?php
/**
 * Centralized Security Helpers
 * BPC School Clinic Management System
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
const MAX_LOGIN_ATTEMPTS = 5;

/**
 * Lockout duration in seconds (15 minutes).
 */
const LOGIN_LOCKOUT_SECONDS = 900;

/**
 * Check if the current IP is rate-limited from login attempts.
 * Returns the remaining lockout seconds, or 0 if not locked out.
 */
function login_rate_limit_check(string $ip): int {
    try {
        $db = getDB();

        // Count recent failed attempts within the lockout window
        $stmt = $db->prepare("
            SELECT COUNT(*) FROM login_attempts
            WHERE ip_address = ?
              AND attempted_at >= DATE_SUB(NOW(), INTERVAL ? SECOND)
        ");
        $stmt->execute([$ip, LOGIN_LOCKOUT_SECONDS]);
        $attempts = (int)$stmt->fetchColumn();

        if ($attempts >= MAX_LOGIN_ATTEMPTS) {
            // Check when the last attempt was made
            $stmt = $db->prepare("
                SELECT MAX(attempted_at) FROM login_attempts
                WHERE ip_address = ?
            ");
            $stmt->execute([$ip]);
            $lastAttempt = $stmt->fetchColumn();

            if ($lastAttempt) {
                $lastTs = strtotime($lastAttempt);
                $unlockTs = $lastTs + LOGIN_LOCKOUT_SECONDS;
                $remaining = max(0, $unlockTs - time());
                return $remaining;
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
function login_rate_limit_clear(string $ip): void {
    try {
        $db = getDB();
        $db->prepare("DELETE FROM login_attempts WHERE ip_address = ?")->execute([$ip]);
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

    if (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
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
    return in_array($role, ['student', 'nurse', 'admin'], true);
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
        "frame-src 'self' {$scheme}:; " .
        "object-src 'none'; " .
        "base-uri 'self';");

    // Permissions policy
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
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

    $timeout = 3600; // 1 hour
    if (time() - $_SESSION['last_activity'] > $timeout) {
        return false;
    }

    $_SESSION['last_activity'] = time();
    return true;
}
