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
        $search = trim($_GET['search'] ?? '');
        $where = '1=1';
        $params = [];
        if ($studentId > 0) { $where .= ' AND mr.student_id = ?'; $params[] = $studentId; }
        if ($search !== '') {
            $where .= ' AND (u.first_name LIKE ? OR u.last_name LIKE ? OR s.student_number LIKE ? OR c.chief_complaint LIKE ? OR c.diagnosis LIKE ?)';
            $like = '%' . $search . '%';
            $params = array_merge($params, [$like, $like, $like, $like, $like]);
        }
        $stmt = $db->prepare("
            SELECT mr.*, c.consultation_date, c.chief_complaint, c.diagnosis, c.treatment,
                   u.first_name, u.last_name, s.student_number
            FROM medical_records mr
            JOIN consultations c ON c.id = mr.consultation_id
            JOIN students s ON s.id = mr.student_id
            JOIN users u ON u.id = s.user_id
            WHERE $where
            ORDER BY c.consultation_date DESC
        ");
        $stmt->execute($params);
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

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowedExts = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'doc', 'docx'];
    if (!in_array($ext, $allowedExts, true)) json_response(false, [], 'Invalid file extension. Allowed: PDF, JPG, PNG, DOC.');

    // Prevent executable uploads
    $dangerous = ['php', 'phtml', 'phar', 'exe', 'sh', 'bat', 'cmd', 'js', 'jsp', 'asp'];
    if (in_array($ext, $dangerous, true)) json_response(false, [], 'Executable files are not allowed.');

    $filename = 'record_' . $studentId . '_' . time() . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
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

if ($action === 'detail') {
    if (!in_array($user['role'], ['nurse','admin'], true)) json_response(false, [], 'Not authorized.');
    $visitId = (int)($_GET['visit_id'] ?? 0);
    if ($visitId <= 0) json_response(false, [], 'Invalid visit.');

    $stmt = $db->prepare("
        SELECT c.*, s.student_number, s.course, s.year_level, s.section,
               u.first_name, u.last_name, u.gender, u.birthdate
        FROM consultations c
        JOIN students s ON s.id = c.student_id
        JOIN users u ON u.id = s.user_id
        WHERE c.id = ?
        LIMIT 1
    ");
    $stmt->execute([$visitId]);
    $visit = $stmt->fetch();
    if (!$visit) json_response(false, [], 'Visit not found.');

    $studentName = trim(($visit['first_name'] ?? '') . ' ' . ($visit['last_name'] ?? ''));

    $vitals = null;
    $vStmt = $db->prepare("SELECT * FROM walk_in_visits WHERE student_id = ? ORDER BY visit_date DESC LIMIT 1");
    $vStmt->execute([$visit['student_id']]);
    $v = $vStmt->fetch();
    if ($v) {
        $bp = ($v['blood_pressure_systolic'] ?? '') . '/' . ($v['blood_pressure_diastolic'] ?? '');
        $vitals = [
            'temperature' => $v['temperature'] ?? null,
            'blood_pressure' => $bp ?: null,
            'heart_rate' => $v['heart_rate'] ?? null,
            'respiratory_rate' => $v['respiratory_rate'] ?? null,
            'oxygen_saturation' => $v['oxygen_saturation'] ?? null,
            'weight_kg' => $v['weight_kg'] ?? null,
            'height_cm' => $v['height_cm'] ?? null,
            'bmi' => $v['bmi'] ?? null,
            'bmi_status' => $v['bmi_status'] ?? null,
        ];
    }

    $medsStmt = $db->prepare("
        SELECT md.*, m.medicine_name, m.unit
        FROM medicine_dispensing md
        LEFT JOIN medicines m ON m.id = md.medicine_id
        WHERE md.walk_in_id = (SELECT id FROM walk_in_visits WHERE student_id = ? ORDER BY visit_date DESC LIMIT 1)
    ");
    $medsStmt->execute([$visit['student_id']]);
    $medicines = $medsStmt->fetchAll();

    json_response(true, [
        'data' => [
            'student_name' => $studentName,
            'student_number' => $visit['student_number'] ?? '',
            'course' => $visit['course'] ?? '',
            'year_level' => $visit['year_level'] ?? '',
            'consultation_date' => $visit['consultation_date'],
            'visit_type' => $visit['visit_type'],
            'chief_complaint' => $visit['chief_complaint'],
            'diagnosis' => $visit['diagnosis'],
            'treatment' => $visit['treatment'],
            'prescription' => $visit['prescription'],
            'remarks' => $visit['remarks'],
            'vitals' => $vitals,
            'medicines' => $medicines,
        ]
    ]);
}

json_response(false, [], 'Unknown action');
