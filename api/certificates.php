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
    $certs = $stmt->fetchAll();
    foreach ($certs as &$c) {
        $q = $db->prepare("SELECT qr_data FROM dynamic_qrs WHERE reference_id = ? AND reference_type = 'certificates' AND purpose = 'certificate_verification' AND status = 'active' LIMIT 1");
        $q->execute([$c['id']]);
        $c['verification_qr'] = $q->fetchColumn();
    }
    json_response(true, ['data' => $certs]);
}

if ($action === 'get_qr') {
    $certId = (int)($_GET['id'] ?? 0);
    if (!$certId) json_response(false, [], 'Invalid certificate.');
    $stmt = $db->prepare("SELECT student_id FROM certificates WHERE id = ?");
    $stmt->execute([$certId]);
    $cert = $stmt->fetch();
    if (!$cert) json_response(false, [], 'Certificate not found.');

    // IDOR protection: students can only access their own certificates
    if ($user['role'] === 'student') {
        $stmt = $db->prepare("SELECT id FROM students WHERE user_id=?");
        $stmt->execute([$user['id']]);
        $studentId = (int)$stmt->fetchColumn();
        if ($studentId <= 0 || (int)$cert['student_id'] !== $studentId) {
            json_response(false, [], 'Not authorized to access this certificate.');
        }
    }

    $stmt = $db->prepare("SELECT qr_data, qr_token, expires_at FROM dynamic_qrs WHERE reference_id = ? AND reference_type = 'certificates' AND purpose = 'certificate_verification' ORDER BY issued_at DESC LIMIT 1");
    $stmt->execute([$certId]);
    $qr = $stmt->fetch();
    if (!$qr) {
        $dynamicQr = generate_dynamic_qr($db, 'certificate_verification', $certId, 'certificates', $cert['student_id'], 0, $user['id']);
        json_response(true, ['qr_data' => $dynamicQr['qr_data'], 'qr_token' => $dynamicQr['qr_token'], 'expires_at' => $dynamicQr['expires_at']]);
    }
    json_response(true, ['qr_data' => $qr['qr_data'], 'qr_token' => $qr['qr_token'], 'expires_at' => $qr['expires_at']]);
}

if ($action === 'issue') {
    csrf_verify_or_die();
    if (!in_array(canonical_role($user['role']), ['nurse', 'admin'], true)) json_response(false, [], 'Not authorized. Only clinic staff can issue certificates.');

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
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $allowedExts = ['pdf', 'jpg', 'jpeg', 'png'];
            if (!in_array($ext, $allowedExts, true)) {
                json_response(false, [], 'Invalid file extension.');
            }
            $dangerous = ['php', 'phtml', 'phar', 'exe', 'sh', 'bat', 'cmd', 'js'];
            if (in_array($ext, $dangerous, true)) {
                json_response(false, [], 'Executable files are not allowed.');
            }
            $filename = 'cert_' . $studentId . '_' . time() . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
            $destRel = 'uploads/certificates/' . $filename;
            $uploadDir = dirname($destAbs);
            if (!is_dir($uploadDir)) @mkdir($uploadDir, 0777, true);
            if (move_uploaded_file($file['tmp_name'], $destAbs)) {
                $filePath = $destRel;
            }
        }
    }

    try {
        $db->beginTransaction();

        $stmt = $db->prepare("INSERT INTO certificates (student_id, issued_by, certificate_type, purpose, remarks, date_issued, file_path) VALUES (?,?,?,?,?,?,?)");
        $stmt->execute([$studentId, $user['id'], $certType, $purpose, $remarks, $dateIssued, $filePath]);
        $certId = $db->lastInsertId();

        $year = date('Y', strtotime($dateIssued));
        $seqStmt = $db->prepare("SELECT COUNT(*) FROM certificates WHERE YEAR(date_issued) = ? AND certificate_number IS NOT NULL");
        $seqStmt->execute([$year]);
        $seq = (int)$seqStmt->fetchColumn() + 1;
        $certNumber = 'MC-' . $year . '-' . str_pad($seq, 5, '0', STR_PAD_LEFT);
        $db->prepare("UPDATE certificates SET certificate_number = ? WHERE id = ?")->execute([$certNumber, $certId]);

        $dynamicQr = generate_dynamic_qr($db, 'certificate_verification', $certId, 'certificates', $studentId, 0, $user['id']);

        log_activity($user['id'], 'Issued certificate', 'certificates', "Student #$studentId, Cert #$certNumber");
        
        $db->commit();
        
        json_response(true, ['certificate_id' => $certId, 'certificate_number' => $certNumber, 'dynamic_qr' => $dynamicQr], 'Certificate issued successfully.');
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('Certificate generation failed: ' . $e->getMessage() . ' | User: ' . $user['id'] . ' | Student: ' . $studentId);
        json_response(false, [], 'Unable to generate the medical certificate. Please try again.');
    }
}

if ($action === 'delete') {
    csrf_verify_or_die();
    if (!in_array(canonical_role($user['role']), ['nurse','admin'], true)) json_response(false, [], 'Not authorized.');

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
