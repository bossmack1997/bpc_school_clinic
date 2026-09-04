<?php
/**
 * Global application configuration
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);

define('BASE_PATH', dirname(__DIR__));
define('APP_NAME', 'AI-Enhanced School Clinic Management System for Bulacan Polytechnic College Healthcare Service Improvement');
define('APP_SHORT_NAME', 'BPC School Clinic');

// ============================================================
// .env Loader — environment variables for secrets & API keys
// Reads BASE_PATH/.env if it exists. Never commit .env to git.
// ============================================================
$__envFile = BASE_PATH . '/.env';
if (is_file($__envFile)) {
    $__lines = file($__envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($__lines !== false) {
        foreach ($__lines as $__line) {
            $__line = trim($__line);
            if ($__line === '' || str_starts_with($__line, '#') || !str_contains($__line, '=')) {
                continue;
            }
            [$__key, $__val] = array_map('trim', explode('=', $__line, 2));
            $__val = trim($__val, "\"'");
            if ($__key !== '' && getenv($__key) === false) {
                putenv("$__key=$__val");
                $_ENV[$__key] = $__val;
            }
        }
    }
}

// ============================================================
// Environment detection
// ============================================================
$__appEnv = getenv('APP_ENV') ?: 'production';
if ($__appEnv === 'local' || $__appEnv === 'development' || $__appEnv === 'dev') {
    define('APP_ENV', 'local');
} else {
    define('APP_ENV', 'production');
}

// ============================================================
// APP_KEY — required for QR HMAC and other security features
// ============================================================
$__appKey = getenv('APP_KEY');
if (!$__appKey && APP_ENV === 'production') {
    // In production, APP_KEY must be explicitly configured.
    // Generate one with: php -r "echo bin2hex(random_bytes(32));"
    // and add it to your .env file as APP_KEY=<value>
    error_log('APP_KEY is not configured. Set APP_KEY in .env for production.');
}
define('APP_KEY', $__appKey ?: '');

// Theme colors (per client brief)
define('THEME_PRIMARY', '#0E7A3E');
define('THEME_SECONDARY', '#FFFFFF');
define('THEME_ACCENT', '#EAF7EF');

// Remember-me cookie lifetime (30 days)
define('REMEMBER_ME_DAYS', 30);

// Resolve the public base URL dynamically so the application works from
// XAMPP, local dev servers, and subdirectory deployments without hard-coding
// a single hostname/path.
$__appUrlFromEnv = getenv('APP_URL');
if (empty($__appUrlFromEnv)) {
    $scheme = 'http';
    if ((!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')) {
        $scheme = 'https';
    }

    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $docRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? '');
    $appRoot = realpath(BASE_PATH);
    $webBase = '';

    if ($docRoot !== false && $appRoot !== false && str_starts_with($appRoot, $docRoot)) {
        $relativeRoot = str_replace('\\', '/', substr($appRoot, strlen($docRoot)));
        $relativeRoot = trim($relativeRoot, '/');
        if ($relativeRoot !== '') {
            $webBase = '/' . $relativeRoot;
        }
    }

    $__appUrlFromEnv = $scheme . '://' . $host . $webBase;
}

define('APP_URL', trim($__appUrlFromEnv, '/'));

// ============================================================
// Load AI Configuration (must be loaded early so AI constants
// are available throughout the system, especially for OpenRouter)
// ============================================================
require_once __DIR__ . '/ai_config.php';

require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/includes/functions.php';
require_once BASE_PATH . '/includes/csrf.php';
require_once BASE_PATH . '/includes/security.php';
require_once BASE_PATH . '/includes/auth.php';
require_once BASE_PATH . '/includes/mail.php';

// Initialize session after security functions are loaded
if (session_status() === PHP_SESSION_NONE) {
    configure_secure_session();
}

// Send security headers on every request
if (function_exists('send_security_headers')) {
    send_security_headers();
}

set_error_handler('handle_error');
set_exception_handler('handle_exception');
