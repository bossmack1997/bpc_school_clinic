<?php
/** CLI-only, read-only queue diagnosis. Never print patients, credentials or raw SQL. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../config/config.php';
$db=getDB();
echo 'Selected database: '.$db->query('SELECT DATABASE()')->fetchColumn().PHP_EOL;
echo 'Connection: OK; server '.$db->query('SELECT VERSION()')->fetchColumn().PHP_EOL;
foreach([
 'handoff'=>'SELECT id,queue_id,patient_user_id,referred_by,assigned_doctor_id,consultation_id,priority,reason,status,record_version,created_at,started_at,completed_at FROM physician_queue LIMIT 0',
 'intake'=>'SELECT id,queue_id,chief_complaint,symptoms,assessment,recommendation,temperature,blood_pressure_systolic,blood_pressure_diastolic,heart_rate,respiratory_rate,oxygen_saturation,height_cm,weight_kg FROM walk_in_visits LIMIT 0',
 'ticket'=>'SELECT id,queue_number,student_id,teacher_id,patient_type,priority,status FROM queue_numbers LIMIT 0',
 'encounter'=>'SELECT id,student_id,teacher_id,patient_type,clinician_user_id,record_version,consultation_date,chief_complaint,diagnosis,treatment,prescription,remarks,follow_up_date,vital_signs FROM consultations LIMIT 0'
] as $dependency=>$sql){
 try{$db->query($sql)->closeCursor();echo $dependency.': OK'.PHP_EOL;}
 catch(PDOException $e){echo $dependency.': FAILED sqlstate='.($e->errorInfo[0]??$e->getCode()).' driver='.($e->errorInfo[1]??0).PHP_EOL;}
}
