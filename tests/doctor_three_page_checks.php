<?php
/** Execute only in the disposable Doctor/staff HTTP fixture. */
if(!isset($database)||!preg_match('/^bpc_mis_test_[a-f0-9]{10}$/D',$database)||$db->query('SELECT DATABASE()')->fetchColumn()!==$database)throw new RuntimeException('Disposable fixture required.');
checkMis(!$db->getAttribute(PDO::ATTR_EMULATE_PREPARES),'Doctor regression uses native PDO prepares');
$duplicateFailed=false;
try{$q=$db->prepare('SELECT :probe = :probe');$q->execute(['probe'=>'synthetic']);}catch(PDOException $e){$duplicateFailed=$e->getCode()==='HY093';}
checkMis($duplicateFailed,'Repeated named placeholder reproduces HY093 under native prepares');
$doctorUser=$db->query('SELECT * FROM users WHERE id='.$doctor)->fetch();
$clinical=new DoctorClinicalService($db,$doctorUser);
foreach(['student'=>'CLIN-ST-01','teacher'=>'CLIN-TE-01'] as $type=>$institutionalId) {
    $rows=$clinical->patients('Clinical',$type,$institutionalId);
    checkMis(count($rows)===1&&(int)$rows[0]['id']===$clinicalUsers[$type],$type.' combined name/type/ID search works under native prepares');
    $overview=$clinical->overview($clinicalUsers[$type]);
    checkMis($overview['patient']['role']===$type&&!empty($overview['consultations']),$type.' profile association and existing clinical history load');
}
checkMis(count($clinical->patients('','',''))>=2,'Empty patient filters discover both patient types');
checkMis($clinical->patients("' OR 1=1 --",'','')===[],'Search text is bound as data');
$db->exec('ALTER TABLE teachers CHANGE COLUMN employee_id fixture_employee_id VARCHAR(30) NOT NULL');
try {
    $directoryFailure=false;
    try{$clinical->patients();}catch(PDOException $e){$directoryFailure=$e->getCode()==='42S22'&&(int)($e->errorInfo[1]??0)===1054;}
    checkMis($directoryFailure,'Missing directory join column reproduces SQLSTATE 42S22 / driver 1054');
    $page=misRequest($invitee,'/pages/doctor/patients.php');
    checkMis($page['status']===503&&str_contains($page['body'],'Patient information is temporarily unavailable')&&!str_contains($page['body'],'SQLSTATE'),'Patient directory failure remains distinct from an empty result and hides SQL');
}finally{$db->exec('ALTER TABLE teachers CHANGE COLUMN fixture_employee_id employee_id VARCHAR(30) NOT NULL');}
$db->exec("INSERT INTO queue_numbers(queue_number,student_id,status,visit_type,created_at) VALUES ('FIX-TODAY',$studentId,'waiting','walk-in',NOW()),('FIX-FUTURE',$studentId,'waiting','walk-in',DATE_ADD(NOW(),INTERVAL 2 DAY))");
$expected=(int)$db->query("SELECT COUNT(*) FROM queue_numbers WHERE created_at>=CURDATE() AND created_at<CURDATE()+INTERVAL 1 DAY AND status IN ('waiting','called','serving')")->fetchColumn();
$center=$clinical->commandCenter();
checkMis($center['awaiting_review']===$expected&&$clinical->summary()['queue_awaiting_completion']===$expected,'Both Doctor metrics exclude future-dated active queue rows');
checkMis($center['today_consultations']===(int)$db->query('SELECT COUNT(*) FROM consultations WHERE consultation_date>=CURDATE() AND consultation_date<CURDATE()+INTERVAL 1 DAY')->fetchColumn(),'Doctor consultation metric matches independent database count');
foreach(['clinical_reviewed_by'=>'INT NULL','clinical_reviewed_at'=>'DATETIME NULL','clinical_review_notes'=>'TEXT NULL'] as $column=>$type) {
    $db->exec("ALTER TABLE nurses DROP COLUMN `$column`");
    try {
        $schemaService=new NurseVerificationService($db,$doctorUser);
        checkMis(!$schemaService->ready()&&in_array('nurses.'.$column,$schemaService->missingSchema(),true),'Partial Nurse schema detects missing '.$column);
        $page=misRequest($invitee,'/pages/doctor/nurse_verification.php');
        checkMis($page['status']===503&&str_contains($page['body'],'setup is incomplete'),'Partial schema review route fails closed before query or decision');
        $center=$clinical->commandCenter();
        checkMis($center['pending_nurses']===null&&$center['staff_setup_required']&&is_int($center['today_consultations']),'Missing review component preserves available metrics and unavailable count');
    } finally {$db->exec("ALTER TABLE nurses ADD COLUMN `$column` $type");}
}
checkMis((new NurseVerificationService($db,$doctorUser))->ready(),'Complete restored review schema is ready');
$readOnlyProbe=proc_open([PHP_BINARY,'-d','session.save_path='.__DIR__.'/../scratch',__DIR__.'/../tools/diagnose_doctor_clinical.php'],[1=>['pipe','w'],2=>['pipe','w']],$probePipes);
if(!is_resource($readOnlyProbe))throw new RuntimeException('Could not start diagnostic probe');
$probeOutput=stream_get_contents($probePipes[1]);$probeErrors=stream_get_contents($probePipes[2]);fclose($probePipes[1]);fclose($probePipes[2]);$probeExit=proc_close($readOnlyProbe);
$probeData=json_decode($probeOutput,true);
checkMis($probeExit===0&&($probeData['native_prepares']??false)&&($probeData['student_directory']['available']??false)&&($probeData['teacher_directory']['available']??false),'Read-only CLI diagnostic executes both native-prepared directories');
checkMis(!str_contains($probeOutput,'Nursing intake')&&!str_contains($probeOutput,'Clinical Teacher')&&!str_contains($probeOutput,'password_hash'),'Read-only diagnostic output contains no patient text or credentials');
// Verify diagnostic content using a deliberately sensitive database error payload.
$previousLog=ini_get('error_log');$diagnosticLog=__DIR__.'/../scratch/doctor-safe-diagnostic-'.bin2hex(random_bytes(4)).'.log';
try {
    ini_set('error_log',$diagnosticLog);
    $e=new PDOException('Private diagnosis and credential must never appear');$e->errorInfo=['42S22',1054,"Unknown column 'c.missing_fixture_column' in 'field list' Private diagnosis"];
    clinical_database_failure('doctor.patient.overview',$e);
    $log=file_get_contents($diagnosticLog);
    checkMis(str_contains($log,'operation=doctor.patient.overview sqlstate=42S22 driver=1054')&&!str_contains($log,'missing_fixture_column')&&!str_contains($log,'Private diagnosis'),'Clinical diagnostic logs only operation, SQLSTATE and numeric driver code');
}finally{ini_set('error_log',$previousLog);if(is_file($diagnosticLog))unlink($diagnosticLog);}

// The read-only production diagnostic must survive a partially unavailable history.
foreach(['medical_records'=>'documents','consultations'=>'consultations'] as $source=>$unavailableSource){
 $db->exec('RENAME TABLE '.$source.' TO fixture_diagnostic_unavailable');
 try{
  $process=proc_open([PHP_BINARY,'-d','session.save_path='.__DIR__.'/../scratch',__DIR__.'/../tools/diagnose_doctor_clinical.php'],[1=>['pipe','w'],2=>['pipe','w']],$diagnosticPipes);
  $output=stream_get_contents($diagnosticPipes[1]);$errors=stream_get_contents($diagnosticPipes[2]);fclose($diagnosticPipes[1]);fclose($diagnosticPipes[2]);$exitCode=proc_close($process);$diagnostic=json_decode($output,true);
  foreach(['student','teacher'] as $patientRole){$overviewResult=$diagnostic[$patientRole.'_overview']['result']??[];checkMis($exitCode===0&&in_array($unavailableSource,$overviewResult['unavailable']??[],true)&&array_key_exists($unavailableSource.'_loaded_up_to_50',$overviewResult)&&$overviewResult[$unavailableSource.'_loaded_up_to_50']===null,'Read-only diagnostic preserves unavailable '.$patientRole.' '.$unavailableSource.' without count(null) failure');}
  checkMis(!str_contains($output,'Nursing intake')&&!str_contains($output,'Clinical Teacher')&&!str_contains($output,'password_hash'),'Partial-source diagnostic returns no patient text or credentials');
 }finally{$db->exec('RENAME TABLE fixture_diagnostic_unavailable TO '.$source);}
}
