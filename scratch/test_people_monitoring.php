<?php
if(PHP_SAPI!=='cli')exit;
ini_set('session.save_path',__DIR__);require __DIR__.'/../config/config.php';require __DIR__.'/../includes/account_monitoring.php';require __DIR__.'/../includes/account_security_exceptions.php';require __DIR__.'/../includes/ai/MICARegistrationValidator.php';require __DIR__.'/../includes/ai/MICAHubService.php';
set_exception_handler(function(Throwable $e){fwrite(STDERR,'FAIL '.$e->getMessage().PHP_EOL);exit(1);});
$db=getDB();$db->exec("SET time_zone='+08:00'");date_default_timezone_set('Asia/Manila');
foreach(['users','students','teachers','nurses','institutional_roster','student_qrs','teacher_qrs','activity_logs','admin_permissions','account_security_events','account_security_exceptions','ai_actions','mica_scans','mica_alert_events','mica_alert_reads','medicines','medicine_logs','system_settings','sos_emergencies','notifications'] as $t){$db->exec("CREATE TEMPORARY TABLE people_qa_$t LIKE $t");$db->exec("ALTER TABLE people_qa_$t RENAME TO $t");}
$passes=0;function peopleCheck($ok,$label){global $passes;if(!$ok)throw new RuntimeException($label);$passes++;echo "PASS $label\n";}
function peopleDeny(callable $fn,$label){try{$fn();}catch(RuntimeException|InvalidArgumentException $e){peopleCheck(true,$label);return;}throw new RuntimeException($label.' accepted');}
$insert=$db->prepare("INSERT INTO users(id,username,email,password_hash,first_name,last_name,role,admin_type,status,email_verified,registration_status,otp_hash,otp_expires_at) VALUES (?,?,?,'x','Jane','Roster',?,?,?,?,?,?,DATE_ADD(NOW(),INTERVAL 10 MINUTE))");
foreach([[101,'admin','active',1,'primary_admin'],[102,'student','pending',0,null],[103,'teacher','pending',0,null],[104,'nurse','pending',0,null],[105,'admin','pending',0,'co_admin'],[106,'student','pending',0,null],[107,'teacher','pending',0,null],[108,'admin','active',1,'co_admin'],[109,'teacher','inactive',1,null],[110,'student','rejected',0,null],[111,'student','pending',0,null]] as [$id,$role,$state,$verified,$type])$insert->execute([$id,'people-'.$id,'people-'.$id.'@bpc.edu.ph',$role,$type,$state,$verified,'review',hash('sha256','123456')]);
$db->exec("INSERT INTO students(id,user_id,student_number) VALUES (102,102,'QA-ST102'),(106,106,'QA-ST106'),(110,110,'QA-ST110'),(111,111,'QA-ST111')");
$db->exec("INSERT INTO teachers(id,user_id,employee_id) VALUES (103,103,'QA-T103'),(107,107,'QA-T107'),(109,109,'QA-T109')");
$db->exec("INSERT INTO nurses(id,user_id,employee_id,license_number) VALUES (104,104,'QA-N104','1234567')");
$q=$db->prepare("INSERT INTO institutional_roster(identifier,role,first_name,last_name,institutional_email,license_number,status) VALUES (?,?,'Jane','Roster',?,?,'eligible')");
foreach([['QA-ST102','student','people-102@bpc.edu.ph',null],['QA-T103','teacher','people-103@bpc.edu.ph',null],['QA-N104','nurse','people-104@bpc.edu.ph','1234567'],['QA-T107','nurse','people-107@bpc.edu.ph','1234567']] as $r)$q->execute($r);
$monitor=new AccountMonitoringService($db);$validator=new MICARegistrationValidator($db);$security=new AccountSecurityExceptionService($db);
$c=$monitor->counts();peopleCheck($c['total']===11&&array_sum([$c['active'],$c['inactive'],$c['verification']])===$c['total'],'Status metrics reconcile, including rejected accounts');
foreach([102=>'student',103=>'teacher',104=>'nurse'] as $id=>$role){peopleCheck($validator->verifyOtp($id,'123456')['status']==='verified',"$role OTP verified");peopleCheck($monitor->finishVerification($id)==='active',"$role activates only after trusted identity and role match");}
peopleCheck((int)$db->query('SELECT COUNT(*) FROM student_qrs WHERE student_id=102')->fetchColumn()===1,'Verified student receives one QR');
peopleCheck((int)$db->query('SELECT COUNT(*) FROM teacher_qrs WHERE teacher_id=103')->fetchColumn()===1,'Verified teacher receives one QR');
foreach([105,106,107] as $id){peopleCheck($validator->verifyOtp($id,'123456')['status']==='verified','Untrusted account still verifies email ownership');peopleCheck($monitor->finishVerification($id)==='pending','Admin/unmatched identity/role mismatch cannot auto-activate');}
peopleCheck($db->query('SELECT admin_type FROM users WHERE id=105')->fetchColumn()==='co_admin','Public admin legacy record cannot become primary');
for($i=0;$i<5;$i++)$validator->verifyOtp(111,'999999');
peopleCheck($db->query('SELECT status FROM users WHERE id=111')->fetchColumn()==='rejected','Five failed OTP attempts lock the registration');
peopleCheck($validator->verifyOtp(111,'123456')['status']==='blocked','Correct OTP cannot reopen locked registration');
peopleCheck($validator->resendOtp(111)['status']==='blocked','Resend cannot bypass OTP lock');
$db->exec("UPDATE users SET otp_hash=SHA2('123456',256),otp_expires_at=DATE_SUB(NOW(),INTERVAL 1 SECOND),email_verified=0 WHERE id=106");peopleCheck($validator->verifyOtp(106,'123456')['status']==='expired','Expired OTP rejected');
$db->exec('UPDATE users SET email_verified=1 WHERE id=106');
$base=['role'=>'teacher','first_name'=>'Jane','last_name'=>'Roster','email'=>'newstaff@bpc.edu.ph','username'=>'newstaff','password'=>'ComplexPass123!','confirm_password'=>'ComplexPass123!','certify'=>1,'employee_id'=>'NEW-STAFF','department'=>'Teaching','phone'=>'09123456789'];
$r=$validator->validateRegistration($base,true);peopleCheck($r['decision']==='review','Institutional domain and uploaded ID alone cannot grant staff eligibility');
$q->execute(['NEW-STAFF','teacher','newstaff@bpc.edu.ph',null]);$r=$validator->validateRegistration($base,true);peopleCheck($r['decision']==='approved','Trusted teacher roster enables post-OTP eligibility');
$studentPayload=array_merge($base,['role'=>'student','username'=>'trusted_student','email'=>'trustedstudent@bpc.edu.ph','student_number'=>'2026-999999','course'=>'BSIT','year_level'=>'1','section'=>'A']);
peopleCheck($validator->validateRegistration($studentPayload,true)['decision']==='review','Student ID format alone does not establish enrollment eligibility');
$q->execute(['2026-999999','student','trustedstudent@bpc.edu.ph',null]);peopleCheck($validator->validateRegistration($studentPayload,true)['decision']==='approved','Trusted student registration receives post-OTP automatic eligibility');
$nursePayload=array_merge($base,['role'=>'nurse','username'=>'trusted_nurse','email'=>'trustednurse@bpc.edu.ph','employee_id'=>'NEW-NURSE','license_number'=>'1234567']);
peopleCheck($validator->validateRegistration($nursePayload,true)['decision']==='review','Nursing license format alone does not establish staff eligibility');
$q->execute(['NEW-NURSE','nurse','trustednurse@bpc.edu.ph','1234567']);peopleCheck($validator->validateRegistration($nursePayload,true)['decision']==='approved','Trusted nurse registration requires matching staff identity and license');
$db->exec("UPDATE institutional_roster SET status='revoked' WHERE identifier='NEW-STAFF'");peopleCheck($validator->validateRegistration($base,true)['decision']==='review','Revoked roster eligibility blocks staff automatic activation');
$r=$validator->validateRegistration(array_merge($base,['role'=>'admin']),true);peopleCheck($r['decision']!=='approved','Validator never auto-approves Administration');
$r=$validator->validateRegistration(array_merge($base,['username'=>'people-102']),true);peopleCheck($r['decision']==='rejected','Duplicate username rejected');
$ex=$security->open(101,106,'Identity review needed for school transfer','CASE-QA-106');peopleCheck($security->open(101,106,'Repeated identity review request','CASE-QA-106')===$ex,'Open security exceptions deduplicate by account');
peopleDeny(fn()=>$security->resolve(108,$ex,'verify','Reviewed institutional identity records'),'Unauthorized co-admin cannot decide exception');
$security->resolve(101,$ex,'verify','Roster office verified identity and student eligibility manually');peopleCheck($db->query('SELECT status FROM users WHERE id=106')->fetchColumn()==='active','Documented authorized review can activate verified student');
peopleDeny(fn()=>$security->resolve(101,$ex,'verify','Retry with duplicate disposition'),'Resolved exception cannot execute twice');
peopleDeny(fn()=>$security->open(101,101,'Attempt to change own administrator account','CASE-SELF'),'Self and primary account protected');
$adminEx=$security->open(101,105,'Review legacy public administration request','CASE-ADMIN');peopleDeny(fn()=>$security->resolve(101,$adminEx,'verify','Identity checked but public admin roles prohibited'),'Exception review cannot provision public admin');
$ex=$security->open(101,103,'Compromised staff account reported by owner','CASE-SUSPEND');$security->resolve(101,$ex,'suspend','Confirmed owner report and evidence of compromised access');peopleCheck($db->query('SELECT status FROM users WHERE id=103')->fetchColumn()==='inactive','Security suspension remains available');
$ex=$security->open(101,103,'Account recovery completed with identity checks','CASE-RESTORE');$security->resolve(101,$ex,'restore','Owner identity and recovery safeguards confirmed');peopleCheck($db->query('SELECT status FROM users WHERE id=103')->fetchColumn()==='active','Documented recovery restores suspended account');
peopleCheck((int)$db->query('SELECT credential_version FROM users WHERE id=103')->fetchColumn()===2,'Suspension and restoration invalidate old session credentials');
peopleCheck((int)$db->query("SELECT COUNT(*) FROM activity_logs WHERE module='account_security'")->fetchColumn()>=6,'Exception access changes have durable audit records');
$dir=$monitor->directory(['search'=>'QA-ST102'],true);peopleCheck($dir['total']===1&&$dir['rows'][0]['has_qr']==1,'Identifier search and QR status match source');
$dir=$monitor->directory(['role'=>'nurse','tab'=>'active'],true);peopleCheck($dir['total']===1&&$dir['rows'][0]['role']==='nurse','Role and account-state filters combine');
$dir=$monitor->directory([],false);peopleCheck(count(array_filter($dir['rows'],fn($r)=>$r['role']==='admin'))===0,'Delegated directory scope excludes administrator identities');
$big=$db->prepare("INSERT INTO users(id,username,email,password_hash,first_name,last_name,role,status) VALUES (?,?,?,'x','Directory','Page','student','active')");for($i=2000;$i<3010;$i++)$big->execute([$i,'qa-page-'.$i,'qa-page-'.$i.'@example.invalid']);
$dir=$monitor->directory(['page'=>9999],true);peopleCheck($dir['total']===1021&&$dir['page']===$dir['pages']&&count($dir['rows'])<=25,'Server pagination reaches beyond 1000 records without truncation');
$retry=$security->open(101,111,'Identity confirmed for blocked OTP recovery','CASE-OTP-RETRY');$security->resolve(101,$retry,'retry_verification','Verified identity before restarting locked OTP challenge');
peopleCheck($db->query('SELECT status FROM users WHERE id=111')->fetchColumn()==='pending' && (int)$db->query('SELECT otp_attempts FROM users WHERE id=111')->fetchColumn()===0,'Authorized OTP recovery restarts verification without activating account');
$admin=$db->query('SELECT * FROM users WHERE id=101')->fetch();$hub=new MICAHubService($db,$admin);$hub->scan();$pending=$monitor->counts()['verification'];$alert=$db->query("SELECT title FROM ai_actions WHERE action_type='pending_review' AND status='new'")->fetchColumn();peopleCheck(str_starts_with($alert,(string)$pending.' '),'MICA and directory share the exact pending count');
$hub->scan();peopleCheck((int)$db->query("SELECT COUNT(*) FROM ai_actions WHERE action_type='account_otp_failure' AND status='new'")->fetchColumn()===1,'Repeated scans deduplicate persisted OTP security signals');
peopleCheck((int)$db->query("SELECT COUNT(*) FROM ai_actions WHERE action_type='account_exception' AND status='new'")->fetchColumn()===1,'MICA monitors actual unresolved exceptions');
$db->exec("UPDATE users SET status='inactive' WHERE status='pending'");$hub->scan();peopleCheck((int)$db->query("SELECT COUNT(*) FROM ai_actions WHERE action_type='pending_review' AND status IN ('new','in_progress')")->fetchColumn()===0,'Zero real pending accounts means zero active pending alerts');
$db->exec("UPDATE users SET status='pending',email_verified=0,otp_attempts=0,otp_last_sent_at=DATE_SUB(NOW(),INTERVAL 2 MINUTE) WHERE id=111");
$mailCalls=0;$mailValidator=new MICARegistrationValidator($db,function()use(&$mailCalls){$mailCalls++;return false;});
$delivery=$mailValidator->resendOtp(111);peopleCheck($delivery['status']==='delivery_failed'&&!isset($delivery['otp'])&&!$db->inTransaction(),'Mail failure is transparent, no OTP leaks, and account transaction commits before transport');
peopleCheck($mailValidator->resendOtp(111)['status']==='cooldown'&&$mailCalls===1,'Resend cooldown persists after delivery failure');
$rollback=$security->open(101,104,'Audit rollback test for security suspension','CASE-ROLLBACK');
$db->exec('ALTER TABLE activity_logs CHANGE action unavailable_action VARCHAR(150)');
try{$security->resolve(101,$rollback,'suspend','Evidence verified; audit failure must roll back account change');throw new RuntimeException('Failed audit committed access change');}catch(PDOException $e){}
peopleCheck($db->query('SELECT status FROM users WHERE id=104')->fetchColumn()==='active' && $db->query('SELECT status FROM account_security_exceptions WHERE id='.$rollback)->fetchColumn()==='open','Audit persistence failure rolls back security disposition and access change');
echo "All $passes people workflow checks passed. Production records unchanged.\n";
