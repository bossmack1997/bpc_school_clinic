<?php
if(PHP_SAPI!=='cli')exit;
ini_set('session.save_path',__DIR__);
require __DIR__.'/../config/config.php';
$q=getDB()->prepare("SELECT * FROM users WHERE role=? AND status='active' LIMIT 1");$q->execute([$argv[1]??'nurse']);$user=$q->fetch();
$_SESSION['user']=$user;$_SESSION['created']=time();$_SESSION['last_activity']=time();$_SESSION['last_db_activity_update']=time();$_SESSION['credential_version']=$user['credential_version'];$_SERVER['REQUEST_URI']='/pages/'.($argv[1]??'nurse').'/emergencies.php';$_SERVER['REQUEST_METHOD']='GET';
ob_start();include __DIR__.'/../pages/'.($argv[1]??'nurse').'/emergencies.php';$html=ob_get_clean();
preg_match_all('/<script\b[^>]*>(.*?)<\/script>/si',$html,$scripts);
file_put_contents(__DIR__.'/sos-rendered-'.($argv[1]??'nurse').'.js',implode("\n;\n",$scripts[1]));
echo 'Rendered '.($argv[1]??'nurse').' inline scripts: '.count($scripts[1]).PHP_EOL;
session_destroy();
