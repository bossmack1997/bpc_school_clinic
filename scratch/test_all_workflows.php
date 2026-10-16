<?php
/**
 * scratch/test_all_workflows.php
 * Comprehensive Integration & Workflow Verification Suite
 * BPC School Clinic Management System
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$db = getDB();

$results = [
    'passed' => 0,
    'failed' => 0,
    'tests'  => []
];

function assert_test($description, $condition, $details = '') {
    global $results;
    if ($condition) {
        $results['passed']++;
        $results['tests'][] = ['status' => 'PASS', 'desc' => $description, 'details' => $details];
        echo "[PASS] " . $description . "\n";
    } else {
        $results['failed']++;
        $results['tests'][] = ['status' => 'FAIL', 'desc' => $description, 'details' => $details];
        echo "[FAIL] " . $description . ($details ? " -- " . $details : "") . "\n";
    }
}

echo "=======================================================\n";
echo "BPC SCHOOL CLINIC SYSTEM -- COMPREHENSIVE VERIFICATION\n";
echo "=======================================================\n\n";

// ---------------------------------------------------------------------
// TEST GROUP 1: DATABASE & SCHEMA INTEGRITY
// ---------------------------------------------------------------------
echo "--- 1. Database & Schema Integrity ---\n";

// 1.1 consultation_medicines table
$tbl = $db->query("SHOW TABLES LIKE 'consultation_medicines'")->fetchColumn();
assert_test("consultation_medicines table exists", $tbl === 'consultation_medicines');

// 1.2 Performance indexes
$idxStmt = $db->query("SHOW INDEX FROM queue_numbers WHERE Key_name = 'idx_queue_patient_status'");
assert_test("idx_queue_patient_status index exists on queue_numbers", $idxStmt->fetch() !== false);

$idxStmt2 = $db->query("SHOW INDEX FROM advisory_assignments WHERE Key_name = 'idx_advisory_teacher_status'");
assert_test("idx_advisory_teacher_status index exists on advisory_assignments", $idxStmt2->fetch() !== false);

// 1.3 User 99 (Nazarette - Teacher)
$u99 = $db->query("SELECT u.id, u.role, u.status, t.id AS teacher_id FROM users u JOIN teachers t ON t.user_id = u.id WHERE u.id = 99")->fetch();
assert_test("User 99 exists as active teacher", $u99 && $u99['role'] === 'teacher' && $u99['status'] === 'active');

$advCount = count_active_advisory_assignments($db, 99);
assert_test("User 99 has active advisory assignments", $advCount > 0, "Count: " . $advCount);

// ---------------------------------------------------------------------
// TEST GROUP 2: NOTIFICATIONS & FUNCTION REGRESSIONS
// ---------------------------------------------------------------------
echo "\n--- 2. Notifications & Function Regressions ---\n";

// 2.1 notify_staff_medicine_alert undefined variable regression test
$med = $db->query("SELECT id, medicine_name, quantity, reorder_level FROM medicines WHERE is_archived = 0 LIMIT 1")->fetch();
$medAlertError = null;
try {
    if ($med) {
        notify_staff_medicine_alert($db, $med, 'Low Stock');
        notify_staff_medicine_alert($db, (int)$med['id'], 'Low Stock');
    }
} catch (Throwable $e) {
    $medAlertError = $e->getMessage();
}
assert_test("notify_staff_medicine_alert executes without fatal error", $medAlertError === null, $medAlertError ?? 'No error');

// 2.2 certificates upload directory
$certDir = BASE_PATH . '/uploads/certificates';
assert_test("Uploads certificates directory exists", is_dir($certDir) || file_exists(__DIR__ . '/../uploads/certificates'));

// ---------------------------------------------------------------------
// TEST GROUP 3: QR CODE RESOLUTION & QUEUE LOGIC
// ---------------------------------------------------------------------
echo "\n--- 3. QR Code & Queue Workflow ---\n";

// 3.1 QR resolution returns both 'record' and 'student' keys
$stQr = $db->query("SELECT qr_token FROM student_qrs WHERE status = 'active' LIMIT 1")->fetchColumn();
if ($stQr) {
    $resolved = resolve_patient_qr($db, $stQr);
    assert_test("resolve_patient_qr is valid for active student QR", $resolved['valid'] === true);
    assert_test("resolve_patient_qr has 'record' key", isset($resolved['record']));
    assert_test("resolve_patient_qr has 'student' key (backward compatibility)", isset($resolved['student']));
    assert_test("resolve_patient_qr student record has user_id", !empty($resolved['record']['user_id']));
} else {
    assert_test("Student QR exists for test", false, "No active student QR found in DB");
}

// 3.2 Duplicate active ticket prevention test
$testStudent = $db->query("SELECT s.id, s.user_id, s.student_number FROM students s JOIN users u ON u.id = s.user_id WHERE u.status = 'active' LIMIT 1")->fetch();
if ($testStudent) {
    $stId = (int)$testStudent['id'];
    // Clean up any open test tickets for today
    $db->prepare("DELETE FROM queue_numbers WHERE student_id = ? AND DATE(created_at) = CURDATE() AND status IN ('waiting', 'called', 'serving')")->execute([$stId]);
    
    // First ticket
    $qNum1 = generate_queue_number($db);
    $db->prepare("INSERT INTO queue_numbers (student_id, queue_number, visit_type, status, created_at) VALUES (?, ?, 'walk-in', 'waiting', NOW())")->execute([$stId, $qNum1]);
    $t1Id = $db->lastInsertId();

    // Check active ticket
    $active = get_active_patient_queue_ticket($db, $stId, 'student');
    assert_test("get_active_patient_queue_ticket returns active ticket", $active && (int)$active['id'] === (int)$t1Id);

    // Clean up test ticket
    $db->prepare("DELETE FROM queue_numbers WHERE id = ?")->execute([$t1Id]);
}

// ---------------------------------------------------------------------
// TEST GROUP 4: PRIVACY BOUNDARIES & AUTHORIZATION
// ---------------------------------------------------------------------
echo "\n--- 4. Privacy Boundaries & Role Authorization ---\n";

// 4.1 Adviser student access check
$adviserStudents = get_adviser_students($db, 99);
assert_test("Adviser 99 has access to students in advisory sections", !empty($adviserStudents), "Students found: " . count($adviserStudents));

if (!empty($adviserStudents)) {
    $allowedStudentId = (int)$adviserStudents[0]['id'];
    assert_test("adviser_can_access_student returns TRUE for assigned student", adviser_can_access_student($db, 99, $allowedStudentId) === true);

    // Find a student not in adviser's sections
    $notAllowedStudentId = (int)$db->query("SELECT id FROM students WHERE id NOT IN (SELECT id FROM students WHERE section IN (SELECT name FROM sections WHERE id IN (SELECT section_id FROM advisory_assignments WHERE teacher_id = 99))) LIMIT 1")->fetchColumn();
    if ($notAllowedStudentId > 0) {
        assert_test("adviser_can_access_student returns FALSE for unassigned student", adviser_can_access_student($db, 99, $notAllowedStudentId) === false);
    }
}

// 4.2 API request detection helper
$_SERVER['HTTP_X_REQUESTED_WITH'] = 'xmlhttprequest';
assert_test("is_api_request detects XMLHttpRequest", is_api_request() === true);
unset($_SERVER['HTTP_X_REQUESTED_WITH']);

$_SERVER['REQUEST_URI'] = '/bpc_clinic_system/api/inventory.php?action=list';
assert_test("is_api_request detects /api/ in URL", is_api_request() === true);
unset($_SERVER['REQUEST_URI']);

// ---------------------------------------------------------------------
// TEST GROUP 5: INVENTORY TRANSACTION & ZERO-QUANTITY ADJUSTMENT
// ---------------------------------------------------------------------
echo "\n--- 5. Inventory Transactions & Stock Adjustments ---\n";

// Pick a test medicine
$testMed = $db->query("SELECT id, quantity, status FROM medicines WHERE is_archived = 0 ORDER BY id DESC LIMIT 1")->fetch();
if ($testMed) {
    $medId = (int)$testMed['id'];
    $origQty = (int)$testMed['quantity'];

    // Test adjustment to zero
    $db->beginTransaction();
    $db->prepare("UPDATE medicines SET quantity = 0 WHERE id = ?")->execute([$medId]);
    $newStatus = update_medicine_status($db, $medId);
    $db->commit();

    assert_test("Stock adjustment to 0 updates status to CRITICAL", $newStatus === 'CRITICAL');

    // Restore original quantity
    $db->beginTransaction();
    $db->prepare("UPDATE medicines SET quantity = ? WHERE id = ?")->execute([$origQty, $medId]);
    update_medicine_status($db, $medId);
    $db->commit();
}

// ---------------------------------------------------------------------
// TEST GROUP 6: VERIFY ALL TARGET URLS EXIST ON DISK
// ---------------------------------------------------------------------
echo "\n--- 6. Target URL & Page Verification ---\n";

$targetUrls = [
    'Student Portal' => 'pages/student/dashboard.php',
    'Student Medical Records' => 'pages/student/medical_record.php',
    'Student QR' => 'pages/student/my_qr.php',
    'Student Queue' => 'pages/student/queue.php',
    'Nurse Portal' => 'pages/nurse/dashboard.php',
    'Nurse Emergencies' => 'pages/nurse/emergencies.php',
    'Nurse Patient History' => 'pages/nurse/patient_history.php',
    'Nurse Queue' => 'pages/nurse/queue.php',
    'Nurse Inventory' => 'pages/nurse/inventory.php',
    'Adviser Portal' => 'pages/adviser/dashboard.php',
    'Adviser Consultations' => 'pages/adviser/consultations.php',
    'Adviser Students' => 'pages/adviser/students.php',
    'Adviser Announcements' => 'pages/adviser/announcements.php',
    'Admin Portal' => 'pages/admin/dashboard.php',
    'Admin Settings' => 'pages/admin/settings.php',
];

foreach ($targetUrls as $label => $relPath) {
    $absPath = BASE_PATH . '/' . $relPath;
    assert_test("Page exists: $label ($relPath)", file_exists($absPath));
}

// ---------------------------------------------------------------------
// SUMMARY
// ---------------------------------------------------------------------
echo "\n=======================================================\n";
echo "SUMMARY: " . $results['passed'] . " PASSED, " . $results['failed'] . " FAILED\n";
echo "=======================================================\n";

if ($results['failed'] > 0) {
    exit(1);
}
