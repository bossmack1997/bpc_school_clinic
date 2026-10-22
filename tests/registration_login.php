<?php
/** Execute the real login controller against connection-local temporary account tables. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
ob_start();ini_set('session.save_path',sys_get_temp_dir());
require __DIR__.'/../config/config.php';
set_exception_handler(function(Throwable $e){fwrite(STDERR,'FAIL '.$e->getMessage().PHP_EOL);exit(1);});
$db=getDB();
foreach(['users','students','teachers','nurses','institutional_roster','student_qrs','teacher_qrs','account_security_events','activity_logs','login_attempts','notifications','queue_numbers','system_settings'] as $table){
    $db->exec("CREATE TEMPORARY TABLE login_test_$table LIKE $table");
    $db->exec("ALTER TABLE login_test_$table RENAME TO $table");
}
$case=$argv[1]??'eligible';
$state=match($case){'suspended'=>'suspended','rejected'=>'rejected','unverified_active'=>'active',default=>'pending'};
$verified=in_array($case,['unverified','unverified_active','otp_dashboard'],true)?0:1;
$hash=password_hash('FixturePass!42',PASSWORD_DEFAULT);
$q=$db->prepare("INSERT INTO users(id,username,email,password_hash,first_name,last_name,role,admin_type,status,email_verified,registration_status) VALUES (?,?,?,?,'Alex','Fixture',?,?,?,?, 'review')");
$q->execute([700,'fixture_admin','fixture_admin@example.invalid',$hash,'admin','primary_admin','active',1]);
if($case!=='untrusted')$db->exec("INSERT INTO institutional_roster(identifier,role,first_name,last_name,institutional_email,status) VALUES ('LOGIN-701','student','Alex','Fixture','fixture_login@example.invalid','eligible')");
if($case==='otp_dashboard') {
    require __DIR__.'/../includes/ai/MICARegistrationValidator.php';
    $validator=new MICARegistrationValidator($db);
    $assessment=$validator->validateRegistration(['role'=>'student','first_name'=>'Alex','last_name'=>'Fixture','email'=>'fixture_login@example.invalid','username'=>'fixture_login','password'=>'FixturePass!42','confirm_password'=>'FixturePass!42','certify'=>1,'student_number'=>'LOGIN-701','course'=>'BSIT','year_level'=>'1','section'=>'A'],true);
    if($assessment['decision']!=='approved')throw new RuntimeException('Trusted student registration was not eligible.');
}
$q->execute([701,'fixture_login','fixture_login@example.invalid',$hash,'student',null,$state,$verified]);
$db->exec("INSERT INTO students(user_id,student_number) VALUES (701,'LOGIN-701')");
if($case==='otp_dashboard') {
    $db->exec("UPDATE users SET otp_hash=SHA2('123456',256),otp_expires_at=DATE_ADD(NOW(),INTERVAL 10 MINUTE) WHERE id=701");
    if($validator->verifyOtp(701,'123456')['account_status']!=='active')throw new RuntimeException('OTP did not activate trusted student.');
}
$_SESSION=[];$_SESSION['csrf_token']='fixture-csrf';
$_SERVER['REQUEST_METHOD']='POST';$_SERVER['REMOTE_ADDR']='192.0.2.44';
$_SERVER['REQUEST_URI']='/auth/login.php';$_SERVER['SCRIPT_NAME']='/auth/login.php';
$_POST=['identifier'=>'fixture_login','password'=>$case==='bad_password'?'IncorrectPass!':'FixturePass!42','csrf_token'=>'fixture-csrf'];
register_shutdown_function(function()use($db,$case){
    $html=ob_get_clean();$state=$db->query('SELECT status FROM users WHERE id=701')->fetchColumn();
    $session=(int)($_SESSION['user']['id']??0);$pending=(int)($_SESSION['pending_verification_user_id']??0);
    $ok=match($case){
        'eligible','otp_dashboard'=>$state==='active'&&$session===701,
        'unverified','unverified_active'=>$state==='pending'&&$session===0&&$pending===701,
        'untrusted'=>$state==='pending'&&$session===0&&str_contains($html,'Your email is verified.'),
        'suspended'=>$state==='suspended'&&$session===0&&str_contains($html,'Your account is suspended.'),
        'rejected'=>$state==='rejected'&&$session===0&&str_contains($html,'Your registration was rejected.'),
        'bad_password'=>$state==='pending'&&$session===0&&str_contains($html,'Invalid username or password.'),
        default=>false
    };
    if($ok && $case==='otp_dashboard') {
        $target=dashboard_url_for($_SESSION['user']['role']);
        if($target!==APP_URL.'/pages/student/dashboard.php')throw new RuntimeException('Wrong dashboard target.');
        $reached=false;
        register_shutdown_function(function()use(&$reached){if(!$reached){fwrite(STDERR,"FAIL protected dashboard did not render\n");exit(1);}});
        $_SERVER['REQUEST_METHOD']='GET';$_SERVER['SCRIPT_NAME']='/pages/student/dashboard.php';$_POST=[];
        ob_start();require __DIR__.'/../pages/student/dashboard.php';$dashboard=ob_get_clean();
        $reached=str_contains($dashboard,'My Clinic Services') && (int)(current_user()['id']??0)===701;
        $ok=$reached;
        echo $reached?"PASS trusted registration -> OTP -> active -> real login -> protected student dashboard\n":"FAIL protected dashboard\n";
    }
    echo ($ok?'PASS':'FAIL')." real login controller: $case\n";
    if(!$ok)exit(1);
});
require __DIR__.'/../auth/login.php';
