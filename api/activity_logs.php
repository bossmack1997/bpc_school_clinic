<?php
require_once __DIR__ . '/../config/config.php';
require_admin_permission('view_audit_logs');
header('Content-Type: application/json');

$db = getDB();
$user = current_user();
$action = $_REQUEST['action'] ?? '';

if ($action === 'list') {
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = 30;
    $offset = ($page - 1) * $limit;
    $search = trim($_GET['search'] ?? '');

    $where = '';
    $params = [];
    if ($search !== '') {
        $where = "WHERE (u.first_name LIKE ? OR u.last_name LIKE ? OR al.action LIKE ? OR al.module LIKE ?)";
        $like = '%' . $search . '%';
        $params = [$like, $like, $like, $like];
    }

    $countStmt = $db->prepare("SELECT COUNT(*) FROM activity_logs al LEFT JOIN users u ON u.id = al.user_id $where");
    $countStmt->execute($params);
    $total = $countStmt->fetchColumn();

    $stmt = $db->prepare("
        SELECT al.*, u.first_name, u.last_name, u.role
        FROM activity_logs al
        LEFT JOIN users u ON u.id = al.user_id
        $where
        ORDER BY al.created_at DESC
        LIMIT $limit OFFSET $offset
    ");
    $stmt->execute($params);

    json_response(true, [
        'data' => $stmt->fetchAll(),
        'total' => (int)$total,
        'page' => $page,
        'pages' => ceil($total / $limit)
    ]);
}

json_response(false, [], 'Unknown action');
