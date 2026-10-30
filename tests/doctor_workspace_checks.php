<?php
if(!isset($database)||!preg_match('/^bpc_mis_test_[a-f0-9]{10}$/D',$database)||$db->query('SELECT DATABASE()')->fetchColumn()!==$database)throw new RuntimeException('Disposable workspace fixture required.');
$encounterPage=misRequest($invitee,'/pages/doctor/consultations.php?patient='.$clinicalUsers['student']);
checkMis($encounterPage['status']===200&&str_contains($encounterPage['body'],'New physician encounter')&&str_contains($encounterPage['body'],'name="chief_complaint"'),'Consultations opens a patient-specific active encounter editor');
$workspaceSave=['csrf_token'=>misField($encounterPage,'csrf_token'),'patient_user_id'=>$clinicalUsers['student'],'id'=>0,'chief_complaint'=>'Workspace documentation regression','diagnosis'=>'Synthetic physician finding','treatment'=>'Synthetic follow-up plan','remarks'=>'Synthetic physician note','follow_up_date'=>''];
$workspaceCount=(int)$db->query('SELECT COUNT(*) FROM consultations')->fetchColumn();
$invalidSave=misRequest($invitee,'/pages/doctor/consultations.php?patient='.$clinicalUsers['student'],array_replace($workspaceSave,['chief_complaint'=>'']));
checkMis($invalidSave['status']===422&&(int)$db->query('SELECT COUNT(*) FROM consultations')->fetchColumn()===$workspaceCount,'Encounter form validation rejects empty complaint without creating a record');
$validSave=misRequest($invitee,'/pages/doctor/consultations.php?patient='.$clinicalUsers['student'],$workspaceSave);
checkMis($validSave['status']===302&&str_contains($validSave['headers']['location']??'','consultations.php?patient='.$clinicalUsers['student'].'&tab=consultations')&&(int)$db->query('SELECT COUNT(*) FROM consultations')->fetchColumn()===$workspaceCount+1,'Encounter form saves through real HTTP and returns to dedicated consultation history');
$historyPage=misRequest($invitee,'/pages/doctor/patients.php?patient='.$clinicalUsers['student'].'&tab=notes');
checkMis($historyPage['status']===200&&!str_contains($historyPage['body'],'name="chief_complaint"')&&str_contains($historyPage['body'],'Open physician encounter workspace'),'Patient Records keeps notes longitudinal and routes documentation to Consultations');
$historyPost=misRequest($invitee,'/pages/doctor/patients.php?patient='.$clinicalUsers['student'],['patient_user_id'=>$clinicalUsers['student'],'chief_complaint'=>'Forbidden direct history write']);
checkMis($historyPost['status']===405,'Patient history route rejects documentation POST and directs corrections to Consultations');
foreach(['vitals','treatments','medical'] as $section){$response=misRequest($invitee,'/pages/doctor/patients.php?patient='.$clinicalUsers['student'].'&tab='.$section);checkMis($response['status']===200&&str_contains($response['body'],'data-patient-id="'.$clinicalUsers['student'].'"'),'Patient record '.$section.' section remains patient scoped');}
$db->exec('RENAME TABLE medical_records TO fixture_unavailable_medical_records');
try{
 $response=misRequest($invitee,'/pages/doctor/patients.php?patient='.$clinicalUsers['student'].'&tab=medical');
 checkMis($response['status']===503&&str_contains($response['body'],'data-patient-id="'.$clinicalUsers['student'].'"')&&str_contains($response['body'],'Clinical documents are temporarily unavailable')&&!str_contains($response['body'],'No medical documents recorded'),'Missing document dependency preserves patient context and distinguishes unavailable from empty documents');
 $response=misRequest($invitee,'/pages/doctor/patients.php?patient='.$clinicalUsers['student'].'&tab=consultations');
 checkMis($response['status']===503&&str_contains($response['body'],'Nursing intake student'),'Document failure preserves actual consultation history');
 $response=misRequest($invitee,'/api/clinical.php?action=document&patient_user_id='.$clinicalUsers['student'].'&id=1');checkMis($response['status']===503,'Missing document dependency returns API 503 rather than 404');
 $response=misRequest($invitee,'/api/clinical.php',['action'=>'draft','patient_user_id'=>$clinicalUsers['student'],'csrf_token'=>$token]);checkMis($response['status']===409&&!str_contains($response['body'],'MICA DRAFT'),'MICA refuses to summarize incomplete patient history');
}finally{$db->exec('RENAME TABLE fixture_unavailable_medical_records TO medical_records');}
$db->exec('RENAME TABLE consultations TO fixture_unavailable_encounters');
try{
 $response=misRequest($invitee,'/pages/doctor/consultations.php?patient='.$clinicalUsers['student']);
 checkMis($response['status']===503&&str_contains($response['body'],'data-patient-id="'.$clinicalUsers['student'].'"')&&!str_contains($response['body'],'name="chief_complaint"')&&!str_contains($response['body'],'No clinical notes recorded'),'Missing encounters retain context while blocking documentation based on unavailable history');
}finally{$db->exec('RENAME TABLE fixture_unavailable_encounters TO consultations');}
$history=misRequest($invitee,'/pages/doctor/queue.php?view=history');
checkMis($history['status']===200&&str_contains($history['body'],'Completed assessment')&&str_contains($history['body'],'View documented encounter')&&!str_contains($history['body'],'value="complete"'),'Completed referral history exposes audited encounter links without completion forms');
$historyApi=misRequest($invitee,'/api/physician_queue.php?action=list&view=history',null,true);$historyRows=json_decode($historyApi['body'],true)['data']??[];
checkMis($historyApi['status']===200&&count($historyRows)===1&&$historyRows[0]['status']==='completed','Physician history API returns completed referrals separately from active queue');
$dashboard=misRequest($invitee,'/pages/doctor/dashboard.php');
checkMis($dashboard['status']===200&&str_contains($dashboard['body'],'Physician referrals')&&str_contains($dashboard['body'],'Nurse credential reviews')&&str_contains($dashboard['body'],'clinical-kpis'),'Dashboard presents physician referral and credential review work areas');

$filteredReload=misRequest($invitee,'/pages/doctor/consultations.php?search=Nursing&role=student&identifier=CLIN-ST&patient='.$clinicalUsers['student']);
checkMis($filteredReload['status']===200&&str_contains($filteredReload['body'],'search=Nursing&amp;role=student&amp;identifier=CLIN-ST'),'Workspace reload preserves patient search filters');
checkMis(str_contains($history['body'],'href="queue.php?view=history" data-queue-reload')&&str_contains($history['body'],'100 completed physician assessments'),'Completed queue refresh and limit description preserve history context');
