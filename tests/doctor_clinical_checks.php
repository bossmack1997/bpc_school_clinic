<?php
/** Included only by the disposable mis_admin_http fixture. Never run on live data. */
if(!isset($database)||!preg_match('/^bpc_mis_test_[a-f0-9]{10}$/D',$database)||$db->query('SELECT DATABASE()')->fetchColumn()!==$database)throw new RuntimeException('Disposable fixture required.');
$clinicalUsers=[];
// Missing physician migration must produce a safe unavailable state, never zero.
$beforeHandoff=misRequest($invitee,'/pages/doctor/queue.php');
file_put_contents(__DIR__.'/../scratch/physician-unmigrated.html',$beforeHandoff['body']);
checkMis($beforeHandoff['status']===503&&str_contains($beforeHandoff['body'],'Missing data is not an empty queue'),'Unmigrated physician queue fails safely');
$handoffSql=file_get_contents(__DIR__.'/../database/migration_physician_queue_20261028.sql');
$db->exec($handoffSql);$db->exec($handoffSql);
checkMis(true,'Additive physician queue migration runs twice');
foreach(['student','teacher','nurse'] as $role){$db->prepare("INSERT INTO users(username,email,password_hash,first_name,last_name,role,status,email_verified,self_service_only) VALUES (?,?,?,'Clinical',? ,?,'active',1,0)")->execute(['clinical_'.$role,$role.'_clinical@example.invalid',password_hash('FixturePass!42',PASSWORD_DEFAULT),ucfirst($role),$role]);$clinicalUsers[$role]=(int)$db->lastInsertId();}
$db->prepare("INSERT INTO students(user_id,student_number,allergies) VALUES (?,'CLIN-ST-01','Recorded student allergy')")->execute([$clinicalUsers['student']]);$studentId=(int)$db->lastInsertId();
$syntheticQr=ensure_student_qr($db,$studentId,$doctor);$resolvedQr=resolve_patient_qr($db,$syntheticQr['qr_token']);
checkMis(($resolvedQr['valid']??false) && (int)$resolvedQr['record']['user_id']===$clinicalUsers['student'],'QR token resolves the correct synthetic patient');
checkMis(!(resolve_patient_qr($db,'invalid-synthetic-token')['valid']??false),'QR rejects an unknown synthetic token');
$db->prepare("INSERT INTO teachers(user_id,employee_id,first_name,last_name,allergies) VALUES (?,'CLIN-TE-01','Clinical','Teacher','Recorded teacher allergy')")->execute([$clinicalUsers['teacher']]);$teacherId=(int)$db->lastInsertId();
$db->prepare("INSERT INTO nurses(user_id,employee_id) VALUES (?,'CLIN-NU-01')")->execute([$clinicalUsers['nurse']]);$nurseId=(int)$db->lastInsertId();
$db->prepare("INSERT INTO consultations(student_id,patient_type,nurse_id,consultation_date,chief_complaint,remarks) VALUES (?,'student',?,NOW(),'Nursing intake student','Original nursing note')")->execute([$studentId,$nurseId]);$nursingId=(int)$db->lastInsertId();
$db->prepare("INSERT INTO consultations(teacher_id,patient_type,nurse_id,consultation_date,chief_complaint,remarks) VALUES (?,'teacher',?,NOW(),'Nursing intake teacher','Teacher nursing note')")->execute([$teacherId,$nurseId]);
foreach(['dashboard','patients','consultations','inventory','emergencies','reports','profile','settings'] as $page){$r=misRequest($invitee,'/pages/doctor/'.$page.'.php');checkMis($r['status']===200&&str_contains($r['body'],'<html')&&strlen($r['body'])>2000,'Doctor '.$page.' renders meaningful HTML');}
checkMis(misRequest($invitee,'/pages/doctor/clinic_monitoring.php')['status']===302,'Clinical monitoring routes to command center');
checkMis(misRequest($invitee,'/pages/doctor/messages.php')['status']===302&&misRequest($invitee,'/pages/doctor_messages.php')['status']===200,'Doctor messaging route and conversation empty state render');
checkMis(misRequest($invitee,'/pages/doctor/nurse_verification.php')['status']===503,'Missing staff schema has a controlled unavailable state');
foreach($clinicalUsers as $role=>$id){if($role==='nurse')continue;$r=misRequest($invitee,'/pages/doctor/patients.php?patient='.$id.'&tab=consultations');checkMis($r['status']===200&&str_contains($r['body'],'data-patient-id="'.$id.'"')&&str_contains($r['body'],'Nursing intake '.$role),'Selected '.$role.' shows its actual clinical history');checkMis(!str_contains($r['body'],'Nursing intake '.($role==='student'?'teacher':'student')),'Selected '.$role.' excludes other patient history');}
checkMis(misRequest($invitee,'/pages/doctor/patients.php?patient[]=1')['status']===400,'Malformed patient selector has a clear error');
checkMis(misRequest($invitee,'/pages/doctor/patients.php?patient=99999999')['status']===404,'Missing patient has a clear error');
$filtered=misRequest($invitee,'/pages/doctor/patients.php?role=teacher&identifier=CLIN-TE-01');checkMis(str_contains($filtered['body'],'CLIN-TE-01')&&!str_contains($filtered['body'],'CLIN-ST-01'),'Patient type and institutional ID filters combine');
$page=misRequest($invitee,'/pages/doctor/patients.php?patient='.$clinicalUsers['teacher'].'&tab=notes');$token=misField($page,'csrf_token');
checkMis(misRequest($invitee,'/api/inventory.php?action=list')['status']===200&&misRequest($invitee,'/api/inventory.php?action=stats')['status']===200,'Doctor medicine oversight APIs return authorized data');
checkMis(misRequest($invitee,'/api/inventory.php',['action'=>'save_policy','csrf_token'=>$token])['status']===403,'Doctor cannot change Nurse inventory policies');
checkMis(misRequest($invitee,'/api/emergencies.php?action=oversight')['status']===200,'Doctor emergency oversight API returns authorized data');
$report=misRequest($invitee,'/api/reports.php',['action'=>'generate','type'=>'consultations','from_date'=>date('Y-m-d',strtotime('-1 day')),'to_date'=>date('Y-m-d',strtotime('+1 day')),'csrf_token'=>$token]);$reportRows=json_decode($report['body'],true)['data']??[];
checkMis($report['status']===200&&in_array('teacher',array_column($reportRows,'patient_type'),true)&&in_array('student',array_column($reportRows,'patient_type'),true),'Clinical report includes both Student and Teacher consultations');
$encounter=['action'=>'save','patient_user_id'=>$clinicalUsers['teacher'],'chief_complaint'=>'Doctor reviewed teacher','diagnosis'=>'Recorded review','treatment'=>'Documented plan','prescription'=>'Recorded instructions','remarks'=>'Physician note','follow_up_date'=>date('Y-m-d',strtotime('+2 days')),'csrf_token'=>$token];
$save=json_decode(misRequest($invitee,'/api/clinical.php',$encounter)['body'],true);checkMis($save['success']??false,'Physician consultation with follow-up saves through authorized API');$encounterId=(int)$save['id'];
checkMis($db->query('SELECT remarks FROM consultations WHERE id='.$nursingId)->fetchColumn()==='Original nursing note','Physician action preserves nursing record');
$bad=$encounter;$bad['id']=$nursingId;$bad['record_version']=0;checkMis(misRequest($invitee,'/api/clinical.php',$bad)['status']===409,'Doctor cannot revise nursing-authored encounter');
$bad=$encounter;$bad['id']=$encounterId;$bad['record_version']=999;checkMis(misRequest($invitee,'/api/clinical.php',$bad)['status']===409,'Stale encounter revision is rejected');
require_once __DIR__.'/../includes/ai/MICAWorkflowService.php';$doctorUser=$db->query('SELECT * FROM users WHERE id='.$doctor)->fetch();$workflow=new MICAWorkflowService($db,$doctorUser);$count=(int)$db->query('SELECT COUNT(*) FROM consultations')->fetchColumn();
$draft=$workflow->handleNaturalLanguageQuery('Draft patient #'.$clinicalUsers['teacher'].' summary');checkMis(str_contains($draft['reply'],'MICA DRAFT')&&str_contains($draft['reply'],'Doctor reviewed teacher')&&!str_contains($draft['reply'],'Nursing intake student'),'MICA draft is grounded and scoped to selected Teacher');
checkMis((int)$db->query('SELECT COUNT(*) FROM consultations')->fetchColumn()===$count,'MICA draft does not create clinical documentation');
$unauthorized=new MICAWorkflowService($db,$db->query('SELECT * FROM users WHERE id='.$clinicalUsers['student'])->fetch());checkMis($unauthorized->handleNaturalLanguageQuery('Draft patient #'.$clinicalUsers['teacher'].' summary')['type']==='access_denied','MICA rejects unauthorized cross-patient draft retrieval');
checkMis((int)$db->query("SELECT COUNT(*) FROM activity_logs WHERE action='MICA clinical draft prepared'")->fetchColumn()>0,'Authorized clinical draft leaves a content-free audit event');
$draftApi=misRequest($invitee,'/api/clinical.php',['action'=>'draft','patient_user_id'=>$clinicalUsers['teacher'],'csrf_token'=>$token]);checkMis($draftApi['status']===200&&str_contains(json_decode($draftApi['body'],true)['draft']??'','MICA DRAFT'),'Clinical workspace prepares authorized draft through CSRF-protected API');
checkMis(misRequest($invitee,'/api/clinical.php',['action'=>'draft','patient_user_id'=>$clinicalUsers['teacher'],'csrf_token'=>'invalid'])['status']===403,'Clinical draft requires CSRF');
$chat=misRequest($invitee,'/api/ai_assistant.php',['action'=>'chat','message'=>'Draft patient #'.$clinicalUsers['teacher'].' summary','csrf_token'=>$token],true);checkMis($chat['status']===200&&str_contains(json_decode($chat['body'],true)['reply']??'','MICA DRAFT'),'Floating MICA chat endpoint produces grounded draft without external AI provider');
$bad=$encounter;$bad['id']=$encounterId;$bad['record_version']=0;$bad['patient_user_id']=$clinicalUsers['student'];checkMis(misRequest($invitee,'/api/clinical.php',$bad)['status']===400,'Revision cannot be attached to a different patient');
checkMis(misRequest($invitee,'/api/clinical.php?action=records&patient_user_id[]=1')['status']===400,'Clinical API rejects malformed patient identifiers');
$db->prepare("INSERT INTO support_referrals(student_id,created_by,referred_to,reason,status) VALUES (?,?,'Doctor','Synthetic physician referral','pending'),(?,?,'Guidance Office','Synthetic guidance referral','pending')")->execute([$studentId,$clinicalUsers['nurse'],$studentId,$clinicalUsers['nurse']]);
$db->exec("INSERT INTO medicines(medicine_name,category,quantity,unit,reorder_level) VALUES ('Doctor fixture low stock','Tablet',1,'tablets',10)");
$center=(new DoctorClinicalService($db,$doctorUser))->commandCenter();
checkMis($center['unavailable']===['pending_nurses']&&$center['today_consultations']===3,'Command center counts actual consultations without unavailable sources');
checkMis(count($center['escalations'])===1&&(int)$center['escalations'][0]['patient_user_id']===$clinicalUsers['student'],'Command center shows only recorded Nurse-to-physician referrals');
checkMis(count($center['followups'])===1&&(int)$center['followups'][0]['patient_user_id']===$clinicalUsers['teacher'],'Command center links dated Teacher follow-up to stable account ID');
checkMis(in_array('Doctor fixture low stock',array_column($center['stock'],'medicine_name'),true),'Medicine concerns use actual low-stock inventory');
$beforeDocumentCount=(int)$db->query('SELECT COUNT(*) FROM medical_records')->fetchColumn();
$migration=preg_replace('/^\s*--.*$/m','',file_get_contents(__DIR__.'/../database/migration_doctor_teacher_documents_20261024.sql'));
for($attempt=0;$attempt<2;$attempt++)foreach(array_filter(array_map('trim',explode(';',$migration))) as $sql){$query=$db->query($sql);$query->closeCursor();}
checkMis((int)$db->query('SELECT COUNT(*) FROM medical_records')->fetchColumn()===$beforeDocumentCount,'Teacher document nullable migration reruns without changing records');
$fixturePath='uploads/medical_records/'.$database.'_document.pdf';file_put_contents(__DIR__.'/../'.$fixturePath,'Synthetic clinical attachment');
try{
 $documentIds=[];
 foreach(['student','teacher'] as $type){$db->prepare('INSERT INTO medical_records(consultation_id,student_id,teacher_id,patient_type,file_path,file_type,description) VALUES (?,?,?,?,?,?,?)')->execute([$type==='student'?$nursingId:$encounterId,$type==='student'?$studentId:null,$type==='teacher'?$teacherId:null,$type,$fixturePath,'application/pdf','Synthetic '.$type.' clinical document']);$documentIds[$type]=(int)$db->lastInsertId();
  $r=misRequest($invitee,'/api/clinical.php?action=document&patient_user_id='.$clinicalUsers[$type].'&id='.$documentIds[$type]);checkMis($r['status']===200&&$r['body']==='Synthetic clinical attachment','Doctor downloads authorized '.$type.' document');
 }
 checkMis(misRequest($invitee,'/api/clinical.php?action=document&patient_user_id='.$clinicalUsers['student'].'&id='.$documentIds['teacher'])['status']===404,'Selected patient document scope rejects another patient document');
 $db->prepare('UPDATE medical_records SET file_path=? WHERE id=?')->execute(['config/config.php',$documentIds['teacher']]);checkMis(misRequest($invitee,'/api/clinical.php?action=document&patient_user_id='.$clinicalUsers['teacher'].'&id='.$documentIds['teacher'])['status']===404,'Clinical document download cannot escape private attachment directory');
}finally{unlink(__DIR__.'/../'.$fixturePath);}
foreach(['/pages/admin/role_profiles.php','/pages/admin/backups.php','/pages/admin/settings.php','/pages/admin/security.php','/api/system_backups.php?action=list'] as $technicalPath)checkMis(misRequest($invitee,$technicalPath)['status']===403,'Doctor denied technical route '.$technicalPath);
$db->exec('RENAME TABLE consultations TO doctor_fixture_consultations');
try{$r=misRequest($invitee,'/pages/doctor/dashboard.php');checkMis($r['status']===200&&str_contains($r['body'],"Couldn't load this count")&&!str_contains($r['body'],'SQLSTATE'),'Dashboard retains shell when a clinical source fails');$r=misRequest($invitee,'/pages/doctor/patients.php?patient='.$clinicalUsers['student']);checkMis($r['status']===503&&str_contains($r['body'],'<html')&&str_contains($r['body'],'temporarily unavailable')&&!str_contains($r['body'],'SQLSTATE'),'Patient query failure returns safe HTML instead of raw JSON or blank page');}finally{$db->exec('RENAME TABLE doctor_fixture_consultations TO consultations');}
$nurse=misClient();$r=misRequest($nurse,'/auth/login.php');misRequest($nurse,'/auth/login.php',['csrf_token'=>misField($r,'csrf_token'),'identifier'=>'clinical_nurse','password'=>'FixturePass!42']);checkMis(misRequest($nurse,'/pages/nurse/queue.php')['status']===200,'Existing authorized Nurse queue page remains functional');
$db->exec('ALTER TABLE admin_profiles CHANGE COLUMN official_designation doctor_fixture_designation VARCHAR(150) NULL');
try{$failure=misRequest($invitee,'/pages/doctor/profile.php');checkMis($failure['status']===503&&str_contains($failure['body'],'Clinical workspace temporarily unavailable')&&str_contains($failure['body'],'<html')&&!str_contains($failure['body'],'SQLSTATE'),'Uncaught Doctor profile query failure produces safe recovery HTML');}finally{$db->exec('ALTER TABLE admin_profiles CHANGE COLUMN doctor_fixture_designation official_designation VARCHAR(150) NULL');}
checkMis(misRequest($nurse,'/api/clinical.php?action=records&patient_user_id='.$clinicalUsers['student'])['status']===403,'Doctor-only API stays closed to Nurse role');
require __DIR__.'/physician_queue_checks.php';
require __DIR__.'/queue_policy_checks.php';
if(in_array('--doctor-browser',$argv,true)){putenv('DOCTOR_TEST_BASE='.$base);putenv('DOCTOR_TEST_USER=accept_http');putenv('DOCTOR_TEST_PATIENT='.(string)$clinicalUsers['teacher']);passthru('node '.escapeshellarg(__DIR__.'/doctor_browser.cjs'),$browserExit);checkMis($browserExit===0,'Doctor mobile tablet desktop browser workflows');}
