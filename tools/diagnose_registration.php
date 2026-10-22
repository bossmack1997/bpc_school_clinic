<?php
/** Read-only, aggregate-only registration diagnostics. Run on the affected server via PHP CLI. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
ini_set('session.save_path',sys_get_temp_dir());
require __DIR__.'/../config/config.php';
require __DIR__.'/../includes/account_monitoring.php';
$db=getDB();$monitor=new AccountMonitoringService($db);
$report=['environment'=>APP_ENV,'database_is_loopback'=>in_array(DB_HOST,['localhost','127.0.0.1','::1'],true),'schema'=>[],'source_hashes'=>[]];
foreach(['users'=>['status','email_verified','email_verified_at','self_service_only','registration_status','risk_level','validation_reason','otp_hash','otp_expires_at','otp_attempts'],'students'=>['user_id','student_number'],'teachers'=>['user_id','employee_id'],'nurses'=>['user_id','employee_id','license_number'],'account_security_events'=>['event_type','subject_key','user_id','details']] as $table=>$required) {
    try {
        $columns=$db->query("SHOW COLUMNS FROM $table")->fetchAll(PDO::FETCH_ASSOC);
        $present=array_column($columns,'Field');
        $report['schema'][$table]=['missing_columns'=>array_values(array_diff($required,$present))];
    } catch(PDOException $e) {$report['schema'][$table]=['unavailable'=>true,'sqlstate'=>(string)$e->getCode()];}
}
foreach(['auth/register.php','auth/login.php','auth/verify_registration.php','includes/account_monitoring.php','includes/institutional_eligibility.php','includes/ai/MICARegistrationValidator.php','includes/auth.php'] as $file) {
    $source=file_get_contents(__DIR__.'/../'.$file);
    $report['source_hashes'][$file]=hash('sha256',str_replace("\r\n","\n",$source));
}
try {
    $report['roster_counts']=$db->query('SELECT role,status,COUNT(*) AS count FROM institutional_roster GROUP BY role,status')->fetchAll();
} catch(PDOException $e) {$report['roster_counts']=['unavailable'=>true];}
$reasons=[];
try {
    $q=$db->query("SELECT id,role,first_name,last_name,email,status,email_verified,registration_status,risk_level FROM users WHERE status='pending' AND email_verified=1");
    foreach($q->fetchAll() as $user) {
        $code=$user['registration_status']==='rejected'?'rejected':(in_array($user['role'],['student','teacher'],true)?'basic_activation_ready':'staff_provisioning_required');
        $key=$user['role'].':'.$code;$reasons[$key]=($reasons[$key]??0)+1;
    }
    $report['verified_pending_counts']=$reasons;
} catch(PDOException $e) {$report['verified_pending_counts']=['schema_error'=>true,'sqlstate'=>(string)$e->getCode()];}
echo json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
