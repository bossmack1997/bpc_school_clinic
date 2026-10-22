<?php
// CLI-only integration test. Temporary tables shadow production tables for this connection.
if(PHP_SAPI!=='cli')exit;
ini_set('session.save_path',__DIR__);
require __DIR__.'/../config/config.php';
$testDb=getDB();
foreach(['users','students','sos_emergencies','activity_logs','emergency_location_updates'] as $table){$testDb->exec("CREATE TEMPORARY TABLE sos_test_$table LIKE $table");$testDb->exec("ALTER TABLE sos_test_$table RENAME TO $table");}
$testDb->exec("INSERT INTO users (id,username,email,password_hash,first_name,last_name,role,status) VALUES (901,'sos-test-student','sos-student@example.invalid','x','Test','Student','student','active'),(902,'sos-test-teacher','sos-teacher@example.invalid','x','Test','Teacher','teacher','active'),(903,'sos-test-nurse','sos-nurse@example.invalid','x','Test','Nurse','nurse','active'),(904,'sos-test-nurse2','sos-nurse2@example.invalid','x','Test','Nurse2','nurse','active'),(905,'sos-test-admin','sos-admin@example.invalid','x','Test','Admin','admin','active')");
// Evaluate workflow in namespace with controlled delivery and JSON-exit boundaries.
require __DIR__.'/sos_test_stubs.php';
$source=file_get_contents(__DIR__.'/../includes/sos_workflow.php');
$source=preg_replace('/^<\?php/','',$source);
eval('namespace SosIntegration; use PDO; use Throwable; '.$source);
function checkSos($ok,$label){if(!$ok)throw new RuntimeException($label);echo 'PASS '.$label.PHP_EOL;}
function expectSos($code,callable $fn){try{$fn();throw new RuntimeException('Expected '.$code);}catch(SosIntegration\ApiFailure $e){checkSos($e->payload['error_code']===$code,$code);}}
$student=['id'=>901,'role'=>'student'];$teacher=['id'=>902,'role'=>'teacher'];$nurse=['id'=>903,'role'=>'nurse'];$other=['id'=>904,'role'=>'nurse'];$admin=['id'=>905,'role'=>'admin'];
$a=SosIntegration\sos_create($testDb,$student,['description'=>'Denied GPS','location'=>'Room 204']);$id=$a['emergency_id'];
checkSos($a['latitude']===null,'Student SOS without GPS');
$b=SosIntegration\sos_create($testDb,$student,[]);checkSos($b['emergency_id']===$id,'Duplicate submission returns same SOS');
$t=SosIntegration\sos_create($testDb,$teacher,['latitude'=>14.5,'longitude'=>121,'accuracy'=>'5000000m']);
$row=$testDb->query('SELECT * FROM sos_emergencies WHERE id='.$t['emergency_id'])->fetch();checkSos($row['student_id']===null && $row['reporter_role']==='teacher','Teacher patient without student fields');
checkSos((float)$row['location_accuracy']===5000000.0,'Accuracy stored in meters');
expectSos('SOS_FORBIDDEN',fn()=>SosIntegration\sos_transition($testDb,$teacher,$id,'accept',[]));
$r=SosIntegration\sos_transition($testDb,$nurse,$id,'accept',[]);checkSos($r['status']==='acknowledged','Nurse accepts');
$count=$testDb->query('SELECT COUNT(*) FROM activity_logs')->fetchColumn();
SosIntegration\sos_transition($testDb,$nurse,$id,'accept',[]);checkSos($count==$testDb->query('SELECT COUNT(*) FROM activity_logs')->fetchColumn(),'Repeat action has no duplicate audit');
expectSos('SOS_ALREADY_ACCEPTED',fn()=>SosIntegration\sos_transition($testDb,$other,$id,'accept',[]));
expectSos('SOS_ALREADY_ACCEPTED',fn()=>SosIntegration\sos_transition($testDb,$student,$id,'cancel',['reason'=>'Test'],true));
$r=SosIntegration\sos_transition($testDb,$nurse,$id,'respond',[]);checkSos($r['status']==='responding','Responding transition');
expectSos('SOS_ALREADY_ACCEPTED',fn()=>SosIntegration\sos_transition($testDb,$nurse,$id,'acknowledge',[]));
expectSos('SOS_NOTES_REQUIRED',fn()=>SosIntegration\sos_transition($testDb,$nurse,$id,'resolve',[]));
$r=SosIntegration\sos_transition($testDb,$nurse,$id,'resolve',['resolution_notes'=>'Assistance complete']);checkSos($r['status']==='resolved','Resolution with notes');
expectSos('SOS_CLOSED',fn()=>SosIntegration\sos_transition($testDb,$nurse,$id,'respond',[]));
$r=SosIntegration\sos_transition($testDb,$teacher,$t['emergency_id'],'cancel',['reason'=>'Accidental activation'],true);checkSos($r['status']==='cancelled','Reporter cancellation');
$row=$testDb->query('SELECT * FROM sos_emergencies WHERE id='.$id)->fetch();checkSos($row['acknowledged_at'] && $row['responded_at'] && $row['resolved_at'] && $row['assigned_to']==903,'Responder identity and all timestamps');
checkSos($testDb->query("SELECT COUNT(*) FROM activity_logs WHERE details LIKE '%actor_role%'")->fetchColumn()>=6,'Transactional audit metadata');
// Simulated notification failure happened throughout; committed state remains available.
checkSos($testDb->query('SELECT COUNT(*) FROM sos_emergencies')->fetchColumn()==2,'Notification outage preserves state and no duplicate reports');
echo "PASS isolated MySQL workflow integration; no permanent records written.\n";

// Administration monitors emergencies; clinical response remains with the nurse.
$testDb->exec("UPDATE sos_emergencies SET created_at=DATE_SUB(NOW(),INTERVAL 3 MINUTE) WHERE user_id=902");
$third=SosIntegration\sos_create($testDb,$teacher,[]);
expectSos('SOS_FORBIDDEN',fn()=>SosIntegration\sos_transition($testDb,$admin,$third['emergency_id'],'acknowledge',[]));
// A missing audit column forces SQL failure: neither the SOS nor its location may persist.
$before=$testDb->query('SELECT COUNT(*) FROM sos_emergencies')->fetchColumn();
$testDb->exec('ALTER TABLE activity_logs CHANGE action unavailable_action VARCHAR(150)');
$testDb->exec("UPDATE sos_emergencies SET status='resolved',created_at=DATE_SUB(NOW(),INTERVAL 3 MINUTE) WHERE user_id=901");
try { SosIntegration\sos_create($testDb,$student,[]); throw new RuntimeException('Audit failure was ignored'); }
catch(PDOException $e) {if($testDb->inTransaction())$testDb->rollBack();}
checkSos($before==$testDb->query('SELECT COUNT(*) FROM sos_emergencies')->fetchColumn(),'Audit failure rolls back emergency creation');





