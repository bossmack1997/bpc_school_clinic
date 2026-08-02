<?php
require_once __DIR__ . '/../config/config.php';
require_role(['nurse','admin']);
header('Content-Type: application/json');

$db = getDB();
$user = current_user();
$action = $_REQUEST['action'] ?? '';

if ($action === 'search_medicines') {
    $q = '%' . ($_GET['q'] ?? '') . '%';
    $stmt = $db->prepare("SELECT id, medicine_name, quantity, unit, expiration_date FROM medicines WHERE medicine_name LIKE ? AND quantity > 0 ORDER BY medicine_name LIMIT 10");
    $stmt->execute([$q]);
    json_response(true, ['data' => $stmt->fetchAll()]);
}

if ($action === 'dispense') {
    csrf_verify_or_die();
    $studentId = (int)($_POST['student_id'] ?? 0);
    $medicineId = (int)($_POST['medicine_id'] ?? 0);
    $quantity = (int)($_POST['quantity'] ?? 0);
    $reason = trim($_POST['reason'] ?? 'Dispensing');

    if ($studentId <= 0 || $medicineId <= 0 || $quantity <= 0) json_response(false, [], 'Invalid input.');

    $stmt = $db->prepare("SELECT quantity FROM medicines WHERE id=?");
    $stmt->execute([$medicineId]);
    $current = $stmt->fetchColumn();
    if ($current === false) json_response(false, [], 'Medicine not found.');
    if ($quantity > $current) json_response(false, [], 'Not enough stock.');

    $newQty = $current - $quantity;
    $db->prepare("UPDATE medicines SET quantity=? WHERE id=?")->execute([$newQty, $medicineId]);
    $db->prepare("INSERT INTO medicine_logs (medicine_id, action, quantity, reason, student_id, performed_by) VALUES (?, 'stock_out', ?, ?, ?, ?)")
       ->execute([$medicineId, $quantity, $reason, $studentId, $user['id']]);

    log_activity($user['id'], 'Dispensed medicine', 'dispensing', "Medicine #$medicineId to Student #$studentId");
    json_response(true, [], 'Medicine dispensed successfully.');
}

if ($action === 'search_students') {
    $q = '%' . ($_GET['q'] ?? '') . '%';
    $stmt = $db->prepare("SELECT s.id, s.student_number, u.first_name, u.last_name FROM students s JOIN users u ON u.id=s.user_id WHERE s.student_number LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ? LIMIT 10");
    $stmt->execute([$q, $q, $q]);
    json_response(true, ['data' => $stmt->fetchAll()]);
}

if ($action === 'history') {
    $studentId = (int)($_GET['student_id'] ?? 0);
    if ($studentId <= 0) json_response(false, [], 'Invalid student.');

    $stmt = $db->prepare("
        SELECT ml.*, m.medicine_name, m.unit
        FROM medicine_logs ml
        JOIN medicines m ON m.id = ml.medicine_id
        WHERE ml.student_id = ? AND ml.action = 'stock_out'
        ORDER BY ml.performed_at DESC
    ");
    $stmt->execute([$studentId]);
    json_response(true, ['data' => $stmt->fetchAll()]);
}

json_response(false, [], 'Unknown action');
