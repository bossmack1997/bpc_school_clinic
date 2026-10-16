<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
$db = getDB();
$qr = $db->query("SELECT qr_token FROM student_qrs WHERE status = 'active' LIMIT 1")->fetchColumn();
if ($qr) {
    $res = resolve_patient_qr($db, $qr);
    var_dump(array_keys($res));
    echo "patient_type: " . $res['patient_type'] . "\n";
    echo "record user_id: " . ($res['record']['user_id'] ?? 'null') . "\n";
    echo "has student key? " . (isset($res['student']) ? 'yes' : 'no') . "\n";
}

