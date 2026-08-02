<?php
require_once __DIR__ . '/../config/config.php';
require_login();
header('Content-Type: application/json');

$db = getDB();
$user = current_user();
$action = $_REQUEST['action'] ?? '';

if ($action === 'list') {
    $audience = $user['role'];
    if ($audience === 'admin') {
        $stmt = $db->query("SELECT a.*, u.first_name, u.last_name FROM announcements a LEFT JOIN users u ON u.id = a.posted_by ORDER BY a.is_pinned DESC, a.created_at DESC");
    } else {
        $stmt = $db->prepare("SELECT a.*, u.first_name, u.last_name FROM announcements a LEFT JOIN users u ON u.id = a.posted_by WHERE a.audience IN ('all', ?) ORDER BY a.is_pinned DESC, a.created_at DESC");
        $stmt->execute([$audience]);
    }
    json_response(true, ['data' => $stmt->fetchAll()]);
}

if ($action === 'add') {
    csrf_verify_or_die();
    if ($user['role'] !== 'nurse') json_response(false, [], 'Not authorized. Only nurses can post announcements.');

    $title = trim($_POST['title'] ?? '');
    $content = trim($_POST['content'] ?? '');
    $audience = $_POST['audience'] ?? 'all';
    $is_pinned = !empty($_POST['is_pinned']) ? 1 : 0;

    if (!$title || !$content) json_response(false, [], 'Title and content are required.');

    $stmt = $db->prepare("INSERT INTO announcements (title, content, posted_by, audience, is_pinned) VALUES (?,?,?,?,?)");
    $stmt->execute([$title, $content, $user['id'], $audience, $is_pinned]);
    log_activity($user['id'], 'Added announcement', 'announcements', $title);
    json_response(true, [], 'Announcement posted.');
}

if ($action === 'delete') {
    csrf_verify_or_die();
    if ($user['role'] !== 'admin') json_response(false, [], 'Not authorized.');

    $id = (int)($_POST['id'] ?? 0);
    $stmt = $db->prepare("DELETE FROM announcements WHERE id=?");
    $stmt->execute([$id]);
    log_activity($user['id'], 'Deleted announcement', 'announcements', "ID $id");
    json_response(true, [], 'Announcement deleted.');
}

if ($action === 'toggle_pin') {
    csrf_verify_or_die();
    if ($user['role'] !== 'admin') json_response(false, [], 'Not authorized.');

    $id = (int)($_POST['id'] ?? 0);
    $stmt = $db->prepare("UPDATE announcements SET is_pinned = NOT is_pinned WHERE id=?");
    $stmt->execute([$id]);
    json_response(true, [], 'Updated.');
}

json_response(false, [], 'Unknown action');
