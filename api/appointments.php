<?php
require_once __DIR__ . '/../config/config.php';
require_login();
header('Content-Type: application/json');

$db = getDB();
$user = current_user();
$action = $_REQUEST['action'] ?? '';

function get_student_id($db, $userId) {
    $stmt = $db->prepare("SELECT id FROM students WHERE user_id = ?");
    $stmt->execute([$userId]);
    return $stmt->fetchColumn();
}
function get_nurse_id($db, $userId) {
    $stmt = $db->prepare("SELECT id FROM nurses WHERE user_id = ?");
    $stmt->execute([$userId]);
    return $stmt->fetchColumn();
}

if ($action === 'list') {
    if ($user['role'] === 'student') {
        $studentId = get_student_id($db, $user['id']);
        $stmt = $db->prepare("SELECT a.*, u.first_name, u.last_name FROM appointments a
            LEFT JOIN nurses n ON n.id = a.nurse_id LEFT JOIN users u ON u.id = n.user_id
            WHERE a.student_id = ? ORDER BY a.appointment_date DESC, a.appointment_time DESC");
        $stmt->execute([$studentId]);
    } else {
        // nurse / admin see all
        $stmt = $db->query("SELECT a.*, s.student_number, su.first_name AS s_first, su.last_name AS s_last
            FROM appointments a
            JOIN students s ON s.id = a.student_id
            JOIN users su ON su.id = s.user_id
            ORDER BY a.appointment_date DESC, a.appointment_time DESC");
    }
    json_response(true, ['data' => $stmt->fetchAll()]);
}

if ($action === 'book') {
    csrf_verify_or_die();
    if ($user['role'] !== 'student') json_response(false, [], 'Only students can book appointments.');

    $studentId = get_student_id($db, $user['id']);
    $date   = $_POST['appointment_date'] ?? '';
    $time   = $_POST['appointment_time'] ?? '';
    $reason = trim($_POST['reason'] ?? '');

    if (!$date || !$time || !$reason) json_response(false, [], 'All fields are required.');
    if (strtotime($date) < strtotime(date('Y-m-d'))) json_response(false, [], 'Cannot book a date in the past.');

    $stmt = $db->prepare("INSERT INTO appointments (student_id, appointment_date, appointment_time, reason, status) VALUES (?,?,?,?,'pending')");
    $stmt->execute([$studentId, $date, $time, $reason]);
    log_activity($user['id'], 'Booked appointment', 'appointments', "$date $time");
    json_response(true, [], 'Appointment request submitted. Awaiting nurse approval.');
}

if ($action === 'update_status') {
    csrf_verify_or_die();
    if ($user['role'] !== 'nurse') json_response(false, [], 'Not authorized. Only nurses can manage appointments.');

    $id = (int)($_POST['id'] ?? 0);
    $status = $_POST['status'] ?? '';
    $newDate = $_POST['new_date'] ?? null;
    $newTime = $_POST['new_time'] ?? null;

    $allowed = ['approved','rejected','completed','cancelled','rescheduled'];
    if (!in_array($status, $allowed, true)) json_response(false, [], 'Invalid status.');

    $stmt = $db->prepare("SELECT * FROM appointments WHERE id = ?");
    $stmt->execute([$id]);
    $appt = $stmt->fetch();
    if (!$appt) json_response(false, [], 'Appointment not found.');

    $nurseId = $user['role'] === 'nurse' ? get_nurse_id($db, $user['id']) : $appt['nurse_id'];

    if ($user['role'] === 'nurse' && $appt['nurse_id'] !== null && $appt['nurse_id'] !== $nurseId) {
        json_response(false, [], 'Not authorized to update this appointment.');
    }

    if ($status === 'rescheduled' && $newDate && $newTime) {
        $stmt = $db->prepare("UPDATE appointments SET status='rescheduled', appointment_date=?, appointment_time=?, nurse_id=? WHERE id=?");
        $stmt->execute([$newDate, $newTime, $nurseId, $id]);
    } else {
        $stmt = $db->prepare("UPDATE appointments SET status=?, nurse_id=? WHERE id=?");
        $stmt->execute([$status, $nurseId, $id]);
    }

    // notify student
    $studentUserStmt = $db->prepare("SELECT u.id FROM students s JOIN users u ON u.id = s.user_id WHERE s.id = ?");
    $studentUserStmt->execute([$appt['student_id']]);
    $studentUserId = $studentUserStmt->fetchColumn();
    if ($studentUserId) {
        push_notification($studentUserId, 'appointment', 'Appointment ' . ucfirst($status), "Your appointment on " . $appt['appointment_date'] . " was $status.", APP_URL . '/pages/student/appointments.php');
    }

    log_activity($user['id'], "Appointment $status", 'appointments', "ID $id");
    json_response(true, [], 'Appointment updated.');
}

json_response(false, [], 'Unknown action');
