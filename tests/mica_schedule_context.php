<?php
/** Isolated aggregate policy test; no school rows copied or changed. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/../config/config.php';
require __DIR__.'/../includes/ai/MICAContextService.php';
if(APP_ENV!=='local'||!in_array(DB_HOST,['localhost','127.0.0.1','::1'],true))throw new RuntimeException('Local loopback fixture required.');
$admin=getDB();$name='bpc_mica_context_test_'.bin2hex(random_bytes(5));$checks=0;
function checkSchedule($ok,$label){global $checks;if(!$ok)throw new RuntimeException($label);$checks++;echo 'PASS '.$label.PHP_EOL;}
try {
    $admin->exec("CREATE DATABASE `$name`");
    $db=new PDO('mysql:host='.DB_HOST.';port='.DB_PORT.';dbname='.$name.';charset=utf8mb4',DB_USER,DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $db->exec('CREATE TABLE system_settings(setting_key VARCHAR(100) PRIMARY KEY,setting_value TEXT)');
    $db->exec("INSERT INTO system_settings VALUES ('clinic_open_time','10:15'),('clinic_close_time','14:45'),('clinic_operating_days','2,4'),('clinic_manual_status','closed')");
    $context=new MICAContextService($db,['id'=>1,'role'=>'student']);$method=new ReflectionMethod($context,'getSharedClinicContextString');
    $text=$method->invoke($context);
    checkSchedule(str_contains($text,'10:15 AM')&&str_contains($text,'2:45 PM')&&str_contains($text,'Tuesday, Thursday'),'MICA schedule reflects actual configured hours and operating days');
    checkSchedule(str_contains($text,'Current status: Closed')&&!str_contains($text,'monitored 24/7')&&!str_contains($text,'Ground Floor'),'MICA respects staff closure and makes no invented staffing or location claim');
    $db->exec("UPDATE system_settings SET setting_value='11:30' WHERE setting_key='clinic_open_time'");
    checkSchedule(str_contains($method->invoke($context),'11:30 AM'),'MICA refreshes changed schedule without a new session');
    $db->exec('DROP TABLE system_settings');
    $unavailable=$method->invoke($context);
    checkSchedule(str_contains($unavailable,'Current status: Unavailable')&&str_contains($unavailable,'cannot be verified')&&!str_contains($unavailable,'8:00 AM'),'MICA reports unavailable policy storage without inventing hours');
    echo "$checks MICA schedule checks passed.\n";
}finally{if(preg_match('/^bpc_mica_context_test_[a-f0-9]{10}$/D',$name))$admin->exec("DROP DATABASE IF EXISTS `$name`");}
