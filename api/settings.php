<?php
require_once __DIR__ . '/../config/config.php';
require_role('admin');
header('Content-Type: application/json');

$db = getDB();
$user = current_user();
$action = $_REQUEST['action'] ?? '';

if (in_array($action,['get_admin_permissions','update_admin_permissions'],true)) {
    require_primary_admin();
    $method=$action==='get_admin_permissions'?'GET':'POST';
    if($_SERVER['REQUEST_METHOD']!==$method){http_response_code(405);header('Allow: '.$method);json_response(false,[],'Use '.$method.'.');}
    if($method==='POST'&&!csrf_verify($_POST['csrf_token']??'')){http_response_code(403);json_response(false,[],'Your security token expired. Refresh and try again.');}
    require_once __DIR__.'/../includes/admin_permission_service.php';
    $target=filter_var(($method==='POST'?$_POST:$_GET)['target_user_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    if(!$target){http_response_code(422);json_response(false,[],'Select a valid co-admin account.');}
    try {
        $service=new AdminPermissionService($db);
        $data=$method==='GET'?$service->read((int)$user['id'],$target):$service->save((int)$user['id'],$target,$_POST['permissions']??[],$_POST['permissions_revision']??null);
        json_response(true,$data,$method==='POST'?'Co-admin permissions saved.':'');
    }catch(Throwable $e){
        $code=in_array($e->getCode(),[403,404,409,422],true)?$e->getCode():500;
        if($code===500)error_log('Admin permissions: '.$e->getMessage());
        http_response_code($code);json_response(false,[],$code===500?'Permissions could not be saved. No changes were applied.':$e->getMessage());
    }
}
require_admin_permission('manage_settings');

if ($action === 'list') {
    $stmt = $db->query("SELECT setting_key, setting_value FROM system_settings ORDER BY setting_key");
    $settings = [];
    foreach ($stmt->fetchAll() as $row) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
    json_response(true, ['data' => $settings]);
}

if ($action === 'update') {
    csrf_verify_or_die();
    $updates = $_POST['settings'] ?? [];
    if (empty($updates) || !is_array($updates)) json_response(false, [], 'No settings provided.');

    $stmt = $db->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = ?");
    foreach ($updates as $key => $value) {
        $stmt->execute([trim($value), trim($key)]);
    }
    log_activity($user['id'], 'Updated system settings', 'settings');
    json_response(true, [], 'Settings saved successfully.');
}

json_response(false, [], 'Unknown action');
