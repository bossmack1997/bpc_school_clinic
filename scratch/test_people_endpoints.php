<?php
if(PHP_SAPI!=='cli')exit;ini_set('session.save_path',__DIR__);require __DIR__.'/../config/config.php';
$db=getDB();foreach(['users','students','teachers','nurses','student_qrs','teacher_qrs','admin_permissions','account_security_exceptions','activity_logs','notifications'] as $t){$db->exec("CREATE TEMPORARY TABLE people_page_$t LIKE $t");$db->exec("ALTER TABLE people_page_$t RENAME TO $t");}
$case=$argv[1]??'directory';$role=$argv[2]??'admin';$type=($role==='co'?'co_admin':'primary_admin');if($role==='co')$role='admin';
$db->prepare("INSERT INTO users(id,username,email,password_hash,first_name,last_name,role,admin_type,status,email_verified) VALUES (700,'people-http','people-http@example.invalid','x','<script>','People',?,?,'active',1)")->execute([$role,$role==='admin'?$type:null]);
$db->exec("INSERT INTO users(id,username,email,password_hash,first_name,last_name,role,status,email_verified) VALUES (701,'target-person','target-person@example.invalid','x','Target','Person','student','pending',0)");
$user=$db->query('SELECT * FROM users WHERE id=700')->fetch();$_SESSION['user']=$user;$_SESSION['credential_version']=0;$_SESSION['created']=time();$_SESSION['last_activity']=time();$_SESSION['last_db_activity_update']=time();$_SESSION['csrf_token']='people-csrf';
$_SERVER['REQUEST_METHOD']='GET';$_GET=[];$_POST=[];$file='pages/admin/users.php';$expected=$role==='admin'?($type==='co_admin'?403:200):302;
if($case==='directory_post'){$_SERVER['REQUEST_METHOD']='POST';$_POST=['action'=>'approve_account','user_id'=>701,'csrf_token'=>'people-csrf'];$expected=405;}
if($case==='malformed'){$_GET=['role'=>['admin']];$expected=400;}
if($case==='exception'){$file='pages/admin/account_exceptions.php';}
if($case==='legacy'){$file='api/users.php';$_SERVER['REQUEST_METHOD']='POST';$_POST=['action'=>'approve','id'=>701,'csrf_token'=>'people-csrf'];$expected=$role==='admin'?($type==='co_admin'?403:410):302;}
if($case==='provision'){$file='api/users.php';$_SERVER['REQUEST_METHOD']='POST';$_POST=['action'=>'create_admin_account','csrf_token'=>'people-csrf','first_name'=>'Provisioned','last_name'=>'Fixture','username'=>'provisioned_fixture','email'=>'provisioned@example.invalid','password'=>'StrongFixture123!','confirm_password'=>'StrongFixture123!','status'=>'active'];$expected=$type==='co_admin'?403:200;}
if($case==='bootstrap'){$file='setup/initial_admin.php';$db->exec("UPDATE users SET role='student',admin_type=NULL WHERE id=700");putenv('INITIAL_ADMIN_SETUP_TOKEN=');$expected=503;}
$_REQUEST=array_merge($_GET,$_POST);$_SERVER['REQUEST_URI']='/'.$file;$_SERVER['SCRIPT_NAME']=$_SERVER['REQUEST_URI'];
ob_start();register_shutdown_function(function()use($db,$case,$role,$expected){$raw=ob_get_clean();$code=http_response_code()?:200;$ok=$code===$expected;
 if($case==='directory'&&$expected===200)$ok=$ok&&str_contains($raw,'People Directory &amp; Account Monitoring')&&str_contains($raw,'target-person')&&!str_contains($raw,'name="action" value="approve_account"')&&str_contains($raw,'&lt;script&gt;');
 if(in_array($case,['legacy','directory_post'],true))$ok=$ok&&$db->query('SELECT status FROM users WHERE id=701')->fetchColumn()==='pending';
 if($case==='provision'&&$expected===200){$body=json_decode($raw,true);$ok=$ok&&($body['success']??false)&&$db->query("SELECT admin_type FROM users WHERE username='provisioned_fixture'")->fetchColumn()==='co_admin';}
 echo ($ok?'PASS':'FAIL')." people page/API $case $role HTTP $code\n";if(!$ok){echo substr(strip_tags($raw),0,400).PHP_EOL;exit(1);}session_destroy();});
require __DIR__.'/../'.$file;

