<?php
if(!isset($database)||!preg_match('/^bpc_mis_test_[a-f0-9]{10}$/D',$database)||$db->query('SELECT DATABASE()')->fetchColumn()!==$database)throw new RuntimeException('Disposable fixture required.');
require_once __DIR__.'/../includes/clinic_policy_service.php';require_once __DIR__.'/../includes/clinic_availability.php';
$policyService=new ClinicPolicyService($db);
$queuePolicy=['clinic_open_time'=>'00:00','clinic_close_time'=>'23:59','clinic_operating_days'=>'1,2,3,4,5,6,7','clinic_manual_status'=>'scheduled','clinic_closed_dates'=>'','clinic_queue_capacity'=>'1000'];
$queueNurse=$db->query('SELECT * FROM users WHERE id='.$clinicalUsers['nurse'])->fetch();$policyService->save($queueNurse,$queuePolicy);
$denied=false;try{$policyService->save($doctorUser,$queuePolicy);}catch(RuntimeException $e){$denied=true;}checkMis($denied,'Doctor cannot mutate operational availability through Nurse service');
$db->prepare("INSERT INTO users(username,email,password_hash,first_name,last_name,role,status,email_verified) VALUES ('queue_patient','queue_patient@example.invalid',?,'Queue','Patient','student','active',1)")->execute([password_hash('FixturePass!42',PASSWORD_DEFAULT)]);$queuePatient=(int)$db->lastInsertId();
$db->prepare("INSERT INTO students(user_id,student_number) VALUES (?,'QUEUE-POLICY-01')")->execute([$queuePatient]);$queueStudent=(int)$db->lastInsertId();
$queueClient=misClient();$login=misRequest($queueClient,'/auth/login.php');misRequest($queueClient,'/auth/login.php',['csrf_token'=>misField($login,'csrf_token'),'identifier'=>'queue_patient','password'=>'FixturePass!42']);
checkMis(misRequest(misClient(),'/api/queue.php?action=get_status',null,true)['status']===401,'Authenticated queue status is not exposed through anonymous polling');
$queuePage=misRequest($queueClient,'/pages/student/queue.php');$queueToken=misField($queuePage,'csrf_token');
$request=['action'=>'checkin','csrf_token'=>$queueToken,'priority'=>'emergency','chief_complaint'=>'Reported symptoms','student_id'=>$studentId];
$beforeQueue=(int)$db->query('SELECT COUNT(*) FROM queue_numbers')->fetchColumn();
$policyService->save($queueNurse,array_replace($queuePolicy,['clinic_manual_status'=>'closed']));
$closed=misRequest($queueClient,'/api/queue.php',$request);checkMis($closed['status']===409&&str_contains($closed['body'],'SOS')&&(int)$db->query('SELECT COUNT(*) FROM queue_numbers')->fetchColumn()===$beforeQueue,'Closed clinic rejects patient ticket and preserves SOS guidance');
$status=json_decode(misRequest($queueClient,'/api/queue.php?action=get_status')['body'],true);checkMis(($status['data']['availability']['is_open']??true)===false,'Polling reflects actual closed status');
$policyService->save($queueNurse,array_replace($queuePolicy,['clinic_closed_dates'=>date('Y-m-d')]));checkMis(misRequest($queueClient,'/api/queue.php',$request)['status']===409,'Holiday closure blocks ticket creation');
$policyService->save($queueNurse,array_replace($queuePolicy,['clinic_queue_capacity'=>'1']));checkMis(misRequest($queueClient,'/api/queue.php',$request)['status']===409,'Capacity blocks normal queue admission');
$policyService->save($queueNurse,$queuePolicy);
$preflightSql=str_replace('u962995739_bpc_clinic',$database,file_get_contents(__DIR__.'/../database/preflight_final_clinical_20261028_hostinger.sql'));
$preflightSql=preg_replace('/^\s*--[^\r\n]*/m','',$preflightSql);$beforeMetadata=(int)$db->query('SELECT COUNT(*) FROM queue_numbers')->fetchColumn();
foreach(array_filter(array_map('trim',explode(';',$preflightSql))) as $statement){$q=$db->query($statement);$q->fetchAll();$q->closeCursor();}
checkMis((int)$db->query('SELECT COUNT(*) FROM queue_numbers')->fetchColumn()===$beforeMetadata,'Hostinger preflight SQL executes read-only against the guarded fixture target');
$db->exec(file_get_contents(__DIR__.'/../database/migration_queue_policy_20261028.sql'));
$db->exec(file_get_contents(__DIR__.'/../database/migration_queue_policy_20261028.sql'));
checkMis($db->query("SELECT setting_value FROM system_settings WHERE setting_key='clinic_queue_capacity'")->fetchColumn()==='1000','Queue policy migration reruns without overwriting configured capacity');
$lockName='bpc_queue_admit_'.substr(hash('sha256',$database),0,32);$db->prepare('SELECT GET_LOCK(?,1)')->execute([$lockName]);
try {checkMis(misRequest($queueClient,'/api/queue.php',$request)['status']===409,'Contended admission lock fails safely without a ticket');}finally{$db->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]);}
$joined=misRequest($queueClient,'/api/queue.php',$request);$joinedBody=json_decode($joined['body'],true);checkMis($joinedBody['success']??false,'Open clinic admits authenticated patient');$queueTicket=(int)$joinedBody['data']['queue_id'];
$newTicket=$db->query('SELECT * FROM queue_numbers WHERE id='.$queueTicket)->fetch();checkMis((int)$newTicket['student_id']===$queueStudent&&$newTicket['priority']==='normal','Patient cannot impersonate another profile or assign confirmed emergency priority');
$intake=$db->query('SELECT chief_complaint FROM walk_in_visits WHERE queue_id='.$queueTicket)->fetchColumn();checkMis(str_contains($intake,'Patient urgency: emergency'),'Nurse intake retains patient-reported emergency warning');
$again=json_decode(misRequest($queueClient,'/api/queue.php',$request)['body'],true);checkMis(($again['data']['is_existing']??false)&&(int)$db->query('SELECT COUNT(*) FROM queue_numbers WHERE student_id='.$queueStudent)->fetchColumn()===1,'Duplicate active patient request reuses original ticket');
$hours=array_replace($queuePolicy,['clinic_open_time'=>'08:00','clinic_close_time'=>'17:00']);$policyService->save($queueNurse,$hours);
checkMis(!clinic_availability($db,new DateTimeImmutable('2026-10-28 07:59',new DateTimeZone('Asia/Manila')))['is_open']&&clinic_availability($db,new DateTimeImmutable('2026-10-28 08:00',new DateTimeZone('Asia/Manila')))['is_open']&&!clinic_availability($db,new DateTimeImmutable('2026-10-28 17:00',new DateTimeZone('Asia/Manila')))['is_open'],'Schedule uses Manila opening inclusive and closing exclusive boundaries');
$db->exec('RENAME TABLE system_settings TO fixture_unavailable_settings');
try{checkMis(!clinic_availability($db)['is_open'],'Settings database failure closes admission instead of assuming default availability');}finally{$db->exec('RENAME TABLE fixture_unavailable_settings TO system_settings');}
$policyService->save($queueNurse,$queuePolicy);
