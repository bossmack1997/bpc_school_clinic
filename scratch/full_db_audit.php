<?php
require_once __DIR__ . '/../config/config.php';

$db = getDB();
echo "========================================================\n";
echo "DATABASE SCHEMA AUDIT\n";
echo "========================================================\n\n";

$tables = $db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

echo "Total tables: " . count($tables) . "\n\n";

foreach ($tables as $t) {
    $count = $db->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn();
    echo "TABLE: {$t} ({$count} rows)\n";
    $cols = $db->query("SHOW COLUMNS FROM `{$t}`")->fetchAll();
    foreach ($cols as $c) {
        echo "  - {$c['Field']} ({$c['Type']})" . ($c['Key'] ? " [{$c['Key']}]" : "") . ($c['Null'] === 'NO' ? ' NOT NULL' : '') . "\n";
    }
    echo "\n";
}

