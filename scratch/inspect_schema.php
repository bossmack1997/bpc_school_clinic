<?php
require_once __DIR__ . '/../config/config.php';
$db = getDB();

echo "=== USER ROLES & STATUSES ===\n";
$stmt = $db->query("SELECT id, username, email, role, status, admin_type FROM users");
$users = $stmt->fetchAll();
foreach ($users as $u) {
    echo "ID: {$u['id']} | User: {$u['username']} | Email: {$u['email']} | Role: {$u['role']} | Status: {$u['status']} | AdminType: {$u['admin_type']}\n";
}

echo "\n=== SECTIONS ===\n";
$stmt = $db->query("SELECT * FROM sections LIMIT 10");
foreach ($stmt->fetchAll() as $sec) {
    echo "Section: {$sec['id']} - {$sec['name']} (Course: {$sec['course']})\n";
}

echo "\n=== ADVISORY ASSIGNMENTS ===\n";
$stmt = $db->query("SELECT * FROM advisory_assignments");
foreach ($stmt->fetchAll() as $adv) {
    echo "Teacher ID: {$adv['teacher_id']} -> Section ID: {$adv['section_id']}\n";
}

echo "\n=== QUEUE STATUSES IN QUEUE_NUMBERS ===\n";
$cols = $db->query("SHOW COLUMNS FROM queue_numbers")->fetchAll();
foreach ($cols as $c) {
    echo "Col: {$c['Field']} ({$c['Type']})\n";
}

echo "\n=== MEDICINES COLUMNS ===\n";
$cols = $db->query("SHOW COLUMNS FROM medicines")->fetchAll();
foreach ($cols as $c) {
    echo "Col: {$c['Field']} ({$c['Type']})\n";
}

echo "\n=== MEDICAL_RECORDS COLUMNS ===\n";
$cols = $db->query("SHOW COLUMNS FROM medical_records")->fetchAll();
foreach ($cols as $c) {
    echo "Col: {$c['Field']} ({$c['Type']})\n";
}

