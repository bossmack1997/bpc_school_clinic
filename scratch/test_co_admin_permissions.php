<?php
if(PHP_SAPI!=='cli')exit;
ob_start();
ini_set('session.save_path',__DIR__);require __DIR__.'/../config/config.php';
set_exception_handler(function(Throwable $e){fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);});
require __DIR__.'/../includes/admin_permission_service.php';
$db=getDB();foreach(['users','admin_permissions','activity_logs'] as $table){$db->exec("CREATE TEMPORARY TABLE cap_test_$table LIKE $table");$db->exec("ALTER TABLE cap_test_$table RENAME TO $table");}
$db->exec("INSERT INTO users (id,username,email,password_hash,first_name,last_name,role,admin_type,status) VALUES (901,'cap-primary','cap-primary@example.invalid','preserved-secret','Primary','Test','admin','primary_admin','active'),(902,'cap-co','cap-co@example.invalid','preserved-co-secret','Co','Test','admin','co_admin','active'),(903,'cap-nurse','cap-nurse@example.invalid','x','Nurse','Test','nurse',NULL,'active')");
$db->exec("INSERT INTO admin_permissions (user_id,permission_key,granted_by,granted_at) VALUES (902,'manage_users',901,'2020-01-01 00:00:00'),(902,'legacy_custom',901,'2020-01-01 00:00:00'),(902,'approve_admins',901,'2020-01-01 00:00:00')");
$primary=$db->query('SELECT * FROM users WHERE id=901')->fetch();$co=$db->query('SELECT * FROM users WHERE id=902')->fetch();$beforeUsers=$db->query('SELECT * FROM users ORDER BY id')->fetchAll();$service=new AdminPermissionService($db);
function capCheck($ok,$label){if(!$ok)throw new RuntimeException($label);echo 'PASS '.$label.PHP_EOL;}
function capDenied($code,callable $fn,$label){try{$fn();throw new LogicException('Expected denial: '.$label);}catch(RuntimeException $e){capCheck($e->getCode()===$code,$label);}}
$initial=$service->read(901,902);capCheck(in_array('manage_users',$initial['permissions'],true),'Saved checkbox keys load');
capDenied(403,fn()=>$service->read(902,902),'Co-admin cannot load privileged permission editor');
capDenied(403,fn()=>$service->save(902,902,['manage_settings'],$initial['revision']),'Co-admin cannot change own permissions');
capDenied(403,fn()=>$service->save(901,901,[],$initial['revision']),'Primary account protected');
capDenied(404,fn()=>$service->save(901,999999,[],$initial['revision']),'Missing target rejected');
capDenied(422,fn()=>$service->save(901,902,['primary_admin'],$initial['revision']),'Privilege escalation key rejected');
capDenied(422,fn()=>$service->save(901,902,'manage_users',$initial['revision']),'Malformed permissions rejected');
$saved=$service->save(901,902,['manage_users','view_reports','view_reports','view_emergencies'],$initial['revision']);
capCheck(has_admin_permission('view_reports',$co,$db)&&has_admin_permission('view_emergencies',$co,$db),'Granted permissions apply to existing user object/session');
capCheck($db->query("SELECT granted_at FROM admin_permissions WHERE user_id=902 AND permission_key='manage_users'")->fetchColumn()==='2020-01-01 00:00:00','Unchanged grant metadata preserved');
capCheck(in_array('legacy_custom',$saved['permissions'],true)&&in_array('approve_admins',$saved['permissions'],true),'Unmanaged legacy grants preserved without granting privileged access');
$logs=$db->query('SELECT COUNT(*) FROM activity_logs')->fetchColumn();
$service->save(901,902,['manage_users','view_reports','view_emergencies'],$initial['revision']);capCheck($logs==$db->query('SELECT COUNT(*) FROM activity_logs')->fetchColumn(),'Exact stale retry has no duplicate rows or audit');
capDenied(409,fn()=>$service->save(901,902,['manage_security'],$initial['revision']),'Stale conflicting save rejected atomically');
$revoked=$service->save(901,902,[],$saved['revision']);capCheck(!has_admin_permission('view_reports',$co,$db)&&!has_admin_permission('manage_users',$co,$db),'Revocation effective on next lookup without logout');
capCheck(in_array('legacy_custom',$revoked['permissions'],true),'Empty selection preserves unmanaged rows');
foreach(AdminPermissionService::KEYS as $key){$result=$service->save(901,902,[$key],$service->read(901,902)['revision']);capCheck(has_admin_permission($key,$co,$db),'Grant enforced: '.$key);}
capCheck(is_primary_admin($primary)&&!is_primary_admin($co),'Primary/co-admin classification unchanged');
capCheck(!admin_can_respond_sos($co)&&admin_can_respond_sos($primary),'Co-admin clinical SOS action blocked independently of grants');
// Exercise real session initialization/revalidation using temporary accounts.
login_user($co);capCheck(validate_session()&&current_user()['id']===902,'Co-admin session login helper and revalidation');
$latest=$service->read(901,902);capCheck($latest['permissions']===(new AdminPermissionService($db))->read(901,902)['permissions'],'Permissions survive service/session reload');
login_user($primary);capCheck(validate_session()&&is_primary_admin(),'Primary session login helper and revalidation');
$afterUsers=$db->query('SELECT id,role,admin_type,status,password_hash FROM users ORDER BY id')->fetchAll();
$immutable=array_map(fn($row)=>array_intersect_key($row,array_flip(['id','role','admin_type','status','password_hash'])),$beforeUsers);
capCheck($immutable==$afterUsers,'Account IDs, roles, classifications, status and credentials preserved');
// Audit write failure must roll back both grant and revoke deltas.
$baseline=$service->read(901,902);$db->exec('ALTER TABLE activity_logs CHANGE action unavailable_action VARCHAR(150)');
try{$service->save(901,902,['manage_security'],$baseline['revision']);throw new LogicException('Audit failure ignored');}catch(PDOException $e){}
capCheck($baseline['permissions']===$service->read(901,902)['permissions']&&!$db->inTransaction(),'Audit failure rolls back permission transaction');
require __DIR__.'/../includes/ai/MICAActionService.php';
$mica=new MICAActionService($db,$co);
capCheck(!$mica->createReorderRequest(999999,1,'test')['success']&&!$mica->approveReorderRequest(999999,1)['success']&&!$mica->rejectReorderRequest(999999)['success'],'MICA cannot bypass restricted inventory administration');
capCheck(!$mica->deactivateGraduatedStudent(999999)['success'],'MICA cannot bypass revoked user management permission');
session_destroy();echo "PASS temporary MySQL tables only; no real account or permission changes.\n";


