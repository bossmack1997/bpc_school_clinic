<?php
/**
 * Database connection (PDO)
 * BPC School Clinic Management System
 * AI-Enhanced School Clinic Management System for Bulacan Polytechnic College Healthcare Service Improvement
 */

$dbHost = getenv('DB_HOST');
$dbName = getenv('DB_NAME');
$dbUser = getenv('DB_USER');
$dbPass = getenv('DB_PASS');
$dbCharset = getenv('DB_CHARSET');
$dbPort = getenv('DB_PORT');

$databasePath = strtolower(str_replace('\\', '/', __DIR__));
$isDirectXamppLoad = !defined('APP_ENV') && PHP_OS_FAMILY === 'Windows' && str_contains($databasePath, '/xampp/htdocs/');
$isLocalDatabase = (defined('APP_ENV') && APP_ENV === 'local') || $isDirectXamppLoad;
if (!$isLocalDatabase) {
    $missing = [];
    foreach (['DB_HOST'=>$dbHost, 'DB_NAME'=>$dbName, 'DB_USER'=>$dbUser, 'DB_PASS'=>$dbPass] as $key=>$value) {
        if ($value === false || $value === '') $missing[] = $key;
    }
    if ($missing) {
        error_log('Production database configuration is incomplete: ' . implode(', ', $missing));
        http_response_code(503);
        exit('The application database is not configured. Contact the system administrator.');
    }
}

define('DB_HOST', $dbHost !== false && $dbHost !== '' ? $dbHost : 'localhost');
define('DB_NAME', $dbName !== false && $dbName !== '' ? $dbName : 'bpc_clinic_system');
define('DB_USER', $dbUser !== false && $dbUser !== '' ? $dbUser : 'root');
define('DB_PASS', $dbPass !== false ? $dbPass : '');
define('DB_CHARSET', $dbCharset !== false && $dbCharset !== '' ? $dbCharset : 'utf8mb4');
define('DB_PORT', $dbPort !== false && ctype_digit((string)$dbPort) ? (int)$dbPort : 3306);

function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_TIMEOUT            => 8,
            ];
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            $reference = bin2hex(random_bytes(6));
            error_log('Database connection failure [' . $reference . '] environment=' . (defined('APP_ENV') ? APP_ENV : 'unknown') . ' sqlstate=' . ($e->getCode() ?: 'unknown'));
            http_response_code(503);
            die('Database connection failed. Reference: ' . $reference);
        }
    }
    return $pdo;
}
