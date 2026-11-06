<?php
require_once __DIR__ . '/../config/config.php';
require_login();
header('Content-Type: application/json');

$db = getDB();
$user = current_user();
if($user['role']==='admin'&&!has_permission('clinical_records_read',$user))admin_access_denied('Clinical records and dispensing are restricted to authorized clinic staff.');
$action = $_REQUEST['action'] ?? '';
if($action==='file') {
    if($_SERVER['REQUEST_METHOD']!=='GET'){http_response_code(405);exit;}
    $q=$db->prepare('SELECT mr.*,s.user_id AS owner_user_id FROM medical_records mr JOIN students s ON s.id=mr.student_id WHERE mr.id=?');
    $q->execute([(int)($_GET['id']??0)]);$record=$q->fetch();
    if(!$record){http_response_code(404);exit;}
    $own=$user['role']==='student' && (int)$record['owner_user_id']===(int)$user['id'];
    if(!$own && !in_array($user['role'],['nurse','admin'],true)){http_response_code(403);exit;}
    $directory=realpath(BASE_PATH.'/uploads/medical_records');
    $file=realpath(BASE_PATH.'/'.($record['file_path']??''));
    if(!$directory || !$file || !str_starts_with($file,$directory.DIRECTORY_SEPARATOR) || !is_file($file)){http_response_code(404);exit;}
    header('Cache-Control: no-store, private');
    header('Content-Type: application/octet-stream');
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: attachment; filename="medical-record-'.(int)$record['id'].'.'.preg_replace('/[^a-zA-Z0-9]/','',pathinfo($file,PATHINFO_EXTENSION)).'"');
    readfile($file);exit;
}

if ($action === 'list') {
    $role = canonical_role($user['role'] ?? '');
    if ($role === 'student') {
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
    } elseif (in_array($role, ['nurse', 'admin'], true)) {
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
    } else {
        json_response(false, [], 'Access denied.');
    }
    $records=$stmt->fetchAll();
    foreach($records as &$record)if(!empty($record['file_path']))$record['file_path']='api/medical_records.php?action=file&id='.(int)$record['id'];
    unset($record);
    json_response(true, ['data' => $records]);
}

if ($action === 'upload') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); json_response(false, [], 'Use POST.'); }
    csrf_verify_or_die();
    if ($user['role'] !== 'nurse') json_response(false, [], 'Not authorized. Only nurses can upload medical records.');

    $consultationId = (int)($_POST['consultation_id'] ?? 0);
    $studentId = (int)($_POST['student_id'] ?? 0);
    $description = trim($_POST['description'] ?? '');

    if ($consultationId <= 0 || $studentId <= 0) json_response(false, [], 'Invalid consultation or student.');

    $owner = $db->prepare('SELECT student_id FROM consultations WHERE id=?');
    $owner->execute([$consultationId]);
    if ((int)$owner->fetchColumn() !== $studentId) {
        http_response_code(409); json_response(false, [], 'The consultation does not belong to this patient. Reload the patient record.');
    }

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

    $db->beginTransaction();
    try {
        $owner=$db->prepare('SELECT student_id FROM consultations WHERE id=? FOR UPDATE');$owner->execute([$consultationId]);
        if((int)$owner->fetchColumn()!==$studentId)throw new DomainException('The consultation changed. Reload the patient record.');
        if(!move_uploaded_file($file['tmp_name'],$destAbs))throw new RuntimeException('Attachment storage unavailable.');
        $stmt = $db->prepare("INSERT INTO medical_records (consultation_id, student_id, file_path, file_type, description) VALUES (?,?,?,?,?)");
        $stmt->execute([$consultationId, $studentId, $destRel, $file['type'], $description]);
        $recordId=(int)$db->lastInsertId();
        $db->prepare("INSERT INTO activity_logs(user_id,action,module,details) VALUES (?,'Uploaded medical record','medical_records',?)")->execute([$user['id'],'Consultation #'.$consultationId.'; patient #'.$studentId]);
        $db->commit();
    }catch(Throwable $e){
        if($db->inTransaction())$db->rollBack();
        if(is_file($destAbs))unlink($destAbs);
        http_response_code($e instanceof DomainException?409:503);
        json_response(false, [], $e instanceof DomainException?$e->getMessage():safe_api_error($e,'The attachment could not be saved. Please try again.','medical_records/upload'));
    }
    json_response(true, ['path' => app_url('api/medical_records.php?action=file&id='.$recordId)], 'File uploaded successfully.');
}

if ($action === 'delete') {
    csrf_verify_or_die();
    if (!in_array(canonical_role($user['role'] ?? ''), ['nurse', 'admin'], true)) json_response(false, [], 'Not authorized.');

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
    if (!in_array(canonical_role($user['role'] ?? ''), ['nurse', 'admin'], true)) json_response(false, [], 'Not authorized.');
    $studentId = (int)($_GET['student_id'] ?? 0);
    if ($studentId <= 0) json_response(false, [], 'Invalid student.');

    $stmt = $db->prepare("SELECT id, consultation_date, chief_complaint, diagnosis FROM consultations WHERE student_id=? ORDER BY consultation_date DESC");
    $stmt->execute([$studentId]);
    json_response(true, ['data' => $stmt->fetchAll()]);
}

if ($action === 'detail') {
    if (!has_permission('clinical_records_read', $user)) admin_access_denied('Authorized clinic staff required.');
    $visitId = (int)($_GET['visit_id'] ?? 0);
    if ($visitId <= 0) json_response(false, [], 'Invalid visit.');

    $stmt = $db->prepare("
        SELECT c.*, s.student_number, s.course, s.year_level, s.section,
               u.first_name, u.last_name, u.gender, COALESCE(u.date_of_birth, s.birthdate) AS birthdate
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

    // Use the selected encounter, never a different visit's latest observations.
    $vitals = !empty($visit['vital_signs']) ? json_decode($visit['vital_signs'], true) : null;
    $medsStmt = $db->prepare("SELECT cm.*, m.medicine_name, m.unit
        FROM consultation_medicines cm LEFT JOIN medicines m ON m.id=cm.medicine_id
        WHERE cm.consultation_id=? ORDER BY cm.id");
    $medsStmt->execute([$visitId]);
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
