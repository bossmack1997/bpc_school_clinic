<?php
/** Characterization audit. PASS confirms a finding, not production safety. Local synthetic DB only. */
if(PHP_SAPI!=='cli')exit;
ini_set('session.save_path',__DIR__.'/../scratch');
require __DIR__.'/../config/config.php';
if(APP_ENV!=='local'||!in_array(DB_HOST,['localhost','127.0.0.1','::1'],true))throw new RuntimeException('Local only');
require_once __DIR__.'/../includes/nurse_verification_service.php';
$db=getDB();$name='bpc_nurse_auth_test_'.bin2hex(random_bytes(5));$count=0;
function finding(bool $ok,string $label):void{global $count;if(!$ok)throw new RuntimeException($label);++$count;echo "CONFIRMED $label\n";}
try{
$db->exec("CREATE DATABASE `$name`");$db->exec("USE `$name`");
$db->exec("CREATE TABLE users (id INT PRIMARY KEY,role VARCHAR(20),status VARCHAR(20),email_verified TINYINT,self_service_only TINYINT NOT NULL DEFAULT 0,credential_version INT UNSIGNED NOT NULL DEFAULT 0,reviewed_by INT,reviewed_at DATETIME,validation_reason TEXT)");
$db->exec("CREATE TABLE nurses (id INT PRIMARY KEY,user_id INT,clinical_authorization_status VARCHAR(30),clinical_reviewed_by INT,clinical_reviewed_at DATETIME,clinical_review_notes TEXT)");
$db->exec('CREATE TABLE admin_profiles (user_id INT PRIMARY KEY,profile VARCHAR(20))');
$db->exec('CREATE TABLE activity_logs (id INT AUTO_INCREMENT PRIMARY KEY,user_id INT,action VARCHAR(200),module VARCHAR(100),details TEXT)');
$db->exec("INSERT INTO users (id,role,status,email_verified,self_service_only) VALUES (1,'nurse','active',1,0),(2,'admin','active',1,0)");
$db->exec("INSERT INTO admin_profiles VALUES (2,'doctor')");$db->exec("INSERT INTO nurses (id,user_id,clinical_authorization_status) VALUES(1,1,'pending')");
$clinical=['clinic_monitor','clinical_records_read','consultations_manage','inventory_oversight','view_emergencies','view_reports','clinical_messages','inventory_manage','emergencies_respond'];
foreach(['pending','needs_information','rejected','revoked','authorized'] as $status){
    $db->prepare('UPDATE nurses SET clinical_authorization_status=?')->execute([$status]);
    $nurse=$db->query('SELECT u.*,n.clinical_authorization_status FROM users u JOIN nurses n ON n.user_id=u.id WHERE u.id=1')->fetch(PDO::FETCH_ASSOC);
    $all=true;foreach($clinical as $permission)$all=$all&&has_permission($permission,$nurse,$db);
    finding($all,"self_service_only=0 grants all Nurse permissions with status=$status");
}
$nurse['self_service_only']=1;$any=false;foreach($clinical as $permission)$any=$any||has_permission($permission,$nurse,$db);
finding(!$any,'self_service_only=1 denies permission helper grants');
finding(NurseVerificationService::normalizeStatus(null,0,'active')==='authorized','Missing explicit status is presented as authorized for legacy scope zero');
$db->exec("UPDATE users SET self_service_only=1 WHERE id=1");$db->exec("UPDATE nurses SET clinical_authorization_status='pending'");
$actor=$db->query('SELECT * FROM users WHERE id=2')->fetch(PDO::FETCH_ASSOC);
$service=new NurseVerificationService($db,$actor);$service->authorize(1,'synthetic review audit finding');
finding((int)$db->query('SELECT self_service_only FROM users WHERE id=1')->fetchColumn()===0,'Service grants access when review audit table is absent');
finding((int)$db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='nurse_clinical_reviews'")->fetchColumn()===0,'Grant succeeds without dedicated clinical review history');
$db->exec('DELETE FROM nurses WHERE user_id=1');$db->exec('UPDATE users SET self_service_only=1 WHERE id=1');
$service=new NurseVerificationService($db,$actor);$service->authorize(1,'synthetic missing profile finding');
finding((int)$db->query('SELECT self_service_only FROM users WHERE id=1')->fetchColumn()===0,'Service grants access without a Nurse profile row');
echo "All $count findings reproduced. AUTHORIZATION RELEASE IS NO-GO. Production not accessed.\n";
}finally{if(preg_match('/^bpc_nurse_auth_test_[a-f0-9]{10}$/',$name))$db->exec("DROP DATABASE IF EXISTS `$name`");}
