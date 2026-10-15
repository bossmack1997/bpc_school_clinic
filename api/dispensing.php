<?php
require_once __DIR__ . '/../config/config.php';
require_login();
header('Content-Type: application/json');

$db = getDB();
$user = current_user();
$action = $_REQUEST['action'] ?? '';

if ($action === 'search_medicines') {
    if (!in_array(canonical_role($user['role'] ?? ''), ['nurse', 'admin'], true)) json_response(false, [], 'Not authorized.');
    $q = '%' . ($_GET['q'] ?? '') . '%';
    $stmt = $db->prepare("SELECT id, medicine_name, generic_name, brand, category, quantity, unit, reorder_level, expiration_date, batch_number, status FROM medicines WHERE (medicine_name LIKE ? OR generic_name LIKE ? OR brand LIKE ?) AND is_archived = 0 ORDER BY medicine_name LIMIT 10");
    $stmt->execute([$q, $q, $q]);
    json_response(true, ['data' => $stmt->fetchAll()]);
}

if ($action === 'dispense') {
    csrf_verify_or_die();
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

    $stmt = $db->prepare("SELECT id, medicine_name, quantity, unit, expiration_date, category, reorder_level FROM medicines WHERE id=? AND is_archived = 0");
    $stmt->execute([$medicineId]);
    $medicine = $stmt->fetch();
    if (!$medicine) {
        json_response(false, [], 'Medicine not found.');
    }

    // Out of stock check
    if ((int)$medicine['quantity'] <= 0) {
        json_response(false, [], 'Medicine is out of stock.');
    }

    // Expiration check
    if (!empty($medicine['expiration_date']) && strtotime($medicine['expiration_date']) < strtotime(date('Y-m-d'))) {
        json_response(false, [], 'Cannot dispense: this medicine is expired (' . $medicine['expiration_date'] . ').');
    }

    // Quantity exceeds available stock check
    if ($quantity > (int)$medicine['quantity']) {
        json_response(false, [], 'Not enough stock. Only ' . $medicine['quantity'] . ' ' . $medicine['unit'] . ' available.');
    }

    $db->beginTransaction();
    try {
        $newQty = (int)$medicine['quantity'] - $quantity;
        $db->prepare("UPDATE medicines SET quantity=? WHERE id=?")->execute([$newQty, $medicineId]);
        update_medicine_status($db, $medicineId);

        // Low stock notification if remaining is at or below reorder level
        $reorderLvl = (int)($medicine['reorder_level'] ?? 20);
        if ($newQty <= $reorderLvl) {
            try {
                $nUsers = $db->query("SELECT id FROM users WHERE role IN ('nurse','admin') AND status = 'active'")->fetchAll(PDO::FETCH_COLUMN);
                foreach ($nUsers as $nUid) {
                    push_notification(
                        (int)$nUid,
                        'inventory',
                        'Low Stock Warning',
                        "{$medicine['medicine_name']} is low in stock ({$newQty} remaining).",
                        APP_URL . '/pages/nurse/inventory.php?status=low_stock',
                        null,
                        'high'
                    );
                }
            } catch (Throwable $ignore) {}
        }

        // Log in medicine_logs with reason
        $reason = 'Dispensed to Student #' . $studentId . ' — ' . ($purpose ?: 'Dispensing');
        $db->prepare("INSERT INTO medicine_logs (medicine_id, action, quantity, reason, student_id, performed_by) VALUES (?, 'stock_out', ?, ?, ?, ?)")
           ->execute([$medicineId, $quantity, $reason, $studentId, $user['id']]);

        // Insert a dispensing record into consultations (visit_type = 'dispensing') so it
        // appears in the student's Medical Record and Clinic Visit History.
        $diagnosisText = $diagnosis ?: $purpose;
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
            $medicine['medicine_name'] . ' ' . $quantity . ' ' . $medicine['unit'],
            $remarks
        ]);

        log_activity($user['id'], 'Dispensed medicine', 'dispensing', "Medicine #$medicineId to Student #$studentId");

        // Notify the student that their medicine was dispensed
        notify_student_dispense($db, $studentId, $medicine['medicine_name'], $quantity);

        $db->commit();
        json_response(true, ['new_quantity' => $newQty], 'Medicine dispensed successfully.');
    } catch (Exception $e) {
        $db->rollBack();
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
