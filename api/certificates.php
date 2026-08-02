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

        $stmt = $db->prepare("SELECT c.*, u.first_name, u.last_name FROM certificates c LEFT JOIN users u ON u.id = c.issued_by WHERE c.student_id=? ORDER BY c.date_issued DESC");
        $stmt->execute([$studentId]);
    } else {
        $studentId = (int)($_GET['student_id'] ?? 0);
        if ($studentId > 0) {
            $stmt = $db->prepare("SELECT c.*, u2.first_name AS s_first, u2.last_name AS s_last, s.student_number, u.first_name AS issued_by_name FROM certificates c JOIN students s ON s.id=c.student_id JOIN users u2 ON u2.id=s.user_id LEFT JOIN users u ON u.id=c.issued_by WHERE c.student_id=? ORDER BY c.date_issued DESC");
            $stmt->execute([$studentId]);
        } else {
            $stmt = $db->query("SELECT c.*, u2.first_name AS s_first, u2.last_name AS s_last, s.student_number, u.first_name AS issued_by_name FROM certificates c JOIN students s ON s.id=c.student_id JOIN users u2 ON u2.id=s.user_id LEFT JOIN users u ON u.id=c.issued_by ORDER BY c.date_issued DESC");
        }
    }
    json_response(true, ['data' => $stmt->fetchAll()]);
}

if ($action === 'issue') {
    csrf_verify_or_die();
    if ($user['role'] !== 'nurse') json_response(false, [], 'Not authorized. Only nurses can issue certificates.');

    $studentId = (int)($_POST['student_id'] ?? 0);
    $certType = trim($_POST['certificate_type'] ?? 'Medical Certificate');
    $purpose = trim($_POST['purpose'] ?? '');
    $remarks = trim($_POST['remarks'] ?? '');
    $dateIssued = $_POST['date_issued'] ?? date('Y-m-d');

    if (!$studentId) json_response(false, [], 'Invalid student.');

    $filePath = null;
    if (!empty($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
        $allowed = ['application/pdf','image/jpeg','image/png'];
        $file = $_FILES['file'];
        if (in_array($file['type'], $allowed, true)) {
            $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
            $filename = 'cert_' . $studentId . '_' . time() . '.' . $ext;
            $destRel = 'uploads/certificates/' . $filename;
            $destAbs = BASE_PATH . '/' . $destRel;
            if (move_uploaded_file($file['tmp_name'], $destAbs)) {
                $filePath = $destRel;
            }
        }
    }

    $stmt = $db->prepare("INSERT INTO certificates (student_id, issued_by, certificate_type, purpose, remarks, date_issued, file_path) VALUES (?,?,?,?,?,?,?)");
    $stmt->execute([$studentId, $user['id'], $certType, $purpose, $remarks, $dateIssued, $filePath]);

    log_activity($user['id'], 'Issued certificate', 'certificates', "Student #$studentId");
    json_response(true, [], 'Certificate issued successfully.');
}

if ($action === 'delete') {
    csrf_verify_or_die();
    if (!in_array($user['role'], ['nurse','admin'], true)) json_response(false, [], 'Not authorized.');

    $id = (int)($_POST['id'] ?? 0);
    $stmt = $db->prepare("SELECT * FROM certificates WHERE id=?");
    $stmt->execute([$id]);
    $cert = $stmt->fetch();
    if ($cert && $cert['file_path']) {
        $p = BASE_PATH . '/' . $cert['file_path'];
        if (file_exists($p)) unlink($p);
    }
    $db->prepare("DELETE FROM certificates WHERE id=?")->execute([$id]);
    json_response(true, [], 'Certificate deleted.');
}

json_response(false, [], 'Unknown action');
