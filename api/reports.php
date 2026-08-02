<?php
require_once __DIR__ . '/../config/config.php';
require_role(['admin','nurse']);
header('Content-Type: application/json');

$db = getDB();
$user = current_user();
$action = $_REQUEST['action'] ?? '';

if ($action === 'generate') {
    csrf_verify_or_die();
    $type = $_POST['type'] ?? 'appointments';
    $from = $_POST['from_date'] ?? date('Y-m-01');
    $to = $_POST['to_date'] ?? date('Y-m-d');

    $data = [];
    $title = '';

    if ($type === 'appointments') {
        $title = 'Appointments Report';
        $stmt = $db->prepare("
            SELECT a.*, s.student_number, u.first_name, u.last_name
            FROM appointments a
            JOIN students s ON s.id = a.student_id
            JOIN users u ON u.id = s.user_id
            WHERE a.appointment_date BETWEEN ? AND ?
            ORDER BY a.appointment_date
        ");
        $stmt->execute([$from, $to]);
        $data = $stmt->fetchAll();
    } elseif ($type === 'consultations') {
        $title = 'Consultations Report';
        $stmt = $db->prepare("
            SELECT c.*, s.student_number, u.first_name, u.last_name
            FROM consultations c
            JOIN students s ON s.id = c.student_id
            JOIN users u ON u.id = s.user_id
            WHERE DATE(c.consultation_date) BETWEEN ? AND ?
            ORDER BY c.consultation_date
        ");
        $stmt->execute([$from, $to]);
        $data = $stmt->fetchAll();
    } elseif ($type === 'inventory') {
        $title = 'Inventory Report';
        $stmt = $db->prepare("
            SELECT m.*, COALESCE(SUM(CASE WHEN ml.action='stock_out' THEN ml.quantity ELSE 0 END),0) as total_used
            FROM medicines m
            LEFT JOIN medicine_logs ml ON ml.medicine_id = m.id
            AND DATE(ml.performed_at) BETWEEN ? AND ?
            GROUP BY m.id
            ORDER BY m.medicine_name
        ");
        $stmt->execute([$from, $to]);
        $data = $stmt->fetchAll();
    } elseif ($type === 'health') {
        $title = 'Health Monitoring Report';
        $stmt = $db->prepare("
            SELECT hm.*, s.student_number, u.first_name, u.last_name
            FROM health_monitoring hm
            JOIN students s ON s.id = hm.student_id
            JOIN users u ON u.id = s.user_id
            WHERE DATE(hm.recorded_at) BETWEEN ? AND ?
            ORDER BY hm.recorded_at
        ");
        $stmt->execute([$from, $to]);
        $data = $stmt->fetchAll();
    }

    // Log the report generation
    $stmt = $db->prepare("INSERT INTO reports (report_type, period_from, period_to, generated_by) VALUES (?,?,?,?)");
    $stmt->execute([$type, $from, $to, $user['id']]);
    log_activity($user['id'], 'Generated report', 'reports', "$type $from - $to");

    json_response(true, ['data' => $data, 'title' => $title, 'from' => $from, 'to' => $to]);
}

if ($action === 'history') {
    $stmt = $db->prepare("SELECT r.*, u.first_name, u.last_name FROM reports r LEFT JOIN users u ON u.id = r.generated_by ORDER BY r.created_at DESC LIMIT 50");
    $stmt->execute();
    json_response(true, ['data' => $stmt->fetchAll()]);
}

json_response(false, [], 'Unknown action');
