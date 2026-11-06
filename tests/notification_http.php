<?php
/** Schema-only, loopback notification fixture. Never copies patient rows. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/../config/config.php';
if(APP_ENV!=='local'||!in_array(DB_HOST,['localhost','127.0.0.1','::1'],true))throw new RuntimeException('Local fixture required.');
set_exception_handler(function(Throwable $e){fwrite(STDERR,'FAIL '.$e->getMessage().PHP_EOL);exit(1);});
$admin=getDB();$name='bpc_notif_test_'.bin2hex(random_bytes(5));$server=null;
try{
 $admin->exec("CREATE DATABASE `$name`");
 foreach($admin->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetchAll(PDO::FETCH_NUM) as $row){$table=$row[0];if(!preg_match('/^[a-zA-Z0-9_]+$/D',$table))throw new RuntimeException('Invalid table');$admin->exec("CREATE TABLE `$name`.`$table` LIKE `".DB_NAME."`.`$table`");}
 $db=new PDO('mysql:host='.DB_HOST.';port='.DB_PORT.';dbname='.$name.';charset=utf8mb4',DB_USER,DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
 if(!$db->query("SHOW COLUMNS FROM nurses LIKE 'clinical_authorization_status'")->fetch())$db->exec("ALTER TABLE nurses ADD clinical_authorization_status VARCHAR(30) DEFAULT 'pending'");
 $ids=[];$notifications=[];
 foreach(['mis','doctor','nurse','student','teacher'] as $role){
  $db->prepare("INSERT INTO users(username,email,password_hash,first_name,last_name,role,admin_type,status,email_verified,self_service_only) VALUES (?,?,? ,?,'Fixture',?,?,'active',1,0)")->execute(['notif_'.$role,$role.'@example.invalid',password_hash('FixturePass!42',PASSWORD_DEFAULT),ucfirst($role),in_array($role,['mis','doctor'])?'admin':$role,$role==='mis'?'primary_admin':($role==='doctor'?'co_admin':null)]);$id=(int)$db->lastInsertId();$ids[$role]=$id;
  if(in_array($role,['mis','doctor']))$db->prepare('INSERT INTO admin_profiles(user_id,profile) VALUES (?,?)')->execute([$id,$role]);
  if($role==='nurse')$db->prepare("INSERT INTO nurses(user_id,employee_id,clinical_authorization_status) VALUES (?,'NOTIF-NURSE','authorized')")->execute([$id]);
  if($role==='student')$db->prepare("INSERT INTO students(user_id,student_number) VALUES (?,'NOTIF-STUDENT')")->execute([$id]);
  if($role==='teacher')$db->prepare("INSERT INTO teachers(user_id,employee_id,first_name,last_name) VALUES (?,'NOTIF-TEACHER','Teacher','Fixture')")->execute([$id]);
  $db->prepare("INSERT INTO notifications(user_id,type,title,message,is_read,priority) VALUES (?,'system','Fixture notification','Synthetic notification details',0,'medium')")->execute([$id]);$notifications[$role]=(int)$db->lastInsertId();
 }
 $db->prepare("INSERT INTO sos_emergencies(user_id,emergency_type,description,status,priority,source,reporter_role) VALUES (?,'Other','Synthetic resolved incident','resolved','urgent','sos','student')")->execute([$ids['student']]);$sos=(int)$db->lastInsertId();
 foreach(['doctor','nurse'] as $role)$db->prepare("INSERT INTO notifications(user_id,type,title,message,is_read,priority,sos_emergency_id) VALUES (?,'emergency','Resolved fixture SOS','Synthetic emergency notification',0,'urgent',?)")->execute([$ids[$role],$sos]);
 $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);$port=(int)substr(strrchr(stream_socket_get_name($socket,false),':'),1);fclose($socket);$base='http://127.0.0.1:'.$port;
 foreach(['DB_NAME'=>$name,'APP_URL'=>$base,'APP_ENV'=>'local','APP_ENV_FILE'=>__DIR__.'/nonexistent-test.env','MAIL_HOST'=>'127.0.0.1','MAIL_PORT'=>'1'] as $key=>$value)putenv($key.'='.$value);
 $server=proc_open([PHP_BINARY,'-d','session.save_path='.__DIR__.'/../scratch','-S','127.0.0.1:'.$port,'-t',dirname(__DIR__)],[0=>['pipe','r'],1=>['file','NUL','a'],2=>['file',__DIR__.'/../scratch/notification-server.log','a']],$pipes,dirname(__DIR__),null,['bypass_shell'=>true]);fclose($pipes[0]);
 for($i=0;$i<40;$i++){if($socket=@fsockopen('127.0.0.1',$port,$errno,$error,.1)){fclose($socket);break;}usleep(100000);}
 putenv('NOTIFICATION_TEST_BASE='.$base);putenv('NOTIFICATION_TEST_IDS='.json_encode($notifications));putenv('NOTIFICATION_BASELINE='.(in_array('--baseline',$argv,true)?'1':'0'));
 passthru('node '.escapeshellarg(__DIR__.'/notification_browser.cjs'),$exit);
 if($exit!==0)throw new RuntimeException('Notification browser regression failed.');
 foreach($notifications as $role=>$id){$value=$db->query('SELECT is_read FROM notifications WHERE id='.$id)->fetchColumn();if($value!==false&&(int)$value!==1)throw new RuntimeException($role.' read status not persisted');}
 echo "PASS All role read states persisted in MySQL.\n";
}finally{if(is_resource($server)){proc_terminate($server);proc_close($server);}if(preg_match('/^bpc_notif_test_[a-f0-9]{10}$/D',$name))$admin->exec("DROP DATABASE IF EXISTS `$name`");}
