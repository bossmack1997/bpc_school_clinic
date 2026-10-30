<?php
/** CLI-only read-only workspace probes; no patient rows or credentials are printed. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/../config/config.php';$db=getDB();$failed=false;
echo 'Selected database: '.$db->query('SELECT DATABASE()')->fetchColumn().PHP_EOL;
$probes=[
 'patient_directory'=>"SELECT u.id,u.role,s.student_number,t.employee_id FROM users u LEFT JOIN students s ON s.user_id=u.id AND u.role='student' LEFT JOIN teachers t ON t.user_id=u.id AND u.role='teacher' WHERE u.id=0 AND COALESCE(s.student_number,t.employee_id,'') LIKE '%'",
 'patient_context'=>"SELECT u.id,s.blood_type,t.blood_type,s.allergies,t.allergies FROM users u LEFT JOIN students s ON s.user_id=u.id LEFT JOIN teachers t ON t.user_id=u.id WHERE u.id=0",
 'consultations'=>'SELECT id,student_id,teacher_id,consultation_date,chief_complaint,diagnosis,treatment,prescription,remarks,vital_signs,follow_up_date,clinician_user_id,nurse_id,record_version FROM consultations LIMIT 0',
 'documents'=>'SELECT id,student_id,teacher_id,consultation_id,file_type,description,uploaded_at,file_path FROM medical_records LIMIT 0',
 'physician_queue'=>'SELECT id,queue_id,status,priority,created_at,completed_at,assigned_doctor_id,consultation_id FROM physician_queue LIMIT 0',
 'account_activation_invitations'=>'SELECT id,user_id,token_hash,expires_at,used_at FROM account_activation_invitations LIMIT 0',
 'account_provisioning_lock'=>'SELECT id FROM account_provisioning_lock LIMIT 0'
];
foreach($probes as $source=>$sql){try{$db->query($sql)->closeCursor();echo $source.': OK'.PHP_EOL;}catch(PDOException $e){$failed=true;echo $source.': UNAVAILABLE sqlstate='.($e->errorInfo[0]??$e->getCode()).' driver='.($e->errorInfo[1]??0).PHP_EOL;}}
echo 'No clinical rows or schemas were changed.'.PHP_EOL;exit($failed?2:0);
