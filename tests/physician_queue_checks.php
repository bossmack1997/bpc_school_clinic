<?php
/** Included only in the guarded disposable Doctor fixture. */
if(!isset($database)||!preg_match('/^bpc_mis_test_[a-f0-9]{10}$/D',$database)||$db->query('SELECT DATABASE()')->fetchColumn()!==$database)throw new RuntimeException('Disposable fixture required.');
$nursePage=misRequest($nurse,'/pages/nurse/queue.php');$nurseToken=misField($nursePage,'csrf_token');
file_put_contents(__DIR__.'/../scratch/physician-nurse-queue.html',$nursePage['body']);
checkMis(str_contains($nursePage['body'],'Refer a patient for physician assessment'),'Nurse queue exposes physician handoff');
checkMis(!has_permission('consultations_manage',$db->query('SELECT * FROM users WHERE id='.$clinicalUsers['nurse'])->fetch(),$db),'Nurse permission profile excludes physician consultation authority');
checkMis(!str_contains($nursePage['body'],'name="diagnosis"')&&!str_contains($nursePage['body'],'name="digital_signature"')&&str_contains($nursePage['body'],'name="nursing_interventions"'),'Nursing workspace offers observations and interventions without physician inputs');
foreach (['/api/queue.php','/api/walkin.php','/api/dispensing.php','/api/students_api.php'] as $nurseApiPath) foreach (['diagnosis','prescription','digital_signature','treatment'] as $field) {
 checkMis(misRequest($nurse,$nurseApiPath,['action'=>'save_consultation_draft','csrf_token'=>$nurseToken,$field=>'Forbidden physician decision'])['status']===403,'Nurse cannot submit '.$field.' through '.$nurseApiPath);
}
checkMis(misRequest($c,'/api/physician_queue.php?action=list')['status']===403,'MIS cannot enumerate physician queue');
checkMis(misRequest($nurse,'/api/physician_queue.php?action=list')['status']===403,'Nurse cannot read physician-only assessment queue');
$handoffs=[];
foreach(['student','teacher'] as $type){
 $db->prepare('INSERT INTO queue_numbers(queue_number,student_id,teacher_id,patient_type,priority,status) VALUES (?,?,?,?,\'normal\',\'serving\')')->execute(['PHYS-'.$type,$type==='student'?$studentId:null,$type==='teacher'?$teacherId:null,$type]);$ticket=(int)$db->lastInsertId();
 $referral=['action'=>'refer','queue_id'=>$ticket,'reason'=>'Recorded nursing referral','priority'=>'urgent','csrf_token'=>$nurseToken];
 checkMis(misRequest($nurse,'/api/physician_queue.php',$referral)['status']===409,'Referral requires saved nursing assessment for '.$type);
 $db->prepare("INSERT INTO walk_in_visits(queue_id,student_id,is_temp_visitor,temp_full_name,visit_number,visit_date,arrival_time,chief_complaint,assessment,temperature,status) VALUES (?,?,?,?,?,CURDATE(),CURTIME(),'Saved intake','Nursing observation',37.2,'in_consultation')")->execute([$ticket,$type==='student'?$studentId:null,$type==='teacher'?1:0,'Clinical '.ucfirst($type),'PHYS-VISIT-'.$type]);
 checkMis(misRequest($nurse,'/api/physician_queue.php',array_replace($referral,['csrf_token'=>'invalid']))['status']===403,'Handoff rejects invalid CSRF');
 checkMis(misRequest($invitee,'/api/physician_queue.php',array_replace($referral,['csrf_token'=>$token]))['status']===403,'Doctor cannot impersonate referring Nurse');
 $r=misRequest($nurse,'/api/physician_queue.php',$referral);$body=json_decode($r['body'],true);
 checkMis($r['status']===200&&($body['success']??false),'Authorized Nurse refers '.$type);$handoffs[$type]=(int)$body['id'];
 checkMis(misRequest($nurse,'/api/physician_queue.php',$referral)['status']===409,'Duplicate handoff prevented for '.$type);
}
$queuePage=misRequest($invitee,'/pages/doctor/queue.php');checkMis($queuePage['status']===200&&str_contains($queuePage['body'],'Nursing observation')&&str_contains($queuePage['body'],'37.2'),'Doctor queue displays actual intake and vital signs');
$preservedHandoffs=$db->query('SELECT * FROM physician_queue ORDER BY id')->fetchAll();
physicianSql($db,'migration_physician_queue_hostinger_20261030.sql');
checkMis($db->query('SELECT * FROM physician_queue ORDER BY id')->fetchAll()===$preservedHandoffs,'Rerunning Hostinger migration preserves populated referrals and versions');
$db->prepare("UPDATE physician_queue SET priority='emergency' WHERE id=?")->execute([$handoffs['teacher']]);
$ordered=json_decode(misRequest($invitee,'/api/physician_queue.php?action=list',null,true)['body'],true);
checkMis((int)$ordered['data'][0]['id']===$handoffs['teacher']&&$ordered['data'][0]['priority']==='emergency'&&$ordered['data'][0]['status']==='awaiting','Queue HTTP API orders emergency before urgent and returns actual referral status');
$db->prepare("UPDATE physician_queue SET priority='urgent' WHERE id=?")->execute([$handoffs['teacher']]);
$center=(new DoctorClinicalService($db,$doctorUser))->commandCenter();checkMis($center['awaiting_doctor']===2&&$center['urgent_cases']===2&&$center['patients_seen_today']===1,'Doctor cards count unique physician patients and explicit urgent handoffs');
$handoff=$handoffs['teacher'];$transition=['action'=>'start','handoff_id'=>$handoff,'record_version'=>0,'csrf_token'=>$token];
checkMis(misRequest($invitee,'/api/physician_queue.php?action=start&handoff_id='.$handoff.'&record_version=0')['status']===405,'Handoff mutation requires POST');
checkMis(misRequest($invitee,'/api/physician_queue.php',array_replace($transition,['csrf_token'=>'invalid']))['status']===403,'Doctor transition requires CSRF');
checkMis(misRequest($invitee,'/api/physician_queue.php',$transition)['status']===200,'Doctor claims referred Teacher assessment');
// Simulate ownership by another actor and prove completion cannot bypass it.
$db->prepare('UPDATE physician_queue SET assigned_doctor_id=? WHERE id=?')->execute([$actor,$handoff]);
checkMis(misRequest($invitee,'/api/physician_queue.php',array_replace($encounter,['action'=>'complete','handoff_id'=>$handoff,'record_version'=>1]))['status']===409,'Doctor cannot finalize an assessment assigned to another actor');
$db->prepare('UPDATE physician_queue SET assigned_doctor_id=? WHERE id=?')->execute([$doctor,$handoff]);
checkMis(misRequest($invitee,'/api/physician_queue.php',$transition)['status']===409,'Concurrent or duplicate assessment claim rejected');
$completion=array_replace($encounter,['action'=>'complete','handoff_id'=>$handoff,'record_version'=>1,'patient_user_id'=>$clinicalUsers['student']]);
checkMis(misRequest($invitee,'/api/physician_queue.php',array_replace($completion,['diagnosis'=>'']))['status']===400,'Finalization requires physician findings');
checkMis(misRequest($invitee,'/api/physician_queue.php',array_replace($completion,['follow_up_date'=>'2026-02-30']))['status']===400,'Invalid completion date rolls back clinical transaction');
checkMis($db->query('SELECT status FROM physician_queue WHERE id='.$handoff)->fetchColumn()==='in_progress','Failed finalization leaves handoff active');
$countBefore=(int)$db->query('SELECT COUNT(*) FROM consultations')->fetchColumn();
checkMis(misRequest($invitee,'/api/physician_queue.php',$completion)['status']===200,'Assigned Doctor completes physician encounter atomically');
$completed=$db->query('SELECT p.*,c.teacher_id,c.student_id,c.clinician_user_id FROM physician_queue p JOIN consultations c ON c.id=p.consultation_id WHERE p.id='.$handoff)->fetch();
checkMis($completed['status']==='completed'&&(int)$completed['teacher_id']===$teacherId&&$completed['student_id']===null&&(int)$completed['clinician_user_id']===$doctor,'Completion binds server-selected Teacher and physician author');
checkMis($db->query('SELECT status FROM queue_numbers WHERE id='.(int)$completed['queue_id'])->fetchColumn()==='serving','Physician completion preserves Nurse operational ticket ownership and follow-up');
$vitalSnapshot=json_decode($db->query('SELECT vital_signs FROM consultations WHERE id='.(int)$completed['consultation_id'])->fetchColumn(),true);
checkMis((float)$vitalSnapshot['temperature']===37.2,'Physician encounter retains nurse-recorded vital signs');
checkMis(misRequest($invitee,'/api/physician_queue.php',array_replace($completion,['record_version'=>2]))['status']===409&&(int)$db->query('SELECT COUNT(*) FROM consultations')->fetchColumn()===$countBefore+1,'Duplicate finalization creates no duplicate record');
checkMis($db->query('SELECT remarks FROM consultations WHERE id='.$nursingId)->fetchColumn()==='Original nursing note','Handoff completion preserves historical nursing documentation');
checkMis((int)$db->query("SELECT COUNT(*) FROM activity_logs WHERE action IN ('Patient referred for physician assessment','Physician assessment started','Physician assessment completed')")->fetchColumn()===4,'All successful handoff transitions leave content-free audit events');
$micaQueue=(new MICAWorkflowService($db,$doctorUser))->handleNaturalLanguageQuery('Show pending Doctor consultations');
checkMis(str_contains($micaQueue['reply'],'physician handoffs awaiting a Doctor: 1')&&str_contains($micaQueue['reply'],'MICA cannot diagnose'),'MICA Doctor queue summary uses trusted handoff counts and requires explicit clinical action');
checkMis(misRequest($nurse,'/api/certificates.php',['action'=>'issue','csrf_token'=>$nurseToken,'student_id'=>$studentId,'certificate_type'=>'Medical Certificate'])['status']===403,'Nurse cannot issue physician medical clearance');
$nursingTicket=(int)$db->query('SELECT queue_id FROM physician_queue WHERE id='.$handoffs['student'])->fetchColumn();
$nursingDraft=['action'=>'save_consultation_draft','ticket_id'=>$nursingTicket,'csrf_token'=>$nurseToken,'chief_complaint'=>'Nursing complaint','assessment'=>'Nursing observation only','nursing_interventions'=>'Recorded nursing intervention','recommendation'=>'Return for review'];
$r=misRequest($nurse,'/api/queue.php',$nursingDraft);checkMis(json_decode($r['body'],true)['success']??false,'Nurse saves legitimate observations and interventions');
$nursingSaved=$db->query('SELECT diagnosis,treatment,digital_signature FROM walk_in_visits WHERE queue_id='.$nursingTicket)->fetch();
checkMis(empty($nursingSaved['diagnosis'])&&empty($nursingSaved['digital_signature'])&&$nursingSaved['treatment']==='Recorded nursing intervention','Nursing draft creates no physician diagnosis or signature');
$r=misRequest($nurse,'/api/queue.php',array_replace($nursingDraft,['action'=>'complete_consultation_full']));$nursingCompleted=json_decode($r['body'],true);
checkMis($nursingCompleted['success']??false,'Nurse completes nursing care without independent diagnosis');
$record=$db->query('SELECT diagnosis,treatment,clinician_user_id FROM consultations WHERE id='.(int)$nursingCompleted['data']['consultation_id'])->fetch();
checkMis(empty($record['diagnosis'])&&empty($record['clinician_user_id'])&&$record['treatment']==='Recorded nursing intervention','Completed nursing record preserves clinical scope');
