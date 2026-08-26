<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';

$db = getDB();
$stmt = $db->query("SELECT id, first_name, last_name, email FROM users WHERE role = 'adviser'");
$advisers = $stmt->fetchAll();

echo "Advisers in DB: " . count($advisers) . "\n";
foreach ($advisers as $adv) {
    echo "--------------------------------------------------\n";
    echo "Adviser #{$adv['id']} {$adv['first_name']} {$adv['last_name']} ({$adv['email']})\n";
    $assigns = get_adviser_active_assignment($db, $adv['id']);
    echo "Assigned Sections in DB (" . count($assigns) . "):\n";
    foreach ($assigns as $a) {
        echo "  - Section ID: {$a['section_id']} | Name: {$a['section_name']} | Course: {$a['course']} | Year: {$a['year_level']}\n";
    }
    $students = get_adviser_students($db, $adv['id']);
    echo "Accessible Students (" . count($students) . "):\n";
    foreach ($students as $st) {
        $sec = $st['assigned_section_name'] ?: $st['section'];
        echo "  * {$st['first_name']} {$st['last_name']} ({$st['student_number']}) - Course: {$st['course']} | Section: {$sec} | Year: {$st['year_level']}\n";
    }
}

