<?php
/** Read-only database and deployment verification. Never prints credentials. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Operator CLI only.'); }
ini_set('session.save_path', __DIR__ . '/../scratch');
require_once __DIR__ . '/../config/config.php';

$allowLocal = in_array('--allow-local', $argv, true);
if (APP_ENV !== 'production' && !$allowLocal) {
    fwrite(STDERR, "Refusing production verification while APP_ENV is not production. Use --allow-local only for XAMPP validation.\n");
    exit(2);
}

$failures = [];
$warnings = [];
$requiredExtensions = ['pdo','pdo_mysql','mbstring','json','openssl','fileinfo'];
echo "PHP version: " . PHP_VERSION . "\n";
if (version_compare(PHP_VERSION, '8.0.0', '<')) $failures[] = 'PHP 8.0 or newer is required.';
foreach ($requiredExtensions as $extension) {
    if (!extension_loaded($extension)) $failures[] = "Missing PHP extension: $extension";
}
echo "PHP extensions: " . implode(', ', array_filter($requiredExtensions, 'extension_loaded')) . "\n";
foreach (['curl','gd','zip','intl'] as $optionalExtension) {
    if (!extension_loaded($optionalExtension)) $warnings[] = "Optional PHP extension is unavailable: $optionalExtension";
}
try {
    $db = getDB();
    $probe = $db->query("SELECT DATABASE() database_name, @@hostname server_name, @@version server_version, @@character_set_connection connection_charset, @@collation_connection connection_collation")->fetch(PDO::FETCH_ASSOC);
    echo "PASS PDO connection\n";
    echo "Environment: " . APP_ENV . "\n";
    echo "Application URL: " . APP_URL . "\n";
    echo "Database: " . $probe['database_name'] . "\n";
    echo "Database host setting: " . DB_HOST . ':' . DB_PORT . "\n";
    echo "Connected server: " . $probe['server_name'] . " (" . $probe['server_version'] . ")\n";
    echo "Connection charset: " . $probe['connection_charset'] . " / " . $probe['connection_collation'] . "\n";
    if (strtolower($probe['connection_charset']) !== 'utf8mb4') $failures[] = 'Connection charset is not utf8mb4.';
    if (APP_ENV === 'production' && !str_starts_with(strtolower(APP_URL), 'https://')) $failures[] = 'Production APP_URL must use HTTPS.';

    $requiredTables = ['users','students','teachers','nurses','activity_logs','consultations','queue_numbers','medicines','messages','notifications','sos_emergencies','system_settings','admin_profiles','admin_invitations'];
    $tableRows = $db->query("SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE() AND table_type='BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
    $tableLookup = array_fill_keys(array_map('strtolower', $tableRows), true);
    foreach ($requiredTables as $table) if (!isset($tableLookup[strtolower($table)])) $failures[] = "Missing required table: $table";

    $requiredColumns = [
        'users'=>['id','role','username','email','password_hash','status','credential_version'],
        'consultations'=>['id','consultation_date','clinician_user_id','record_version'],
        'admin_profiles'=>['user_id','profile','appointment_reference','official_designation'],
        'admin_invitations'=>['user_id','token_hash','expires_at','used_at','delivery_status'],
    ];
    foreach ($requiredColumns as $table=>$columns) {
        if (!isset($tableLookup[$table])) continue;
        $q = $db->prepare('SELECT column_name FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=?');
        $q->execute([$table]);
        $existing = array_fill_keys(array_map('strtolower', $q->fetchAll(PDO::FETCH_COLUMN)), true);
        foreach ($columns as $column) if (!isset($existing[strtolower($column)])) $failures[] = "Missing required column: $table.$column";
    }
    echo "Schema tables found: " . count($tableRows) . "\n";
} catch (Throwable $e) {
    $failures[] = 'Connection or schema probe failed. Check the private server log for the reference.';
}

$alternateConnections = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(BASE_PATH, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') continue;
    $path = $file->getPathname();
    if (str_contains($path, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR)
        || str_contains($path, DIRECTORY_SEPARATOR . 'scratch' . DIRECTORY_SEPARATOR)
        || str_contains($path, DIRECTORY_SEPARATOR . '.git' . DIRECTORY_SEPARATOR)
        || str_contains($path, DIRECTORY_SEPARATOR . '.kilo' . DIRECTORY_SEPARATOR)) continue;
    $relative = str_replace('\\', '/', substr($path, strlen(BASE_PATH) + 1));
    if ($relative === 'config/database.php') continue;
    $source = file_get_contents($path);
    if (preg_match('/\bnew\s+PDO\s*\(|\bmysqli_(?:connect|init)\s*\(|\bnew\s+mysqli\s*\(/i', $source)) $alternateConnections[] = $relative;
}
if ($alternateConnections) $failures[] = 'Alternate database connections found: ' . implode(', ', $alternateConnections);
else echo "PASS centralized PDO usage\n";

if (!is_file(BASE_PATH . '/.htaccess')) $warnings[] = 'Root .htaccess is missing.';
foreach (['config','database','includes','scratch','setup','storage'] as $privateDir) {
    if (!is_file(BASE_PATH . '/' . $privateDir . '/.htaccess')) $warnings[] = "Missing direct-access protection: $privateDir/.htaccess";
}

$runtimeDirectories = ['assets/images/profiles','assets/images/student_ids','uploads','uploads/messages','storage'];
foreach ($runtimeDirectories as $relativeDirectory) {
    $directory = BASE_PATH . '/' . $relativeDirectory;
    if (!is_dir($directory)) $warnings[] = "Runtime directory is missing: $relativeDirectory";
    elseif (!is_writable($directory)) $failures[] = "Runtime directory is not writable: $relativeDirectory";
}
foreach (['assets/images/profiles','assets/images/student_ids','uploads'] as $uploadDirectory) {
    if (!is_file(BASE_PATH . '/' . $uploadDirectory . '/.htaccess')) $failures[] = "Upload execution protection is missing: $uploadDirectory/.htaccess";
}
if (!is_file(BASE_PATH . '/vendor/autoload.php')) $failures[] = 'Composer runtime dependencies are missing (vendor/autoload.php).';
if (APP_ENV === 'production' && APP_DEBUG) $failures[] = 'Production debugging must be disabled.';
if (APP_ENV === 'production' && strlen((string)APP_KEY) < 32) $failures[] = 'Production APP_KEY is missing or too short.';

foreach ($warnings as $warning) echo "WARN $warning\n";
foreach ($failures as $failure) echo "FAIL $failure\n";
if ($failures) exit(1);
echo "PASS read-only deployment verification completed\n";
