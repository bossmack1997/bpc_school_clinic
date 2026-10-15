<?php
require_once __DIR__ . '/../config/config.php';
require_login();
header('Content-Type: application/json');

$db = getDB();
$user = current_user();
$action = $_REQUEST['action'] ?? '';

if ($action === 'search_students') {
    if (!in_array($user['role'], ['nurse','admin'], true)) json_response(false, [], 'Not authorized.');
    $q = '%' . ($_GET['q'] ?? '') . '%';
    $stmt = $db->prepare("SELECT s.id, s.student_number, u.first_name, u.last_name FROM students s
        JOIN users u ON u.id = s.user_id
        WHERE s.student_number LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ? LIMIT 10");
    $stmt->execute([$q, $q, $q]);
    json_response(true, ['data' => $stmt->fetchAll()]);
}

if ($action === 'record') {
    csrf_verify_or_die();
    if ($user['role'] !== 'nurse') json_response(false, [], 'Not authorized. Only nurses can record health vitals.');

    $studentId = (int)($_POST['student_id'] ?? 0);
    if ($studentId <= 0) json_response(false, [], 'Invalid student.');

    $stmt = $db->prepare("SELECT id FROM students WHERE id = ?");
    $stmt->execute([$studentId]);
    if (!$stmt->fetch()) json_response(false, [], 'Student not found.');

    $height = $_POST['height_cm'] !== '' ? (float)$_POST['height_cm'] : null;
    $weight = $_POST['weight_kg'] !== '' ? (float)$_POST['weight_kg'] : null;
    $bmi = calculate_bmi($weight, $height);
    $status = bmi_status($bmi);

    $stmt = $db->prepare("INSERT INTO health_monitoring
        (student_id, recorded_by, height_cm, weight_kg, bmi, bmi_status, blood_pressure, heart_rate, pulse_rate, temperature, oxygen_saturation, vision, hearing)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $stmt->execute([
        $studentId, $user['id'], $height, $weight, $bmi, $status,
        $_POST['blood_pressure'] ?: null,
        $_POST['heart_rate'] !== '' ? (int)$_POST['heart_rate'] : null,
        $_POST['pulse_rate'] !== '' ? (int)$_POST['pulse_rate'] : null,
        $_POST['temperature'] !== '' ? (float)$_POST['temperature'] : null,
        $_POST['oxygen_saturation'] !== '' ? (float)$_POST['oxygen_saturation'] : null,
        $_POST['vision'] ?: null,
        $_POST['hearing'] ?: null,
    ]);

    $studentUserStmt = $db->prepare("SELECT u.id FROM students s JOIN users u ON u.id = s.user_id WHERE s.id=?");
    $studentUserStmt->execute([$studentId]);
    if ($sid = $studentUserStmt->fetchColumn()) {
        push_notification($sid, 'health', 'New Health Record', "New vitals recorded. BMI: $bmi ($status)", APP_URL . '/pages/student/health_monitoring.php');
    }

    log_activity($user['id'], 'Recorded health monitoring', 'health', "Student #$studentId");
    json_response(true, ['bmi' => $bmi, 'status' => $status], 'Health record saved.');
}

if ($action === 'student_record') {
    csrf_verify_or_die();
    if ($user['role'] !== 'student') json_response(false, [], 'Not authorized. Only students can record their own BMI.');

    $stmt = $db->prepare("SELECT id FROM students WHERE user_id=?");
    $stmt->execute([$user['id']]);
    $studentId = (int)$stmt->fetchColumn();
    if ($studentId <= 0) json_response(false, [], 'Student record not found.');

    $height = isset($_POST['height_cm']) ? (float)$_POST['height_cm'] : null;
    $weight = isset($_POST['weight_kg']) ? (float)$_POST['weight_kg'] : null;

    if (!$height || $height <= 0) json_response(false, [], 'Please enter a valid height.');
    if (!$weight || $weight <= 0) json_response(false, [], 'Please enter a valid weight.');

    $bmi = calculate_bmi($weight, $height);
    $status = bmi_status($bmi);

    $stmt = $db->prepare("INSERT INTO health_monitoring
        (student_id, recorded_by, height_cm, weight_kg, bmi, bmi_status)
        VALUES (?, NULL, ?, ?, ?, ?)");
    $stmt->execute([$studentId, $height, $weight, $bmi, $status]);

    log_activity($user['id'], 'Recorded own BMI', 'health', "BMI: $bmi ($status)");
    json_response(true, ['bmi' => $bmi, 'status' => $status], 'BMI saved successfully.');
}

if ($action === 'history') {
    $role = canonical_role($user['role'] ?? '');
    if ($role === 'student') {
        $stmt = $db->prepare("SELECT id FROM students WHERE user_id=?");
        $stmt->execute([$user['id']]);
        $studentId = $stmt->fetchColumn();
        if (!$studentId) json_response(false, [], 'Student record not found.');
    } elseif ($role === 'teacher') {
        $studentId = (int)($_GET['student_id'] ?? 0);
        if ($studentId <= 0) json_response(false, [], 'Invalid student.');
        require_adviser_student_access($db, $studentId);
    } elseif (in_array($role, ['nurse', 'admin'], true)) {
        $studentId = (int)($_GET['student_id'] ?? 0);
        if ($studentId <= 0) json_response(false, [], 'Invalid student.');
    } else {
        json_response(false, [], 'Access denied.');
    }
    $stmt = $db->prepare("
        SELECT *,
               CASE WHEN recorded_by IS NULL THEN 'self_recorded' ELSE 'nurse_assessment' END AS record_type
        FROM health_monitoring
        WHERE student_id=?
        ORDER BY recorded_at DESC
    ");
    $stmt->execute([$studentId]);
    json_response(true, ['data' => $stmt->fetchAll()]);
}

json_response(false, [], 'Unknown action');
