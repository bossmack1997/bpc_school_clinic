<?php
require_once __DIR__ . '/../config/config.php';
require_login();

header('Content-Type: application/json');
$db = getDB();
$userId = current_user()['id'];
$action = $_REQUEST['action'] ?? 'list';

if ($action === 'list') {
    // Emergency (SOS) notifications always appear at the top, newest first.
    $stmt = $db->prepare("
        SELECT * FROM notifications
        WHERE user_id = ?
        ORDER BY FIELD(type, 'emergency') DESC, created_at DESC
        LIMIT 50
    ");
    $stmt->execute([$userId]);
    $notifications = $stmt->fetchAll();

    // Get unread count
    $unreadStmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $unreadStmt->execute([$userId]);
    $unreadCount = (int)$unreadStmt->fetchColumn();

    // Enrich emergency (SOS) notifications with full SOS details so the
    // notification bell can render student info + GPS without extra calls.
    $sosIds = [];
    foreach ($notifications as $n) {
        if (!empty($n['sos_emergency_id'])) {
            $sosIds[(int)$n['sos_emergency_id']] = (int)$n['sos_emergency_id'];
        }
    }

    $sosMap = [];
    if ($sosIds) {
        $inList = implode(',', array_map('intval', array_keys($sosIds)));
        try {
            $sosStmt = $db->query("
                SELECT
                    se.id AS emergency_id,
                    se.emergency_type,
                    se.description,
                    se.status AS sos_status,
                    se.latitude,
                    se.longitude,
                    se.created_at AS sos_created_at,
                    se.responded_at,
                    se.resolved_at,
                    s.student_number,
                    s.course,
                    s.year_level,
                    s.gender,
                    u.first_name,
                    u.last_name,
                    u.email,
                    u.phone
                FROM sos_emergencies se
                LEFT JOIN students s ON s.id = se.student_id
                LEFT JOIN users u ON u.id = se.user_id
                WHERE se.id IN ($inList)
            ");
            $sosRows = $sosStmt->fetchAll();
            foreach ($sosRows as $row) {
                $sosMap[(int)$row['emergency_id']] = $row;
            }
        } catch (PDOException $e) {
            error_log('[Notifications] SOS detail lookup failed: ' . $e->getMessage());
        }
    }

    foreach ($notifications as &$n) {
        $n['sos'] = null;
        if (!empty($n['sos_emergency_id']) && isset($sosMap[(int)$n['sos_emergency_id']])) {
            $n['sos'] = $sosMap[(int)$n['sos_emergency_id']];
        }
    }
    unset($n);

    json_response(true, ['data' => $notifications, 'unread_count' => $unreadCount]);
}

if ($action === 'mark_read') {
    csrf_verify_or_die();
    $id = (int)($_POST['id'] ?? 0);
    $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
    $stmt->execute([$id, $userId]);
    json_response(true);
}

if ($action === 'mark_all_read') {
    csrf_verify_or_die();
    $db->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?")->execute([$userId]);
    json_response(true);
}

json_response(false, [], 'Unknown action');
