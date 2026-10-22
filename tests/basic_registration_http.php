<?php
/** Real HTTP registration/OTP/dashboard tests against a disposable, schema-only local database. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
ini_set('session.save_path',sys_get_temp_dir());
require __DIR__.'/../config/config.php';
if(APP_ENV!=='local' || !in_array(DB_HOST,['localhost','127.0.0.1','::1'],true))throw new RuntimeException('This test requires a local loopback database.');
$admin=getDB();$database='bpc_basic_otp_test_'.bin2hex(random_bytes(5));$server=null;$checks=0;
function basicCheck(bool $ok,string $label): void {global $checks;if(!$ok)throw new RuntimeException($label);$checks++;echo "PASS $label\n";}
function client(){ $c=curl_init();curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEFILE=>'',CURLOPT_FOLLOWLOCATION=>false,CURLOPT_TIMEOUT=>15]);return $c; }
function request($client,string $path,?array $post=null): array {
    global $base;
    $headers=[];curl_setopt_array($client,[CURLOPT_URL=>$base.$path,CURLOPT_POST=>$post!==null,CURLOPT_HEADERFUNCTION=>function($c,$line)use(&$headers){if(str_contains($line,':')){[$name,$value]=explode(':',$line,2);$headers[strtolower($name)]=trim($value);}return strlen($line);}]);
    if($post!==null)curl_setopt($client,CURLOPT_POSTFIELDS,http_build_query($post));
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
    $socket=stream_socket_server('tcp://127.0.0.1:0',$error,$description);$port=(int)substr(strrchr(stream_socket_get_name($socket,false),':'),1);fclose($socket);$base='http://127.0.0.1:'.$port;
    // Child server uses synthetic records only and a closed loopback SMTP port: no real mail is sent.
    foreach(['DB_NAME'=>$database,'APP_URL'=>$base,'APP_ENV'=>'local','MAIL_HOST'=>'127.0.0.1','MAIL_PORT'=>'1','MAIL_USERNAME'=>'','MAIL_PASSWORD'=>'','MAIL_FROM_ADDRESS'=>'fixture@example.invalid','APP_ENV_FILE'=>__DIR__.'/nonexistent-test.env'] as $key=>$value)putenv($key.'='.$value);
    $log=sys_get_temp_dir().'/'.$database.'.log';
    $server=proc_open([PHP_BINARY,'-d','session.save_path='.sys_get_temp_dir(),'-S','127.0.0.1:'.$port,'-t',dirname(__DIR__)],[0=>['pipe','r'],1=>['file',$log,'a'],2=>['file',$log,'a']],$pipes,dirname(__DIR__),null,['bypass_shell'=>true]);
    if(!is_resource($server))throw new RuntimeException('Local server failed to start.');
    fclose($pipes[0]);
    for($i=0;$i<30;$i++){ $ready=@fsockopen('127.0.0.1',$port,$error,$description,0.1);if($ready){fclose($ready);break;}usleep(100000); }
    foreach(['student','teacher','nurse'] as $role){
        $c=client();$username='http_'.$role;
        $registration=request($c,'/auth/register.php');$csrf=token($registration);
        $payload=['csrf_token'=>$csrf,'account_type'=>$role,'role'=>$role,'first_name'=>'Alex','last_name'=>'Fixture','username'=>$username,'email'=>$username.'@example.invalid','password'=>'FixturePass!42','confirm_password'=>'FixturePass!42','certify'=>1,'contact_number'=>'09123456789','student_number'=>'HTTP-ST001','course'=>'BSIT','year_level'=>'1','section'=>'A','employee_id'=>'HTTP-'.$role,'license_number'=>'7654321','self_service_only'=>0,'email_verified'=>1,'status'=>'active'];
        $registered=request($c,'/auth/register.php',$payload);$account=testAccount($db,$username);
        basicCheck($registered['status']===302 && ($registered['headers']['location']??'')==='verify_registration.php' && $account!==[],"$role actual registration creates an account");
        basicCheck($account['status']==='pending' && !$account['email_verified'] && $account['self_service_only']==1,"$role registration ignores client status and permission escalation");
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
            basicCheck($after['status']==='pending' && $after['self_service_only']==1,'Nurse OTP cannot grant clinical access');
            basicCheck(request($c,'/pages/nurse/dashboard.php')['status']===302,'Unprovisioned nurse cannot enter clinical dashboard');
            continue;
        }
        $target=$base.'/pages/'.($role==='teacher'?'adviser':'student').'/dashboard.php';
        basicCheck($verified['status']===302 && ($verified['headers']['location']??'')===$target && $after['status']==='active',"$role correct OTP immediately redirects to authorized dashboard");
        basicCheck($cookiesBefore!==curl_getinfo($c,CURLINFO_COOKIELIST),"$role automatic login rotates the session cookie");
        $dashboard=request($c,substr($target,strlen($base)));
        basicCheck($dashboard['status']===200 && str_contains($dashboard['body'],$role==='teacher'?'My Clinic Status':'My Clinic Services'),"$role reaches actual protected dashboard over HTTP");
        $denied=request($c,'/api/medical_records.php?action=detail&visit_id='.$otherVisit);$body=json_decode($denied['body'],true);
        basicCheck(($body['success']??true)===false,"$role cannot read another user's medical record");
        $list=request($c,'/api/medical_records.php?action=list&student_id='.$otherPatient);
        basicCheck(!str_contains($list['body'],'OTHER_PATIENT_PRIVATE_FIXTURE'),"$role cannot enumerate another patient's stored records");
        $workspace=request($c,'/api/patients.php?action=lookup&student_id='.$otherPatient);
        basicCheck((json_decode($workspace['body'],true)['success']??true)===false && !str_contains($workspace['body'],'OTHER_PATIENT_PRIVATE_FIXTURE'),"$role cannot open another patient's stored workspace");
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
    foreach(['admin','doctor','co_admin'] as $role){
        $c=client();$csrf=token(request($c,'/auth/register.php'));$payload['csrf_token']=$csrf;$payload['account_type']=$role;$payload['role']=$role;$payload['username']='forbidden_'.$role;$payload['email']='forbidden_'.$role.'@example.invalid';
        request($c,'/auth/register.php',$payload);
        basicCheck(testAccount($db,$payload['username'])===[],"Public registration cannot obtain $role role");curl_close($c);
    }
    echo "All $checks real HTTP checks passed. No live records or real mail recipients were used.\n";
} catch(Throwable $e) {fwrite(STDERR,'FAIL '.$e->getMessage().PHP_EOL);$failed=true;}
finally {
    if(is_resource($server)){proc_terminate($server);proc_close($server);}
    if(preg_match('/^bpc_basic_otp_test_[a-f0-9]{10}$/',$database))$admin->exec("DROP DATABASE IF EXISTS `$database`");
}
exit(!empty($failed)?1:0);
