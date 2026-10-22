<?php
if(PHP_SAPI!=='cli')exit;
ini_set('session.save_path',__DIR__);require __DIR__.'/../config/config.php';
$role=$argv[1]??'student';$page=$argv[2]??'dashboard';$folder=$role==='teacher'?'adviser':$role;
$q=getDB()->prepare("SELECT * FROM users WHERE role=? AND status='active' ORDER BY id LIMIT 1");$q->execute([$role]);$user=$q->fetch();
$_SESSION['user']=$user;$_SESSION['credential_version']=$user['credential_version'];$_SESSION['created']=time();$_SESSION['last_activity']=time();$_SESSION['last_db_activity_update']=time();
$_GET=[];$_POST=[];$_SERVER['REQUEST_METHOD']='GET';$_SERVER['SCRIPT_NAME']='/pages/'.$folder.'/'.$page.'.php';$_SERVER['REQUEST_URI']=$_SERVER['SCRIPT_NAME'];
ob_start();register_shutdown_function(function()use($role,$page){$html=ob_get_clean();$code=http_response_code()?:200;$ok=$code===200&&str_contains($html,'<body')&&str_contains($html,'sidebar');if($page==='dashboard')$ok=$ok&&str_contains($html,'mica-assistant');session_destroy();echo ($ok?'PASS':'FAIL').' rendered '.$role.' '.$page.' HTTP '.$code.PHP_EOL;if(!$ok)exit(1);});
require __DIR__.'/../pages/'.$folder.'/'.$page.'.php';
