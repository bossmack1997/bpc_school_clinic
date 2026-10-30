<?php
/** Runs only inside the schema-only MIS HTTP fixture. */
if (!isset($database) || !preg_match('/^bpc_mis_test_[a-f0-9]{10}$/D',$database) || $db->query('SELECT DATABASE()')->fetchColumn()!==$database) throw new RuntimeException('Disposable fixture required.');
foreach(['clinical_authorization_status'=>"VARCHAR(30) NOT NULL DEFAULT 'pending'",'clinical_reviewed_by'=>'INT NULL','clinical_reviewed_at'=>'DATETIME NULL','clinical_review_notes'=>'TEXT NULL'] as $column=>$definition) $db->exec("ALTER TABLE nurses ADD COLUMN `$column` $definition");
$db->exec("CREATE TABLE nurse_clinical_reviews(id INT AUTO_INCREMENT PRIMARY KEY,nurse_user_id INT NOT NULL,doctor_user_id INT NOT NULL,action ENUM('authorize','request_info','reject','revoke') NOT NULL,previous_status VARCHAR(30) NOT NULL,new_status VARCHAR(30) NOT NULL,notes TEXT,created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
$db->exec(file_get_contents(__DIR__.'/../database/migration_nurse_credential_correction_20261024.sql'));
$db->prepare("INSERT INTO users(username,email,password_hash,first_name,last_name,role,status,email_verified,self_service_only,credential_version) VALUES ('staff_pending','staff@example.invalid',?,'Pending','Nurse','nurse','active',1,1,1)")->execute([password_hash('FixturePass!42',PASSWORD_DEFAULT)]);
$staffId=(int)$db->lastInsertId();
$staffFixtureDocument='storage/registration_documents/'.$database.'_credential.pdf';
file_put_contents(__DIR__.'/../'.$staffFixtureDocument,"%PDF-1.4\nSynthetic credential fixture\n%%EOF");
$db->prepare("INSERT INTO nurses(user_id,employee_id,license_number,position,valid_id_path) VALUES (?,'FIXTURE-NURSE','FIXTURE-PRC-01','School Nurse',?)")->execute([$staffId,$staffFixtureDocument]);
$pendingClient=misClient();$login=misRequest($pendingClient,'/auth/login.php');misRequest($pendingClient,'/auth/login.php',['csrf_token'=>misField($login,'csrf_token'),'identifier'=>'staff_pending','password'=>'FixturePass!42']);
checkMis(misRequest($pendingClient,'/pages/nurse/account.php')['status']===200,'Pending Nurse account renders');
checkMis(misRequest($pendingClient,'/api/inventory.php?action=list',null,true)['status']===403,'Pending Nurse clinical API denied');
$review=misRequest($invitee,'/pages/doctor/nurse_verification.php?view='.$staffId);
checkMis($review['status']===200 && str_contains($review['body'],'FIXTURE-PRC-01'),'Doctor sees actual credential application');
$decision=['institutional_verified'=>'1','action'=>'authorize','nurse_id'=>$staffId,'notes'=>'PRC and school appointment checked: FIXTURE-REF','review_password'=>'FixturePass!42','review_version'=>1,'csrf_token'=>misField($review,'csrf_token')];
$bad=$decision;$bad['csrf_token']='invalid';checkMis(misRequest($invitee,'/pages/doctor/nurse_verification.php',$bad)['status']===403,'Credential decision rejects invalid CSRF');
$bad=$decision;$bad['review_password']='wrong';$r=misRequest($invitee,'/pages/doctor/nurse_verification.php',$bad);checkMis(str_contains($r['body'],'reauthentication failed') && (int)$db->query('SELECT self_service_only FROM users WHERE id='.$staffId)->fetchColumn()===1,'Incorrect Doctor password grants no access');
checkMis(misRequest($c,'/pages/doctor/nurse_verification.php',$decision)['status']===403,'MIS cannot approve credentials');
$r=misRequest($invitee,'/pages/doctor/nurse_verification.php',$decision);checkMis($r['status']===302 && $db->query('SELECT clinical_authorization_status FROM nurses WHERE user_id='.$staffId)->fetchColumn()==='authorized','Doctor approval activates authoritative clinical scope');
checkMis((int)$db->query('SELECT self_service_only FROM users WHERE id='.$staffId)->fetchColumn()===0,'Approved Nurse receives operational access');
require_once __DIR__.'/../includes/doctor_messaging_service.php';
$physicianMessages=new DoctorMessagingService($db,$db->query('SELECT * FROM users WHERE id='.$doctor)->fetch());
$conversation=$physicianMessages->start($staffId);$physicianMessages->send($conversation,'Synthetic clinical coordination');
$nurseMessages=new DoctorMessagingService($db,$db->query('SELECT * FROM users WHERE id='.$staffId)->fetch());$nurseMessages->send($conversation,'Synthetic nursing reply');
$threadSummary=$physicianMessages->threads()[0];
checkMis($threadSummary['contact_name']==='Pending Nurse'&&$threadSummary['preview']==='Synthetic nursing reply'&&(int)$threadSummary['unread_count']===1,'Conversation preview identifies authorized contact and actual unread reply');
$messagePage=misRequest($invitee,'/pages/doctor_messages.php?thread='.$conversation);
checkMis($messagePage['status']===200&&str_contains($messagePage['body'],'Back to conversations')&&str_contains($messagePage['body'],'clinical-contact-search')&&str_contains($messagePage['body'],'Synthetic nursing reply'),'Physician conversation renders searchable contacts, mobile back navigation and saved reply');
checkMis(count($physicianMessages->messages($conversation))===2,'Existing messaging tables support Nurse and Doctor coordination');
$db->exec('RENAME TABLE admin_profiles TO fixture_unavailable_profiles');
try {
 $failed=false;try{$physicianMessages->threads();}catch(PDOException $e){$failed=true;}
 checkMis($failed,'Conversation membership database failure propagates instead of becoming empty threads');
} finally {$db->exec('RENAME TABLE fixture_unavailable_profiles TO admin_profiles');}
$db->exec('RENAME TABLE messages TO fixture_unavailable_messages');
try {
 $failure=misRequest($invitee,'/pages/doctor_messages.php?thread='.$conversation);
 checkMis($failure['status']===503&&str_contains($failure['body'],'Reload messages')&&!str_contains($failure['body'],'SQLSTATE')&&!str_contains($failure['body'],'No conversations available')&&!str_contains($failure['body'],'No messages yet'),'Message history database failure renders truthful recoverable HTTP 503');
} finally {$db->exec('RENAME TABLE fixture_unavailable_messages TO messages');}
$db->prepare("INSERT INTO users(username,email,password_hash,first_name,last_name,role,status,email_verified) VALUES ('message_patient','message_patient@example.invalid',?,'Message','Patient','student','active',1)")->execute([password_hash('FixturePass!42',PASSWORD_DEFAULT)]);$messagePatient=(int)$db->lastInsertId();
try{$physicianMessages->start($messagePatient);checkMis(false,'Doctor to patient messaging denied');}catch(InvalidArgumentException $e){checkMis(true,'Doctor to patient messaging denied');}
$deniedPatient=false;try{new DoctorMessagingService($db,$db->query('SELECT * FROM users WHERE id='.$messagePatient)->fetch());}catch(RuntimeException $e){$deniedPatient=true;}checkMis($deniedPatient,'Patient cannot read physician messages');
$count=(int)$db->query('SELECT COUNT(*) FROM nurse_clinical_reviews WHERE nurse_user_id='.$staffId)->fetchColumn();
misRequest($invitee,'/pages/doctor/nurse_verification.php',$decision);
checkMis((int)$db->query('SELECT COUNT(*) FROM nurse_clinical_reviews WHERE nurse_user_id='.$staffId)->fetchColumn()===$count,'Replay creates no duplicate review');
checkMis(misRequest($pendingClient,'/api/inventory.php?action=list',null,true)['status']===401,'Old Nurse session requires reauthentication after scope change');
$login=misRequest($pendingClient,'/auth/login.php');misRequest($pendingClient,'/auth/login.php',['csrf_token'=>misField($login,'csrf_token'),'identifier'=>'staff_pending','password'=>'FixturePass!42']);
checkMis(misRequest($pendingClient,'/pages/nurse/account.php')['status']===302 && misRequest($pendingClient,'/pages/nurse/dashboard.php')['status']===200,'Approved Nurse routes to operational dashboard after sign-in');
$decision['review_version']=2;$decision['action']='revoke';misRequest($invitee,'/pages/doctor/nurse_verification.php',$decision);
checkMis((int)$db->query('SELECT self_service_only FROM users WHERE id='.$staffId)->fetchColumn()===1,'Revocation immediately removes clinical scope');
$decision['review_version']=3;$decision['action']='request_info';misRequest($invitee,'/pages/doctor/nurse_verification.php',$decision);
checkMis($db->query('SELECT clinical_authorization_status FROM nurses WHERE user_id='.$staffId)->fetchColumn()==='needs_information','Doctor can request additional information');
$decision['review_version']=4;$decision['action']='reject';misRequest($invitee,'/pages/doctor/nurse_verification.php',$decision);
checkMis($db->query('SELECT clinical_authorization_status FROM nurses WHERE user_id='.$staffId)->fetchColumn()==='rejected','Doctor can reject application without deleting account');
checkMis((int)$db->query("SELECT COUNT(*) FROM notifications WHERE user_id=$staffId AND type='clinical_review'")->fetchColumn()===4,'Every committed decision notifies Nurse once');
require_once __DIR__.'/../includes/ai/MICAWorkflowService.php';
$workflow=new MICAWorkflowService($db,$db->query('SELECT * FROM users WHERE id='.$doctor)->fetch());
checkMis(str_contains($workflow->handleNaturalLanguageQuery('Show pending Nurse verifications')['reply'],'Manage Nurses'),'MICA provides grounded staff status and navigation');
$workflow=new MICAWorkflowService($db,$db->query('SELECT * FROM users WHERE id='.$staffId)->fetch());
checkMis($workflow->handleNaturalLanguageQuery('Show pending Nurse verifications')['type']==='access_denied','MICA staff review denied to Nurse');
$login=misRequest($pendingClient,'/auth/login.php');misRequest($pendingClient,'/auth/login.php',['csrf_token'=>misField($login,'csrf_token'),'identifier'=>'staff_pending','password'=>'FixturePass!42']);
$account=misRequest($pendingClient,'/pages/nurse/credentials.php');
$resubmitted=misRequest($pendingClient,'/pages/nurse/credentials.php',['csrf_token'=>misField($account,'csrf_token'),'intent'=>'submit','license_number'=>'FIXTURE-PRC-02','employee_id'=>'FIXTURE-NURSE','position'=>'School Nurse']);
checkMis($resubmitted['status']===302 && $db->query('SELECT clinical_authorization_status FROM nurses WHERE user_id='.$staffId)->fetchColumn()==='pending','Restricted Nurse resubmits credentials for a new review');
checkMis((int)$db->query("SELECT COUNT(*) FROM notifications WHERE user_id=$doctor AND title='Nurse credentials awaiting review'")->fetchColumn()===1,'Credential submission notifies Doctor');
$decision['review_version']=5;$decision['action']='authorize';misRequest($invitee,'/pages/doctor/nurse_verification.php',$decision);
checkMis((int)$db->query('SELECT self_service_only FROM users WHERE id='.$staffId)->fetchColumn()===1,'New credential submission invalidates an older review form');
$credentialReader=new NurseVerificationService($db,$db->query('SELECT * FROM users WHERE id='.$staffId)->fetch());
$deniedCredential=false;try { $credentialReader->getNurse($doctor); } catch(RuntimeException $e) { $deniedCredential=true; }checkMis($deniedCredential,'Cross-account credential access rejected');
$login=misRequest($pendingClient,'/auth/login.php');misRequest($pendingClient,'/auth/login.php',['csrf_token'=>misField($login,'csrf_token'),'identifier'=>'staff_pending','password'=>'FixturePass!42']);
$account=misRequest($pendingClient,'/pages/nurse/credentials.php');$credentialFile=tempnam(__DIR__.'/../scratch','credential_');$credentialStored=null;
try {
    file_put_contents($credentialFile,'Not a PDF');
    $upload=['csrf_token'=>misField($account,'csrf_token'),'intent'=>'submit','license_number'=>'FIXTURE-UPLOAD-03','employee_id'=>'FIXTURE-NURSE','position'=>'School Nurse','credential_document'=>new CURLFile($credentialFile,'application/pdf','credential.pdf')];
    curl_setopt_array($pendingClient,[CURLOPT_URL=>$base.'/pages/nurse/credentials.php',CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$upload]);$body=curl_exec($pendingClient);
    checkMis(str_contains($body,'Only PDF, JPG and PNG documents are accepted.'),'Credential upload rejects MIME spoofing');
    file_put_contents($credentialFile,base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aE1sAAAAASUVORK5CYII='));
    $upload['credential_document']=new CURLFile($credentialFile,'image/png','credential.png');curl_setopt($pendingClient,CURLOPT_POSTFIELDS,$upload);curl_exec($pendingClient);
    $credentialStored=$db->query('SELECT valid_id_path FROM nurses WHERE user_id='.$staffId)->fetchColumn();
    checkMis(curl_getinfo($pendingClient,CURLINFO_RESPONSE_CODE)===302 && str_starts_with($credentialStored,'storage/registration_documents/credential_') && is_file(__DIR__.'/../'.$credentialStored),'Nurse supporting credential uploads to private storage');
} finally {
    unlink($credentialFile);
    // Keep the synthetic current document through subsequent browser eligibility checks.
}
$db->prepare("INSERT INTO conversations(conversation_type,title,created_by) VALUES ('doctor_consultation','Legacy physician patient thread',?)")->execute([$doctor]);$legacyConversation=(int)$db->lastInsertId();
$db->prepare('INSERT INTO conversation_participants(conversation_id,user_id) VALUES (?,?),(?,?)')->execute([$legacyConversation,$doctor,$legacyConversation,$messagePatient]);
$db->prepare("INSERT INTO messages(conversation_id,sender_id,message_body,message_type) VALUES (?,?,'Restricted legacy preview','text')")->execute([$legacyConversation,$doctor]);
$patientClient=misClient();$login=misRequest($patientClient,'/auth/login.php');misRequest($patientClient,'/auth/login.php',['csrf_token'=>misField($login,'csrf_token'),'identifier'=>'message_patient','password'=>'FixturePass!42']);
$listed=misRequest($patientClient,'/api/messages.php?action=list_conversations');checkMis(!str_contains($listed['body'],'Restricted legacy preview'),'Patient conversation listing excludes legacy physician previews');
$deniedMessages=json_decode(misRequest($patientClient,'/api/messages.php?action=get_messages&conversation_id='.$legacyConversation)['body'],true);checkMis(!($deniedMessages['success']??true),'Patient cannot retrieve legacy physician messages through clinic API');
$contacts=json_decode(misRequest($patientClient,'/api/messages.php?action=available_staff')['body'],true);checkMis(!in_array($staffId,array_column($contacts['staff']??[],'id'),true),'Pending Nurse is excluded from patient messaging contacts');
checkMis($physicianMessages->threads()===[],'Physician thread access closes when Nurse loses authorization');

if(in_array('--doctor-browser',$argv,true)) {
    putenv('DOCTOR_TEST_BASE='.$base);putenv('DOCTOR_TEST_PATIENT='.$staffId);
    passthru('node '.escapeshellarg(__DIR__.'/staff_verification_browser.cjs'),$staffBrowserExit);
    checkMis($staffBrowserExit===0,'Clinical staff review browser checks');
}
