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

        $stmt = $db->prepare("SELECT * FROM vaccinations WHERE student_id=? ORDER BY date_administered DESC");
        $stmt->execute([$studentId]);
    } else {
        $studentId = (int)($_GET['student_id'] ?? 0);
        if ($studentId > 0) {
            $stmt = $db->prepare("SELECT v.*, u.first_name, u.last_name FROM vaccinations v JOIN students s ON s.id=v.student_id JOIN users u ON u.id=s.user_id WHERE v.student_id=? ORDER BY v.date_administered DESC");
            $stmt->execute([$studentId]);
        } else {
            $stmt = $db->query("SELECT v.*, u.first_name, u.last_name, s.student_number FROM vaccinations v JOIN students s ON s.id=v.student_id JOIN users u ON u.id=s.user_id ORDER BY v.date_administered DESC");
        }
    }
    json_response(true, ['data' => $stmt->fetchAll()]);
}

if ($action === 'add') {
    csrf_verify_or_die();
    if ($user['role'] !== 'nurse') json_response(false, [], 'Not authorized. Only nurses can add vaccination records.');

    $studentId = (int)($_POST['student_id'] ?? 0);
    $vaccineName = trim($_POST['vaccine_name'] ?? '');
    $doseNumber = trim($_POST['dose_number'] ?? '');
    $dateAdministered = $_POST['date_administered'] ?? '';
    $nextDoseDate = $_POST['next_dose_date'] ?? null;

    if (!$studentId || !$vaccineName || !$dateAdministered) json_response(false, [], 'All fields are required.');

    $stmt = $db->prepare("INSERT INTO vaccinations (student_id, vaccine_name, dose_number, date_administered, administered_by, next_dose_date) VALUES (?,?,?,?,?,?)");
    $stmt->execute([$studentId, $vaccineName, $doseNumber, $dateAdministered, $user['first_name'] . ' ' . $user['last_name'], $nextDoseDate]);

    log_activity($user['id'], 'Added vaccination record', 'vaccinations', "Student #$studentId");
    json_response(true, [], 'Vaccination record added.');
}

if ($action === 'delete') {
    csrf_verify_or_die();
    if (!in_array($user['role'], ['nurse','admin'], true)) json_response(false, [], 'Not authorized.');

    $id = (int)($_POST['id'] ?? 0);
    $db->prepare("DELETE FROM vaccinations WHERE id=?")->execute([$id]);
    json_response(true, [], 'Record deleted.');
}

json_response(false, [], 'Unknown action');
