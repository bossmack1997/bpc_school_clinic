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
    } elseif ($type === 'qr_scan_logs') {
        $title = 'QR Scan Logs Report';
        $stmt = $db->prepare("
            SELECT qsl.scan_time, s.student_number, u.first_name, u.last_name,
                   qsl.purpose, qsl.scan_location, qsl.device, qsl.ip_address,
                   qsl.scanner_name, qsl.status
            FROM qr_scan_logs qsl
            LEFT JOIN students s ON s.id = qsl.student_id
            LEFT JOIN users u ON u.id = s.user_id
            WHERE DATE(qsl.scan_time) BETWEEN ? AND ?
            ORDER BY qsl.scan_time DESC
        ");
        $stmt->execute([$from, $to]);
        $data = $stmt->fetchAll();
    } elseif ($type === 'daily_checkins') {
        $title = 'Daily Check-ins Report';
        $stmt = $db->prepare("
            SELECT DATE(scan_time) AS checkin_date, COUNT(*) AS total_scans,
                   SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) AS successful,
                   SUM(CASE WHEN status = 'duplicate' THEN 1 ELSE 0 END) AS duplicates,
                   COUNT(DISTINCT student_id) AS unique_students
            FROM qr_scan_logs
            WHERE DATE(scan_time) BETWEEN ? AND ?
            GROUP BY DATE(scan_time)
            ORDER BY checkin_date DESC
        ");
        $stmt->execute([$from, $to]);
        $data = $stmt->fetchAll();
    } elseif ($type === 'walkin_stats') {
        $title = 'Walk-in Statistics Report';
        $stmt = $db->prepare("
            SELECT w.visit_date, w.status, w.visit_type, w.priority_level,
                   w.chief_complaint, w.diagnosis, w.treatment,
                   COALESCE(CONCAT(u.first_name, ' ', u.last_name), w.temp_full_name) AS patient_name,
                   s.student_number, w.bmi, w.bmi_status
            FROM walk_in_visits w
            LEFT JOIN students s ON s.id = w.student_id
            LEFT JOIN users u ON u.id = s.user_id
            WHERE w.visit_date BETWEEN ? AND ?
            ORDER BY w.visit_date DESC
        ");
        $stmt->execute([$from, $to]);
        $data = $stmt->fetchAll();
    } elseif ($type === 'queue_reports') {
        $title = 'Queue Reports';
        $stmt = $db->prepare("
            SELECT q.queue_number, q.visit_type, q.priority, q.status,
                   q.created_at, q.called_at, q.served_at, q.completed_at,
                   q.estimated_wait_minutes,
                   COALESCE(CONCAT(u.first_name, ' ', u.last_name), q.full_name) AS patient_name,
                   s.student_number
            FROM queue_numbers q
            LEFT JOIN students s ON s.id = q.student_id
            LEFT JOIN users u ON u.id = s.user_id
            WHERE DATE(q.created_at) BETWEEN ? AND ?
            ORDER BY q.created_at DESC
        ");
        $stmt->execute([$from, $to]);
        $data = $stmt->fetchAll();
    } elseif ($type === 'appointment_attendance') {
        $title = 'Appointment Attendance Report';
        $stmt = $db->prepare("
            SELECT a.appointment_date, a.appointment_time, a.reason, a.status,
                   s.student_number, u.first_name, u.last_name
            FROM appointments a
            JOIN students s ON s.id = a.student_id
            JOIN users u ON u.id = s.user_id
            WHERE a.appointment_date BETWEEN ? AND ?
            ORDER BY a.appointment_date
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
