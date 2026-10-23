<?php
/** Synthetic HTTP suite; schema copies only, no production records or outbound mail. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
ini_set('session.save_path',sys_get_temp_dir());require __DIR__.'/../config/config.php';
require __DIR__.'/../includes/doctor_invitation_service.php';
set_exception_handler(function(Throwable $e){fwrite(STDERR,'FAIL MIS HTTP: '.$e->getMessage().PHP_EOL);exit(1);});
if(APP_ENV!=='local'||!in_array(DB_HOST,['localhost','127.0.0.1','::1'],true))throw new RuntimeException('Loopback local database required.');
$admin=getDB();$database='bpc_mis_test_'.bin2hex(random_bytes(5));$server=null;$checks=0;
function checkMis($ok,$label){global $checks;if(!$ok)throw new RuntimeException($label);echo 'PASS '.$label.PHP_EOL;$checks++;}
function misClient(){$c=curl_init();curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEFILE=>'',CURLOPT_TIMEOUT=>15]);return $c;}
function misRequest($c,$path,$post=null,$ajax=false){global $base;$headers=[];curl_setopt_array($c,[CURLOPT_URL=>$base.$path,CURLOPT_POST=>$post!==null,CURLOPT_HTTPHEADER=>$ajax?['X-Requested-With: XMLHttpRequest','Accept: application/json']:[],CURLOPT_HEADERFUNCTION=>function($c,$s)use(&$headers){if(str_contains($s,':')){[$k,$v]=explode(':',$s,2);$headers[strtolower($k)]=trim($v);}return strlen($s);}]);if($post!==null)curl_setopt($c,CURLOPT_POSTFIELDS,http_build_query($post));$body=curl_exec($c);if($body===false)throw new RuntimeException('HTTP request failed');return ['status'=>curl_getinfo($c,CURLINFO_RESPONSE_CODE),'body'=>$body,'headers'=>$headers];}
function misField($r,$name){if(!preg_match('/name="'.preg_quote($name,'/').'" value="([^"]+)"/',$r['body'],$m))throw new RuntimeException('Missing form field '.$name);return html_entity_decode($m[1]);}
try{
 $admin->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
 foreach($admin->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetchAll(PDO::FETCH_NUM)as$row){$t=$row[0];if(!preg_match('/^[a-zA-Z0-9_]+$/',$t))throw new RuntimeException('Invalid table');$admin->exec("CREATE TABLE `$database`.`$t` LIKE `".DB_NAME."`.`$t`");}
 $db=new PDO('mysql:host='.DB_HOST.';port='.DB_PORT.';dbname='.$database.';charset=utf8mb4',DB_USER,DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
 $db->prepare("INSERT INTO users(username,email,password_hash,first_name,last_name,role,admin_type,status,email_verified) VALUES ('mis_fixture','mis@example.invalid',?,'MIS','Fixture','admin','primary_admin','active',1)")->execute([password_hash('FixturePass!42',PASSWORD_DEFAULT)]);$actor=(int)$db->lastInsertId();
 for($i=0;$i<12;$i++)$db->prepare("INSERT INTO activity_logs(user_id,action,module,details) VALUES (?,'Login','auth','Synthetic browser fixture')")->execute([$actor]);
 $socket=stream_socket_server('tcp://127.0.0.1:0',$err,$description);$port=(int)substr(strrchr(stream_socket_get_name($socket,false),':'),1);fclose($socket);$base='http://127.0.0.1:'.$port;
 foreach(['DB_NAME'=>$database,'APP_URL'=>$base,'APP_ENV'=>'local','MAIL_HOST'=>'127.0.0.1','MAIL_PORT'=>'1','MAIL_USERNAME'=>'','MAIL_PASSWORD'=>'','MAIL_FROM_ADDRESS'=>'fixture@example.invalid','APP_ENV_FILE'=>__DIR__.'/nonexistent-test.env']as$k=>$v)putenv($k.'='.$v);
 // Discard access logs, including synthetic setup query strings.
 $server=proc_open([PHP_BINARY,'-d','session.save_path='.sys_get_temp_dir(),'-S','127.0.0.1:'.$port,'-t',dirname(__DIR__)],[0=>['pipe','r'],1=>['file','NUL','a'],2=>['file','NUL','a']],$pipes,dirname(__DIR__),null,['bypass_shell'=>true]);fclose($pipes[0]);
 for($i=0;$i<30;$i++){if($s=@fsockopen('127.0.0.1',$port,$err,$description,.1)){fclose($s);break;}usleep(100000);}
 $c=misClient();$r=misRequest($c,'/auth/login.php');$r=misRequest($c,'/auth/login.php',['csrf_token'=>misField($r,'csrf_token'),'identifier'=>'mis_fixture','password'=>'FixturePass!42']);checkMis($r['status']===302,'MIS fixture signs in');
 $path='/pages/admin/admin_accounts.php';$page=misRequest($c,$path);$payload=['action'=>'invite_doctor','csrf_token'=>misField($page,'csrf_token'),'request_nonce'=>misField($page,'request_nonce'),'username'=>'doctor_http','email'=>'doctor_http@example.invalid','first_name'=>'Doctor','last_name'=>'Fixture','official_designation'=>'School Physician','appointment_reference'=>'SYNTHETIC','verification_status'=>'verified'];
 checkMis(misRequest($c,$path,array_replace($payload,['csrf_token'=>'bad']),true)['status']===403,'Invitation rejects invalid CSRF');
 checkMis(misRequest($c,$path,array_replace($payload,['email'=>'bad']),true)['status']===422,'Invitation validates input over HTTP');
 $r=misRequest($c,$path,$payload,true);$json=json_decode($r['body'],true);checkMis($r['status']===202&&($json['success']??false)&&($json['delivery_status']??'')==='failed','Mail failure returns accurate pending result');
 checkMis(!str_contains($r['body'],'token=')&&!str_contains(misRequest($c,$path)['body'],'setup_account.php?token='),'MIS responses never expose setup links');
 checkMis(misRequest($c,$path,$payload,true)['status']===409,'Replayed request nonce cannot send twice');
 $page=misRequest($c,$path);$payload['request_nonce']=misField($page,'request_nonce');checkMis(misRequest($c,$path,$payload,true)['status']===422,'Duplicate identity cannot create a second invitation');
 $id=(int)$db->query("SELECT id FROM users WHERE username='doctor_http'")->fetchColumn();
 $r=misRequest($c,$path,['csrf_token'=>$payload['csrf_token'],'request_nonce'=>$payload['request_nonce'],'action'=>'resend_invitation','user_id'=>$id],true);checkMis($r['status']===202&&(int)$db->query("SELECT COUNT(*) FROM admin_invitations WHERE user_id=$id AND used_at IS NULL")->fetchColumn()===1,'Resend leaves exactly one usable invitation');
 $captured='';$service=new DoctorInvitationService($db,function($email,$name,$url)use(&$captured){parse_str(parse_url($url,PHP_URL_QUERY),$q);$captured=$q['token'];return true;});
 $result=$service->create($actor,array_replace($payload,['username'=>'accept_http','email'=>'accept@example.invalid']));$doctor=$result['user_id'];$invitee=misClient();$setup='/auth/setup_account.php?token='.$captured;$r=misRequest($invitee,$setup);
 checkMis($r['status']===200&&str_contains($r['body'],'setupAccountForm')&&str_contains($r['headers']['cache-control']??'','no-store'),'Valid appointee receives private setup form');
 $post=['csrf_token'=>misField($r,'csrf_token'),'token'=>$captured,'password'=>'FixturePass!42','confirm_password'=>'mismatch'];$r=misRequest($invitee,$setup,$post);checkMis(str_contains($r['body'],'Passwords do not match'),'Mismatched password cannot activate');
 $post['confirm_password']=$post['password'];$r=misRequest($invitee,$setup,$post);checkMis(str_contains($r['body'],'Your account is active.')&&$db->query("SELECT status FROM users WHERE id=$doctor")->fetchColumn()==='active','Appointee privately activates verified Doctor account');
 checkMis(str_contains(misRequest($invitee,$setup)['body'],'already been used'),'Consumed token cannot be replayed');
 $r=misRequest($invitee,'/auth/login.php');$r=misRequest($invitee,'/auth/login.php',['csrf_token'=>misField($r,'csrf_token'),'identifier'=>'accept_http','password'=>'FixturePass!42']);checkMis($r['status']===302&&str_contains($r['headers']['location']??'','doctor/dashboard.php'),'Doctor login redirects to clinical dashboard');
 checkMis(misRequest($invitee,'/pages/admin/users.php')['status']===403,'Doctor cannot enter People Management');
 foreach(['suspended','rejected','pending','expired']as$case){$service->create($actor,array_replace($payload,['username'=>'case_'.$case,'email'=>$case.'@example.invalid','verification_status'=>$case==='pending'?'pending':'verified']));$caseId=(int)$db->lastInsertId();$hash=hash('sha256',$captured);if(in_array($case,['suspended','rejected']))$db->prepare('UPDATE users u JOIN admin_invitations ai ON ai.user_id=u.id SET u.status=? WHERE ai.token_hash=?')->execute([$case,$hash]);if($case==='expired')$db->prepare('UPDATE admin_invitations SET expires_at=DATE_SUB(NOW(),INTERVAL 1 DAY) WHERE token_hash=?')->execute([$hash]);$r=misRequest(misClient(),'/auth/setup_account.php?token='.$captured);checkMis(!str_contains($r['body'],'id="setupAccountForm"'),"$case invitation cannot activate");}
 $r=misRequest($c,'/pages/admin/users.php?search=accept_http&role=admin');checkMis($r['status']===200&&str_contains($r['body'],'@accept_http'),'People search and role filter render HTML');
 $db->exec('ALTER TABLE users DROP COLUMN email_verified_at, DROP COLUMN self_service_only');checkMis(misRequest($c,'/pages/admin/users.php')['status']===200,'People directory supports older verification schema');
 $db->exec('RENAME TABLE student_qrs TO unavailable_student_qrs');$r=misRequest($c,'/pages/admin/users.php');checkMis($r['status']===503&&str_contains($r['body'],'role="alert"')&&!str_starts_with($r['body'],'{'),'Directory failure renders actionable HTML instead of JSON');$db->exec('RENAME TABLE unavailable_student_qrs TO student_qrs');
 if(in_array('--browser',$argv,true)){putenv('MIS_TEST_BASE='.$base);passthru('node '.escapeshellarg(__DIR__.'/mis_admin_browser.cjs'),$exit);checkMis($exit===0,'Actual Chrome viewport and modal checks');}
 echo "$checks MIS HTTP checks passed.\n";
}finally{if(is_resource($server)){proc_terminate($server);proc_close($server);}$admin->exec("DROP DATABASE IF EXISTS `$database`");}
