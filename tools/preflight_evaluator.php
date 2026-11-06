<?php
/** Read-only local integrity counts; never prints patient identifiers or rows. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/../config/config.php';
if(APP_ENV!=='local'||!in_array(DB_HOST,['localhost','127.0.0.1','::1'],true))throw new RuntimeException('Local loopback database required.');
$db=getDB();$results=[];
$queries=[
    'duplicate_student_accounts'=>'SELECT COUNT(*) FROM (SELECT user_id FROM students WHERE user_id IS NOT NULL GROUP BY user_id HAVING COUNT(*)>1) x',
    'duplicate_teacher_accounts'=>'SELECT COUNT(*) FROM (SELECT user_id FROM teachers WHERE user_id IS NOT NULL GROUP BY user_id HAVING COUNT(*)>1) x',
    'duplicate_student_numbers'=>"SELECT COUNT(*) FROM (SELECT student_number FROM students WHERE TRIM(COALESCE(student_number,''))<>'' GROUP BY student_number HAVING COUNT(*)>1) x",
    'duplicate_teacher_numbers'=>"SELECT COUNT(*) FROM (SELECT employee_id FROM teachers WHERE TRIM(COALESCE(employee_id,''))<>'' GROUP BY employee_id HAVING COUNT(*)>1) x",
    'conflicting_patient_roles'=>'SELECT COUNT(*) FROM students s JOIN teachers t ON t.user_id=s.user_id',
    'orphan_consultation_students'=>'SELECT COUNT(*) FROM consultations c LEFT JOIN students s ON s.id=c.student_id WHERE c.student_id IS NOT NULL AND s.id IS NULL',
    'orphan_consultation_teachers'=>'SELECT COUNT(*) FROM consultations c LEFT JOIN teachers t ON t.id=c.teacher_id WHERE c.teacher_id IS NOT NULL AND t.id IS NULL',
    'ambiguous_consultation_patients'=>'SELECT COUNT(*) FROM consultations WHERE student_id IS NOT NULL AND teacher_id IS NOT NULL',
    'duplicate_queue_visits'=>'SELECT COUNT(*) FROM (SELECT queue_id FROM walk_in_visits WHERE queue_id IS NOT NULL GROUP BY queue_id HAVING COUNT(*)>1) x',
    'queue_intake_priority_mismatch'=>'SELECT COUNT(*) FROM walk_in_visits w JOIN queue_numbers q ON q.id=w.queue_id WHERE NOT(w.priority_level <=> q.priority)',
    'handoff_priority_mismatch'=>'SELECT COUNT(*) FROM physician_queue p JOIN queue_numbers q ON q.id=p.queue_id WHERE NOT(p.priority <=> q.priority)',
    'document_patient_mismatch'=>'SELECT COUNT(*) FROM medical_records m JOIN consultations c ON c.id=m.consultation_id WHERE NOT(m.student_id <=> c.student_id) OR NOT(m.teacher_id <=> c.teacher_id)',
];
$db->exec('SET TRANSACTION READ ONLY');$db->beginTransaction();
try {
    foreach($queries as $label=>$sql){try{$results[$label]=['count'=>(int)$db->query($sql)->fetchColumn()];}catch(PDOException $e){$results[$label]=['unavailable'=>true];}}
    foreach(['nurses','consultations','consultation_medicines','medicines'] as $table)$results['columns'][$table]=$db->query('SHOW COLUMNS FROM `'.$table.'`')->fetchAll(PDO::FETCH_COLUMN);
    $db->rollBack();
}catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
echo json_encode($results,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),PHP_EOL;
exit(array_filter($results,static fn($r)=>isset($r['unavailable'])||($r['count']??0)>0)?2:0);
