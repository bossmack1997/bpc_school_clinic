<?php
require_once __DIR__ . '/../config/database.php';
$db = getDB();
$cols = $db->query("DESCRIBE inventory_logs")->fetchAll();
foreach ($cols as $c) {
    echo $c['Field'] . ' (' . $c['Type'] . ')' . "\n";
}

