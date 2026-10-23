<?php
/** Real HTTP registration/OTP/dashboard tests against a disposable, schema-only local database. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$suiteCompleted=false;
register_shutdown_function(function()use(&$suiteCompleted){if(!$suiteCompleted){fwrite(STDERR,"FAIL HTTP suite did not complete\n");exit(1);}});
ini_set('session.save_path',sys_get_temp_dir());
require __DIR__.'/../config/config.php';
if(APP_ENV!=='local' || !in_array(DB_HOST,['localhost','127.0.0.1','::1'],true))throw new RuntimeException('This test requires a local loopback database.');
$admin=getDB();$database='bpc_basic_otp_test_'.bin2hex(random_bytes(5));$server=null;$checks=0;$fixtureFile=null;$uploadedFixtures=[];$uploadSource=null;$registrationUpload=null;
function basicCheck(bool $ok,string $label): void {global $checks;if(!$ok)throw new RuntimeException($label);$checks++;echo "PASS $label\n";}
function client(){ $c=curl_init();curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEFILE=>'',CURLOPT_FOLLOWLOCATION=>false,CURLOPT_TIMEOUT=>15]);return $c; }
function request($client,string $path,?array $post=null): array {
    global $base;
    $headers=[];curl_setopt_array($client,[CURLOPT_URL=>$base.$path,CURLOPT_POST=>$post!==null,CURLOPT_HEADERFUNCTION=>function($c,$line)use(&$headers){if(str_contains($line,':')){[$name,$value]=explode(':',$line,2);$headers[strtolower($name)]=trim($value);}return strlen($line);}]);
    if($post!==null)curl_setopt($client,CURLOPT_POSTFIELDS,array_filter($post,static fn($value)=>$value instanceof CURLFile)?$post:http_build_query($post));
    $body=curl_exec($client);if($body===false)throw new RuntimeException('Local HTTP test request failed.');
    return ['status'=>curl_getinfo($client,CURLINFO_RESPONSE_CODE),'headers'=>$headers,'body'=>$body];
}
function token(array $response): string {if(!preg_match('/name="csrf_token" value="([^"]+)"/',$response['body'],$match))throw new RuntimeException('CSRF field unavailable.');return $match[1];}
function testAccount(PDO $db,string $username): array {$q=$db->prepare('SELECT * FROM users WHERE username=?');$q->execute([$username]);return $q->fetch()?:[];}
try {
    $admin->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    foreach($admin->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetchAll(PDO::FETCH_NUM) as $row){
        $table=$row[0];if(!preg_match('/^[a-zA-Z0-9_]+$/',$table))throw new RuntimeException('Unexpected schema identifier.');
        $admin->exec("CREATE TABLE `$database`.`$table` LIKE `".DB_NAME."`.`$table`");
    }
    $db=new PDO('mysql:host='.DB_HOST.';port='.DB_PORT.';dbname='.$database.';charset=utf8mb4',DB_USER,DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $db->exec("INSERT INTO users(username,email,password_hash,first_name,last_name,role,admin_type,status,email_verified) VALUES ('http_fixture_admin','admin@example.invalid','unused','Local','Fixture','admin','primary_admin','active',1)");
    $db->exec("INSERT INTO users(username,email,password_hash,first_name,last_name,role,status,email_verified,self_service_only) VALUES ('other_patient','other_patient@example.invalid','unused','Other','Fixture','student','active',1,1)");
    $otherUser=(int)$db->lastInsertId();
    $db->prepare("INSERT INTO students(user_id,student_number) VALUES (?,'OTHER-ST001')")->execute([$otherUser]);$otherPatient=(int)$db->lastInsertId();
    $db->prepare("INSERT INTO consultations(student_id,consultation_date,chief_complaint) VALUES (?,NOW(),'OTHER_PATIENT_PRIVATE_FIXTURE')")->execute([$otherPatient]);$otherVisit=(int)$db->lastInsertId();
    $db->prepare("INSERT INTO medical_records(consultation_id,student_id,description) VALUES (?,?,'OTHER_PATIENT_PRIVATE_FIXTURE')")->execute([$otherVisit,$otherPatient]);
    $otherRecord=(int)$db->lastInsertId();
    $fixtureName='basic-otp-fixture-'.bin2hex(random_bytes(6)).'.txt';
    $fixtureFile=BASE_PATH.'/uploads/medical_records/'.$fixtureName;
    if(!is_dir(dirname($fixtureFile)))mkdir(dirname($fixtureFile),0755,true);
    file_put_contents($fixtureFile,'SYNTHETIC_PRIVATE_ATTACHMENT');
    $db->prepare('UPDATE medical_records SET file_path=? WHERE id=?')->execute(['uploads/medical_records/'.$fixtureName,$otherRecord]);
    if(in_array('--legacy', $argv, true))$db->exec('ALTER TABLE users DROP COLUMN email_verified_at, DROP COLUMN self_service_only');
    $socket=stream_socket_server('tcp://127.0.0.1:0',$error,$description);$port=(int)substr(strrchr(stream_socket_get_name($socket,false),':'),1);fclose($socket);$base='http://127.0.0.1:'.$port;
    // Child server uses synthetic records only and a closed loopback SMTP port: no real mail is sent.
    foreach(['DB_NAME'=>$database,'APP_URL'=>$base,'APP_ENV'=>'local','MAIL_HOST'=>'127.0.0.1','MAIL_PORT'=>'1','MAIL_USERNAME'=>'','MAIL_PASSWORD'=>'','MAIL_FROM_ADDRESS'=>'fixture@example.invalid','APP_ENV_FILE'=>__DIR__.'/nonexistent-test.env'] as $key=>$value)putenv($key.'='.$value);
    $log=sys_get_temp_dir().'/'.$database.'.log';
    $server=proc_open([PHP_BINARY,'-d','session.save_path='.sys_get_temp_dir(),'-S','127.0.0.1:'.$port,'-t',dirname(__DIR__)],[0=>['pipe','r'],1=>['file',$log,'a'],2=>['file',$log,'a']],$pipes,dirname(__DIR__),null,['bypass_shell'=>true]);
    if(!is_resource($server))throw new RuntimeException('Local server failed to start.');
    fclose($pipes[0]);
    for($i=0;$i<30;$i++){ $ready=@fsockopen('127.0.0.1',$port,$error,$description,0.1);if($ready){fclose($ready);break;}usleep(100000); }
    if(in_array('--legacy',$argv,true)){
        $c=client();$csrf=token(request($c,'/auth/register.php'));
        $probe=['csrf_token'=>$csrf,'role'=>'student','first_name'=>'Alex','last_name'=>'Fixture','username'=>'legacy_fixture','email'=>'legacy@example.invalid','password'=>'FixturePass!42','confirm_password'=>'FixturePass!42','certify'=>1,'student_number'=>'LEGACY001','course'=>'BSIT','year_level'=>'1','section'=>'A','contact_number'=>'09123456789'];
        $blocked=request($c,'/auth/register.php',$probe);
        basicCheck($blocked['status']===503&&str_contains($blocked['body'],'system update')&&testAccount($db,'legacy_fixture')===[],'Missing scope schema fails safely without an account');
        $invalidProbe=$probe;$invalidProbe['username']='invalid name';
        curl_setopt($c,CURLOPT_HTTPHEADER,['X-Requested-With: XMLHttpRequest']);
        $separate=request($c,'/auth/register.php',$invalidProbe);$separateBody=json_decode($separate['body'],true);
        basicCheck($separate['status']===503 && ($separateBody['error_code']??'')==='schema_update_required' && !str_contains($separate['body'],'Username must'),'Missing schema returns only deployment failure, never mixed username validation');
        curl_setopt($c,CURLOPT_HTTPHEADER,[]);
        require __DIR__.'/../includes/registration_schema.php';
        basicCheck(count(migrate_basic_registration_schema($db))===2,'Portable additive migration repairs both missing columns');
        basicCheck(migrate_basic_registration_schema($db)===[],'Migration can be repeated without changing existing accounts');
        curl_close($c);
    }
    $registrationUpload=tempnam(sys_get_temp_dir(),'bpc-reg');
    file_put_contents($registrationUpload,base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aE1sAAAAASUVORK5CYII='));
    foreach(['student','teacher','nurse'] as $role){
        $c=client();$username='http_'.$role;
        $registration=request($c,'/auth/register.php');$csrf=token($registration);
        $payload=['csrf_token'=>$csrf,'account_type'=>$role,'role'=>$role,'first_name'=>'Alex','last_name'=>'Fixture','username'=>$username,'email'=>$username.'@example.invalid','password'=>'FixturePass!42','confirm_password'=>'FixturePass!42','certify'=>1,'contact_number'=>'09123456789','student_number'=>'HTTP-ST001','course'=>'BSIT','year_level'=>'1','section'=>'A','employee_id'=>'HTTP-'.$role,'license_number'=>'7654321','self_service_only'=>0,'email_verified'=>1,'status'=>'active'];
        $payload['profile_picture']=new CURLFile($registrationUpload,'image/png','fixture.png');
        if($role!=='teacher')$payload['valid_id']=new CURLFile($registrationUpload,'image/png','fixture-id.png');
        $registered=request($c,'/auth/register.php',$payload);$account=testAccount($db,$username);
        basicCheck($registered['status']===302 && ($registered['headers']['location']??'')==='verify_registration.php' && $account!==[],"$role actual registration creates an account");
        if(!empty($account['profile_picture']) && $account['profile_picture']!=='assets/images/default-avatar.png')$uploadedFixtures[]=BASE_PATH.'/'.$account['profile_picture'];
        basicCheck(is_file(BASE_PATH.'/'.$account['profile_picture']) && $account['profile_picture']!=='assets/images/default-avatar.png',"$role profile upload remains mapped to account");
        if($role!=='teacher'){
            $profileTable=$role==='student'?'students':'nurses';
            $document=$db->prepare("SELECT valid_id_path FROM $profileTable WHERE user_id=?");$document->execute([$account['id']]);$documentPath=$document->fetchColumn();
            if($documentPath)$uploadedFixtures[]=BASE_PATH.'/'.$documentPath;
            basicCheck($documentPath && is_file(BASE_PATH.'/'.$documentPath),"$role identity document upload remains stored and mapped");
        }
        basicCheck($account['status']==='pending' && !$account['email_verified'] && $account['self_service_only']==1,"$role registration ignores client status and permission escalation");
        basicCheck(password_verify('FixturePass!42',$account['password_hash'])&&strlen($account['otp_hash'])===64&&!empty($account['otp_expires_at']),"$role saves a password hash and expiring hashed OTP");
        $retry=request($c,'/auth/register.php',$payload);
        basicCheck($retry['status']===302&&testAccount($db,$username)['id']===$account['id'],"$role retry resumes the existing unverified account");
        // Known test challenge simulates a received email. Only this disposable fixture is changed.
        $db->prepare("UPDATE users SET otp_hash=SHA2('123456',256),otp_expires_at=DATE_ADD(NOW(),INTERVAL 10 MINUTE) WHERE id=?")->execute([$account['id']]);
        $verify=request($c,'/auth/verify_registration.php');$verifyToken=token($verify);
        request($c,'/auth/verify_registration.php',['csrf_token'=>$verifyToken,'action'=>'verify','otp'=>'999999']);
        basicCheck(!testAccount($db,$username)['email_verified'],"$role incorrect OTP does not activate");
        $db->prepare('UPDATE users SET otp_expires_at=DATE_SUB(NOW(),INTERVAL 1 SECOND) WHERE id=?')->execute([$account['id']]);
        request($c,'/auth/verify_registration.php',['csrf_token'=>$verifyToken,'action'=>'verify','otp'=>'123456']);
        basicCheck(!testAccount($db,$username)['email_verified'],"$role expired OTP does not activate");
        $db->prepare('UPDATE users SET otp_expires_at=DATE_ADD(NOW(),INTERVAL 10 MINUTE) WHERE id=?')->execute([$account['id']]);
        $cookiesBefore=curl_getinfo($c,CURLINFO_COOKIELIST);
        $verified=request($c,'/auth/verify_registration.php',['csrf_token'=>$verifyToken,'action'=>'verify','otp'=>'123456']);
        $after=testAccount($db,$username);
        basicCheck($after['email_verified'] && $after['email_verified_at'] && !$after['otp_hash'] && !$after['otp_expires_at'],"$role OTP records ownership time and consumes challenge");
        if($role==='nurse') {
            basicCheck($after['status']==='active' && $after['self_service_only']==1,'Nurse OTP cannot grant clinical access');
            basicCheck(request($c,'/pages/nurse/dashboard.php')['status']===302,'Unprovisioned nurse cannot enter clinical dashboard');
            basicCheck(($verified['headers']['location']??'')===$base.'/pages/nurse/account.php','Nurse OTP routes to basic account');
            basicCheck(str_contains(request($c,'/pages/nurse/account.php')['body'],'basic account is active'),'Basic nurse can open account page');
            basicCheck(request($c,'/api/medical_records.php?action=file&id='.$otherRecord)['status']===403,'Basic nurse cannot download clinical documents');
            basicCheck(request($c,'/api/patients.php?action=lookup&student_id='.$otherPatient)['status']===403,'Basic nurse cannot open patient workspace');
            basicCheck(request($c,'/api/ai_assistant.php?action=daily_summary')['status']===403,'Basic nurse cannot obtain clinical AI summaries');
            $basicLogin=client();$basicToken=token(request($basicLogin,'/auth/login.php'));
            $basicSigned=request($basicLogin,'/auth/login.php',['csrf_token'=>$basicToken,'identifier'=>$username,'password'=>'FixturePass!42']);
            basicCheck(($basicSigned['headers']['location']??'')===$base.'/pages/nurse/account.php','Basic nurse can sign in with saved credentials');
            curl_close($basicLogin);
            require_once __DIR__.'/../includes/account_security_exceptions.php';
            $actor=(int)$db->query("SELECT id FROM users WHERE username='http_fixture_admin'")->fetchColumn();
            $security=new AccountSecurityExceptionService($db);
            $exception=$security->open($actor,(int)$after['id'],'Synthetic authorized clinical staff provisioning','HTTP-TEST-EVIDENCE');
            $security->resolve($actor,$exception,'verify','Authorized test officer confirmed staff credentials and clinical access');
            basicCheck((int)testAccount($db,$username)['self_service_only']===1,'MIS identity review cannot grant Nurse clinical access');
            require_once __DIR__.'/../includes/nurse_verification_service.php';
            $db->prepare("INSERT INTO users(username,email,password_hash,first_name,last_name,role,admin_type,status,email_verified) VALUES ('clinical_reviewer','reviewer@example.invalid',?,'Clinical','Reviewer','admin','co_admin','active',1)")->execute([password_hash('FixturePass!42',PASSWORD_DEFAULT)]);
            $reviewer=(int)$db->lastInsertId();$db->prepare("INSERT INTO admin_profiles(user_id,profile) VALUES (?,'doctor')")->execute([$reviewer]);
            $db->prepare("UPDATE nurses SET position='School Nurse',valid_id_path=COALESCE(valid_id_path,'storage/registration_documents/fixture.pdf') WHERE user_id=?")->execute([$after['id']]);
            $reviewerUser=$db->query('SELECT * FROM users WHERE id='.$reviewer)->fetch();
            $authorization=new NurseVerificationService($db,$reviewerUser);
            $authorization->authorize((int)$after['id'],'Synthetic PRC and appointment verification reference','FixturePass!42',(int)testAccount($db,$username)['credential_version']);
            request($c,'/auth/logout.php');
            $loginToken=token(request($c,'/auth/login.php'));
            $login=request($c,'/auth/login.php',['csrf_token'=>$loginToken,'identifier'=>$username,'password'=>'FixturePass!42']);
            basicCheck($login['status']===302 && testAccount($db,$username)['self_service_only']==0,'Nurse signs in only after audited physician authorization');
        $sosData=['action'=>'sos','csrf_token'=>$loginToken,'emergency_type'=>'Other','description'=>'Synthetic mobile workflow test','location'=>'Synthetic room'];
        $createdSos=json_decode(request($c,'/api/emergencies.php',$sosData)['body'],true);
        basicCheck(($createdSos['success']??false) && $createdSos['latitude']===null,"$role explicit SOS saves without inventing GPS");
        $sosId=(int)$createdSos['emergency_id'];
        $duplicateSos=json_decode(request($c,'/api/emergencies.php',$sosData)['body'],true);
        basicCheck((int)$duplicateSos['emergency_id']===$sosId,"$role repeated SOS returns existing emergency");
        $historySos=json_decode(request($c,'/api/emergencies.php?action=list&history=1&mine=1')['body'],true);
        basicCheck(($historySos['success']??false) && count($historySos['data'])===1 && (int)$historySos['data'][0]['user_id']===(int)$account['id'],"$role SOS history is reporter-scoped");
        $cancelSos=json_decode(request($c,'/api/emergencies.php',['action'=>'cancel_sos','csrf_token'=>$loginToken,'emergency_id'=>$sosId,'reason'=>'Synthetic test complete'])['body'],true);
        basicCheck(($cancelSos['success']??false) && $cancelSos['status']==='cancelled',"$role can cancel own synthetic emergency");
            basicCheck(request($c,'/api/medical_records.php?action=file&id='.$otherRecord)['body']==='SYNTHETIC_PRIVATE_ATTACHMENT','Authorized clinic nurse can download a patient attachment');
            $uploadToken=token(request($c,'/pages/nurse/medical_records.php'));
            $uploadSource=tempnam(sys_get_temp_dir(),'bpc-otp');file_put_contents($uploadSource,"%PDF-1.4\nSynthetic test attachment\n%%EOF");
            $uploaded=request($c,'/api/medical_records.php',['action'=>'upload','csrf_token'=>$uploadToken,'consultation_id'=>$otherVisit,'student_id'=>$otherPatient,'description'=>'Synthetic upload fixture','file'=>new CURLFile($uploadSource,'application/pdf','fixture.pdf')]);
            $uploadBody=json_decode($uploaded['body'],true);
            basicCheck(($uploadBody['success']??false) && str_starts_with($uploadBody['path']??'',$base.'/api/medical_records.php?action=file&id='),'Nurse upload returns the authorized download URL');
            parse_str(parse_url($uploadBody['path'],PHP_URL_QUERY),$downloadQuery);$uploadedId=(int)$downloadQuery['id'];
            $pathQuery=$db->prepare('SELECT file_path FROM medical_records WHERE id=?');$pathQuery->execute([$uploadedId]);
            $uploadedPath=realpath(BASE_PATH.'/'.$pathQuery->fetchColumn());
            if($uploadedPath && str_starts_with($uploadedPath,realpath(BASE_PATH.'/uploads/medical_records').DIRECTORY_SEPARATOR))$uploadedFixtures[]=$uploadedPath;
            basicCheck(str_starts_with(request($c,'/api/medical_records.php?action=file&id='.$uploadedId)['body'],'%PDF-1.4'),'Newly uploaded record remains downloadable by authorized clinic staff');
            $db->prepare("UPDATE medical_records SET file_path='config/config.php' WHERE id=?")->execute([$otherRecord]);
            basicCheck(request($c,'/api/medical_records.php?action=file&id='.$otherRecord)['status']===404,'Attachment download cannot escape the private medical directory');
            $db->prepare('UPDATE medical_records SET file_path=? WHERE id=?')->execute(['uploads/medical_records/'.$fixtureName,$otherRecord]);
            curl_close($c);
            continue;
        }
        $target=$base.'/pages/'.($role==='teacher'?'adviser':'student').'/dashboard.php';
        basicCheck($verified['status']===302 && ($verified['headers']['location']??'')===$target && $after['status']==='active',"$role correct OTP immediately redirects to authorized dashboard");
        basicCheck($cookiesBefore!==curl_getinfo($c,CURLINFO_COOKIELIST),"$role automatic login rotates the session cookie");
        $dashboard=request($c,substr($target,strlen($base)));
        basicCheck($dashboard['status']===200 && str_contains($dashboard['body'],$role==='teacher'?'My Clinic Status':'My Clinic Services'),"$role reaches actual protected dashboard over HTTP");
        $sosData=['action'=>'sos','csrf_token'=>$verifyToken,'emergency_type'=>'Other','description'=>'Synthetic mobile workflow test','location'=>'Synthetic room'];
        $createdSos=json_decode(request($c,'/api/emergencies.php',$sosData)['body'],true);
        basicCheck(($createdSos['success']??false) && $createdSos['latitude']===null,"$role explicit SOS saves without inventing GPS");
        $sosId=(int)$createdSos['emergency_id'];
        $duplicateSos=json_decode(request($c,'/api/emergencies.php',$sosData)['body'],true);
        basicCheck((int)$duplicateSos['emergency_id']===$sosId,"$role repeated SOS returns existing emergency");
        $historySos=json_decode(request($c,'/api/emergencies.php?action=list&history=1&mine=1')['body'],true);
        basicCheck(($historySos['success']??false) && count($historySos['data'])===1 && (int)$historySos['data'][0]['user_id']===(int)$account['id'],"$role SOS history is reporter-scoped");
        $cancelSos=json_decode(request($c,'/api/emergencies.php',['action'=>'cancel_sos','csrf_token'=>$verifyToken,'emergency_id'=>$sosId,'reason'=>'Synthetic test complete'])['body'],true);
        basicCheck(($cancelSos['success']??false) && $cancelSos['status']==='cancelled',"$role can cancel own synthetic emergency");
        $denied=request($c,'/api/medical_records.php?action=detail&visit_id='.$otherVisit);$body=json_decode($denied['body'],true);
        basicCheck(($body['success']??true)===false,"$role cannot read another user's medical record");
        basicCheck(request($c,'/api/medical_records.php?action=file&id='.$otherRecord)['status']===403,"$role cannot download another patient's attachment");
        $list=request($c,'/api/medical_records.php?action=list&student_id='.$otherPatient);
        basicCheck(!str_contains($list['body'],'OTHER_PATIENT_PRIVATE_FIXTURE'),"$role cannot enumerate another patient's stored records");
        $workspace=request($c,'/api/patients.php?action=lookup&student_id='.$otherPatient);
        basicCheck((json_decode($workspace['body'],true)['success']??true)===false && !str_contains($workspace['body'],'OTHER_PATIENT_PRIVATE_FIXTURE'),"$role cannot open another patient's stored workspace");
        if($role==='student') {
            $profile=$db->prepare('SELECT id FROM students WHERE user_id=?');$profile->execute([$account['id']]);$ownId=(int)$profile->fetchColumn();
            $db->prepare('UPDATE medical_records SET student_id=? WHERE id=?')->execute([$ownId,$otherRecord]);
            basicCheck(request($c,'/api/medical_records.php?action=file&id='.$otherRecord)['body']==='SYNTHETIC_PRIVATE_ATTACHMENT','Student can download an attachment owned by the authenticated account');
            $db->prepare('UPDATE medical_records SET student_id=? WHERE id=?')->execute([$otherPatient,$otherRecord]);
        }
        if($role==='teacher') {
            basicCheck(request($c,'/api/my_advisory.php?action=set',['csrf_token'=>$verifyToken,'section_id'=>1])['status']===403,'Basic teacher cannot self-grant advisory access');
            basicCheck(request($c,'/api/sections.php?action=list')['status']===403,'Basic teacher cannot enumerate advisory student information');
            basicCheck(request($c,'/api/patients.php?action=lookup&student_id=1')['status']===403,'Basic teacher cannot open patient workspaces');
        }
        $db->prepare("UPDATE users SET status='pending' WHERE id=?")->execute([$account['id']]);
        $login=client();$loginToken=token(request($login,'/auth/login.php'));
        $wrong=request($login,'/auth/login.php',['csrf_token'=>$loginToken,'identifier'=>$username,'password'=>'WrongPassword!']);
        basicCheck(str_contains($wrong['body'],'Invalid username or password.') && testAccount($db,$username)['status']==='pending',"$role wrong password cannot reconcile pending state");
        $signed=request($login,'/auth/login.php',['csrf_token'=>$loginToken,'identifier'=>$username,'password'=>'FixturePass!42']);
        basicCheck($signed['status']===302 && ($signed['headers']['location']??'')===$target && testAccount($db,$username)['status']==='active',"$role existing verified pending account reconciles individually");
        foreach(['suspended','rejected'] as $state){
            $db->prepare('UPDATE users SET status=? WHERE id=?')->execute([$state,$account['id']]);
            $blocked=client();$blockedToken=token(request($blocked,'/auth/login.php'));
            $attempt=request($blocked,'/auth/login.php',['csrf_token'=>$blockedToken,'identifier'=>$username,'password'=>'FixturePass!42']);
            basicCheck($attempt['status']===200 && testAccount($db,$username)['status']===$state && str_contains($attempt['body'],$state==='suspended'?'Your account is suspended.':'Your registration was rejected.'),"$role $state account stays blocked");
            curl_close($blocked);
        }
        curl_close($login);curl_close($c);
    }
    // Exercise existing recovery endpoints without sending real mail.
    $recovery=client();$recoveryToken=token(request($recovery,'/auth/forgot_password.php'));
    request($recovery,'/auth/forgot_password.php',['csrf_token'=>$recoveryToken,'email'=>'http_student@example.invalid']);
    $beforeReset=testAccount($db,'http_student');
    basicCheck(strlen($beforeReset['reset_token']??'')===64 && !empty($beforeReset['reset_token_expires']),'Forgot Password stores an expiring hashed challenge');
    $resetChallenge=bin2hex(random_bytes(32));
    $db->prepare('UPDATE users SET reset_token=?,reset_token_expires=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 10 MINUTE) WHERE id=?')->execute([hash('sha256',$resetChallenge),$beforeReset['id']]);
    $resetToken=token(request($recovery,'/auth/reset_password.php?token='.$resetChallenge));
    request($recovery,'/auth/reset_password.php',['csrf_token'=>$resetToken,'token'=>$resetChallenge,'password'=>'NewFixturePass!42','confirm_password'=>'NewFixturePass!42']);
    $afterReset=testAccount($db,'http_student');
    basicCheck(password_verify('NewFixturePass!42',$afterReset['password_hash']) && !$afterReset['reset_token'],'Password reset saves hash and consumes challenge');
    basicCheck($afterReset['role']===$beforeReset['role'] && $afterReset['status']===$beforeReset['status'] && $afterReset['self_service_only']===$beforeReset['self_service_only'],'Password reset preserves role, suspended/rejected status and permissions');
    curl_close($recovery);
    require_once __DIR__.'/../includes/doctor_invitation_service.php';
    $inviteChallenge=null;
    $invitations=new DoctorInvitationService($db,function($email,$name,$url)use(&$inviteChallenge){parse_str(parse_url($url,PHP_URL_FRAGMENT),$query);$inviteChallenge=$query['token'];return true;});
    $actor=(int)$db->query("SELECT id FROM users WHERE username='http_fixture_admin'")->fetchColumn();
    $invitations->create($actor,['first_name'=>'Doctor','last_name'=>'Fixture','username'=>'http_doctor','email'=>'http_doctor@example.invalid','official_designation'=>'School Physician','appointment_reference'=>'HTTP-SYNTHETIC','verification_status'=>'verified']);
    $doctor=client();$setup=token(request($doctor,'/auth/setup_account.php?token='.$inviteChallenge));
    request($doctor,'/auth/setup_account.php',['csrf_token'=>$setup,'token'=>$inviteChallenge,'password'=>'FixturePass!42','confirm_password'=>'FixturePass!42']);
    basicCheck(testAccount($db,'http_doctor')['status']==='active','Invited Doctor sets password and activates through real endpoint');
    $doctorToken=token(request($doctor,'/auth/login.php'));
    $doctorLogin=request($doctor,'/auth/login.php',['csrf_token'=>$doctorToken,'identifier'=>'http_doctor','password'=>'FixturePass!42']);
    basicCheck(($doctorLogin['headers']['location']??'')===$base.'/pages/doctor/dashboard.php','Invited Doctor signs in to clinical Co-Admin dashboard');
    basicCheck(request($doctor,'/pages/doctor/dashboard.php')['status']===200,'Invited Doctor protected dashboard renders');
    curl_close($doctor);
    $ajax=client();curl_setopt($ajax,CURLOPT_HTTPHEADER,['X-Requested-With: XMLHttpRequest','Accept: application/json']);
    $ajaxCsrf=token(request($ajax,'/auth/register.php'));
    $valid=['csrf_token'=>$ajaxCsrf,'role'=>'student','account_type'=>'student','first_name'=>'Alex','last_name'=>'Fixture','username'=>'ajax_student','email'=>'ajax_student@example.invalid','password'=>'FixturePass!42','confirm_password'=>'FixturePass!42','certify'=>1,'student_number'=>'AJAX-ST001','course'=>'BSIT','year_level'=>'1','section'=>'A','contact_number'=>'09123456789'];
    foreach([['first_name'=>''],['email'=>'invalid'],['confirm_password'=>''],['confirm_password'=>'mismatch'],['username'=>'http_student'],['email'=>'http_student@example.invalid']]as$bad){
        $r=request($ajax,'/auth/register.php',array_replace($valid,$bad));$body=json_decode($r['body'],true);
        basicCheck(in_array($r['status'],[422,409],true)&&($body['success']??true)===false,'AJAX invalid or duplicate information returns safe JSON');
    }
    foreach([['username'=>'http_student'],['email'=>'http_student@example.invalid']] as $duplicate){
        $conflict=request($ajax,'/auth/register.php',array_replace($valid,$duplicate));$conflictBody=json_decode($conflict['body'],true);
        $field=array_key_first($duplicate);
        basicCheck($conflict['status']===409 && ($conflictBody['error_code']??'')==='duplicate_account' && isset($conflictBody['field_errors'][$field]),'Duplicate account returns 409 and correct field detail');
    }
    foreach(['ab','invalid name','invalid-name',str_repeat('a',51)] as $invalidUsername){
        $invalid=request($ajax,'/auth/register.php',array_replace($valid,['username'=>$invalidUsername]));$details=json_decode($invalid['body'],true);
        basicCheck($invalid['status']===422 && isset($details['field_errors']['username']) && !str_contains($invalid['body'],'system update'),'Invalid username returns field-specific 422 without maintenance alert');
    }
    $normal=client();$normalToken=token(request($normal,'/auth/register.php'));
    $inline=request($normal,'/auth/register.php',array_replace($valid,['csrf_token'=>$normalToken,'username'=>'bad name']));
    basicCheck(str_contains($inline['body'],'id="usernameInputError"'),'Ordinary form renders username error beside username field');curl_close($normal);
    $r=request($ajax,'/auth/register.php',array_replace($valid,['csrf_token'=>'bad']));basicCheck($r['status']===403&&(json_decode($r['body'],true)['success']??true)===false,'AJAX invalid CSRF returns JSON 403');
    $r=request($ajax,'/auth/register.php',$valid);$body=json_decode($r['body'],true);$saved=testAccount($db,'ajax_student');
    basicCheck($r['status']===200&&($body['success']??false)&&($body['redirect']??'')==='verify_registration.php'&&$saved!==[],'AJAX creates an account and returns the OTP destination');
    foreach(['Letters123','  Trimmed_123  '] as $i=>$validName){
        $fresh=client();$freshToken=token(request($fresh,'/auth/register.php'));
        $freshPayload=array_replace($valid,['csrf_token'=>$freshToken,'username'=>$validName,'email'=>'username'.$i.'@example.invalid','student_number'=>'USERNAME'.$i]);
        $freshResult=request($fresh,'/auth/register.php',$freshPayload);
        basicCheck($freshResult['status']===302 && testAccount($db,trim($validName))!==[],'Alphanumeric or whitespace-trimmed underscore username proceeds to OTP');curl_close($fresh);
    }
    $verify=request($ajax,'/auth/verify_registration.php');basicCheck(str_contains(html_entity_decode($verify['body']),"couldn't send")&&$saved['status']==='pending','Mail failure is visible and leaves the account pending');
    $verifyToken=token($verify);$r=request($ajax,'/auth/verify_registration.php',['csrf_token'=>$verifyToken,'action'=>'resend']);basicCheck(str_contains($r['body'],'before requesting another code'),'Resend cooldown is enforced');
    $db->prepare('UPDATE users SET otp_last_sent_at=DATE_SUB(NOW(),INTERVAL 2 MINUTE) WHERE id=?')->execute([$saved['id']]);
    $r=request($ajax,'/auth/verify_registration.php',['csrf_token'=>$verifyToken,'action'=>'resend']);basicCheck(str_contains($r['body'],'delivery failed')&&testAccount($db,'ajax_student')['otp_hash']!==$saved['otp_hash'],'Resend replaces the challenge and reports transport failure accurately');
    $r=request($ajax,'/auth/register.php',$valid);basicCheck((json_decode($r['body'],true)['success']??false)&&(int)$db->query("SELECT COUNT(*) FROM users WHERE username='ajax_student'")->fetchColumn()===1,'AJAX retry resumes without a duplicate account');curl_close($ajax);
    if(in_array('--browser',$argv,true)){putenv('REG_TEST_BASE='.$base);passthru('node '.escapeshellarg(__DIR__.'/registration_browser.cjs'),$browserExit);basicCheck($browserExit===0,'Actual Chrome password control and scrolling checks');}
    unset($payload['profile_picture'],$payload['valid_id']);
    foreach(['admin','doctor','co_admin'] as $role){
        $c=client();$csrf=token(request($c,'/auth/register.php'));$payload['csrf_token']=$csrf;$payload['account_type']=$role;$payload['role']=$role;$payload['username']='forbidden_'.$role;$payload['email']='forbidden_'.$role.'@example.invalid';
        request($c,'/auth/register.php',$payload);
        basicCheck(testAccount($db,$payload['username'])===[],"Public registration cannot obtain $role role");curl_close($c);
    }
    if($apache=getenv('BPC_APACHE_TEST_URL')) {
        $anonymous=client();curl_setopt($anonymous,CURLOPT_URL,rtrim($apache,'/').'/uploads/medical_records/'.$fixtureName);
        curl_exec($anonymous);basicCheck(curl_getinfo($anonymous,CURLINFO_RESPONSE_CODE)===403,'Apache blocks direct private attachment URLs');curl_close($anonymous);
    }
    echo "All $checks real HTTP checks passed. No live records or real mail recipients were used.\n";
} catch(Throwable $e) {fwrite(STDERR,'FAIL '.$e->getMessage().PHP_EOL);$failed=true;}
finally {
    if(is_resource($server)){proc_terminate($server);proc_close($server);}
    if($fixtureFile && is_file($fixtureFile))unlink($fixtureFile);
    if($uploadSource && is_file($uploadSource))unlink($uploadSource);
    if($registrationUpload && is_file($registrationUpload))unlink($registrationUpload);
    foreach($uploadedFixtures as $uploadedFixture)if(is_file($uploadedFixture))unlink($uploadedFixture);
    if(preg_match('/^bpc_basic_otp_test_[a-f0-9]{10}$/',$database))$admin->exec("DROP DATABASE IF EXISTS `$database`");
}
$suiteCompleted=true;exit(!empty($failed)?1:0);
