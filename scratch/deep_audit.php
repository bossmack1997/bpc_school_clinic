<?php
require_once __DIR__ . '/../config/config.php';
$db = getDB();

echo "=======================================================\n";
echo "1. VERIFYING DATABASE TABLES AND COLUMNS IN SQL QUERIES\n";
echo "=======================================================\n";

// Fetch all tables and columns from MySQL
$dbSchema = [];
$tables = $db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
foreach ($tables as $table) {
    $cols = $db->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_COLUMN);
    $dbSchema[$table] = array_map('strtolower', $cols);
}

// Find all PHP files in api/ and pages/ and includes/
$files = [];
$dirs = [
    BASE_PATH . '/api',
    BASE_PATH . '/pages',
    BASE_PATH . '/includes',
    BASE_PATH . '/auth'
];

foreach ($dirs as $dir) {
    if (!is_dir($dir)) continue;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
    foreach ($it as $f) {
        if ($f->isFile() && $f->getExtension() === 'php') {
            $files[] = $f->getRealPath();
        }
    }
}

echo "Scanning " . count($files) . " files for database queries and column references...\n";

// Let's also check all API files for authentication and role checks
echo "\n=======================================================\n";
echo "2. API ENDPOINTS AUDIT (AUTH, ROLE CHECKS, CSRF)\n";
echo "=======================================================\n";

$apiDir = BASE_PATH . '/api';
$apiFiles = glob($apiDir . '/*.php');
$apiAudit = [];

foreach ($apiFiles as $apiFile) {
    $content = file_get_contents($apiFile);
    $filename = basename($apiFile);
    
    $hasRequireLogin = str_contains($content, 'require_login');
    $hasRequireRole = str_contains($content, 'require_role');
    $hasIsLoggedIn = str_contains($content, 'is_logged_in');
    $hasCsrf = str_contains($content, 'verify_csrf') || str_contains($content, 'validate_csrf');
    $hasJsonResponse = str_contains($content, 'json_response');
    
    // Check if roles are checked
    $rolesChecked = [];
    if (preg_match('/require_role\(\[?([^\]\)]+)\]?\)/', $content, $m)) {
        $rolesChecked[] = trim($m[1]);
    }
    
    $apiAudit[$filename] = [
        'has_auth' => ($hasRequireLogin || $hasRequireRole || $hasIsLoggedIn),
        'has_role' => $hasRequireRole,
        'roles' => $rolesChecked,
        'has_csrf' => $hasCsrf,
        'returns_json' => $hasJsonResponse
    ];
}

foreach ($apiAudit as $file => $info) {
    $authStatus = $info['has_auth'] ? "AUTH OK" : "NO AUTH CHECK!";
    $roleStatus = $info['has_role'] ? "ROLES: " . implode(', ', $info['roles']) : "NO ROLE RESTRICTION";
    echo sprintf("%-25s | %-15s | %s\n", $file, $authStatus, $roleStatus);
}

