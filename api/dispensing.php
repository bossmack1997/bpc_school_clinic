<?php
require_once __DIR__ . '/../config/config.php';
require_login();
header('Content-Type: application/json');

$db = getDB();
$user = current_user();
if($user['role']==='admin')admin_access_denied('Clinical records and dispensing are restricted to authorized clinic staff.');
$action = $_REQUEST['action'] ?? '';
if (($user['role']??'')==='nurse' && $_SERVER['REQUEST_METHOD']==='POST') {
    require_once __DIR__.'/../includes/nursing_scope.php';enforce_nursing_documentation($_POST);
}

if ($action === 'search_medicines') {
    if (!in_array(canonical_role($user['role'] ?? ''), ['nurse', 'admin'], true)) json_response(false, [], 'Not authorized.');
    $q = '%' . ($_GET['q'] ?? '') . '%';
    $stmt = $db->prepare("SELECT id, medicine_name, generic_name, brand, category, quantity, unit, reorder_level, expiration_date, batch_number, status FROM medicines WHERE (medicine_name LIKE ? OR generic_name LIKE ? OR brand LIKE ?) AND is_archived = 0 ORDER BY medicine_name LIMIT 10");
    $stmt->execute([$q, $q, $q]);
    json_response(true, ['data' => $stmt->fetchAll()]);
}

if ($action === 'dispense') {
    if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);json_response(false,[],'Use POST.');}
    csrf_verify_or_die();
    require_once __DIR__.'/../includes/medicine_inventory_service.php';
    if (canonical_role($user['role'] ?? '') !== 'nurse') json_response(false, [], 'Only nurses can dispense medicine.');
    $studentId = (int)($_POST['student_id'] ?? 0);
    $medicineId = (int)($_POST['medicine_id'] ?? 0);
    $quantity = (int)($_POST['quantity'] ?? 0);
    $purpose = trim($_POST['purpose'] ?? '');
    $diagnosis = trim($_POST['diagnosis'] ?? '');
    $remarks = trim($_POST['remarks'] ?? '');

    if ($studentId <= 0 || $medicineId <= 0 || $quantity <= 0) {
        json_response(false, [], 'Invalid input parameters.');
    }
    if (!$purpose) {
        json_response(false, [], 'Purpose/Reason is required.');
    }

    $db->beginTransaction();
    try {
        $medicine=(new MedicineInventoryService($db))->lockForDispensing($medicineId,$quantity);
        $newQty = (int)$medicine['quantity'] - $quantity;
        $db->prepare("UPDATE medicines SET quantity=? WHERE id=?")->execute([$newQty, $medicineId]);
        update_medicine_status($db, $medicineId);

        // Log in medicine_logs with reason
        $reason = 'Dispensed to Student #' . $studentId . ' — ' . ($purpose ?: 'Dispensing');
        $db->prepare("INSERT INTO medicine_logs (medicine_id, action, quantity, reason, student_id, performed_by) VALUES (?, 'stock_out', ?, ?, ?, ?)")
           ->execute([$medicineId, $quantity, $reason, $studentId, $user['id']]);

        // Insert a dispensing record into consultations (visit_type = 'dispensing') so it
        // appears in the student's Medical Record and Clinic Visit History.
        $diagnosisText = '';
        $treatmentText = 'Dispensed ' . $quantity . ' ' . $medicine['unit'] . ' of ' . $medicine['medicine_name'] . ($remarks ? '. ' . $remarks : '');
        $db->prepare("
            INSERT INTO consultations (student_id, nurse_id, visit_type, consultation_date, chief_complaint, diagnosis, treatment, prescription, remarks)
            VALUES (?, ?, 'dispensing', NOW(), ?, ?, ?, ?, ?)
        ")->execute([
            $studentId,
            ($user['role'] === 'nurse') ? (int)(function() use ($db, $user) {
                $n = $db->prepare("SELECT id FROM nurses WHERE user_id=?");
                $n->execute([$user['id']]);
                return (int)$n->fetchColumn();
            })() : null,
            $purpose,
            $diagnosisText,
            $treatmentText,
            null,
            $remarks
        ]);

        log_activity($user['id'], 'Dispensed medicine', 'dispensing', "Medicine #$medicineId to Student #$studentId");

        $db->commit();
        try{notify_student_dispense($db,$studentId,$medicine['medicine_name'],$quantity);(new MedicineInventoryService($db))->monitor();}catch(Throwable $e){error_log('Post-dispensing alert: '.$e->getMessage());}
        json_response(true, ['new_quantity' => $newQty], 'Medicine dispensed successfully.');
    } catch (Exception $e) {
        if($db->inTransaction())$db->rollBack();
        if($e instanceof InvalidArgumentException){http_response_code(422);json_response(false,[],$e->getMessage());}
        json_response(false, [], safe_api_error($e, 'The medicine could not be dispensed. Please try again.', 'dispensing/dispense'));
    }
}

if ($action === 'search_students') {
    if (!in_array(canonical_role($user['role'] ?? ''), ['nurse', 'admin'], true)) json_response(false, [], 'Not authorized.');
    $q = '%' . ($_GET['q'] ?? '') . '%';
    $stmt = $db->prepare("SELECT s.id, s.student_number, u.first_name, u.last_name FROM students s JOIN users u ON u.id=s.user_id WHERE (s.student_number LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ? OR CONCAT(u.first_name, ' ', u.last_name) LIKE ?) AND u.status='active' LIMIT 10");
    $stmt->execute([$q, $q, $q, $q]);
    json_response(true, ['data' => $stmt->fetchAll()]);
}

if ($action === 'history') {
    $studentId = (int)($_GET['student_id'] ?? 0);
    if ($studentId <= 0) json_response(false, [], 'Invalid student.');

    $role = canonical_role($user['role'] ?? '');
    // IDOR protection: students can only access their own history
    if ($role === 'student') {
        $stmt = $db->prepare("SELECT id FROM students WHERE user_id=?");
        $stmt->execute([$user['id']]);
        $myStudentId = (int)$stmt->fetchColumn();
        if ($myStudentId <= 0 || $studentId !== $myStudentId) {
            json_response(false, [], 'Not authorized to view this dispensing history.');
        }
    } elseif ($role === 'teacher') {
        require_adviser_student_access($db, $studentId);
    } elseif (!in_array($role, ['nurse', 'admin'], true)) {
        json_response(false, [], 'Not authorized.');
    }

    $stmt = $db->prepare("
        SELECT ml.*, m.medicine_name, m.unit, u.first_name, u.last_name
        FROM medicine_logs ml
        JOIN medicines m ON m.id = ml.medicine_id
        LEFT JOIN users u ON u.id = ml.performed_by
        WHERE ml.student_id = ? AND ml.action = 'stock_out'
        ORDER BY ml.performed_at DESC
    ");
    $stmt->execute([$studentId]);
    json_response(true, ['data' => $stmt->fetchAll()]);
}

// ================================================================
// DISPENSING HISTORY (all, for nurse) — searchable & printable
// ================================================================
if ($action === 'all_history') {
    if (!in_array(canonical_role($user['role'] ?? ''), ['nurse', 'admin'], true)) json_response(false, [], 'Not authorized.');
    $search = trim($_GET['search'] ?? '');
    $limit = min((int)($_GET['limit'] ?? 50), 200);
    $offset = (int)($_GET['offset'] ?? 0);

    $where = "ml.action = 'stock_out' AND ml.student_id IS NOT NULL";
    $params = [];
    if ($search !== '') {
        $like = '%' . $search . '%';
        $where .= " AND (m.medicine_name LIKE ? OR su.first_name LIKE ? OR su.last_name LIKE ? OR s.student_number LIKE ? OR ml.reason LIKE ?)";
        $params = array_merge($params, [$like, $like, $like, $like, $like]);
    }

    $countStmt = $db->prepare("
        SELECT COUNT(*) FROM medicine_logs ml
        JOIN medicines m ON m.id = ml.medicine_id
        LEFT JOIN students s ON s.id = ml.student_id
        LEFT JOIN users su ON su.id = s.user_id
        WHERE $where
    ");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $stmt = $db->prepare("
        SELECT ml.*, m.medicine_name, m.unit,
               s.student_number, su.first_name AS stud_first, su.last_name AS stud_last,
               nu.first_name AS nurse_first, nu.last_name AS nurse_last
        FROM medicine_logs ml
        JOIN medicines m ON m.id = ml.medicine_id
        LEFT JOIN students s ON s.id = ml.student_id
        LEFT JOIN users su ON su.id = s.user_id
        LEFT JOIN users nu ON nu.id = ml.performed_by
        WHERE $where
        ORDER BY ml.performed_at DESC
        LIMIT ? OFFSET ?
    ");
    $params[] = $limit;
    $params[] = $offset;
    $stmt->execute($params);

    json_response(true, ['data' => $stmt->fetchAll(), 'total' => $total]);
}

json_response(false, [], 'Unknown action');
