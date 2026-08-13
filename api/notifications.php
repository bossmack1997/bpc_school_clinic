<?php
require_once __DIR__ . '/../config/config.php';
require_login();

header('Content-Type: application/json');
$db = getDB();
$userId = current_user()['id'];
$action = $_REQUEST['action'] ?? 'list';

/**
 * Enrich notification rows with SOS emergency details (for nurse/admin)
 * so the modal can render student info + GPS for emergency notifications.
 */
function enrich_sos($db, array $notifications) {
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
            $rows = $db->query("
                SELECT se.id AS emergency_id, se.emergency_type, se.description,
                       se.status AS sos_status, se.latitude, se.longitude,
                       se.created_at AS sos_created_at, se.responded_at, se.resolved_at,
                       s.student_number, s.course, s.year_level, s.gender,
                       u.first_name, u.last_name, u.email, u.phone
                FROM sos_emergencies se
                LEFT JOIN students s ON s.id = se.student_id
                LEFT JOIN users u ON u.id = se.user_id
                WHERE se.id IN ($inList)
            ")->fetchAll();
            foreach ($rows as $row) {
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
    return $notifications;
}

// ================================================================
// Bell: latest 5 notifications + unread count (for the dropdown)
// ================================================================
if ($action === 'latest') {
    $stmt = $db->prepare("
        SELECT * FROM notifications
        WHERE user_id = ?
        ORDER BY FIELD(type, 'emergency') DESC, created_at DESC
        LIMIT 5
    ");
    $stmt->execute([$userId]);
    $notifications = $stmt->fetchAll();

    $unreadStmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $unreadStmt->execute([$userId]);
    $unreadCount = (int)$unreadStmt->fetchColumn();

    $notifications = array_map('normalize_notification', enrich_sos($db, $notifications));
    json_response(true, ['data' => $notifications, 'unread_count' => $unreadCount]);
}

// ================================================================
// Full list: filters + search + sort + pagination (Notification Center)
// ================================================================
if ($action === 'list') {
    $filter   = $_GET['filter'] ?? 'all';   // all | unread | read | emergency | appointment | medicine | inventory | student
    $search   = trim($_GET['search'] ?? '');
    $sort     = $_GET['sort'] ?? 'newest';  // newest | oldest
    $limit    = min((int)($_GET['limit'] ?? 20), 100);
    $offset   = (int)($_GET['offset'] ?? 0);

    $where = "WHERE n.user_id = ?";
    $params = [$userId];

    // Type filters
    $typeMap = [
        'emergency'      => "n.type = 'emergency'",
        'appointment'    => "n.type = 'appointment'",
        'consultation'   => "n.type = 'consultation'",
        'health'         => "n.type = 'health'",
        'bmi_update'     => "n.type = 'bmi_update'",
        'medicine'       => "n.type = 'medicine'",
        'inventory'      => "n.type = 'inventory'",
        'stock_alert'    => "n.type = 'stock_alert'",
        'announcement'   => "n.type = 'announcement'",
        'qr_checkin'     => "n.type = 'qr_checkin'",
        'qr_scan'        => "n.type = 'qr_scan'",
        'vaccination'    => "n.type = 'vaccination'",
        'system'         => "n.type = 'system'",
        'account'        => "n.type = 'account'",
        'ai_assistant'   => "n.type = 'ai_assistant'",
        'walkin'         => "n.type = 'walkin'",
        'student'        => "n.type IN ('consultation','health','walkin','medicine')",
    ];
    if ($filter === 'unread') {
        $where .= " AND n.is_read = 0";
    } elseif ($filter === 'read') {
        $where .= " AND n.is_read = 1";
    } elseif (isset($typeMap[$filter])) {
        $where .= " AND " . $typeMap[$filter];
    }

    if ($search !== '') {
        $where .= " AND (n.title LIKE ? OR n.message LIKE ?)";
        $like = '%' . $search . '%';
        $params[] = $like;
        $params[] = $like;
    }

    $order = $sort === 'oldest' ? "n.created_at ASC" : "FIELD(n.type, 'emergency') DESC, n.created_at DESC";

    // Count
    $countStmt = $db->prepare("SELECT COUNT(*) FROM notifications n $where");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    // Data
    $stmt = $db->prepare("
        SELECT n.*
        FROM notifications n
        $where
        ORDER BY $order
        LIMIT ? OFFSET ?
    ");
    $params[] = $limit;
    $params[] = $offset;
    $stmt->execute($params);
    $notifications = $stmt->fetchAll();

    $notifications = array_map('normalize_notification', enrich_sos($db, $notifications));

    // Unread count (for badge)
    $unreadStmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $unreadStmt->execute([$userId]);

    json_response(true, [
        'data' => $notifications,
        'total' => $total,
        'unread_count' => (int)$unreadStmt->fetchColumn(),
    ]);
}

// ================================================================
// Single notification details
// ================================================================
if ($action === 'details') {
    $id = (int)($_GET['id'] ?? 0);
    $db->prepare("UPDATE notifications SET is_read = 1, read_at = COALESCE(read_at, NOW()) WHERE id = ? AND user_id = ?")
       ->execute([$id, $userId]);

    $stmt = $db->prepare("SELECT * FROM notifications WHERE id = ? AND user_id = ?");
    $stmt->execute([$id, $userId]);
    $n = $stmt->fetch();
    if (!$n) json_response(false, [], 'Notification not found.');

    // SOS enrichment
    if (!empty($n['sos_emergency_id'])) {
        $n = enrich_sos($db, [$n])[0];
    }

    $n = normalize_notification($n);
    json_response(true, ['data' => $n]);
}

// ================================================================
// Mark a single notification as read
// ================================================================
if ($action === 'mark_read') {
    csrf_verify_or_die();
    $id = (int)($_POST['id'] ?? 0);
    $stmt = $db->prepare("UPDATE notifications SET is_read = 1, read_at = COALESCE(read_at, NOW()) WHERE id = ? AND user_id = ?");
    $stmt->execute([$id, $userId]);
    json_response(true);
}

// ================================================================
// Mark all as read
// ================================================================
if ($action === 'mark_all_read') {
    csrf_verify_or_die();
    $db->prepare("UPDATE notifications SET is_read = 1, read_at = COALESCE(read_at, NOW()) WHERE user_id = ? AND is_read = 0")
       ->execute([$userId]);
    json_response(true);
}

// ================================================================
// Delete a single notification
// ================================================================
if ($action === 'delete') {
    csrf_verify_or_die();
    $id = (int)($_POST['id'] ?? 0);
    $db->prepare("DELETE FROM notifications WHERE id = ? AND user_id = ?")->execute([$id, $userId]);
    json_response(true, [], 'Notification deleted.');
}

// ================================================================
// Delete multiple notifications (bulk)
// ================================================================
if ($action === 'delete_selected') {
    csrf_verify_or_die();
    $ids = $_POST['ids'] ?? [];
    if (!is_array($ids) || count($ids) === 0) json_response(false, [], 'No notifications selected.');
    $safeIds = array_values(array_filter(array_map('intval', $ids), fn($i) => $i > 0));
    if (!$safeIds) json_response(false, [], 'Invalid selection.');
    $placeholders = implode(',', array_fill(0, count($safeIds), '?'));
    $stmt = $db->prepare("DELETE FROM notifications WHERE user_id = ? AND id IN ($placeholders)");
    $stmt->execute(array_merge([$userId], $safeIds));
    json_response(true, ['deleted' => count($safeIds)], count($safeIds) . ' notification(s) deleted.');
}

json_response(false, [], 'Unknown action');
