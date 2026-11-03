<?php
require_once __DIR__ . '/../config/config.php';
require_admin_permission('view_audit_logs');
require_once __DIR__ . '/../includes/activity_history.php';
header('Content-Type: application/json');
header('Cache-Control: no-store, private');

$db = getDB();
$user = current_user();
$action = $_REQUEST['action'] ?? '';

if ($action === 'list') {
    foreach(['page','search','from','to'] as $key)if(isset($_GET[$key])&&!is_string($_GET[$key])){http_response_code(400);json_response(false,[],'Choose valid search and date filters.');}
    $page = min(1000000,max(1, (int)($_GET['page'] ?? 1)));
    $limit = 30;
    $offset = ($page - 1) * $limit;
    $search = mb_substr(trim($_GET['search'] ?? ''),0,150);

    $activity = activity_history_expression();
    $where = 'WHERE 1=1';
    $params = [];
    if ($search !== '') {
        $where .= " AND (CONCAT_WS(' ',u.first_name,u.last_name) LIKE ? OR u.role LIKE ? OR ($activity) LIKE ?)";
        $like = '%' . $search . '%';
        $params = array_fill(0,3,$like);
    }

    foreach(['from'=>'>=','to'=>'<='] as $key=>$operator){
        if(empty($_GET[$key]))continue;
        $date=DateTimeImmutable::createFromFormat('!Y-m-d',$_GET[$key],new DateTimeZone('Asia/Manila'));
        if(!$date||$date->format('Y-m-d')!==$_GET[$key]){http_response_code(400);json_response(false,[],'Choose a valid date.');}
        $where.=" AND al.created_at $operator ?";
        $params[]=$_GET[$key].($key==='from'?' 00:00:00':' 23:59:59');
    }
    if(!empty($_GET['from'])&&!empty($_GET['to'])&&$_GET['from']>$_GET['to']){http_response_code(400);json_response(false,[],'The end date must be on or after the start date.');}
    $countStmt = $db->prepare("SELECT COUNT(*) FROM activity_logs al LEFT JOIN users u ON u.id = al.user_id $where");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();
    $pages=max(1,(int)ceil($total/$limit));$page=min($page,$pages);$offset=($page-1)*$limit;

    $stmt = $db->prepare("
        SELECT al.id,($activity) AS activity,al.created_at, u.first_name, u.last_name, u.role
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
