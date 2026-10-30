<?php
/** Included only by the schema-only disposable HTTP suite. */
if(!isset($database)||!preg_match('/^bpc_mis_test_[a-f0-9]{10}$/D',$database)||$db->query('SELECT DATABASE()')->fetchColumn()!==$database)throw new RuntimeException('Disposable Doctor access fixture required.');
if(!in_array('--doctor-clinical',$argv,true)||!in_array('--staff-verification',$argv,true)||!in_array('--provisioning',$argv,true))throw new RuntimeException('Doctor clinical, staff and provisioning fixtures required.');
$accessToken='';
$accessService=new DoctorInvitationService($db,function($email,$name,$url)use(&$accessToken){parse_str(parse_url($url,PHP_URL_FRAGMENT),$params);$accessToken=$params['token'];return true;});
$accessResult=$accessService->create($actor,array_replace($payload,['username'=>'automatic_doctor','email'=>'automatic_doctor@example.invalid']));
$accessId=(int)$accessResult['user_id'];
// A stale basic-account flag must be cleared atomically by secure activation.
$db->prepare('UPDATE users SET self_service_only=1 WHERE id=?')->execute([$accessId]);
$accessClient=misClient();$accessSetup='/auth/setup_account.php?token='.$accessToken;
$accessPage=misRequest($accessClient,$accessSetup);
$accessActivated=misRequest($accessClient,$accessSetup,['csrf_token'=>misField($accessPage,'csrf_token'),'token'=>$accessToken,'password'=>'FixturePass!42','confirm_password'=>'FixturePass!42']);
checkMis(str_contains($accessActivated['body'],'No additional MIS approval is required')&&(int)$db->query('SELECT self_service_only FROM users WHERE id='.$accessId)->fetchColumn()===0,'Doctor activation clears stale basic scope and confirms immediate role access');
$accessLogin=misRequest($accessClient,'/auth/login.php');$accessLogin=misRequest($accessClient,'/auth/login.php',['csrf_token'=>misField($accessLogin,'csrf_token'),'identifier'=>'automatic_doctor','password'=>'FixturePass!42']);
checkMis($accessLogin['status']===302&&str_ends_with($accessLogin['headers']['location']??'','pages/doctor/dashboard.php'),'Newly activated Doctor signs in directly to Doctor Dashboard');
checkMis((int)$db->query('SELECT COUNT(*) FROM admin_permissions WHERE user_id='.$accessId)->fetchColumn()===0,'Doctor activation creates no manual feature grants');
// Metadata remains historical evidence, not another post-activation approval gate.
$db->prepare("UPDATE users SET registration_status='review',self_service_only=1 WHERE id=?")->execute([$accessId]);
$db->prepare('UPDATE admin_profiles SET verified_at=NULL,verified_by=NULL WHERE user_id=?')->execute([$accessId]);
$db->exec('RENAME TABLE admin_permissions TO fixture_legacy_permission_grants');
try {
 foreach(['dashboard','queue','consultations','patients','nurse_verification','reports','settings','profile','create_nurse'] as $accessRoute){
  $accessResponse=misRequest($accessClient,'/pages/doctor/'.$accessRoute.'.php');
  checkMis($accessResponse['status']===200||($accessRoute==='consultations'&&$accessResponse['status']===302),'Automatic Doctor access without legacy grants: '.$accessRoute);
 }
 checkMis(misRequest($accessClient,'/pages/doctor_messages.php')['status']===200,'Automatic physician messaging access without legacy grants');
 $accessQueue=misRequest($accessClient,'/api/physician_queue.php?action=list');
 checkMis($accessQueue['status']===200&&(json_decode($accessQueue['body'],true)['success']??false),'Automatic physician API access without legacy grants');
 foreach(['users','security','backups','admin_accounts','role_profiles'] as $technicalRoute)checkMis(misRequest($accessClient,'/pages/admin/'.$technicalRoute.'.php')['status']===403,'Doctor still denied MIS administration: '.$technicalRoute);
} finally {$db->exec('RENAME TABLE fixture_legacy_permission_grants TO admin_permissions');}
foreach(['pending','inactive','suspended','rejected'] as $accessStatus){
 $db->prepare('UPDATE users SET status=? WHERE id=?')->execute([$accessStatus,$accessId]);
 checkMis(misRequest($accessClient,'/api/physician_queue.php?action=list',null,true)['status']===401,'Doctor session blocked when account becomes '.$accessStatus);
 $blockedClient=misClient();$blockedPage=misRequest($blockedClient,'/auth/login.php');
 $blockedLogin=misRequest($blockedClient,'/auth/login.php',['csrf_token'=>misField($blockedPage,'csrf_token'),'identifier'=>'automatic_doctor','password'=>'FixturePass!42']);
 checkMis($blockedLogin['status']!==302,'Doctor cannot sign in while '.$accessStatus);
}
$db->prepare("UPDATE users SET status='active',email_verified=0 WHERE id=?")->execute([$accessId]);
$blockedClient=misClient();$blockedPage=misRequest($blockedClient,'/auth/login.php');
$blockedLogin=misRequest($blockedClient,'/auth/login.php',['csrf_token'=>misField($blockedPage,'csrf_token'),'identifier'=>'automatic_doctor','password'=>'FixturePass!42']);
checkMis($blockedLogin['status']===200&&str_contains($blockedLogin['body'],'Email verification is required'),'Unverified Doctor cannot inherit the technical admin verification exception');
$db->prepare("UPDATE users SET status='active',email_verified=1,registration_status='approved',self_service_only=0 WHERE id=?")->execute([$accessId]);
checkMis((int)$db->query('SELECT COUNT(*) FROM admin_profiles WHERE user_id='.$accessId)->fetchColumn()===1,'Activation and automatic access preserve one assigned Doctor profile');
$accessClient=misClient();$accessPage=misRequest($accessClient,'/auth/login.php');
misRequest($accessClient,'/auth/login.php',['csrf_token'=>misField($accessPage,'csrf_token'),'identifier'=>'automatic_doctor','password'=>'FixturePass!42']);
$db->prepare('UPDATE users SET email_verified=0 WHERE id=?')->execute([$accessId]);
checkMis(misRequest($accessClient,'/api/physician_queue.php?action=list',null,true)['status']===401,'Existing Doctor session loses access immediately if verification is withdrawn');
$db->prepare('UPDATE users SET email_verified=1 WHERE id=?')->execute([$accessId]);
$accessPage=misRequest($accessClient,'/auth/login.php');
misRequest($accessClient,'/auth/login.php',['csrf_token'=>misField($accessPage,'csrf_token'),'identifier'=>'automatic_doctor','password'=>'FixturePass!42']);
$db->prepare("UPDATE admin_profiles SET profile='legacy' WHERE user_id=?")->execute([$accessId]);
checkMis(misRequest($accessClient,'/api/physician_queue.php?action=list',null,true)['status']===403,'Revoking Doctor role immediately removes physician API access');
$db->prepare("UPDATE admin_profiles SET profile='doctor' WHERE user_id=?")->execute([$accessId]);
