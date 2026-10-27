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
    foreach(['page','search','module','from','to'] as $key)if(isset($_GET[$key])&&!is_scalar($_GET[$key])){http_response_code(400);json_response(false,[],'Invalid audit filter.');}
    $search = mb_substr(trim($_GET['search'] ?? ''),0,150);

    $where = "WHERE al.module IN ('auth','security','admin','account_security','admin_profiles','admin_permissions','backup','backups','settings','users','system')";
    $params = [];
    if ($search !== '') {
        $where .= " AND (u.first_name LIKE ? OR u.last_name LIKE ? OR u.username LIKE ? OR al.action LIKE ? OR al.module LIKE ? OR al.details LIKE ?)";
        $like = '%' . $search . '%';
        $params = array_fill(0,6,$like);
    }

    if(!empty($_GET['module'])){$where.=' AND al.module=?';$params[]=mb_substr((string)$_GET['module'],0,80);}
    foreach(['from'=>'>=','to'=>'<='] as $key=>$operator){if(!empty($_GET[$key])){if(!preg_match('/^\d{4}-\d{2}-\d{2}$/D',(string)$_GET[$key])){http_response_code(400);json_response(false,[],'Use a valid date filter.');}$where.=" AND al.created_at $operator ?";$params[]=$_GET[$key].($key==='from'?' 00:00:00':' 23:59:59');}}
    $countStmt = $db->prepare("SELECT COUNT(*) FROM activity_logs al LEFT JOIN users u ON u.id = al.user_id $where");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();
    $pages=max(1,(int)ceil($total/$limit));$page=min($page,$pages);$offset=($page-1)*$limit;

    $stmt = $db->prepare("
        SELECT al.id,al.user_id,al.action,al.module,al.details,al.created_at, u.first_name, u.last_name, u.role
        FROM activity_logs al
        LEFT JOIN users u ON u.id = al.user_id
        $where
        ORDER BY al.created_at DESC,al.id DESC
        LIMIT $limit OFFSET $offset
    ");
    $stmt->execute($params);

    json_response(true, [
        'data' => $stmt->fetchAll(),
        'total' => (int)$total,
        'page' => $page,
        'pages' => $pages
    ]);
}

json_response(false, [], 'Unknown action');
