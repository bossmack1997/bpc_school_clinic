<?php
require_once __DIR__ . '/../config/config.php';
$db = getDB();
foreach ($db->query("SELECT id, username, password_hash FROM users LIMIT 10") as $u) {
    $info = password_get_info($u['password_hash']);
    echo $u['username'] . " => " . substr($u['password_hash'], 0, 15) . " (algoName: " . $info['algoName'] . ")\n";
}

