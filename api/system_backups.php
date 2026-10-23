<?php
require_once __DIR__.'/../config/config.php';
require_admin_permission('backups_manage');
require_once __DIR__.'/../includes/backup_service.php';
header('Cache-Control: private, no-store');
$db=getDB();$user=current_user();
$service=new BackupService($db,BASE_PATH.'/storage/backups',DB_NAME);
$action=$_SERVER['REQUEST_METHOD']==='POST'?($_POST['action']??''):($_GET['action']??'list');
try {
 if(!is_string($action))throw new InvalidArgumentException('Invalid backup action.');
 if($_SERVER['REQUEST_METHOD']==='GET' && $action==='download'){
  $id=filter_var($_GET['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);if(!$id)throw new InvalidArgumentException('Invalid backup identifier.');
  $path=$service->verified($id);log_activity((int)$user['id'],'Downloaded system backup','backups','Backup record #'.$id);
  header('Content-Type: application/sql');header('Content-Disposition: attachment; filename="'.basename($path).'"');header('Content-Length: '.filesize($path));readfile($path);exit;
 }
 if($_SERVER['REQUEST_METHOD']==='GET' && $action==='list'){
  $rows=$db->query("SELECT b.id,b.status,b.filename,b.sha256,b.created_at,b.completed_at,b.error_message,CONCAT_WS(' ',u.first_name,u.last_name) requested_by_name FROM system_backups b LEFT JOIN users u ON u.id=b.requested_by ORDER BY b.id DESC LIMIT 50")->fetchAll();
  json_response(true,['backups'=>$rows]);
 }
 if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);header('Allow: POST');json_response(false,[],'Use POST for this backup action.');}
 if(!is_string($_POST['csrf_token']??null)||!csrf_verify($_POST['csrf_token'])){http_response_code(403);json_response(false,[],'Your security token expired. Refresh the page.');}
 if($action==='create'){$id=$service->create((int)$user['id']);json_response(true,['id'=>$id],'Database backup completed.');}
 $id=filter_var($_POST['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);if(!$id)throw new InvalidArgumentException('Select a valid backup.');
 if($action==='verify'){$service->verified($id);log_activity((int)$user['id'],'Verified system backup','backups','Backup record #'.$id);json_response(true,['valid'=>true],'Backup integrity verified.');}
 if($action==='restore'){
  foreach(['target_database','current_password','confirmation'] as $key)if(!is_string($_POST[$key]??null))throw new InvalidArgumentException('Complete the recovery confirmation and reauthentication.');
  $service->restore($id,trim($_POST['target_database']),(int)$user['id'],$_POST['current_password'],trim($_POST['confirmation']));
  json_response(true,[],'Recovery completed into the separate database. The active database was unchanged.');
 }
 throw new InvalidArgumentException('Unknown backup action.');
}catch(InvalidArgumentException $e){http_response_code(422);json_response(false,[],$e->getMessage());}
catch(Throwable $e){$code=in_array($e->getCode(),[403,404,409],true)?$e->getCode():503;if($action==='restore')log_activity((int)$user['id'],'Rejected backup recovery','backups','Backup record #'.(int)($_POST['id']??0));http_response_code($code);json_response(false,[],$code===503?safe_api_error($e,'Backup operation unavailable. Check storage, database schema and server logs.','system_backups'):$e->getMessage());}
