<?php
require_once __DIR__ . '/../config/config.php';
require_role('admin');
header('Content-Type: application/json');

$db = getDB();
$user = current_user();
$action = $_REQUEST['action'] ?? '';

if ($action === 'approve') {
    csrf_verify_or_die();
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) json_response(false, [], 'Invalid user ID.');

    $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$id]);
    $target = $stmt->fetch();
    if (!$target) json_response(false, [], 'User not found.');
    if ($target['status'] === 'active') json_response(false, [], 'Account is already active.');

$db->prepare("UPDATE users SET status = 'active' WHERE id = ?")->execute([$id]);

    // Auto-generate a permanent QR for approved student accounts.
    if ($target['role'] === 'student') {
        try {
            $stmt = $db->prepare("SELECT id FROM students WHERE user_id = ? LIMIT 1");
            $stmt->execute([$id]);
            $studentId = $stmt->fetchColumn();
            if ($studentId) {
                ensure_student_qr($db, (int)$studentId, $user['id']);
            }
        } catch (Exception $e) {
            error_log('QR generation on account approval failed: ' . $e->getMessage());
        }
    }

    log_activity($user['id'], 'Approved user account', 'users', "User ID $id ({$target['role']})");
    json_response(true, [], 'Account approved successfully.');
}

if ($action === 'reject') {
    csrf_verify_or_die();
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) json_response(false, [], 'Invalid user ID.');

    $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$id]);
    $target = $stmt->fetch();
    if (!$target) json_response(false, [], 'User not found.');
    if ($target['status'] === 'rejected') json_response(false, [], 'Account is already rejected.');

    $db->prepare("UPDATE users SET status = 'rejected' WHERE id = ?")->execute([$id]);
    log_activity($user['id'], 'Rejected user account', 'users', "User ID $id ({$target['role']})");
    json_response(true, [], 'Account rejected successfully.');
}

if ($action === 'deactivate') {
    csrf_verify_or_die();
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) json_response(false, [], 'Invalid user ID.');

    $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$id]);
    $target = $stmt->fetch();
    if (!$target) json_response(false, [], 'User not found.');
    if ($target['role'] === 'admin') json_response(false, [], 'Cannot deactivate an admin account.');

    $db->prepare("UPDATE users SET status = 'inactive' WHERE id = ?")->execute([$id]);
    log_activity($user['id'], 'Deactivated user account', 'users', "User ID $id ({$target['role']})");
    json_response(true, [], 'Account deactivated successfully.');
}

if ($action === 'bulk_approve') {
    csrf_verify_or_die();
    $ids = array_values(array_filter(array_map('intval', (array)($_POST['ids'] ?? [])), function ($v) { return $v > 0; }));
    if (!$ids) json_response(false, [], 'No accounts selected.');

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare("SELECT id, role, status FROM users WHERE id IN ($placeholders) AND role != 'admin'");
    $stmt->execute($ids);
    $targets = $stmt->fetchAll();

    if (!$targets) json_response(false, [], 'No eligible accounts selected.');

    $approveIds = [];
    foreach ($targets as $target) {
        if ($target['status'] !== 'active') {
            $approveIds[] = (int)$target['id'];
        }
    }

    if (!$approveIds) json_response(false, [], 'Selected accounts are already active.');

    $approvePlaceholders = implode(',', array_fill(0, count($approveIds), '?'));
    $db->prepare("UPDATE users SET status = 'active' WHERE id IN ($approvePlaceholders)")->execute($approveIds);

    foreach ($targets as $target) {
        if ($target['role'] === 'student') {
            try {
                $stmt = $db->prepare("SELECT id FROM students WHERE user_id = ? LIMIT 1");
                $stmt->execute([$target['id']]);
                $studentId = $stmt->fetchColumn();
                if ($studentId) {
                    ensure_student_qr($db, (int)$studentId, $user['id']);
                }
            } catch (Exception $e) {
                error_log('QR generation on bulk approval failed: ' . $e->getMessage());
            }
        }
        log_activity($user['id'], 'Bulk approved user account', 'users', "User ID {$target['id']} ({$target['role']})");
    }

    json_response(true, [], count($approveIds) . ' account' . (count($approveIds) > 1 ? 's' : '') . ' approved successfully.');
}

json_response(false, [], 'Unknown action');