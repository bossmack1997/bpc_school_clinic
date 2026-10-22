<?php
if(PHP_SAPI!=='cli')exit;
ini_set('session.save_path',__DIR__);require __DIR__.'/../config/config.php';
$kind=$argv[1]??'co';$case=$argv[2]??'reports';$grant=$argv[3]??'';$db=getDB();
$q=$db->prepare("SELECT * FROM users WHERE role='admin' AND admin_type=? AND status='active' LIMIT 1");$q->execute([$kind==='primary'?'primary_admin':'co_admin']);$user=$q->fetch();
$target=(int)$db->query("SELECT id FROM users WHERE role='admin' AND admin_type='co_admin' LIMIT 1")->fetchColumn();
foreach(['admin_permissions','activity_logs'] as $table){$db->exec("CREATE TEMPORARY TABLE cap_endpoint_$table LIKE $table");$db->exec("ALTER TABLE cap_endpoint_$table RENAME TO $table");}
if($grant!==''){$q=$db->prepare('INSERT INTO admin_permissions (user_id,permission_key,granted_by) VALUES (?,?,?)');foreach(explode(',',$grant) as $key)$q->execute([$user['id'],$key,$user['id']]);}
$_SESSION['user']=$user;$_SESSION['credential_version']=$user['credential_version'];$_SESSION['created']=time();$_SESSION['last_activity']=time();$_SESSION['last_db_activity_update']=time();$_SESSION['csrf_token']='cap-endpoint';
$_SERVER['REQUEST_METHOD']='GET';$_GET=[];$_POST=[];$route='';$expected=200;$check='';
switch($case){
case 'reports':$route='pages/admin/reports.php';$expected=$kind==='primary'||str_contains($grant,'view_reports')?200:403;break;
case 'security':$route='pages/admin/security.php';$expected=$kind==='primary'||str_contains($grant,'manage_security')?302:403;break;
case 'audit':$route='api/activity_logs.php';$_GET=['action'=>'list'];$expected=$kind==='primary'||str_contains($grant,'view_audit_logs')?200:403;break;
case 'sections':$route='api/sections.php';$_GET=['action'=>'list'];$expected=$kind==='primary'||str_contains($grant,'manage_sections')?200:403;break;
case 'sections-page':$route='pages/admin/sections.php';$expected=$kind==='primary'||str_contains($grant,'manage_sections')?200:403;break;
case 'oversight':$route='api/emergencies.php';$_GET=['action'=>'oversight'];$expected=$kind==='primary'||str_contains($grant,'view_emergencies')?200:403;$check='oversight';break;
case 'sos-write':$route='api/emergencies.php';$_SERVER['REQUEST_METHOD']='POST';$_POST=['action'=>'update_status','emergency_id'=>2147483647,'op'=>'accept','csrf_token'=>'cap-endpoint'];$expected=$kind==='primary'?404:403;break;
case 'settings':$route='pages/admin/settings.php';$expected=$kind==='primary'||$grant!==''?200:403;$check='settings';break;
case 'settings-security':$route='pages/admin/settings.php';$_GET=['tab'=>'security'];$expected=$kind==='primary'||str_contains($grant,'manage_security')?200:403;break;
case 'load-permissions':$route='api/settings.php';$_GET=['action'=>'get_admin_permissions','target_user_id'=>$target];$expected=$kind==='primary'?200:403;break;
case 'save-permissions':case 'csrf':case 'wrong-method':case 'audit-failure':
 $route='api/settings.php';require __DIR__.'/../includes/admin_permission_service.php';$_SERVER['REQUEST_METHOD']='POST';$_POST=['action'=>'update_admin_permissions','target_user_id'=>$target,'permissions'=>['manage_users','view_emergencies'],'permissions_revision'=>AdminPermissionService::revision($kind==='co'&&$grant!==''?explode(',',$grant):[]),'csrf_token'=>'cap-endpoint'];
 $expected=$kind==='primary'?200:403;
 if($case==='audit-failure'){$db->exec('ALTER TABLE activity_logs CHANGE action unavailable_action VARCHAR(150)');$expected=500;}
 if($case==='csrf'){$_POST['csrf_token']='bad';$expected=403;}
 if($case==='wrong-method'){$_GET=$_POST;$_POST=[];$_SERVER['REQUEST_METHOD']='GET';$expected=405;}
 break;
case 'legacy-self-save':$route='pages/admin/settings.php';$_SERVER['REQUEST_METHOD']='POST';$_POST=['action'=>'update_admin_permissions','target_user_id'=>$user['id'],'permissions'=>['manage_settings'],'csrf_token'=>'cap-endpoint'];$expected=403;break;
case 'admin-accounts':$route='api/users.php';$_GET=['action'=>'list_admins'];$expected=$kind==='primary'?200:403;break;
case 'sidebar':$route='components/sidebar.php';$check='sidebar';break;
case 'clinical-records':$route='api/medical_records.php';$_GET=['action'=>'list'];$expected=403;break;
case 'dispensing':$route='api/dispensing.php';$_GET=['action'=>'search_medicines'];$expected=403;break;
case 'queue':$route='api/queue.php';$_GET=['action'=>'queue_list'];$expected=403;break;
case 'mica-graduation':$route='api/ai_assistant.php';$_GET=['action'=>'deactivate_graduated'];$expected=403;break;
case 'mica-inventory':$route='api/ai_assistant.php';$_GET=['action'=>'reorder_list'];$expected=403;break;
case 'global-sos':$route='api/global_sos.php';$_GET=['action'=>'snapshot'];$expected=str_contains($grant,'view_emergencies')?200:403;break;
case 'qr':$route='api/qr_codes.php';$_GET=['action'=>'list'];$expected=$kind==='primary'?200:403;break;
default:throw new RuntimeException('Unknown case');
}
$_REQUEST=array_merge($_GET,$_POST);$_SERVER['REQUEST_URI']='/'.$route;$_SERVER['SCRIPT_NAME']='/'.$route;
ob_start();register_shutdown_function(function()use($route,$kind,$case,$grant,$expected,$check){
 $output=ob_get_clean();
 if(getenv('CAP_SCRIPT_QA')&&$expected===200&&!str_starts_with($route,'api/')){preg_match_all('/<script\b[^>]*>(.*?)<\/script>/si',$output,$matches);file_put_contents(__DIR__.'/cap-rendered-inline.js',implode("\n;\n",$matches[1]));}$status=http_response_code()?:200;$json=str_starts_with($route,'api/')?json_decode($output,true):null;$ok=$status===$expected&&(!str_starts_with($route,'api/')||is_array($json));
 if(str_starts_with($route,'api/')&&$status===200)$ok=$ok&&($json['success']??false);
 if($case==='audit-failure')$ok=$ok&&!getDB()->inTransaction()&&getDB()->query('SELECT COUNT(*) FROM admin_permissions')->fetchColumn()==0;
 if($check==='settings'&&$kind==='co'&&$status===200){$ok=$ok&&!str_contains($output,'id="permissionsModal"')&&!str_contains($output,'id="settingsPanel-admins"');if(!str_contains($grant,'view_audit_logs'))$ok=$ok&&!str_contains($output,'admin-activity-logs.js');}
 if($check==='oversight'&&$kind==='co'&&$status===200)foreach($json['reports'] as $row)$ok=$ok&&$row['allowed_actions']===[];
 if($check==='sidebar'){
  foreach(['manage_users'=>'users.php','manage_sections'=>'sections.php','view_reports'=>'reports.php','view_emergencies'=>'emergencies.php'] as $permission=>$file){$visible=$kind==='primary'||str_contains($grant,$permission);$ok=$ok&&(str_contains($output,'/pages/admin/'.$file)===$visible);}
  $ok=$ok&&(str_contains($output,'/pages/admin/admin_accounts.php')===($kind==='primary'));
 }
 session_destroy();echo ($ok?'PASS':'FAIL').' '.$kind.' '.$case.' ['.($grant?:'no grants').'] HTTP '.$status.PHP_EOL;if(!$ok)exit(1);
});
require __DIR__.'/../'.$route;
