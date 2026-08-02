<?php
require_once __DIR__ . '/../config/config.php';
require_login();
header('Content-Type: application/json');

$db = getDB();
$user = current_user();
$action = $_REQUEST['action'] ?? '';

if ($action === 'list') {
    if ($user['role'] === 'student') {
        $stmt = $db->prepare("SELECT id FROM students WHERE user_id=?");
        $stmt->execute([$user['id']]);
        $studentId = $stmt->fetchColumn();
        if (!$studentId) json_response(false, [], 'Student record not found.');

        $stmt = $db->prepare("
            SELECT mr.*, c.consultation_date, c.chief_complaint, c.diagnosis, c.treatment
            FROM medical_records mr
            JOIN consultations c ON c.id = mr.consultation_id
            WHERE mr.student_id = ?
            ORDER BY c.consultation_date DESC
        ");
        $stmt->execute([$studentId]);
    } else {
        $studentId = (int)($_GET['student_id'] ?? 0);
        if ($studentId > 0) {
            $stmt = $db->prepare("
                SELECT mr.*, c.consultation_date, c.chief_complaint, c.diagnosis, c.treatment,
                       u.first_name, u.last_name
                FROM medical_records mr
                JOIN consultations c ON c.id = mr.consultation_id
                JOIN students s ON s.id = mr.student_id
                JOIN users u ON u.id = s.user_id
                WHERE mr.student_id = ?
                ORDER BY c.consultation_date DESC
            ");
            $stmt->execute([$studentId]);
        } else {
            $stmt = $db->query("
                SELECT mr.*, c.consultation_date, c.chief_complaint, c.diagnosis, c.treatment,
                       u.first_name, u.last_name, s.student_number
                FROM medical_records mr
                JOIN consultations c ON c.id = mr.consultation_id
                JOIN students s ON s.id = mr.student_id
                JOIN users u ON u.id = s.user_id
                ORDER BY c.consultation_date DESC
            ");
        }
    }
    json_response(true, ['data' => $stmt->fetchAll()]);
}

if ($action === 'upload') {
    csrf_verify_or_die();
    if ($user['role'] !== 'nurse') json_response(false, [], 'Not authorized. Only nurses can upload medical records.');

    $consultationId = (int)($_POST['consultation_id'] ?? 0);
    $studentId = (int)($_POST['student_id'] ?? 0);
    $description = trim($_POST['description'] ?? '');

    if ($consultationId <= 0 || $studentId <= 0) json_response(false, [], 'Invalid consultation or student.');

    if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        json_response(false, [], 'Please select a file to upload.');
    }

    $allowed = ['application/pdf','image/jpeg','image/png','image/webp','application/msword','application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
    $file = $_FILES['file'];
    if (!in_array($file['type'], $allowed, true)) json_response(false, [], 'Invalid file type. Allowed: PDF, JPG, PNG, DOC.');

    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = 'record_' . $studentId . '_' . time() . '.' . $ext;
    $destRel = 'uploads/medical_records/' . $filename;
    $destAbs = BASE_PATH . '/' . $destRel;

    if (!move_uploaded_file($file['tmp_name'], $destAbs)) {
        json_response(false, [], 'Failed to upload file.');
    }

    $stmt = $db->prepare("INSERT INTO medical_records (consultation_id, student_id, file_path, file_type, description) VALUES (?,?,?,?,?)");
    $stmt->execute([$consultationId, $studentId, $destRel, $file['type'], $description]);

    log_activity($user['id'], 'Uploaded medical record', 'medical_records', "Student #$studentId");
    json_response(true, ['path' => APP_URL . '/' . $destRel], 'File uploaded successfully.');
}

if ($action === 'delete') {
    csrf_verify_or_die();
    if (!in_array($user['role'], ['nurse','admin'], true)) json_response(false, [], 'Not authorized.');

    $id = (int)($_POST['id'] ?? 0);
    $stmt = $db->prepare("SELECT * FROM medical_records WHERE id=?");
    $stmt->execute([$id]);
    $rec = $stmt->fetch();
    if (!$rec) json_response(false, [], 'Record not found.');

    $filePath = BASE_PATH . '/' . $rec['file_path'];
    if (file_exists($filePath)) unlink($filePath);

    $db->prepare("DELETE FROM medical_records WHERE id=?")->execute([$id]);
    json_response(true, [], 'Record deleted.');
}

if ($action === 'consultations') {
    if (!in_array($user['role'], ['nurse','admin'], true)) json_response(false, [], 'Not authorized.');
    $studentId = (int)($_GET['student_id'] ?? 0);
    if ($studentId <= 0) json_response(false, [], 'Invalid student.');

    $stmt = $db->prepare("SELECT id, consultation_date, chief_complaint, diagnosis FROM consultations WHERE student_id=? ORDER BY consultation_date DESC");
    $stmt->execute([$studentId]);
    json_response(true, ['data' => $stmt->fetchAll()]);
}

json_response(false, [], 'Unknown action');
