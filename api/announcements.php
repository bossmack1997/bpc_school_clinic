<?php
require_once __DIR__ . '/../config/config.php';
require_login();
header('Content-Type: application/json');

$db = getDB();
$user = current_user();
$action = $_REQUEST['action'] ?? '';

if ($action === 'list') {
    $audience = $user['role'];
    if ($audience === 'admin' || $audience === 'nurse') {
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
    $announcementId = (int)$db->lastInsertId();
    log_activity($user['id'], 'Added announcement', 'announcements', $title);

    $targetRoles = [];
    if ($audience === 'all') {
        $targetRoles = ['admin', 'student', 'nurse', 'adviser', 'guidance'];
    } elseif ($audience === 'students') {
        $targetRoles = ['student'];
    } elseif ($audience === 'teachers') {
        $targetRoles = ['adviser'];
    } elseif ($audience === 'guidance') {
        $targetRoles = ['guidance'];
    } elseif ($audience === 'nurses') {
        $targetRoles = ['nurse'];
    }

    if ($targetRoles) {
        $inList = implode(',', array_fill(0, count($targetRoles), '?'));
        $stmt = $db->prepare("SELECT id, role FROM users WHERE role IN ($inList) AND id != ?");
        $stmt->execute(array_merge($targetRoles, [(int)$user['id']]));
        $targetUsers = $stmt->fetchAll();

        foreach ($targetUsers as $targetUser) {
            $roleDir = $targetUser['role'];
            $link = APP_URL . '/pages/' . $roleDir . '/announcements.php';
            push_notification(
                (int)$targetUser['id'],
                'announcement',
                'New Clinic Announcement',
                $title,
                $link,
                null,
                'medium',
                $announcementId
            );
        }
    }

    json_response(true, [], 'Announcement posted.');
}

if ($action === 'delete') {
    csrf_verify_or_die();
    if ($user['role'] !== 'nurse') json_response(false, [], 'Not authorized.');

    $id = (int)($_POST['id'] ?? 0);
    $stmt = $db->prepare("DELETE FROM announcements WHERE id=?");
    $stmt->execute([$id]);
    log_activity($user['id'], 'Deleted announcement', 'announcements', "ID $id");
    json_response(true, [], 'Announcement deleted.');
}

if ($action === 'edit') {
    csrf_verify_or_die();
    if ($user['role'] !== 'nurse') json_response(false, [], 'Not authorized.');

    $id = (int)($_POST['id'] ?? 0);
    $title = trim($_POST['title'] ?? '');
    $content = trim($_POST['content'] ?? '');
    $audience = $_POST['audience'] ?? 'all';
    $is_pinned = !empty($_POST['is_pinned']) ? 1 : 0;

    if (!$title || !$content) json_response(false, [], 'Title and content are required.');

    $stmt = $db->prepare("UPDATE announcements SET title=?, content=?, audience=?, is_pinned=? WHERE id=?");
    $stmt->execute([$title, $content, $audience, $is_pinned, $id]);
    log_activity($user['id'], 'Updated announcement', 'announcements', "ID $id");
    json_response(true, [], 'Announcement updated.');
}

if ($action === 'toggle_pin') {
    csrf_verify_or_die();
    if ($user['role'] !== 'nurse') json_response(false, [], 'Not authorized.');

    $id = (int)($_POST['id'] ?? 0);
    $stmt = $db->prepare("UPDATE announcements SET is_pinned = NOT is_pinned WHERE id=?");
    $stmt->execute([$id]);
    json_response(true, [], 'Updated.');
}

json_response(false, [], 'Unknown action');
