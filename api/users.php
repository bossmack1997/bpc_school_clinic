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

json_response(false, [], 'Unknown action');