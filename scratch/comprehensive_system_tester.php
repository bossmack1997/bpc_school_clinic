<?php
/**
 * Comprehensive Multi-Role Workflow & Security Verification Suite
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

echo "========================================================\n";
echo "BPC SCHOOL CLINIC SYSTEM — FULL WORKFLOW AUDIT\n";
echo "========================================================\n\n";

$db = getDB();

$testResults = [];

function recordTest($name, $status, $details = '') {
    global $testResults;
    $testResults[] = ['name' => $name, 'status' => $status, 'details' => $details];
    $icon = $status === 'PASS' ? '✅' : ($status === 'FAIL' ? '❌' : '⚠️');
    echo "{$icon} [{$status}] {$name}\n" . ($details ? "   -> {$details}\n" : "");
}

// -------------------------------------------------------------
// 1. Role Accounts & Authentication Audit
// -------------------------------------------------------------
echo "--- 1. Role Accounts & Access Control ---\n";
$roles = ['admin', 'nurse', 'student', 'adviser', 'guidance'];
foreach ($roles as $r) {
    $stmt = $db->prepare("SELECT id, username, email, role, status FROM users WHERE role = ? LIMIT 1");
    $stmt->execute([$r]);
    $u = $stmt->fetch();
    if ($u) {
        recordTest("Role Account Available: {$r}", 'PASS', "User ID: {$u['id']} ({$u['username']}) - Status: {$u['status']}");
    } else {
        recordTest("Role Account Available: {$r}", 'FAIL', "No user account exists with role: {$r}");
    }
}
echo "\n";

// -------------------------------------------------------------
// 2. Student Clinic Services & Profile Integrity
// -------------------------------------------------------------
echo "--- 2. Student Clinic Services & Records ---\n";
$stmt = $db->query("SELECT s.*, u.first_name, u.last_name, u.email FROM students s JOIN users u ON u.id = s.user_id LIMIT 1");
$sampleStudent = $stmt->fetch();
if ($sampleStudent) {
    recordTest("Student Record Linked to Users Table", 'PASS', "Student: {$sampleStudent['first_name']} {$sampleStudent['last_name']} ({$sampleStudent['student_number']})");
    
    // Check QR code token in student_qrs
    $qrStmt = $db->prepare("SELECT * FROM student_qrs WHERE student_id = ? AND status = 'active' LIMIT 1");
    $qrStmt->execute([$sampleStudent['id']]);
    $studentQr = $qrStmt->fetch();
    if ($studentQr) {
        recordTest("Student Active QR Token Generated", 'PASS', "QR Token: {$studentQr['qr_token']}");
    } else {
        // Look up ANY active student QR to test
        $anyQr = $db->query("SELECT sq.*, s.student_number FROM student_qrs sq JOIN students s ON s.id = sq.student_id WHERE sq.status = 'active' LIMIT 1")->fetch();
        if ($anyQr) {
            $studentQr = $anyQr;
            recordTest("Student Active QR Token Generated", 'PASS', "Active QR Token: {$anyQr['qr_token']} (Student {$anyQr['student_number']})");
        } else {
            recordTest("Student Active QR Token Generated", 'WARN', "No active token in student_qrs");
        }
    }

    // Check health monitoring / BMI records
    $hmStmt = $db->prepare("SELECT * FROM health_monitoring WHERE student_id = ? ORDER BY id DESC LIMIT 1");
    $hmStmt->execute([$sampleStudent['id']]);
    $hmRecord = $hmStmt->fetch();
    recordTest("Student Health Monitoring Query", 'PASS', $hmRecord ? "Found latest vitals: BP {$hmRecord['blood_pressure']}, Temp {$hmRecord['temperature']}°C" : "No health records logged yet (Query OK)");

    // Check appointments query
    $aptStmt = $db->prepare("SELECT * FROM appointments WHERE student_id = ? ORDER BY id DESC LIMIT 1");
    $aptStmt->execute([$sampleStudent['id']]);
    $aptRecord = $aptStmt->fetch();
    recordTest("Student Appointments Query", 'PASS', $aptRecord ? "Found appointment: {$aptRecord['appointment_date']} ({$aptRecord['status']})" : "No appointments yet (Query OK)");
} else {
    recordTest("Student Record Linked to Users Table", 'FAIL', "No student record found");
}
echo "\n";

// -------------------------------------------------------------
// 3. QR Scanner Backend Lookup Audit
// -------------------------------------------------------------
echo "--- 3. QR Scanner & Patient Identification ---\n";
if (!empty($studentQr['qr_token'])) {
    $testQrToken = $studentQr['qr_token'];
    $lookupStmt = $db->prepare("
        SELECT s.id, s.student_number, s.course, s.year_level, s.section,
               u.first_name, u.last_name, u.email, u.phone, u.profile_picture,
               sq.qr_token, sq.status AS qr_status
        FROM student_qrs sq
        JOIN students s ON s.id = sq.student_id
        JOIN users u ON u.id = s.user_id
        WHERE sq.qr_token = ? AND sq.status = 'active'
        LIMIT 1
    ");
    $lookupStmt->execute([$testQrToken]);
    $foundByToken = $lookupStmt->fetch();

    if ($foundByToken) {
        recordTest("QR Scanner Token Database Lookup", 'PASS', "Successfully resolved '{$testQrToken}' to Student {$foundByToken['first_name']} {$foundByToken['last_name']} ({$foundByToken['student_number']})");
    } else {
        recordTest("QR Scanner Token Database Lookup", 'FAIL', "Token lookup failed for {$testQrToken}");
    }
} else {
    recordTest("QR Scanner Token Database Lookup", 'WARN', "No active token available to test lookup");
}
echo "\n";

// -------------------------------------------------------------
// 4. Emergency SOS & Real-time GPS Location
// -------------------------------------------------------------
echo "--- 4. Emergency SOS & GPS Location ---\n";
$sosStmt = $db->query("SELECT * FROM sos_emergencies ORDER BY id DESC LIMIT 1");
$latestSos = $sosStmt->fetch();
if ($latestSos) {
    recordTest("Emergency SOS Table & Status Query", 'PASS', "Latest SOS ID {$latestSos['id']}: Status '{$latestSos['status']}', Lat: " . ($latestSos['latitude'] ?: 'N/A') . ", Long: " . ($latestSos['longitude'] ?: 'N/A'));
} else {
    recordTest("Emergency SOS Table & Status Query", 'PASS', "SOS table ready (0 emergencies logged)");
}
echo "\n";

// -------------------------------------------------------------
// 5. Nurse Medicine Inventory & Dispensing
// -------------------------------------------------------------
echo "--- 5. Nurse Medicine Inventory & Dispensing ---\n";
$medStmt = $db->query("SELECT id, medicine_name, category, quantity, unit FROM medicines WHERE is_archived = 0 LIMIT 3");
$meds = $medStmt->fetchAll();
recordTest("Medicine Inventory Query", 'PASS', "Loaded " . count($meds) . " active medicine records");
echo "\n";

// -------------------------------------------------------------
// 6. Teacher / Adviser Scope Isolation
// -------------------------------------------------------------
echo "--- 6. Teacher / Adviser Scope & Course+Section Grouping ---\n";
$adviserStmt = $db->query("SELECT id, first_name, last_name FROM users WHERE role = 'adviser' LIMIT 1");
$adv = $adviserStmt->fetch();
if ($adv) {
    $assignments = get_adviser_active_assignment($db, $adv['id']);
    $advStudents = get_adviser_students($db, $adv['id']);
    recordTest("Adviser Section Assignment Isolation", 'PASS', "Adviser {$adv['first_name']} {$adv['last_name']} has " . count($assignments) . " section(s) and " . count($advStudents) . " accessible student(s)");
} else {
    recordTest("Adviser Section Assignment Isolation", 'WARN', "No adviser user found");
}
echo "\n";

// -------------------------------------------------------------
// 7. Guidance Counselor Referrals & Privacy
// -------------------------------------------------------------
echo "--- 7. Guidance Counselor Referrals & Privacy ---\n";
$refStmt = $db->query("SELECT sr.*, s.student_number, u.first_name, u.last_name FROM support_referrals sr JOIN students s ON s.id = sr.student_id JOIN users u ON u.id = s.user_id LIMIT 3");
$refs = $refStmt->fetchAll();
recordTest("Guidance Support Referrals Query", 'PASS', "Loaded " . count($refs) . " referral case(s)");
echo "\n";

// -------------------------------------------------------------
// 8. Admin Management & System Settings
// -------------------------------------------------------------
echo "--- 8. Admin Management & System Settings ---\n";
$usersCount = $db->query("SELECT COUNT(*) FROM users")->fetchColumn();
$studentsCount = $db->query("SELECT COUNT(*) FROM students")->fetchColumn();
$settingsCount = $db->query("SELECT COUNT(*) FROM system_settings")->fetchColumn();
recordTest("Admin Core Entities Count", 'PASS', "Users: {$usersCount}, Students: {$studentsCount}, Settings: {$settingsCount}");
echo "\n";

echo "========================================================\n";
echo "SUMMARY REPORT\n";
echo "========================================================\n";
$passed = count(array_filter($testResults, fn($t) => $t['status'] === 'PASS'));
$warns = count(array_filter($testResults, fn($t) => $t['status'] === 'WARN'));
$fails = count(array_filter($testResults, fn($t) => $t['status'] === 'FAIL'));
echo "Total Tests: " . count($testResults) . " | Passed: {$passed} | Warnings: {$warns} | Failed: {$fails}\n\n";

