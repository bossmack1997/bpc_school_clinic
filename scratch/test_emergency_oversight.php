<?php
if(PHP_SAPI!=='cli')exit;
ini_set('session.save_path',__DIR__);
require __DIR__.'/../config/config.php';
set_exception_handler(function(Throwable $e){fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);});
require __DIR__.'/../includes/emergency_oversight.php';
$db=getDB();
foreach(['users','students','teachers','sos_emergencies'] as $table){$db->exec("CREATE TEMPORARY TABLE eo_test_$table LIKE $table");$db->exec("ALTER TABLE eo_test_$table RENAME TO $table");}
$db->exec("INSERT INTO users (id,username,email,password_hash,first_name,last_name,role,status) VALUES (901,'eo-student','eo-student@example.invalid','x','Alex','Student','student','active'),(902,'eo-teacher','eo-teacher@example.invalid','x','Jamie','Teacher','teacher','active'),(903,'eo-nurse','eo-nurse@example.invalid','x','Robin','Nurse','nurse','active'),(905,'eo-admin','eo-admin@example.invalid','x','Casey','Admin','admin','active')");
$db->exec("INSERT INTO students (id,user_id,student_number) VALUES (911,901,'BPC-TEST-123')");
$db->exec("INSERT INTO teachers (user_id,employee_id) VALUES (902,'EMP-TEST-99')");
$q=$db->prepare('INSERT INTO sos_emergencies (id,user_id,student_id,emergency_type,location,description,status,created_at,assigned_to,acknowledged_by,acknowledged_at,responded_by,responded_at,reporter_role) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
$statuses=['new','active','acknowledged','responding','responded','resolved','cancelled','false_alarm'];
foreach($statuses as $index=>$status){$id=$index+1;$teacher=$id===2||$id===3;$responder=in_array($status,['acknowledged','responding','responded'],true)?903:null;$q->execute([$id,$teacher?902:901,$id===2?null:911,$id===2?'Teacher assistance':'Emergency '.$id,'Room '.$id,'Test description',$status,'2026-10-'.str_pad((string)$id,2,'0',STR_PAD_LEFT).' 09:30:00',$responder,$responder,$responder?'2026-10-03 09:31:00':null,$status==='responding'?903:null,$status==='responding'?'2026-10-04 09:32:00':null,$teacher?'teacher':'student']);}
for($id=9;$id<=213;$id++)$q->execute([$id,901,911,'Archived report '.$id,'Library','Archived test','resolved','2025-01-01 10:00:00',null,null,null,null,null,'student']);
$service=new EmergencyOversight($db);
function checkEo($ok,$message){if(!$ok)throw new RuntimeException($message);echo 'PASS '.$message.PHP_EOL;}
function invalidEo($service,$input,$label){try{$service->listing($input,905);throw new RuntimeException('Validation missed: '.$label);}catch(InvalidArgumentException $e){checkEo(true,$label);}}
checkEo($service->summary()===['total'=>213,'active'=>3,'responding'=>2,'resolved'=>206,'history'=>208],'All-time database counts exceed former 200-record limit');
$active=$service->listing([],905);checkEo(array_column($active['reports'],'status')===['acknowledged','active','new'],'Active tab has only pending/acknowledged states, newest first');
$responding=$service->listing(['tab'=>'responding'],905);checkEo(array_column($responding['reports'],'status')===['responded','responding'],'Responding and legacy responded classification');
checkEo($responding['reports'][1]['responder_name']==='Robin Nurse' && $responding['reports'][1]['responded_at']!==null,'Responder name and response timestamp mapping');
$history=$service->listing(['tab'=>'history','per_page'=>10,'page'=>21],905);checkEo($history['pagination']['total']===208 && count($history['reports'])===8,'History pagination reaches records beyond 200');
checkEo($history['reports'][0]['id']===16,'Stable most-recent ordering breaks timestamp ties by ID');
foreach([10,25,50] as $size){$data=$service->listing(['tab'=>'history','per_page'=>$size],905);checkEo(count($data['reports'])===$size,'Page size '.$size);}
$data=$service->listing(['search'=>'EMP-TEST-99'],905);checkEo(count($data['reports'])===1&&$data['reports'][0]['patient_role']==='teacher'&&$data['reports'][0]['student_id']===null,'Teacher employee search and patient mapping');
$data=$service->listing(['search'=>'BPC-TEST-123'],905);checkEo(count($data['reports'])===2,'Student ID search');
$data=$service->listing(['search'=>'Jamie Teacher'],905);checkEo(count($data['reports'])===2,'Reporter and patient name search');
$data=$service->listing(['search'=>'Teacher assistance'],905);checkEo(count($data['reports'])===1,'Emergency type search');
$data=$service->listing(['reporter_role'=>'teacher'],905);checkEo(count($data['reports'])===2,'Actual reporter role filter includes teacher report for student');
$data=$service->listing(['tab'=>'history','status'=>'cancelled','date_from'=>'2026-10-07','date_to'=>'2026-10-07'],905);checkEo($data['pagination']['total']===1&&$data['reports'][0]['status']==='cancelled','Combined history status and inclusive date range');
$data=$service->listing(['search'=>"%' OR 1=1 --"],905);checkEo($data['pagination']['total']===0,'Search safely binds SQL/wildcard input');
checkEo($service->detail(2,905)['allowed_actions']===['accept','respond','resolve','cancel'],'Unassigned active actions match existing admin permissions');
checkEo($service->detail(3,905)['allowed_actions']===[],'Different assigned responder has read-only oversight');
checkEo(in_array('resolve',$service->detail(3,903)['allowed_actions'],true),'Assigned responder retains permitted actions');
foreach([6,7,8] as $id)checkEo($service->detail($id,905)['allowed_actions']===[],'Closed SOS '.$id.' cannot be accepted again');
checkEo($service->detail(3,905)['patient_name']==='Alex Student'&&$service->detail(3,905)['reporter_name']==='Jamie Teacher','Patient and reporter identities stay distinct');
checkEo($service->detail(999999,905)===null,'Missing detail record');
invalidEo($service,['tab'=>'invalid'],'Invalid tab rejected');invalidEo($service,['tab'=>'active','status'=>'resolved'],'Cross-tab status rejected');invalidEo($service,['date_from'=>'2026-02-30'],'Impossible date rejected');invalidEo($service,['date_from'=>'2026-10-10','date_to'=>'2026-10-01'],'Reversed range rejected');invalidEo($service,['per_page'=>500],'Unbounded page size rejected');
checkEo($service->listing(['tab'=>'history','page'=>9999],905)['pagination']['page']===21,'Out-of-range page clamps safely');
session_destroy();
echo "PASS read-only oversight queries against temporary MySQL fixtures; existing data unchanged.\n";
