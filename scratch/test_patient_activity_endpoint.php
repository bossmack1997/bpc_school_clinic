<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
ini_set('session.save_path',__DIR__);
require __DIR__.'/../config/config.php';
$db=getDB();$role=$argv[1]??'student';
$q=$db->prepare("SELECT * FROM users WHERE role=? AND status='active' LIMIT 1");$q->execute([$role]);$user=$q->fetch();
$_SESSION['user']=$user;$_SESSION['created']=time();$_SESSION['last_activity']=time();$_SESSION['last_db_activity_update']=time();
$_GET=['action'=>$argv[2]??'patient_scan_history','limit'=>5];
if(in_array($role,['nurse','admin'],true))$_GET['student_id']=(int)$db->query('SELECT id FROM students ORDER BY id LIMIT 1')->fetchColumn();
if(($argv[3]??'')==='tamper')$_GET['patient_id']=999999;
$_REQUEST=$_GET;$_SERVER['REQUEST_METHOD']='GET';$_SERVER['REQUEST_URI']='/api/qr_codes.php';
ob_start();register_shutdown_function(function(){ $result=json_decode(ob_get_clean(),true);echo json_encode(['success'=>$result['success']??null,'total'=>$result['total']??null,'rows'=>count($result['data']??$result['timeline']??[]),'message'=>$result['message']??null]).PHP_EOL; });
require __DIR__.'/../api/qr_codes.php';
