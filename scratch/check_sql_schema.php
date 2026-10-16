<?php
require_once __DIR__ . '/../config/config.php';
$db = getDB();

// Build schema map
$schema = [];
$tables = $db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
foreach ($tables as $t) {
    $cols = $db->query("SHOW COLUMNS FROM `$t`")->fetchAll(PDO::FETCH_COLUMN);
    $schema[$t] = array_map('strtolower', $cols);
}

echo "Database contains " . count($tables) . " tables.\n";

$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(BASE_PATH));
$issues = [];
$scannedFiles = 0;

foreach ($iterator as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') continue;
    $path = $file->getRealPath();
    if (str_contains($path, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR)) continue;
    if (str_contains($path, DIRECTORY_SEPARATOR . '.kilo' . DIRECTORY_SEPARATOR)) continue;
    if (str_contains($path, DIRECTORY_SEPARATOR . 'scratch' . DIRECTORY_SEPARATOR)) continue;
    
    $scannedFiles++;
    $content = file_get_contents($path);
    $rel = str_replace(BASE_PATH . DIRECTORY_SEPARATOR, '', $path);
    
    // Check for queries targeting tables
    // Match "FROM table", "JOIN table", "INTO table", "UPDATE table"
    if (preg_match_all('/(?:FROM|JOIN|INTO|UPDATE)\s+[`]?([a-zA-Z0-9_]+)[`]?/i', $content, $matches)) {
        foreach ($matches[1] as $tbl) {
            $tblLower = strtolower($tbl);
            // Skip common SQL keywords that might follow FROM or INTO
            if (in_array($tblLower, ['select', 'where', 'set', 'values', 'dual', 'information_schema', 'show'], true)) continue;
            if (!isset($schema[$tblLower])) {
                $issues[] = "File $rel references unknown table: $tbl";
            }
        }
    }
}

echo "Scanned $scannedFiles PHP files.\n";
if (empty($issues)) {
    echo "✓ No references to non-existent tables found.\n";
} else {
    $uniqueIssues = array_unique($issues);
    echo "[!] Found " . count($uniqueIssues) . " table reference issues:\n";
    foreach ($uniqueIssues as $iss) {
        echo " - $iss\n";
    }
}

