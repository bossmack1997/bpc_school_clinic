<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
ini_set('session.save_path',sys_get_temp_dir());require __DIR__.'/../config/config.php';require __DIR__.'/../includes/doctor_invitation_service.php';
set_exception_handler(function(Throwable $e){fwrite(STDERR,'FAIL invitation regression: '.$e->getMessage().PHP_EOL);exit(1);});
$db=getDB();foreach(['users','admin_profiles','admin_invitations','activity_logs','admin_permissions'] as $table){$db->exec("CREATE TEMPORARY TABLE invite_test_$table LIKE $table");$db->exec("ALTER TABLE invite_test_$table RENAME TO $table");}
$db->exec("INSERT INTO users(id,role,admin_type,username,email,password_hash,first_name,last_name,status,email_verified) VALUES (1,'admin','primary_admin','invite_mis','mis@example.invalid','unused','MIS','Fixture','active',1),(2,'admin','co_admin','invite_co','co@example.invalid','unused','Co','Fixture','active',1)");
$checks=0;function inviteCheck(bool $value,string $label): void {global $checks;if(!$value)throw new RuntimeException($label);$checks++;echo "PASS $label\n";}
$captured=[];$transportCalls=0;
$service=new DoctorInvitationService($db,function($email,$name,$url,$expiry)use(&$captured,&$transportCalls,$db){$transportCalls++;inviteCheck(!$db->inTransaction(),'Transport runs after account transaction commit');parse_str(parse_url($url,PHP_URL_QUERY),$query);$captured[]=$query['token'];return true;});
$input=['first_name'=>'Doctor','last_name'=>'Fixture','email'=>'doctor_fixture@example.invalid','username'=>'doctor_fixture','official_designation'=>'School Physician','appointment_reference'=>'SYNTHETIC-APPOINTMENT','professional_license'=>'SYNTHETIC-LICENSE','verification_status'=>'verified'];
foreach([['email'=>'invalid'],['first_name'=>''],['verification_status'=>'admin'],['username'=>['bad']]] as $bad){try{$service->create(1,array_replace($input,$bad));throw new LogicException('Invalid input accepted');}catch(InvalidArgumentException $e){inviteCheck(true,'Invalid invitation fields are rejected');}}
try{$service->create(2,$input);throw new LogicException('Co-admin provisioning accepted');}catch(RuntimeException $e){inviteCheck(true,'Unauthorized co-admin cannot create Doctor invitations');}
$result=$service->create(1,$input);$id=$result['user_id'];$user=$db->query('SELECT * FROM users WHERE id='.(int)$id)->fetch();$invitation=$db->query('SELECT * FROM admin_invitations WHERE user_id='.(int)$id)->fetch();
inviteCheck($result['delivery_status']==='sent' && !isset($result['token']) && !isset($result['url']),'Success response contains no invitation token');
inviteCheck($user['status']==='pending' && !$user['email_verified'] && $user['role']==='admin' && $user['admin_type']==='co_admin','Invitation leaves a restricted pending co-admin account');
inviteCheck(strlen($captured[0])===64 && ctype_xdigit($captured[0]) && hash('sha256',$captured[0])===$invitation['token_hash'],'Secure invitation token is stored only as a hash');
inviteCheck(strtotime($invitation['expires_at'])>time() && strtotime($invitation['expires_at'])<=time()+86401,'Invitation expires within 24 hours');
inviteCheck(permission_profile($user,$db)==='doctor' && has_permission('clinical_records_read',$user,$db) && !has_permission('manage_users',$user,$db),'Doctor profile has clinical permissions and no user administration');
try{$service->create(1,$input);throw new LogicException('Duplicate account accepted');}catch(DomainException $e){inviteCheck(true,'Duplicate account/invitation does not create another account');}
inviteCheck($transportCalls===1,'Duplicate submission does not send twice');
$service->resend(1,$id);$old=$db->query('SELECT used_at FROM admin_invitations WHERE id='.(int)$invitation['id'])->fetchColumn();
inviteCheck(!empty($old) && $captured[0]!==$captured[1],'Resend revokes the old token and generates a new token');
$failed=new DoctorInvitationService($db,fn()=>false);$failedResult=$failed->create(1,array_replace($input,['username'=>'failed_fixture','email'=>'failed@example.invalid']));
inviteCheck($failedResult['delivery_status']==='failed' && $db->query('SELECT status FROM users WHERE id='.(int)$failedResult['user_id'])->fetchColumn()==='pending','Mail failure retains a pending account without claiming delivery');
$throwing=new DoctorInvitationService($db,function(){throw new RuntimeException('Synthetic transport failure');});
inviteCheck($throwing->resend(1,$failedResult['user_id'])['delivery_status']==='failed','Transport exception is handled as failed delivery');
$before=(int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn();$db->exec('ALTER TABLE activity_logs CHANGE action unavailable_action VARCHAR(150)');
try{$service->create(1,array_replace($input,['username'=>'rollback_fixture','email'=>'rollback@example.invalid']));throw new LogicException('Audit failure unexpectedly committed');}catch(PDOException $e){}
inviteCheck((int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn()===$before,'Audit failure rolls back the account and invitation transaction');
echo "All $checks invitation service checks passed; no permanent records modified.\n";
