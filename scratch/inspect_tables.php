<?php
require_once __DIR__ . '/../config/config.php';
$db = getDB();

$tables = ['consultations', 'students', 'teachers', 'nurses', 'student_qrs', 'teacher_qrs', 'visit_logs'];
foreach ($tables as $table) {
    echo "=== $table ===\n";
    $cols = $db->query("SHOW COLUMNS FROM $table")->fetchAll();
    foreach ($cols as $c) {
        echo " - {$c['Field']} ({$c['Type']})\n";
    }
}

