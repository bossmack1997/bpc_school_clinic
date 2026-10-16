<?php
require_once __DIR__ . '/../config/config.php';

try {
    $db = getDB();
    echo "DB Connected successfully!\n";
    $tables = $db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    echo "Tables (" . count($tables) . "):\n";
    foreach ($tables as $t) {
        echo " - $t\n";
    }
} catch (Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

