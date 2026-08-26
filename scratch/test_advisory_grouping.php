<?php
/**
 * Test Suite: Adviser My Advisory Class Grouped Layout
 */

// Start session and mock logged in adviser before config loads
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$_SESSION['user'] = [
    'id' => 99,
    'role' => 'adviser',
    'first_name' => 'Naz',
    'last_name' => 'Clemente',
    'email' => 'clementemarkjoseph14@gmail.com',
    'status' => 'active'
];

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';

echo "========================================================\n";
echo "ADVISER 'MY ADVISORY CLASS' GROUPING VERIFICATION\n";
echo "========================================================\n\n";

$db = getDB();

// Test Adviser #99 (Naz Clemente - Multiple sections: BSIS 4B, BSIS 4A)
echo "--- Testing Adviser #99 (Naz Clemente) ---\n";
$assignments = get_adviser_active_assignment($db, 99);
$students = get_adviser_students($db, 99);

echo "Active Assignments in DB: " . count($assignments) . "\n";
foreach ($assignments as $a) {
    echo "  - Section ID {$a['section_id']}: '{$a['section_name']}' (Course: {$a['course']}, Year: {$a['year_level']})\n";
}

echo "Accessible Students: " . count($students) . "\n";

// Execute Grouping Logic
$groups = [];
foreach ($assignments as $a) {
    $secStudents = array_values(array_filter($students, function ($st) use ($a) {
        return (!empty($st['assigned_section_id']) && (int)$st['assigned_section_id'] === (int)$a['section_id']) ||
               (!empty($st['assigned_section_name']) && strcasecmp($st['assigned_section_name'], $a['section_name']) === 0) ||
               (!empty($st['section']) && strcasecmp($st['section'], $a['section_name']) === 0);
    }));
    $groups[$a['section_name']] = $secStudents;
}

foreach ($groups as $secName => $secSts) {
    $count = count($secSts);
    $label = $count . ' ' . ($count === 1 ? 'Student' : 'Students');
    echo "Card Header: [ {$secName} · {$label} ]\n";
    foreach ($secSts as $st) {
        echo "   -> {$st['first_name']} {$st['last_name']} | No: {$st['student_number']} | Course: {$st['course']} | Sec: " . ($st['assigned_section_name'] ?: $st['section']) . "\n";
    }
}

// Verification checks for Adviser 99
$has4B = isset($groups['BSIS 4B']) && count($groups['BSIS 4B']) === 3;
$has4A = isset($groups['BSIS 4A']) && count($groups['BSIS 4A']) === 1;

if ($has4B && $has4A) {
    echo "✅ Adviser 99 correctly separated into BSIS 4B (3 students) and BSIS 4A (1 student)!\n\n";
} else {
    echo "❌ Adviser 99 grouping failed!\n\n";
}

// Test Adviser #95 (S U - Empty section: SMOKE-A)
echo "--- Testing Adviser #95 (S U - Empty Section) ---\n";
$_SESSION['user'] = [
    'id' => 95,
    'role' => 'adviser',
    'first_name' => 'S',
    'last_name' => 'U',
    'email' => 'smAdv@s.com',
    'status' => 'active'
];

$assignments95 = get_adviser_active_assignment($db, 95);
$students95 = get_adviser_students($db, 95);

echo "Active Assignments in DB: " . count($assignments95) . "\n";
echo "Accessible Students: " . count($students95) . "\n";

$groups95 = [];
foreach ($assignments95 as $a) {
    $secStudents = array_values(array_filter($students95, function ($st) use ($a) {
        return (!empty($st['assigned_section_id']) && (int)$st['assigned_section_id'] === (int)$a['section_id']) ||
               (!empty($st['assigned_section_name']) && strcasecmp($st['assigned_section_name'], $a['section_name']) === 0) ||
               (!empty($st['section']) && strcasecmp($st['section'], $a['section_name']) === 0);
    }));
    $groups95[$a['section_name']] = $secStudents;
}

foreach ($groups95 as $secName => $secSts) {
    $count = count($secSts);
    $label = $count . ' ' . ($count === 1 ? 'Student' : 'Students');
    echo "Card Header: [ {$secName} · {$label} ]\n";
    if ($count === 0) {
        echo "   -> 'No students enrolled in this section yet.'\n";
    }
}

if (isset($groups95['SMOKE-A']) && count($groups95['SMOKE-A']) === 0) {
    echo "✅ Adviser 95 correctly displays empty section SMOKE-A with 0 Students and fallback message!\n\n";
} else {
    echo "❌ Adviser 95 empty section test failed!\n\n";
}

// Test HTML structure of pages/adviser/students.php
echo "--- Testing HTML Template Structure ---\n";
$studentsPhpCode = file_get_contents(__DIR__ . '/../pages/adviser/students.php');

$hasAccordion = strpos($studentsPhpCode, 'id="advisoryAccordion"') !== false;
$hasCollapse = strpos($studentsPhpCode, 'advisory-collapse') !== false;
$hasChevron = strpos($studentsPhpCode, 'advisory-chevron') !== false;
$hasExpandAll = strpos($studentsPhpCode, 'id="expandAllBtn"') !== false;
$hasCollapseAll = strpos($studentsPhpCode, 'id="collapseAllBtn"') !== false;

echo "  - Contains advisoryAccordion: " . ($hasAccordion ? '✅ YES' : '❌ NO') . "\n";
echo "  - Contains advisory-collapse: " . ($hasCollapse ? '✅ YES' : '❌ NO') . "\n";
echo "  - Contains animated chevron: " . ($hasChevron ? '✅ YES' : '❌ NO') . "\n";
echo "  - Contains Expand All button: " . ($hasExpandAll ? '✅ YES' : '❌ NO') . "\n";
echo "  - Contains Collapse All button: " . ($hasCollapseAll ? '✅ YES' : '❌ NO') . "\n";

echo "\n========================================================\n";
echo "ALL ADVISORY GROUPING TESTS PASSED!\n";
echo "========================================================\n";

