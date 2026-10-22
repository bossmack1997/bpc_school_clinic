<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
ini_set('session.save_path',__DIR__);
require __DIR__.'/../config/config.php';
$role=$argv[1]??'nurse';$case=$argv[2]??'list';
$q=getDB()->prepare("SELECT * FROM users WHERE role=? AND status='active' LIMIT 1");$q->execute([$role]);$user=$q->fetch();
$_SESSION['user']=$user;$_SESSION['created']=time();$_SESSION['last_activity']=time();$_SESSION['last_db_activity_update']=time();$_SESSION['credential_version']=$user['credential_version'];$_SESSION['csrf_token']='sos-endpoint-test';
$_GET=[];$_POST=[];$_SERVER['REQUEST_METHOD']='POST';$_SERVER['REQUEST_URI']='/api/emergencies.php';
$endpoint='emergencies';
switch($case){
case 'list':$_SERVER['REQUEST_METHOD']='GET';$_GET=['action'=>'list','history'=>'1'];break;
case 'snapshot':$_SERVER['REQUEST_METHOD']='GET';$_GET=['action'=>'snapshot'];$endpoint='global_sos';break;
case 'csrf':$_POST=['action'=>'update_status'];break;
case 'method':$_SERVER['REQUEST_METHOD']='GET';$_GET=['action'=>'update_status'];break;
case 'legacy':$endpoint='ai_assistant';$_POST=['action'=>'sos_update_status','status'=>'accept','emergency_id'=>2147483647,'csrf_token'=>'sos-endpoint-test'];break;
case 'expired':$_SESSION['last_activity']=time()-7200;$_GET=['action'=>'list'];$_SERVER['REQUEST_METHOD']='GET';break;
default:$_POST=['action'=>'update_status','op'=>'accept','emergency_id'=>2147483647,'csrf_token'=>'sos-endpoint-test'];
}
$_REQUEST=array_merge($_GET,$_POST);
ob_start();register_shutdown_function(function()use($role,$case){$body=json_decode(ob_get_clean(),true);echo json_encode(['role'=>$role,'case'=>$case,'http'=>http_response_code()?:200,'valid_json'=>is_array($body),'success'=>$body['success']??null,'message'=>$body['message']??null,'code'=>$body['error_code']??null]).PHP_EOL;});
require __DIR__.'/../api/'.$endpoint.'.php';
