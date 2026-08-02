<?php
/**
 * Global application configuration
 */

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

error_reporting(E_ALL);
ini_set('display_errors', 0);

define('APP_NAME', 'AI-Powered School Clinic Management System with Health Monitoring and Appointment Assistance');
define('APP_URL', 'http://localhost/bpc_clinic_system');
define('BASE_PATH', dirname(__DIR__));

// Theme colors (per client brief)
define('THEME_PRIMARY', '#0E7A3E');
define('THEME_SECONDARY', '#FFFFFF');
define('THEME_ACCENT', '#EAF7EF');

// Remember-me cookie lifetime (30 days)
define('REMEMBER_ME_DAYS', 30);

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
// Load AI Configuration (must be loaded early so AI constants
// are available throughout the system, especially for OpenRouter)
// ============================================================
require_once __DIR__ . '/ai_config.php';

require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/includes/functions.php';
require_once BASE_PATH . '/includes/csrf.php';
require_once BASE_PATH . '/includes/auth.php';

set_error_handler('handle_error');
set_exception_handler('handle_exception');
