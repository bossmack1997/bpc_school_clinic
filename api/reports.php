<?php
require_once __DIR__ . '/../config/config.php';
require_role(['admin', 'nurse']);
header('Content-Type: application/json');

$db = getDB();
$user = current_user();
$action = $_REQUEST['action'] ?? '';

if ($action === 'generate') {
    csrf_verify_or_die();
    $type = $_POST['type'] ?? 'consultations';
    $from = $_POST['from_date'] ?? date('Y-m-01');
    $to   = $_POST['to_date'] ?? date('Y-m-d');

    $data = [];
    $title = '';

    if ($type === 'consultations') {
        $title = 'Clinic Consultations Report';
        $stmt = $db->prepare("
            SELECT c.id AS consultation_id, DATE(c.consultation_date) AS date,
                   TIME(c.consultation_date) AS time,
                   s.student_number, CONCAT(u.first_name, ' ', u.last_name) AS student_name,
                   s.course, s.year_level, s.section,
                   c.chief_complaint, c.diagnosis, c.treatment,
                   c.remarks, c.follow_up_date
            FROM consultations c
            JOIN students s ON s.id = c.student_id
            JOIN users u ON u.id = s.user_id
            WHERE DATE(c.consultation_date) BETWEEN ? AND ?
            ORDER BY c.consultation_date DESC
        ");
        $stmt->execute([$from, $to]);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    } elseif ($type === 'inventory') {
        $title = 'Medicine Inventory & Usage Report';
        $stmt = $db->prepare("
            SELECT m.medicine_name, m.category, m.dosage, m.quantity AS stock_remaining,
                   m.unit, m.reorder_level, m.expiration_date,
                   COALESCE(SUM(CASE WHEN ml.action='stock_out' THEN ml.quantity ELSE 0 END),0) AS total_dispensed
            FROM medicines m
            LEFT JOIN medicine_logs ml ON ml.medicine_id = m.id
            AND DATE(ml.performed_at) BETWEEN ? AND ?
            WHERE m.is_archived = 0
            GROUP BY m.id
            ORDER BY m.medicine_name ASC
        ");
        $stmt->execute([$from, $to]);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    } elseif ($type === 'health') {
        $title = 'Health & Vital Signs Monitoring Report';
        $stmt = $db->prepare("
            SELECT DATE(hm.recorded_at) AS date, s.student_number,
                   CONCAT(u.first_name, ' ', u.last_name) AS student_name,
                   hm.blood_pressure, hm.temperature, hm.heart_rate,
                   hm.height_cm, hm.weight_kg, hm.bmi, hm.bmi_status
            FROM health_monitoring hm
            JOIN students s ON s.id = hm.student_id
            JOIN users u ON u.id = s.user_id
            WHERE DATE(hm.recorded_at) BETWEEN ? AND ?
            ORDER BY hm.recorded_at DESC
        ");
        $stmt->execute([$from, $to]);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    } elseif ($type === 'certificates') {
        $title = 'Medical Certificates Issued Report';
        $stmt = $db->prepare("
            SELECT mc.id AS cert_id, mc.certificate_number, mc.certificate_type,
                   DATE(mc.date_issued) AS date_issued,
                   s.student_number, CONCAT(u.first_name, ' ', u.last_name) AS student_name,
                   mc.purpose, mc.remarks
            FROM certificates mc
            JOIN students s ON s.id = mc.student_id
            JOIN users u ON u.id = s.user_id
            WHERE DATE(mc.date_issued) BETWEEN ? AND ?
            ORDER BY mc.date_issued DESC
        ");
        $stmt->execute([$from, $to]);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    } elseif ($type === 'emergencies') {
        $title = 'Emergency & SOS Incidents Report';
        $stmt = $db->prepare("
            SELECT se.id AS emergency_id, se.created_at AS incident_time,
                   se.source, se.reporter_role, se.emergency_type,
                   se.location, se.status, se.priority,
                   se.description, se.response_notes, se.responded_at, se.resolved_at
            FROM sos_emergencies se
            WHERE DATE(se.created_at) BETWEEN ? AND ?
            ORDER BY se.created_at DESC
        ");
        $stmt->execute([$from, $to]);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    } elseif ($type === 'walkin_stats') {
        $title = 'Walk-in Visits & Triage Report';
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
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    } elseif ($type === 'qr_scan_logs') {
        $title = 'QR Health Pass Scan Logs Report';
        $stmt = $db->prepare("
            SELECT qsl.scan_time, s.student_number, CONCAT(u.first_name, ' ', u.last_name) AS student_name,
                   qsl.purpose, qsl.scan_location, qsl.device, qsl.scanner_name, qsl.status
            FROM qr_scan_logs qsl
            LEFT JOIN students s ON s.id = qsl.student_id
            LEFT JOIN users u ON u.id = s.user_id
            WHERE DATE(qsl.scan_time) BETWEEN ? AND ?
            ORDER BY qsl.scan_time DESC
        ");
        $stmt->execute([$from, $to]);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    } elseif ($type === 'daily_checkins') {
        $title = 'Daily Check-ins Summary Report';
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
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Log the report generation
    try {
        $stmt = $db->prepare("INSERT INTO reports (report_type, period_from, period_to, generated_by) VALUES (?,?,?,?)");
        $stmt->execute([$type, $from, $to, $user['id']]);
        log_activity($user['id'], 'Generated report', 'reports', "$type $from - $to");
    } catch (Exception $e) {
        error_log('Report logging failed: ' . $e->getMessage());
    }

    json_response(true, ['data' => $data, 'title' => $title, 'from' => $from, 'to' => $to]);
}

if ($action === 'history') {
    $stmt = $db->prepare("SELECT r.*, u.first_name, u.last_name FROM reports r LEFT JOIN users u ON u.id = r.generated_by ORDER BY r.created_at DESC LIMIT 50");
    $stmt->execute();
    json_response(true, ['data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

json_response(false, [], 'Unknown action');
