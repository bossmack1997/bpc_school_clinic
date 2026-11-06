<?php
/** Included by --doctor-clinical --evaluator; synthetic schema-only fixture. */
if(!isset($database)||!preg_match('/^bpc_mis_test_[a-f0-9]{10}$/D',$database)||$db->query('SELECT DATABASE()')->fetchColumn()!==$database)throw new RuntimeException('Disposable fixture required.');
(function()use($db,$c,$payload,$queueTicket,$nurse,$nurseToken,$token,$invitee,$clinicalUsers,$queueClient,$queueStudent,$studentId,$nursingId,$base):void {
require_once __DIR__.'/../includes/queue_consultation.php';
foreach(['update_consultation','complete_visit'] as $legacy)checkMis(misRequest($nurse,'/api/walkin.php',['action'=>$legacy,'id'=>1,'csrf_token'=>$nurseToken])['status']===409,'Legacy duplicate writer directs Nurse to unified queue: '.$legacy);
foreach(['queue_list','get_patient_medical_summary','call_next','cancel_ticket'] as $operation) {
    checkMis(misRequest($c,'/api/queue.php',['action'=>$operation,'csrf_token'=>$payload['csrf_token'],'ticket_id'=>$queueTicket],true)['status']===403,'MIS denied clinical queue operation '.$operation);
}
$read=json_decode(misRequest($nurse,'/api/queue.php?action=get_patient_medical_summary&ticket_id='.$queueTicket)['body'],true);
$revision=$read['data']['draft_version'];
$draft=['action'=>'save_consultation_draft','ticket_id'=>$queueTicket,'csrf_token'=>$nurseToken,'draft_version'=>$revision,'chief_complaint'=>'Evaluator intake','assessment'=>'Observed symptoms','clinical_priority'=>'urgent'];
$missing=$draft;unset($missing['draft_version']);
$rejected=json_decode(misRequest($nurse,'/api/queue.php',$missing)['body'],true);
checkMis(!($rejected['success']??true),'Missing Nurse revision cannot bypass concurrency checks');
$rejected=json_decode(misRequest($nurse,'/api/queue.php',$draft)['body'],true);
checkMis(!($rejected['success']??true)&&str_contains($rejected['message'],'Explain why'),'Priority change requires a clinical reason');
checkMis($db->query('SELECT priority FROM queue_numbers WHERE id='.$queueTicket)->fetchColumn()==='normal','Rejected triage leaves queue priority unchanged');
$draft['priority_reason']='Observed worsening symptoms; prompt clinical review';
$saved=json_decode(misRequest($nurse,'/api/queue.php',$draft)['body'],true);
checkMis($saved['success']??false,'Nurse triage saves with current revision and reason');
$triageVersion=$saved['data']['draft_version'];
checkMis($db->query('SELECT priority FROM queue_numbers WHERE id='.$queueTicket)->fetchColumn()==='urgent'&&$db->query('SELECT priority_level FROM walk_in_visits WHERE queue_id='.$queueTicket)->fetchColumn()==='urgent','Nurse triage synchronizes operational queue and intake');
$stale=json_decode(misRequest($nurse,'/api/queue.php',$draft)['body'],true);
checkMis(!($stale['success']??true)&&str_contains($stale['message'],'another window'),'Second editor with stale revision cannot overwrite saved intake');
$workers=[];
try {
    foreach(['Editor A','Editor B'] as $note){
        $pipes=[];$process=proc_open([PHP_BINARY,'-d','session.save_path='.__DIR__.'/../scratch',__DIR__.'/nursing_draft_worker.php',(string)$queueTicket,(string)$clinicalUsers['nurse'],$triageVersion,$note],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        $workers[]=['process'=>$process,'pipes'=>$pipes];checkMis(trim(fgets($pipes[1]))==='READY','Independent nursing draft editor ready');
    }
    foreach($workers as $worker){fwrite($worker['pipes'][0],"GO\n");fclose($worker['pipes'][0]);}
    $outcomes=[];
    foreach($workers as &$worker){$outcomes[]=trim(stream_get_contents($worker['pipes'][1]));fclose($worker['pipes'][1]);$stderr=stream_get_contents($worker['pipes'][2]);fclose($worker['pipes'][2]);$exit=proc_close($worker['process']);$worker['process']=null;checkMis($exit===0&&$stderr==='','Concurrent nursing editor completes without database errors');}unset($worker);
    sort($outcomes);checkMis($outcomes===['CONFLICT','SAVED'],'Two simultaneous nursing saves yield one saved draft and one conflict');
}finally{foreach($workers as $worker)if(is_resource($worker['process'])){proc_terminate($worker['process']);proc_close($worker['process']);}}
$row=$db->query('SELECT * FROM walk_in_visits WHERE queue_id='.$queueTicket)->fetch();$changed=$row;$changed['priority_level']='emergency';
checkMis(queue_consultation_version($row)!==queue_consultation_version($changed),'Nurse revision includes clinical priority');
$audit=$db->query("SELECT * FROM activity_logs WHERE action='Clinical priority changed' AND details LIKE 'Queue #".$queueTicket.";%' ORDER BY id DESC LIMIT 1")->fetch();
checkMis((int)$audit['user_id']===$clinicalUsers['nurse']&&!empty($audit['created_at'])&&str_contains($audit['details'],$draft['priority_reason']),'Triage audit stores actor, time, old/new priority and reason');
$referral=json_decode(misRequest($nurse,'/api/physician_queue.php',['action'=>'refer','queue_id'=>$queueTicket,'priority'=>'urgent','reason'=>'Evaluator physician review','csrf_token'=>$nurseToken])['body'],true);
checkMis($referral['success']??false,'Saved nursing intake can be referred');$referralId=(int)$referral['id'];
$reassessment=['action'=>'reprioritize','handoff_id'=>$referralId,'record_version'=>0,'clinical_priority'=>'emergency','priority_reason'=>'Doctor observed deterioration','csrf_token'=>$token];
checkMis(misRequest($invitee,'/api/physician_queue.php',$reassessment)['status']===200,'Doctor can reassess referred priority');
checkMis($db->query('SELECT priority FROM physician_queue WHERE id='.$referralId)->fetchColumn()==='emergency'&&$db->query('SELECT priority FROM queue_numbers WHERE id='.$queueTicket)->fetchColumn()==='emergency'&&$db->query('SELECT priority_level FROM walk_in_visits WHERE queue_id='.$queueTicket)->fetchColumn()==='emergency','Doctor reassessment synchronizes all three sources');
checkMis(misRequest($invitee,'/api/physician_queue.php',$reassessment)['status']===409,'Stale Doctor reassessment returns conflict');
$ordered=json_decode(misRequest($invitee,'/api/physician_queue.php?action=list')['body'],true);
checkMis($ordered['data'][0]['priority']==='emergency','Emergency referral precedes ordinary referrals');
$ordinary=$db->query("SELECT id,record_version FROM physician_queue WHERE status='awaiting' AND priority<>'emergency' LIMIT 1")->fetch();
checkMis($ordinary&&misRequest($invitee,'/api/physician_queue.php',['action'=>'start','handoff_id'=>$ordinary['id'],'record_version'=>$ordinary['record_version'],'csrf_token'=>$token])['status']===409,'Doctor cannot claim ordinary referral ahead of waiting emergency');
$db->prepare("INSERT INTO queue_numbers(queue_number,student_id,patient_type,priority,status) VALUES ('EVAL-EMERGENCY',?,'student','emergency','waiting')")->execute([$studentId]);$emergencyTicket=(int)$db->lastInsertId();
$db->prepare("INSERT INTO queue_numbers(queue_number,student_id,patient_type,priority,status) VALUES ('EVAL-ROUTINE',?,'student','normal','waiting')")->execute([$queueStudent]);$routineTicket=(int)$db->lastInsertId();
foreach(['call_next','start_serving'] as $operation){$blocked=json_decode(misRequest($nurse,'/api/queue.php',['action'=>$operation,'ticket_id'=>$routineTicket,'csrf_token'=>$nurseToken])['body'],true);checkMis(!($blocked['success']??true)&&str_contains($blocked['message'],'emergency'),'Manual '.$operation.' cannot place ordinary care ahead of waiting emergency');}
$next=json_decode(misRequest($nurse,'/api/queue.php',['action'=>'call_next','csrf_token'=>$nurseToken])['body'],true);
checkMis(($next['success']??false)&&(int)$next['data']['ticket_id']===$emergencyTicket,'Automatic next patient calls waiting emergency first');
$db->prepare("UPDATE queue_numbers SET status='cancelled' WHERE id IN (?,?)")->execute([$emergencyTicket,$routineTicket]);
$draft['draft_version']=$triageVersion;$draft['clinical_priority']='normal';
checkMis(!(json_decode(misRequest($nurse,'/api/queue.php',$draft)['body'],true)['success']??true),'Doctor priority change invalidates open nursing draft');
$teacher=misClient();$login=misRequest($teacher,'/auth/login.php');misRequest($teacher,'/auth/login.php',['csrf_token'=>misField($login,'csrf_token'),'identifier'=>'clinical_teacher','password'=>'FixturePass!42']);
$teacherPage=misRequest($teacher,'/pages/adviser/queue.php');$teacherToken=misField($teacherPage,'csrf_token');
checkMis(misRequest($teacher,'/api/physician_queue.php',array_replace($reassessment,['csrf_token'=>$teacherToken]))['status']===403,'Teacher cannot change clinical priority');
$db->exec("INSERT INTO sections(name,course,year_level) VALUES ('EVALUATOR-A','BSIS','1')");$sectionId=(int)$db->lastInsertId();
$db->prepare("INSERT INTO advisory_assignments(teacher_id,section_id,status) VALUES (?,?,'active')")->execute([$clinicalUsers['teacher'],$sectionId]);
$db->prepare("UPDATE students SET section='EVALUATOR-A',course='BSIS',year_level='1' WHERE id=?")->execute([$studentId]);
$sos=misRequest($teacher,'/api/emergencies.php',['action'=>'report','csrf_token'=>$teacherToken,'student_id'=>$studentId,'emergency_type'=>'Other','location'=>'Evaluator classroom','description'=>'Synthetic request for human assessment','priority'=>'routine']);
$sosBody=json_decode($sos['body'],true);
checkMis(($sosBody['success']??false)&&$db->query('SELECT priority FROM sos_emergencies WHERE id='.(int)($sosBody['emergency_id']??0))->fetchColumn()==='urgent','Teacher cannot downgrade emergency response using posted priority');
// Close only this synthetic incident before subsequent browser layout checks.
$db->prepare("UPDATE sos_emergencies SET status='resolved' WHERE id=?")->execute([$sosBody['emergency_id']]);
$ownRecords=json_decode(misRequest($queueClient,'/api/medical_records.php?action=list&student_id='.$studentId)['body'],true);
checkMis(($ownRecords['success']??false)&&($ownRecords['data']??[])===[],'Student medical-record list ignores another patient selector');
checkMis(misRequest($queueClient,'/api/medical_records.php?action=detail&visit_id='.$nursingId)['status']===403,'Student cannot open another patient encounter detail');
$mismatch=misRequest($nurse,'/api/medical_records.php',['action'=>'upload','csrf_token'=>$nurseToken,'student_id'=>$queueStudent,'consultation_id'=>$nursingId]);
checkMis($mismatch['status']===409&&str_contains($mismatch['body'],'does not belong'),'Upload rejects a consultation linked to a different patient before storing a file');
$db->prepare('UPDATE consultations SET vital_signs=? WHERE id=?')->execute([json_encode(['temperature'=>36.4]),$nursingId]);
$db->prepare("INSERT INTO walk_in_visits(student_id,visit_number,visit_date,arrival_time,temperature,status) VALUES (?,'EVAL-UNRELATED',CURDATE(),CURTIME(),40.5,'completed')")->execute([$studentId]);
$detailResponse=misRequest($nurse,'/api/medical_records.php?action=detail&visit_id='.$nursingId);
file_put_contents(__DIR__.'/../scratch/evaluator-detail-response.json',$detailResponse['body']);
$detail=json_decode($detailResponse['body'],true);
checkMis(($detail['data']['vitals']['temperature']??null)===36.4&&$detail['data']['medicines']===[],'Encounter detail uses its stored vitals and linked medicines, never the latest unrelated visit');
$uploadFixture=tempnam(__DIR__.'/../scratch','evaluator_attachment_');
if($uploadFixture===false)throw new RuntimeException('Cannot create synthetic attachment fixture.');
file_put_contents($uploadFixture,"%PDF-1.4\nSynthetic evaluator attachment\n%%EOF");
$uploadedPath=null;
try {
    $upload=function()use($nurse,$base,$nurseToken,$studentId,$nursingId,$uploadFixture){
        curl_setopt_array($nurse,[CURLOPT_URL=>$base.'/api/medical_records.php',CURLOPT_POST=>true,CURLOPT_HTTPHEADER=>[],CURLOPT_POSTFIELDS=>['action'=>'upload','csrf_token'=>$nurseToken,'student_id'=>$studentId,'consultation_id'=>$nursingId,'file'=>new CURLFile($uploadFixture,'application/pdf','fixture.pdf')]]);
        $body=curl_exec($nurse);if($body===false)throw new RuntimeException('Fixture upload request failed');return ['status'=>curl_getinfo($nurse,CURLINFO_RESPONSE_CODE),'body'=>json_decode($body,true)];
    };
    $uploaded=$upload();checkMis($uploaded['status']===200&&($uploaded['body']['success']??false),'Authorized attachment upload succeeds');
    $stored=$db->query("SELECT * FROM medical_records WHERE consultation_id=".$nursingId." ORDER BY id DESC LIMIT 1")->fetch();$uploadedPath=__DIR__.'/../'.$stored['file_path'];
    checkMis((int)$stored['student_id']===$studentId&&is_file($uploadedPath),'Attachment persists against the selected consultation and patient');
    checkMis(misRequest($queueClient,'/api/medical_records.php?action=file&id='.$stored['id'])['status']===403,'Student cannot download another patient attachment');
    $ownerClient=misClient();$login=misRequest($ownerClient,'/auth/login.php');misRequest($ownerClient,'/auth/login.php',['csrf_token'=>misField($login,'csrf_token'),'identifier'=>'clinical_student','password'=>'FixturePass!42']);
    $download=misRequest($ownerClient,'/api/medical_records.php?action=file&id='.$stored['id']);
    checkMis($download['status']===200&&$download['body']===file_get_contents($uploadFixture),'Patient can download their own stored attachment');
    $fileCount=count(glob(__DIR__.'/../uploads/medical_records/record_'.$studentId.'_*'));$recordCount=(int)$db->query('SELECT COUNT(*) FROM medical_records')->fetchColumn();
    $db->exec('RENAME TABLE activity_logs TO evaluator_unavailable_activity');
    try {$failedUpload=$upload();}finally{$db->exec('RENAME TABLE evaluator_unavailable_activity TO activity_logs');}
    checkMis($failedUpload['status']===503&&(int)$db->query('SELECT COUNT(*) FROM medical_records')->fetchColumn()===$recordCount&&count(glob(__DIR__.'/../uploads/medical_records/record_'.$studentId.'_*'))===$fileCount,'Audit storage failure rolls back attachment row and removes newly stored file');
}finally{if($uploadedPath&&is_file($uploadedPath))unlink($uploadedPath);if(is_file($uploadFixture))unlink($uploadFixture);}
$db->prepare("INSERT INTO vaccinations(student_id,vaccine_name,dose_number,date_administered,administered_by) VALUES (?,'Evaluator private vaccination','1',CURDATE(),'Fixture')")->execute([$studentId]);$vaccination=(int)$db->lastInsertId();
foreach([$teacher,$c] as $outsider)checkMis(misRequest($outsider,'/api/vaccinations.php?action=list&student_id='.$studentId)['status']===403,'Unassigned Teacher and MIS cannot read patient vaccination history');
$vaccinations=json_decode(misRequest($queueClient,'/api/vaccinations.php?action=list&student_id='.$studentId)['body'],true);
checkMis(($vaccinations['success']??false)&&$vaccinations['data']===[],'Student vaccination history ignores other patient IDs');
checkMis(misRequest($nurse,'/api/vaccinations.php',['action'=>'delete','id'=>$vaccination,'csrf_token'=>$nurseToken])['status']===409&&(int)$db->query('SELECT COUNT(*) FROM vaccinations WHERE id='.$vaccination)->fetchColumn()===1,'Vaccination history cannot be silently erased');
$db->prepare("UPDATE nurses SET clinical_authorization_status='revoked' WHERE user_id=?")->execute([$clinicalUsers['nurse']]);
try {
    foreach(['/api/queue.php?action=queue_list','/api/medical_records.php?action=list','/api/ai_assistant.php?action=history'] as $path)checkMis(misRequest($nurse,$path,null,true)['status']===403,'Revoked Nurse denied without signing out: '.$path);
} finally {$db->prepare("UPDATE nurses SET clinical_authorization_status='authorized' WHERE user_id=?")->execute([$clinicalUsers['nurse']]);}
})();
