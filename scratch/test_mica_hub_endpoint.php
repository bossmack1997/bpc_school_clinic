<?php
if(PHP_SAPI!=='cli')exit;
ini_set('session.save_path',__DIR__);require __DIR__.'/../config/config.php';
$db=getDB();foreach(['users','students','medicines','medicine_logs','system_settings','notifications','admin_permissions','sos_emergencies','ai_actions','mica_scans','mica_alert_events','mica_alert_reads','consultations','appointments','queue_numbers'] as $t){$db->exec("CREATE TEMPORARY TABLE api_hub_$t LIKE $t");$db->exec("ALTER TABLE api_hub_$t RENAME TO $t");}
$role=$argv[1]??'admin';$action=$argv[2]??'daily_summary';$bad=($argv[3]??'')==='bad';
$db->prepare("INSERT INTO users (id,username,email,password_hash,first_name,last_name,role,status) VALUES (991,'hub-api','hub-api@example.invalid','x','Hub','API',?,'active')")->execute([$role]);
$user=$db->query('SELECT * FROM users WHERE id=991')->fetch();$_SESSION['user']=$user;$_SESSION['credential_version']=0;$_SESSION['created']=time();$_SESSION['last_activity']=time();$_SESSION['last_db_activity_update']=time();$_SESSION['csrf_token']='hub-api-csrf';
$_SERVER['REQUEST_METHOD']=in_array($action,['run_automation','read_alert'],true)?'POST':'GET';$_REQUEST=['action'=>$action,'csrf_token'=>$bad?'bad':'hub-api-csrf','action_id'=>1];$_POST=$_REQUEST;$_SERVER['REQUEST_URI']='/api/ai_assistant.php';
$expected=in_array($role,['student','teacher'],true)||$bad?403:200;
ob_start();register_shutdown_function(function()use($expected,$role,$action){$raw=ob_get_clean();$body=json_decode($raw,true);$code=http_response_code()?:200;$ok=$expected===$code && is_array($body) && ($expected===200?($body['success']??false):!($body['success']??true));echo ($ok?'PASS':'FAIL')." MICA API $role $action HTTP $code\n";if(!$ok){echo substr($raw,0,500);exit(1);}session_destroy();});
require __DIR__.'/../api/ai_assistant.php';
