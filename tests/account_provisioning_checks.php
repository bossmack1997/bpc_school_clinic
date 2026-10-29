<?php
if(!isset($database)||!preg_match('/^bpc_mis_test_[a-f0-9]{10}$/D',$database)||$db->query('SELECT DATABASE()')->fetchColumn()!==$database)throw new RuntimeException('Disposable fixture required.');
require_once __DIR__.'/../includes/account_provisioning_service.php';
$db->exec(file_get_contents(__DIR__.'/../database/migration_account_provisioning_20261028.sql'));
$db->exec(file_get_contents(__DIR__.'/../database/migration_account_provisioning_20261028.sql'));
checkMis(true,'Account provisioning migration reruns safely');
$public=misClient();
foreach(['/auth/register.php','/auth/register_hub.php'] as $provisionPath){
 checkMis(misRequest($public,$provisionPath)['status']===410,'Public registration entry is retired '.$provisionPath);
 checkMis(misRequest($public,$provisionPath,['action'=>'register','role'=>'admin','email'=>'intruder@example.invalid'])['status']===410,'Public registration POST cannot create privileged account '.$provisionPath);
}
checkMis(misRequest($public,'/api/account_provisioning.php?action=email_check&email=mis@example.invalid')['status']===401,'Anonymous callers cannot enumerate account emails');
checkMis(misRequest($invitee,'/api/account_provisioning.php?action=email_check&email=mis@example.invalid')['status']===403,'Doctor cannot enumerate provisioning emails');
checkMis(misRequest($invitee,'/pages/admin/provision_accounts.php')['status']===403,'Doctor cannot provision routine accounts');
$page=misRequest($c,'/pages/admin/provision_accounts.php');$provisionToken=misField($page,'csrf_token');
checkMis($page['status']===200&&str_contains($page['body'],'Single account')&&str_contains($page['body'],'UTF-8 CSV'),'MIS account and import forms render');
checkMis(str_contains($page['body'],'>Create Accounts</span>')||str_contains($page['body'],'>Create Accounts</a>')||str_contains($page['body'],'href="'.app_url('pages/admin/provision_accounts.php').'"'),'MIS navigation links to account provisioning');
$template=misRequest($c,'/pages/admin/provision_accounts.php?download=template');checkMis($template['status']===200&&str_starts_with($template['body'],"\xEF\xBB\xBFrole,username,email,first_name,last_name,institutional_id"),'Import template supplies exact UTF-8 CSV headers');
$duplicate=misRequest($c,'/api/account_provisioning.php?action=email_check&email=mis@example.invalid');checkMis((json_decode($duplicate['body'],true)['available']??true)===false,'MIS duplicate check reflects actual database');
$links=[];$provision=new AccountProvisioningService($db,function($email,$name,$link)use(&$links){$links[$email]=$link;return true;});
$nurseRoute='/pages/doctor/create_nurse.php';
checkMis(misRequest($public,$nurseRoute)['status']===303&&misRequest($c,$nurseRoute)['status']===403,'Nurse invitation page requires Doctor access');
$nurseForm=misRequest($invitee,$nurseRoute);$nurseCsrf=misField($nurseForm,'csrf_token');
checkMis($nurseForm['status']===200&&str_contains($nurseForm['body'],'Pending invitations'),'Doctor Nurse invitation form renders');
$nurseDraft=['action'=>'preview','csrf_token'=>$nurseCsrf,'username'=>'doctor_invite_ui','email'=>'doctor_invite_ui@example.invalid','first_name'=>'Invited','last_name'=>'Nurse','institutional_id'=>'DOC-UI-1'];
checkMis(misRequest($invitee,$nurseRoute,array_replace($nurseDraft,['csrf_token'=>'invalid']))['status']===403,'Doctor Nurse invitation rejects forged CSRF');
checkMis(misRequest($invitee,$nurseRoute,array_replace($nurseDraft,['role'=>'student']))['status']===422,'Doctor invitation rejects a forged account role');
$nursePreview=misRequest($invitee,$nurseRoute,$nurseDraft);
$nurseSubmit=['action'=>'create','csrf_token'=>$nurseCsrf,'nonce'=>misField($nursePreview,'nonce')];
$nurseSaved=misRequest($invitee,$nurseRoute,$nurseSubmit);
$uiInvite=$db->query("SELECT u.status,n.clinical_authorization_status FROM users u JOIN nurses n ON n.user_id=u.id WHERE u.username='doctor_invite_ui'")->fetch();
checkMis($nurseSaved['status']===200&&str_contains($nurseSaved['body'],'Email sending failed')&&$uiInvite['status']==='pending'&&$uiInvite['clinical_authorization_status']==='pending','Doctor creates pending Nurse and accurately reports transport failure');
checkMis(misRequest($invitee,$nurseRoute,$nurseSubmit)['status']===422,'Doctor Nurse invitation preview cannot be replayed');
$accounts=[];foreach(['student','teacher','nurse'] as $role)$accounts[]=['role'=>$role,'username'=>'provision_'.$role,'email'=>'provision_'.$role.'@example.invalid','first_name'=>'Provisioned','last_name'=>ucfirst($role),'institutional_id'=>'PROV-'.$role];
$before=(int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn();
$bad=$accounts;$bad[1]['role']='admin';$denied=false;try{$provision->create($actor,$bad);}catch(InvalidArgumentException $e){$denied=true;}checkMis($denied&&(int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn()===$before,'Invalid import role rolls back entire batch');
$denied=false;try{$provision->create($doctor,$accounts);}catch(InvalidArgumentException|RuntimeException $e){$denied=true;}checkMis($denied,'Service rejects Doctor bulk or non-Nurse provisioning');
$duplicates=$accounts;$duplicates[1]['email']=$duplicates[0]['email'];checkMis(!empty($provision->preview($duplicates)[1]['errors']),'Import detects intra-batch duplicate emails');
$created=array_merge($provision->create($actor,array_slice($accounts,0,2)),$provision->create($doctor,[$accounts[2]]));checkMis(count($created)===3&&array_unique(array_column($created,'delivery_status'))===['sent'],'MIS provisions Students/Teachers and Doctor invites Nurse with individual links');
checkMis(!str_contains(json_encode($created),'#token=')&&!str_contains(json_encode($created),'password'),'Provisioning result contains no token or password');
$accountHistory=misRequest($c,'/api/activity_logs.php?action=list&search=Provisioned%20account%20batch');
checkMis($accountHistory['status']===200&&(json_decode($accountHistory['body'],true)['total']??0)>0&&!str_contains($accountHistory['body'],'#token=')&&!str_contains($accountHistory['body'],'PrivatePass!42'),'MIS Activity History includes account provisioning without credentials');
checkMis(misRequest($invitee,'/api/activity_logs.php?action=list')['status']===403,'Doctor cannot enumerate MIS technical Activity History');
checkMis(misRequest($c,'/pages/admin/clinical_legacy_dashboard.php')['status']===403,'MIS cannot bypass clinical separation through legacy dashboard');
$denied=false;try{$provision->create($actor,$accounts);}catch(InvalidArgumentException $e){$denied=true;}checkMis($denied&&(int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn()===$before+3,'Duplicate batch does not overwrite existing accounts');
foreach($accounts as $index=>$account){
 $id=$created[$index]['user_id'];$person=$db->query('SELECT * FROM users WHERE id='.$id)->fetch();
 checkMis($person['status']==='pending'&&!(int)$person['email_verified'],'Provisioned '.$account['role'].' remains pending before recipient verification');
 parse_str(parse_url($links[$account['email']],PHP_URL_FRAGMENT),$fragment);$raw=$fragment['token'];
 checkMis($db->query('SELECT token_hash FROM account_activation_invitations WHERE user_id='.$id)->fetchColumn()===hash('sha256',$raw),'Only token hash stored for '.$account['role']);
 $recipient=misClient();$activation=misRequest($recipient,'/auth/activate_account.php');$activationCsrf=misField($activation,'csrf_token');
 $loaded=misRequest($recipient,'/auth/activate_account.php',['action'=>'inspect','token'=>$raw,'csrf_token'=>$activationCsrf]);checkMis(str_contains($loaded['body'],'name="password"'),'Recipient loads private '.$account['role'].' password setup');
 $activationPost=['action'=>'activate','token'=>$raw,'csrf_token'=>$activationCsrf,'password'=>'PrivatePass!42','confirmation'=>'PrivatePass!42','role'=>'admin','user_id'=>$actor];
 checkMis(misRequest($recipient,'/auth/activate_account.php',array_replace($activationPost,['csrf_token'=>'invalid']))['status']===403,'Activation rejects forged CSRF');
 $accepted=misRequest($recipient,'/auth/activate_account.php',$activationPost);$person=$db->query('SELECT * FROM users WHERE id='.$id)->fetch();
 checkMis($accepted['status']===200&&$person['status']==='active'&&$person['role']===$account['role']&&password_verify('PrivatePass!42',$person['password_hash']),'Recipient privately activates token-bound '.$account['role'].' without role escalation');
 checkMis(misRequest($recipient,'/auth/activate_account.php',$activationPost)['status']===422,'Activation token cannot be replayed');
 if($account['role']==='nurse'){
  checkMis((int)$person['self_service_only']===1&&$db->query('SELECT clinical_authorization_status FROM nurses WHERE user_id='.$id)->fetchColumn()==='pending','Activated Nurse still requires Doctor authorization');
  $login=misRequest($recipient,'/auth/login.php');misRequest($recipient,'/auth/login.php',['csrf_token'=>misField($login,'csrf_token'),'identifier'=>$account['username'],'password'=>'PrivatePass!42']);
  checkMis(misRequest($recipient,'/api/inventory.php?action=list',null,true)['status']===403,'Provisioned activated Nurse cannot access clinical inventory');
  checkMis(misRequest($recipient,'/api/qr_codes.php?action=list',null,true)['status']===403,'Restricted Nurse cannot enter clinical QR endpoints');
  checkMis(misRequest($recipient,'/api/queue.php',['action'=>'checkin','csrf_token'=>$activationCsrf,'full_name'=>'Forbidden basic Nurse checkin'],true)['status']===403,'Restricted Nurse cannot bypass authorization through early check-in action');
  checkMis(misRequest($recipient,'/pages/nurse/credentials.php')['status']===200,'Restricted provisioned Nurse can submit professional credentials');
 }
}
$single=['action'=>'preview_single','csrf_token'=>$provisionToken,'role'=>'student','username'=>'provision_ui','email'=>'provision_ui@example.invalid','first_name'=>'UI','last_name'=>'Provision','institutional_id'=>'PROV-UI'];
$preview=misRequest($c,'/pages/admin/provision_accounts.php',$single);checkMis(str_contains($preview['body'],'Review before creating accounts'),'Single account requires a server-generated preview');
$nonce=misField($preview,'nonce');$submit=['action'=>'create','csrf_token'=>$provisionToken,'nonce'=>$nonce];
$saved=misRequest($c,'/pages/admin/provision_accounts.php',$submit);checkMis($saved['status']===200&&str_contains($saved['body'],'failed')&&$db->query("SELECT status FROM users WHERE username='provision_ui'")->fetchColumn()==='pending','SMTP failure reports failed delivery and preserves pending account');
checkMis(misRequest($c,'/pages/admin/provision_accounts.php',$submit)['status']===422,'Provisioning preview cannot be replayed');
$changedEmail=misRequest($c,'/api/profile.php',['action'=>'update_info','csrf_token'=>$provisionToken,'first_name'=>'MIS','last_name'=>'Fixture','email'=>'unverified-change@example.invalid']);
checkMis($changedEmail['status']===422&&$db->query('SELECT email FROM users WHERE id='.$actor)->fetchColumn()==='mis@example.invalid','Profile edits cannot silently transfer verified-email state to a new address');
$csvPath=__DIR__.'/../scratch/'.$database.'_accounts.csv';
try{file_put_contents($csvPath,"\xEF\xBB\xBF".implode(',',AccountProvisioningService::COLUMNS)."\nstudent,csv_user,csv@example.invalid,CSV,Person,CSV-1\n");checkMis(AccountProvisioningService::readCsv($csvPath)[0]['username']==='csv_user','CSV parser accepts Excel UTF-8 BOM');}
finally{if(is_file($csvPath))unlink($csvPath);}
require_once __DIR__.'/../includes/report_export_service.php';
$xlsxFile=ReportExportService::xlsx(['headers'=>AccountProvisioningService::COLUMNS,'rows'=>[['student','xlsx_user','xlsx@example.invalid','Excel','Person','XLSX-1']]]);$formulaFile=null;
try{
 checkMis(AccountProvisioningService::readXlsx($xlsxFile)[0]['username']==='xlsx_user','Native Excel workbook parses template account rows');
 curl_setopt_array($c,[CURLOPT_URL=>$base.'/pages/admin/provision_accounts.php',CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>['action'=>'preview_csv','csrf_token'=>$provisionToken,'accounts_csv'=>new CURLFile($xlsxFile,'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','accounts.xlsx')]]);$xlsxPreview=curl_exec($c);
 checkMis(curl_getinfo($c,CURLINFO_RESPONSE_CODE)===200&&str_contains($xlsxPreview,'xlsx_user')&&str_contains($xlsxPreview,'name="nonce"'),'Uploaded native Excel receives server-validated preview before creation');
 curl_setopt($c,CURLOPT_POSTFIELDS,'');
 $formulaFile=tempnam(sys_get_temp_dir(),'bpc_formula_test_');copy($xlsxFile,$formulaFile);
 $z=new ZipArchive();$z->open($formulaFile);$sheet=$z->getFromName('xl/worksheets/sheet1.xml');$z->addFromString('xl/worksheets/sheet1.xml',str_replace('<sheetData>','<sheetData><row r="3"><c r="A3"><f>HYPERLINK("https://example.invalid")</f><v>1</v></c></row>',$sheet));$z->close();
 $formulaRejected=false;try{AccountProvisioningService::readXlsx($formulaFile);}catch(InvalidArgumentException $e){$formulaRejected=true;}checkMis($formulaRejected,'Excel formulas rejected before preview or account creation');
}finally{if($formulaFile&&is_file($formulaFile))unlink($formulaFile);unlink($xlsxFile);}
$pendingUiId=(int)$db->query("SELECT id FROM users WHERE username='provision_ui'")->fetchColumn();
checkMis($provision->resend($actor,$pendingUiId)==='sent','MIS retries failed activation delivery with a fresh link');
parse_str(parse_url($links['provision_ui@example.invalid'],PHP_URL_FRAGMENT),$newFragment);$newToken=$newFragment['token'];
$throttled=false;try{$provision->resend($actor,$pendingUiId);}catch(InvalidArgumentException $e){$throttled=true;}checkMis($throttled,'Activation resend is throttled after a successful transport send');
$db->prepare('UPDATE account_activation_invitations SET expires_at=DATE_SUB(NOW(),INTERVAL 1 SECOND) WHERE token_hash=?')->execute([hash('sha256',$newToken)]);
$expiredDenied=false;try{$provision->activate($newToken,'PrivatePass!42','PrivatePass!42');}catch(InvalidArgumentException $e){$expiredDenied=true;}checkMis($expiredDenied&&$db->query('SELECT status FROM users WHERE id='.$pendingUiId)->fetchColumn()==='pending','Expired activation link cannot activate a pending account');
