<?php
require_once __DIR__ . '/../config/config.php';
require_role(['nurse','admin']);
header('Content-Type: application/json');

$db = getDB();
$user = current_user();
$action = $_REQUEST['action'] ?? '';

if ($action === 'search') {
    $q = '%' . ($_GET['q'] ?? '') . '%';
    $stmt = $db->prepare("SELECT s.id, s.student_number, s.course, s.year_level, s.section, u.first_name, u.last_name, u.email, u.phone, u.profile_picture FROM students s JOIN users u ON u.id=s.user_id WHERE s.student_number LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ? ORDER BY u.last_name LIMIT 20");
    $stmt->execute([$q, $q, $q]);
    json_response(true, ['data' => $stmt->fetchAll()]);
}

if ($action === 'walkin_start') {
    csrf_verify_or_die();
    if ($user['role'] !== 'nurse') json_response(false, [], 'Not authorized. Only nurses can start walk-in consultations.');
    $studentId = (int)($_POST['student_id'] ?? 0);
    $chiefComplaint = trim($_POST['chief_complaint'] ?? '');
    $diagnosis = trim($_POST['diagnosis'] ?? '');
    $treatment = trim($_POST['treatment'] ?? '');
    $prescription = trim($_POST['prescription'] ?? '');
    $remarks = trim($_POST['remarks'] ?? '');
    $followUp = $_POST['follow_up_date'] ?? null;

    if ($studentId <= 0 || !$chiefComplaint) json_response(false, [], 'Student and chief complaint are required.');

    // Get nurse record
    $nurseStmt = $db->prepare("SELECT id FROM nurses WHERE user_id=?");
    $nurseStmt->execute([$user['id']]);
    $nurseId = $nurseStmt->fetchColumn();

    $stmt = $db->prepare("INSERT INTO consultations (student_id, nurse_id, visit_type, consultation_date, chief_complaint, diagnosis, treatment, prescription, remarks, follow_up_date) VALUES (?,?, 'walk-in', NOW(), ?,?,?,?,?,?)");
    $stmt->execute([$studentId, $nurseId ?: null, $chiefComplaint, $diagnosis ?: null, $treatment ?: null, $prescription ?: null, $remarks ?: null, $followUp]);

    $consultationId = $db->lastInsertId();

    // Notify student
    $studentUserStmt = $db->prepare("SELECT u.id FROM students s JOIN users u ON u.id=s.user_id WHERE s.id=?");
    $studentUserStmt->execute([$studentId]);
    if ($sid = $studentUserStmt->fetchColumn()) {
        push_notification($sid, 'consultation', 'Walk-in Consultation', 'A walk-in consultation was recorded for you.', APP_URL . '/pages/student/health_monitoring.php');
    }

    log_activity($user['id'], 'Walk-in consultation', 'consultations', "Student #$studentId");
    json_response(true, ['id' => $consultationId], 'Walk-in consultation recorded.');
}

if ($action === 'patient_history') {
    $studentId = (int)($_GET['student_id'] ?? 0);
    if ($studentId <= 0) json_response(false, [], 'Invalid student.');

    $stmt = $db->prepare("SELECT c.*, n.license_number FROM consultations c LEFT JOIN nurses n ON n.id=c.nurse_id WHERE c.student_id=? ORDER BY c.consultation_date DESC");
    $stmt->execute([$studentId]);
    $consultations = $stmt->fetchAll();

    $stmt2 = $db->prepare("SELECT * FROM health_monitoring WHERE student_id=? ORDER BY recorded_at DESC LIMIT 10");
    $stmt2->execute([$studentId]);
    $health = $stmt2->fetchAll();

    $stmt3 = $db->prepare("SELECT * FROM vaccinations WHERE student_id=? ORDER BY date_administered DESC");
    $stmt3->execute([$studentId]);
    $vaccinations = $stmt3->fetchAll();

    $stmt4 = $db->prepare("SELECT * FROM appointments WHERE student_id=? ORDER BY appointment_date DESC LIMIT 10");
    $stmt4->execute([$studentId]);
    $appointments = $stmt4->fetchAll();

    json_response(true, [
        'consultations' => $consultations,
        'health_monitoring' => $health,
        'vaccinations' => $vaccinations,
        'appointments' => $appointments
    ]);
}

if ($action === 'today_patients') {
    $stmt = $db->query("
        SELECT c.*, u.first_name, u.last_name, s.student_number, s.course, s.year_level
        FROM consultations c
        JOIN students s ON s.id = c.student_id
        JOIN users u ON u.id = s.user_id
        WHERE DATE(c.consultation_date) = CURDATE()
        ORDER BY c.consultation_date DESC
    ");
    json_response(true, ['data' => $stmt->fetchAll()]);
}

json_response(false, [], 'Unknown action');
