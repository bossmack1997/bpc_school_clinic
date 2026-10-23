<?php
/** Phase 1 integration: disposable LOCAL schema, synthetic accounts, fake mail only. */
if(PHP_SAPI!=='cli')exit;
ini_set('session.save_path',__DIR__.'/../scratch');require __DIR__.'/../config/config.php';
if(APP_ENV!=='local'||!in_array(DB_HOST,['localhost','127.0.0.1','::1'],true))throw new RuntimeException('Local fixture required');
require __DIR__.'/../includes/doctor_invitation_service.php';
set_exception_handler(function(Throwable $e){fwrite(STDERR,'FAIL Doctor Phase 1: '.$e->getMessage().PHP_EOL);exit(1);});
$admin=getDB();$database='bpc_doctor_test_'.bin2hex(random_bytes(5));$checks=0;
function doctorCheck($ok,$label){global $checks;if(!$ok)throw new RuntimeException($label);$checks++;echo "PASS $label\n";}
function doctorSql($db,$name){$sql=preg_replace('/^\s*--.*$/m','',file_get_contents(__DIR__.'/../database/'.$name));foreach(array_filter(array_map('trim',explode(';',$sql)))as$s){$q=$db->query($s);$q->closeCursor();}}
try{
$admin->exec("CREATE DATABASE `$database`");
foreach(['users','admin_profiles','activity_logs','admin_permissions']as$t)$admin->exec("CREATE TABLE `$database`.`$t` LIKE `".DB_NAME."`.`$t`");
$db=new PDO('mysql:host='.DB_HOST.';port='.DB_PORT.';dbname='.$database.';charset=utf8mb4',DB_USER,DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
if(in_array('--unsigned',$argv,true)){$db->exec('ALTER TABLE users MODIFY id INT UNSIGNED NOT NULL AUTO_INCREMENT');$db->exec('ALTER TABLE admin_profiles MODIFY user_id INT UNSIGNED NOT NULL');}
$designation=$db->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='admin_profiles' AND COLUMN_NAME='official_designation'")->fetchColumn();if($designation)$db->exec('ALTER TABLE admin_profiles DROP COLUMN official_designation');
$db->exec("INSERT INTO users(id,role,username,email,password_hash,first_name,last_name,status,email_verified) VALUES(50,'student','existing_schema_fixture','existing_schema@example.invalid','preserved_hash','Existing','Fixture','pending',0)");$existing=$db->query('SELECT * FROM users WHERE id=50')->fetch();
doctorSql($db,'preflight_doctor_invitations_hostinger.sql');doctorCheck(true,'Read-only preflight works without invitation table');
doctorSql($db,'migration_doctor_invitations_hostinger.sql');doctorCheck((int)$db->query('SELECT @doctor_complete')->fetchColumn()===1,'Narrow Phase 1 schema completes');
doctorSql($db,'migration_doctor_invitations_hostinger.sql');doctorCheck((int)$db->query('SELECT @doctor_complete')->fetchColumn()===1,'Migration reruns without account changes');
doctorSql($db,'verify_doctor_invitations_hostinger.sql');doctorCheck(true,'Separate read-only post-verification executes');
doctorCheck($existing===$db->query('SELECT * FROM users WHERE id=50')->fetch(),'Existing account values preserved by initial and repeat DDL');
$db->exec("INSERT INTO users(id,role,admin_type,username,email,password_hash,first_name,last_name,status,email_verified) VALUES (1,'admin','primary_admin','invite_mis','mis@example.invalid','unused','MIS','Fixture','active',1),(2,'admin','co_admin','invite_co','co@example.invalid','unused','Co','Fixture','active',1),(3,'student',NULL,'student_fixture','student@example.invalid','unused','Student','Fixture','active',1),(4,'teacher',NULL,'teacher_fixture','teacher@example.invalid','unused','Teacher','Fixture','active',1),(5,'nurse',NULL,'nurse_fixture','nurse@example.invalid','unused','Nurse','Fixture','active',1),(6,'admin','co_admin','doctor_fixture_actor','doctor_actor@example.invalid','unused','Doctor','Actor','active',1)");
$db->exec("INSERT INTO admin_profiles(user_id,profile,appointment_reference,verified_by,verified_at) VALUES(6,'doctor','SYNTHETIC',1,NOW())");
$captured=[];$calls=0;
$service=new DoctorInvitationService($db,function($email,$name,$url)use(&$captured,&$calls,$db){$calls++;doctorCheck(!$db->inTransaction(),'Transport called only after transaction commit');parse_str(parse_url($url,PHP_URL_FRAGMENT),$q);$captured[]=$q['token'];return true;});
$input=['first_name'=>'Doctor','last_name'=>'Fixture','email'=>'doctor_fixture@example.invalid','username'=>'doctor_fixture','official_designation'=>'School Physician','appointment_reference'=>'SYNTHETIC-APPOINTMENT','professional_license'=>'SYNTHETIC-LICENSE','verification_status'=>'verified'];
foreach([['email'=>'invalid'],['first_name'=>''],['last_name'=>''],['official_designation'=>''],['appointment_reference'=>''],['verification_status'=>'pending'],['username'=>['bad']],['contact_number'=>'invalid phone']]as$bad){try{$service->create(1,array_replace($input,$bad));throw new LogicException('Bad fields accepted');}catch(InvalidArgumentException $e){doctorCheck(true,'Invalid/missing/unverified invitation fields rejected');}}
foreach([2,3,4,5,6]as$actor){$blocked=false;try{$service->create($actor,$input);}catch(RuntimeException $e){$blocked=true;}doctorCheck($blocked,"Role fixture $actor cannot provision Doctors");}
$result=$service->create(1,$input);$id=$result['user_id'];$user=$db->query("SELECT * FROM users WHERE id=$id")->fetch();$invitation=$db->query("SELECT * FROM admin_invitations WHERE user_id=$id")->fetch();
doctorCheck($result['delivery_status']==='sent'&&!isset($result['token'])&&!isset($result['url']),'API/service result never exposes invitation token');
doctorCheck($user['status']==='pending'&&!$user['email_verified'],'Account remains pending until Doctor chooses password');
doctorCheck(strlen($captured[0])===64&&hash('sha256',$captured[0])===$invitation['token_hash'],'Only secure token hash stored');
doctorCheck(strtotime($invitation['expires_at'])>time()&&strtotime($invitation['expires_at'])<=time()+86401,'Token lifetime is 24 hours');
doctorCheck((int)$invitation['created_by']===1&&$invitation['created_at']!==null,'Inviter and issue time recorded');
doctorCheck(permission_profile($user,$db)==='doctor'&&!has_permission('manage_users',$user,$db),'Doctor has no MIS provisioning permission');
try{$service->create(1,$input);throw new LogicException('Duplicate accepted');}catch(DomainException $e){doctorCheck($calls===1,'Duplicate identity sends no second invitation');}
try{$service->resend(1,$id);throw new LogicException('Immediate resend accepted');}catch(DomainException $e){doctorCheck(true,'Immediate resend is rate limited');}
$db->exec("UPDATE admin_invitations SET created_at=DATE_SUB(NOW(),INTERVAL 2 MINUTE) WHERE user_id=$id");$service->resend(1,$id);
doctorCheck(!empty($service->inspect($captured[0])['used_at'])&&$captured[0]!==$captured[1],'Resend invalidates prior token');
$token=$captured[1];
foreach([['weak','weak'],['FixturePass!42','mismatch'],[str_repeat('x',73).'A1!',str_repeat('x',73).'A1!']]as[$pw,$confirm]){try{$service->accept($token,$pw,$confirm);throw new LogicException('Bad password accepted');}catch(InvalidArgumentException $e){doctorCheck($db->query("SELECT status FROM users WHERE id=$id")->fetchColumn()==='pending','Bad password cannot consume or activate invitation');}}
$service->accept($token,'FixturePass!42','FixturePass!42');$active=$db->query("SELECT * FROM users WHERE id=$id")->fetch();
doctorCheck($active['status']==='active'&&$active['email_verified']&&password_verify('FixturePass!42',$active['password_hash']),'Private password activates Doctor account atomically');
try{$service->accept($token,'DifferentPass!42','DifferentPass!42');throw new LogicException('Replay accepted');}catch(DomainException $e){doctorCheck(password_verify('FixturePass!42',$db->query("SELECT password_hash FROM users WHERE id=$id")->fetchColumn()),'Used token cannot replay or overwrite password');}
$failed=new DoctorInvitationService($db,fn()=>false);$r=$failed->create(1,array_replace($input,['username'=>'failed_fixture','email'=>'failed@example.invalid']));
doctorCheck($r['delivery_status']==='failed'&&$db->query('SELECT status FROM users WHERE id='.(int)$r['user_id'])->fetchColumn()==='pending','Transport failure truthfully retains pending account');
$throwing=new DoctorInvitationService($db,function(){throw new RuntimeException('Synthetic transport failure');});
doctorCheck($throwing->resend(1,$r['user_id'])['delivery_status']==='failed','Transport exception is recoverable without exposing token');
$r=$service->create(1,array_replace($input,['username'=>'expired_fixture','email'=>'expired@example.invalid']));$expired=end($captured);$db->prepare('UPDATE admin_invitations SET expires_at=DATE_SUB(NOW(),INTERVAL 1 DAY) WHERE token_hash=?')->execute([hash('sha256',$expired)]);
try{$service->accept($expired,'FixturePass!42','FixturePass!42');throw new LogicException('Expired accepted');}catch(DomainException $e){doctorCheck(true,'Expired invitation rejected');}
$before=(int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn();$db->exec('ALTER TABLE activity_logs CHANGE action unavailable_action VARCHAR(150)');
try{$service->create(1,array_replace($input,['username'=>'rollback_fixture','email'=>'rollback@example.invalid']));throw new LogicException('Audit failure committed');}catch(PDOException $e){}
doctorCheck((int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn()===$before,'Audit failure rolls back account/profile/invitation');
$db->exec('ALTER TABLE activity_logs CHANGE unavailable_action action VARCHAR(150)');
// Existing malformed storage is not silently repaired.
$db->exec('ALTER TABLE admin_invitations MODIFY token_hash VARCHAR(64) NOT NULL');$before=(int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn();doctorSql($db,'migration_doctor_invitations_hostinger.sql');
doctorCheck((int)$db->query('SELECT @doctor_complete')->fetchColumn()===0&&(int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn()===$before,'Incompatible invitation schema blocks without rewriting accounts');
echo "All $checks Doctor Phase 1 checks passed. Fake transport only; production untouched.\n";
}finally{if(preg_match('/^bpc_doctor_test_[a-f0-9]{10}$/',$database))$admin->exec("DROP DATABASE IF EXISTS `$database`");}
