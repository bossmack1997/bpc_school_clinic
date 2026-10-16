<?php
require_once __DIR__ . '/../config/config.php';
$db = getDB();

$migrationFiles = glob(BASE_PATH . '/database/migration_*.sql');
sort($migrationFiles);

echo "Checking migration files against DB...\n";
foreach ($migrationFiles as $mf) {
    $fname = basename($mf);
    echo "--- $fname ---\n";
    $sql = file_get_contents($mf);
    // Find CREATE TABLE statements
    if (preg_match_all('/CREATE TABLE (?:IF NOT EXISTS )?`?([a-zA-Z0-9_]+)`?/i', $sql, $m)) {
        foreach ($m[1] as $tbl) {
            $exists = $db->query("SHOW TABLES LIKE '$tbl'")->fetchColumn();
            echo "  Table $tbl: " . ($exists ? "EXISTS" : "MISSING!") . "\n";
        }
    }
    // Find ADD COLUMN statements
    if (preg_match_all('/ALTER TABLE `?([a-zA-Z0-9_]+)`?\s+ADD (?:COLUMN )?(?:IF NOT EXISTS )?`?([a-zA-Z0-9_]+)`?/i', $sql, $m)) {
        for ($i = 0; $i < count($m[1]); $i++) {
            $tbl = $m[1][$i];
            $col = $m[2][$i];
            $tableExists = $db->query("SHOW TABLES LIKE '$tbl'")->fetchColumn();
            if ($tableExists) {
                $cols = $db->query("SHOW COLUMNS FROM `$tbl` LIKE '$col'")->fetchColumn();
                echo "  Column $tbl.$col: " . ($cols ? "EXISTS" : "MISSING!") . "\n";
            }
        }
    }
}

