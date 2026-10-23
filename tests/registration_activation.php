<?php
/** CLI integration regression. Temporary tables shadow real tables on this connection only. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
ob_start();
ini_set('session.save_path',sys_get_temp_dir());
require __DIR__.'/../config/config.php';
require __DIR__.'/../includes/account_monitoring.php';
require __DIR__.'/../includes/ai/MICARegistrationValidator.php';
set_exception_handler(function(Throwable $e){fwrite(STDERR,'FAIL '.$e->getMessage().PHP_EOL);exit(1);});
$db=getDB();
foreach(['users','students','teachers','nurses','institutional_roster','student_qrs','teacher_qrs','account_security_events','account_security_exceptions','admin_permissions','activity_logs'] as $table){
    $db->exec("CREATE TEMPORARY TABLE activation_test_$table LIKE $table");
    $db->exec("ALTER TABLE activation_test_$table RENAME TO $table");
}
$checks=0;
function checkActivation(bool $ok,string $label): void {
    global $checks;
    if(!$ok)throw new RuntimeException($label);
    $checks++;echo "PASS $label\n";
}
function fixture(int $id,string $role='student',bool $trusted=true,string $status='pending',int $verified=0): void {
    global $db;
    $q=$db->prepare("INSERT INTO users(id,username,email,password_hash,first_name,last_name,role,status,email_verified,registration_status,otp_hash,otp_expires_at) VALUES (?,?,?,?,'Alex','Fixture',?,?,?,'review',?,DATE_ADD(NOW(),INTERVAL 10 MINUTE))");
    $q->execute([$id,'activation_'.$id,'activation_'.$id.'@example.invalid',password_hash('FixturePass!42',PASSWORD_DEFAULT),$role,$status,$verified,hash('sha256','123456')]);
    if($role==='admin')return;
    $table=['student'=>'students','teacher'=>'teachers','nurse'=>'nurses'][$role];
    $column=$role==='student'?'student_number':'employee_id';
    $db->prepare("INSERT INTO $table(user_id,$column".($role==='nurse'?',license_number':'').") VALUES (?,?".($role==='nurse'?',?':'').")")->execute(array_merge([$id,'ACT-'.$id],$role==='nurse'?['7654321']:[]));
    if($trusted)$db->prepare("INSERT INTO institutional_roster(identifier,role,first_name,last_name,institutional_email,status,license_number) VALUES (?,?,'Alex','Fixture',?,'eligible',?)")->execute(['ACT-'.$id,$role,'activation_'.$id.'@example.invalid',$role==='nurse'?'7654321':null]);
}
function account(int $id): array {global $db;$q=$db->prepare('SELECT * FROM users WHERE id=?');$q->execute([$id]);return $q->fetch();}
$monitor=new AccountMonitoringService($db);
$delivered=[];
$validator=new MICARegistrationValidator($db,function($email,$otp)use(&$delivered,$db){
    checkActivation(!$db->inTransaction(),'Email transport runs after commit');
    $delivered[$email]=$otp;return true;
});
foreach(['student','teacher','nurse'] as $i=>$role){
    $id=100+$i;fixture($id,$role,false);
    checkActivation(!account($id)['email_verified']&&account($id)['status']==='pending',"$role begins unverified");
    checkActivation($validator->resendOtp($id)['status']==='sent',"$role OTP delivery accepted by isolated transport");
    $otp=$delivered['activation_'.$id.'@example.invalid'];
    $result=$validator->verifyOtp($id,$otp);
    checkActivation($result['account_status']==='active' && account($id)['email_verified'],"$role OTP activates without institutional matching");
    checkActivation(account($id)['self_service_only']==1 && !empty(account($id)['email_verified_at']),"$role activation records timestamp and personal-only scope");
    checkActivation(empty(account($id)['otp_hash'])&&empty(account($id)['otp_expires_at']),"$role challenge consumed");
    login_user(account($id));checkActivation((int)$_SESSION['user']['id']===$id,"$role can receive authenticated session");unset($_SESSION['user']);
    checkActivation($validator->verifyOtp($id,$otp)['status']==='blocked',"$role duplicate OTP cannot activate twice");
}
foreach(['student','teacher','nurse','admin'] as $i=>$role){
    $id=200+$i;fixture($id,$role,false);
    $expected=in_array($role,['student','teacher','nurse'],true)?'active':'pending';
    checkActivation($validator->verifyOtp($id,'123456')['account_status']===$expected,"$role OTP preserves basic versus privileged activation boundary");
    checkActivation($validator->resendOtp($id)['status']==='blocked','Verified email does not receive another OTP');
}
checkActivation(!admin_can_view_emergencies(account(202)) && !admin_can_respond_sos(account(202)) && !has_permission('clinical_records_read',account(202)),'Basic nurse has no clinical, emergency monitoring or response permissions');
fixture(300);checkActivation($validator->verifyOtp(300,'999999')['status']==='invalid'&&!account(300)['email_verified'],'Incorrect OTP cannot verify or activate');
for($i=0;$i<4;$i++)$validator->verifyOtp(300,'999999');
checkActivation(account(300)['status']==='rejected'&&$validator->verifyOtp(300,'123456')['status']==='blocked','Retry limit blocks even a subsequently correct OTP');
fixture(301);$db->exec('UPDATE users SET otp_expires_at=DATE_SUB(NOW(),INTERVAL 1 SECOND) WHERE id=301');
checkActivation($validator->verifyOtp(301,'123456')['status']==='expired'&&!account(301)['email_verified'],'Expired OTP does not activate');
foreach(['inactive','suspended','rejected'] as $i=>$status){
    $id=400+$i;fixture($id,'student',true,$status,1);
    checkActivation($monitor->reconcileForLogin($id)['status']===$status,"$status cannot be reactivated by login");
    try{login_user(account($id));throw new LogicException('Session unexpectedly created');}catch(RuntimeException $e){checkActivation(true,"$status cannot receive a session");}
}
fixture(500,'student',true,'pending',1);
checkActivation($validator->verifyOtp(500,'999999')['account_status']==='pending','An already-verified account is not activated by an arbitrary OTP submission');
checkActivation($monitor->reconcileForLogin(500)['status']==='active','Existing verified pending account is individually reevaluated');
$events=(int)$db->query('SELECT COUNT(*) FROM account_security_events WHERE user_id=500')->fetchColumn();
$monitor->reconcileForLogin(500);
checkActivation((int)$db->query('SELECT COUNT(*) FROM account_security_events WHERE user_id=500')->fetchColumn()===$events,'Repeated reconciliation does not duplicate activation');
fixture(501,'teacher',false,'pending',1);$monitor->reconcileForLogin(501);$monitor->reconcileForLogin(501);
checkActivation((int)$db->query('SELECT COUNT(*) FROM account_security_events WHERE user_id=501')->fetchColumn()===1,'Untrusted review is not logged repeatedly on login');
fixture(502,'nurse');$db->exec("UPDATE institutional_roster SET role='teacher' WHERE identifier='ACT-502'");
checkActivation($validator->verifyOtp(502,'123456')['account_status']==='active' && account(502)['self_service_only']==1,'Roster role mismatch cannot grant clinical permissions');
fixture(503,'nurse');$db->exec("UPDATE institutional_roster SET license_number='different' WHERE identifier='ACT-503'");
checkActivation($validator->verifyOtp(503,'123456')['account_status']==='active' && account(503)['self_service_only']==1,'License mismatch cannot grant clinical permissions');
fixture(504);$db->exec("UPDATE institutional_roster SET status='revoked' WHERE identifier='ACT-504'");
checkActivation($validator->verifyOtp(504,'123456')['account_status']==='active' && account(504)['self_service_only']==1,'Institutional matching is not a prerequisite for basic student services');
fixture(505,'student',true,'pending',1);$db->exec("UPDATE users SET registration_status='rejected' WHERE id=505");
checkActivation($monitor->reconcileForLogin(505)['registration_status']==='rejected'&&account(505)['status']==='pending','Rejected assessment is never erased by automatic evaluation');
fixture(506,'student',true,'active',0);
try{login_user(account(506));throw new LogicException('Unverified session created');}catch(RuntimeException $e){checkActivation(true,'Unverified public account cannot receive a session');}
checkActivation($monitor->reconcileForLogin(506)['status']==='pending','Legacy active unverified public account resumes OTP instead of protected access');
checkActivation(AccountMonitoringService::statusLabel(account(501))==='Active','Basic teacher is active without a review requirement');
checkActivation(AccountMonitoringService::statusLabel(account(301))==='Email Verification Pending','Unverified pending label identifies email');
$counts=$monitor->counts();checkActivation($counts['total']===$counts['active']+$counts['inactive']+$counts['verification'],'MIS account statistics reconcile');
checkActivation($monitor->directory(['tab'=>'email_pending'],true)['total']===2,'Email pending filter uses email verification flag');
checkActivation($monitor->directory(['tab'=>'staff_pending'],true)['total']>=1,'Privileged pending accounts have a separate staff provisioning filter');
checkActivation($monitor->eligibility(account(201))['code']==='roster_missing','Missing institutional evidence has a precise restricted-state reason');
checkActivation($monitor->eligibility(account(502))['code']==='role_mismatch','Role mismatch has a precise restricted-state reason');
checkActivation($monitor->eligibility(account(503))['code']==='license_mismatch','License mismatch has a precise restricted-state reason');
checkActivation($monitor->eligibility(account(504))['code']==='roster_ineligible','Revoked roster has a precise restricted-state reason');
fixture(507);$db->exec("UPDATE institutional_roster SET first_name=CONCAT(CHAR(194,160),'Alex',CHAR(194,160)),institutional_email='  activation_507@example.invalid  ' WHERE identifier='ACT-507'");
checkActivation($validator->verifyOtp(507,'123456')['account_status']==='active','Harmless source name whitespace and email padding do not block a trusted identity');
$db->exec('ALTER TABLE institutional_roster CHANGE license_number unavailable_license_number VARCHAR(100)');
checkActivation($monitor->eligibility(account(503))['code']==='source_unavailable','Legacy roster missing required nursing evidence cannot grant nurse access');
fixture(509,'student',false);$db->exec("INSERT INTO institutional_roster(identifier,role,first_name,last_name,institutional_email,status) VALUES ('ACT-509','student','Alex','Fixture','activation_509@example.invalid','eligible')");
checkActivation($validator->verifyOtp(509,'123456')['account_status']==='active','Legacy roster without nursing-license column still activates a trusted student');
$db->exec('ALTER TABLE institutional_roster CHANGE unavailable_license_number license_number VARCHAR(100)');
$db->exec('ALTER TABLE institutional_roster CHANGE institutional_email unavailable_institutional_email VARCHAR(191)');
fixture(508,'student',false);
checkActivation($validator->verifyOtp(509,'123456')['status']==='blocked','Schema change cannot reopen an already active OTP challenge');
checkActivation($monitor->eligibility(account(507))['code']==='source_unavailable','Outdated deployment schema is distinguished from identity failure');
checkActivation($validator->verifyOtp(508,'123456')['account_status']==='active' && account(508)['self_service_only']==1,'Basic activation does not depend on institutional schema');
$db->exec('ALTER TABLE institutional_roster CHANGE unavailable_institutional_email institutional_email VARCHAR(191)');
require __DIR__.'/../includes/account_security_exceptions.php';
fixture(700,'admin',false,'active',1);$db->exec("UPDATE users SET admin_type='primary_admin' WHERE id=700");
$security=new AccountSecurityExceptionService($db);
$exception=$security->open(700,202,'Authorized nurse provisioning after professional checks','LOCAL-STAFF-EVIDENCE');
$security->resolve(700,$exception,'verify','Authorized officer confirmed professional credentials and staff access');
checkActivation(account(202)['status']==='active' && account(202)['self_service_only']==0 && account(202)['reviewed_by']==700,'Only audited authorized provisioning grants nurse clinical scope');
login_user(account(202));checkActivation((current_user()['role']??'')==='nurse','Provisioned nurse retains clinical sign-in');unset($_SESSION['user']);
fixture(600);
$db->exec('ALTER TABLE account_security_events CHANGE details unavailable_details VARCHAR(255)');
try{$validator->verifyOtp(600,'123456');throw new LogicException('Missing audit unexpectedly succeeded');}catch(PDOException $e){}
checkActivation(!account(600)['email_verified']&&account(600)['status']==='pending'&&!empty(account(600)['otp_hash']),'Activation failure rolls back email verification and preserves the OTP for retry');
echo "All $checks activation regression checks passed; no permanent records modified.\n";
