<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
ini_set('session.save_path',__DIR__);
require __DIR__.'/../config/config.php';
$role=$argv[1]??'admin';$case=$argv[2]??'list';
$q=getDB()->prepare("SELECT * FROM users WHERE role=? AND status='active' LIMIT 1");$q->execute([$role]);$user=$q->fetch();
if(!$user){fwrite(STDERR,'No test account for role'.PHP_EOL);exit(1);}
$_SESSION['user']=$user;$_SESSION['created']=time();$_SESSION['last_activity']=time();$_SESSION['last_db_activity_update']=time();$_SESSION['credential_version']=$user['credential_version'];
$_SERVER['REQUEST_URI']='/api/emergencies.php';$_SERVER['REQUEST_METHOD']='GET';$_GET=['action'=>'oversight','tab'=>'history','per_page'=>'25'];$_POST=[];
$expected=$role==='admin'?200:403;
if($case==='method'){$_SERVER['REQUEST_METHOD']='POST';$_POST=['action'=>'oversight'];$_GET=[];$expected=405;}
if($case==='invalid'){$_GET['date_from']='2026-02-30';$expected=422;}
if($case==='missing'){$_GET=['action'=>'oversight_detail','emergency_id'=>2147483647];$expected=404;}
if($case==='detail'){
 $id=getDB()->query('SELECT id FROM sos_emergencies ORDER BY id DESC LIMIT 1')->fetchColumn();
 $_GET=['action'=>'oversight_detail','emergency_id'=>$id?:2147483647];if(!$id)$expected=404;
}
$_REQUEST=array_merge($_GET,$_POST);
ob_start();register_shutdown_function(function()use($role,$case,$expected){
 $body=json_decode(ob_get_clean(),true);$status=http_response_code()?:200;
 $ok=is_array($body)&&$status===$expected&&!getDB()->inTransaction();
 if($expected===200){$ok=$ok&&($body['success']??false);if($case==='list'){$ok=$ok&&isset($body['summary']['total'],$body['pagination']['total'])&&count($body['reports'])<=25;}}
 session_destroy();echo ($ok?'PASS':'FAIL').' oversight API '.$role.' '.$case.' HTTP '.$status.' JSON/transaction boundary'.PHP_EOL;if(!$ok)exit(1);
});
require __DIR__.'/../api/emergencies.php';
