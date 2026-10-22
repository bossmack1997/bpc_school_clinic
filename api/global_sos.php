<?php
require_once __DIR__ . '/../config/config.php';
require_role(['admin','nurse']);
if(current_user()['role']==='admin')require_admin_permission('view_emergencies');
require_once __DIR__ . '/../includes/global_sos_service.php';
header('Cache-Control: no-store, private');
set_exception_handler(function(Throwable $e) { if(getDB()->inTransaction())getDB()->rollBack(); error_log('SOS snapshot: '.$e->getMessage()); http_response_code(500); json_response(false, [], 'Emergency alerts are temporarily unavailable. Please retry.'); });
$service=new GlobalSosService(getDB());
$user=current_user();
$action=$_GET['action'] ?? $_POST['action'] ?? 'snapshot';
if ($action==='acknowledge_notification') {
    if ($_SERVER['REQUEST_METHOD']!=='POST') {http_response_code(405);header('Allow: POST');json_response(false,[],'Use POST.');}
    if (!csrf_verify($_POST['csrf_token'] ?? '')) { http_response_code(403); json_response(false, [], 'Your security token expired. Refresh and try again.'); }
    try { $service->acknowledgeNotifications((int)$user['id'],GlobalSosService::identifiers($_POST['ids'] ?? null)); }
    catch(InvalidArgumentException $e) { http_response_code(400);json_response(false,[],$e->getMessage()); }
} elseif ($action!=='snapshot' || $_SERVER['REQUEST_METHOD']!=='GET') {
    http_response_code(400);json_response(false,[],'Invalid alert request.');
}
json_response(true,$service->snapshot((int)$user['id']));
