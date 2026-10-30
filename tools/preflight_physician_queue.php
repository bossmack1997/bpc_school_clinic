<?php
/** Read-only schema checks. No migration is run by this CLI command. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../config/config.php';
$db=getDB();$schema=$db->query('SELECT DATABASE()')->fetchColumn();
$blocked=false;$missing=false;
echo 'Selected database: '.$schema.PHP_EOL;
$required=[
 'users'=>['id','role','status','credential_version','username','email','password_hash','email_verified','email_verified_at','self_service_only','registration_status','first_name','last_name'],
 'students'=>['id','user_id','student_number','blood_type','allergies'], 'teachers'=>['id','user_id','employee_id','blood_type','allergies'],
 'nurses'=>['user_id'],
 'queue_numbers'=>['id','queue_number','student_id','teacher_id','patient_type','priority','status'],
 'walk_in_visits'=>['id','queue_id','chief_complaint','symptoms','assessment','recommendation','temperature','blood_pressure_systolic','blood_pressure_diastolic','heart_rate','respiratory_rate','oxygen_saturation','height_cm','weight_kg'],
 'consultations'=>['id','nurse_id','visit_type','student_id','teacher_id','patient_type','clinician_user_id','record_version','consultation_date','chief_complaint','diagnosis','treatment','prescription','remarks','vital_signs','follow_up_date'],
 'activity_logs'=>['user_id','action','module','details'], 'admin_profiles'=>['user_id','profile'],
 'system_settings'=>['setting_key','setting_value']
];
foreach($required as $table=>$columns){
 $q=$db->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND BINARY TABLE_NAME=BINARY ?');$q->execute([$schema,$table]);
 if($q->fetchColumn()!=='InnoDB'){echo 'BLOCK '.$table.' must be a lowercase InnoDB base table'.PHP_EOL;$blocked=true;continue;}
 $q=$db->prepare('SELECT COLUMN_NAME,COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND BINARY TABLE_NAME=BINARY ?');$q->execute([$schema,$table]);$actual=$q->fetchAll(PDO::FETCH_KEY_PAIR);
 foreach($columns as $column) if(!isset($actual[$column])){echo 'BLOCK missing '.$table.'.'.$column.PHP_EOL;$blocked=true;}
 if(in_array($table,['users','queue_numbers','consultations'],true)&&isset($actual['id'])&&!preg_match('/^int(?:\(\d+\))?$/D',$actual['id'])){echo 'BLOCK '.$table.'.id must match signed INT foreign keys'.PHP_EOL;$blocked=true;}
}
foreach(['email','username'] as $column){
 $q=$db->prepare("SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND NON_UNIQUE=0 GROUP BY INDEX_NAME HAVING COUNT(*)=1 AND MAX(COLUMN_NAME)=? AND MAX(COALESCE(SUB_PART,0))=0");$q->execute([$column]);
 if(!$q->fetchColumn()){echo 'BLOCK missing full-column unique users.'.$column.PHP_EOL;$blocked=true;}
}
if(!in_array('--queue-only',$argv,true))foreach(['account_activation_invitations'=>['id','user_id','token_hash','created_by','expires_at','used_at','delivery_status','created_at'],'account_provisioning_lock'=>['id']] as $table=>$requiredColumns){
 $q=$db->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND BINARY TABLE_NAME=BINARY ?');$q->execute([$table]);$columns=$q->fetchAll(PDO::FETCH_COLUMN);
 if(!$columns){$missing=true;echo 'ADD '.$table.' using the account provisioning migration.'.PHP_EOL;continue;}
 foreach(array_diff($requiredColumns,$columns) as $column){echo 'BLOCK existing '.$table.' missing '.$column.PHP_EOL;$blocked=true;}
 echo 'REVIEW existing '.$table.' engine, types and constraints against the migration.'.PHP_EOL;
}
$q=$db->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND BINARY TABLE_NAME=BINARY 'physician_queue'");$q->execute([$schema]);
if($q->fetchColumn()){
 $q=$db->query('SHOW COLUMNS FROM physician_queue');$columns=$q->fetchAll(PDO::FETCH_COLUMN);
 foreach(['id','queue_id','patient_user_id','referred_by','assigned_doctor_id','consultation_id','priority','reason','status','record_version','created_at','started_at','completed_at'] as $column)if(!in_array($column,$columns,true)){echo 'BLOCK existing physician_queue missing '.$column.PHP_EOL;$blocked=true;}
 $definitions=$db->query('SHOW COLUMNS FROM physician_queue')->fetchAll(PDO::FETCH_UNIQUE|PDO::FETCH_ASSOC);
 foreach(['id','queue_id','patient_user_id','referred_by','assigned_doctor_id','consultation_id','record_version'] as $column){if(!isset($definitions[$column])||!preg_match('/^int(?:\(\d+\))?$/D',$definitions[$column]['Type'])){echo 'BLOCK physician_queue.'.$column.' must be signed INT'.PHP_EOL;$blocked=true;}}
 foreach(['priority'=>"enum('normal','urgent','emergency')",'status'=>"enum('awaiting','in_progress','completed')"] as $column=>$type){if(($definitions[$column]['Type']??'')!==$type){echo 'BLOCK incompatible physician_queue.'.$column.' lifecycle values'.PHP_EOL;$blocked=true;}}
 if(($definitions['id']['Key']??'')!=='PRI'||!str_contains($definitions['id']['Extra']??'','auto_increment')){echo 'BLOCK physician_queue.id must be an auto-increment primary key'.PHP_EOL;$blocked=true;}
 echo 'REVIEW existing physician_queue definition, constraints and indexes against the migration; IF NOT EXISTS does not repair an incompatible table.'.PHP_EOL;
 $engine=$db->query("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='physician_queue'")->fetchColumn();
 if($engine!=='InnoDB'){echo 'BLOCK physician_queue must be InnoDB'.PHP_EOL;$blocked=true;}
 $indexes=$db->query("SELECT INDEX_NAME,NON_UNIQUE,GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS columns_list FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='physician_queue' GROUP BY INDEX_NAME,NON_UNIQUE")->fetchAll();
 foreach(['queue_id','consultation_id'] as $unique){$found=false;foreach($indexes as $index)if((int)$index['NON_UNIQUE']===0&&$index['columns_list']===$unique)$found=true;if(!$found){echo 'BLOCK missing unique physician_queue.'.$unique.PHP_EOL;$blocked=true;}}
 $priorityIndex=false;foreach($indexes as $index)if(str_starts_with($index['columns_list'],'status,priority,created_at'))$priorityIndex=true;
 if(!$priorityIndex){echo 'BLOCK missing physician_queue status/priority/date index'.PHP_EOL;$blocked=true;}
 $foreignKeys=$db->query("SELECT k.COLUMN_NAME,k.REFERENCED_TABLE_NAME,k.REFERENCED_COLUMN_NAME,r.DELETE_RULE FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME AND r.TABLE_NAME=k.TABLE_NAME WHERE k.TABLE_SCHEMA=DATABASE() AND k.TABLE_NAME='physician_queue' AND k.REFERENCED_TABLE_NAME IS NOT NULL")->fetchAll();
 foreach(['queue_id'=>'queue_numbers','patient_user_id'=>'users','referred_by'=>'users','assigned_doctor_id'=>'users','consultation_id'=>'consultations'] as $column=>$parent){$found=false;foreach($foreignKeys as $fk)if($fk['COLUMN_NAME']===$column&&$fk['REFERENCED_TABLE_NAME']===$parent&&$fk['REFERENCED_COLUMN_NAME']==='id'&&in_array($fk['DELETE_RULE'],['RESTRICT','NO ACTION'],true))$found=true;if(!$found){echo 'BLOCK missing restrictive physician_queue foreign key: '.$column.PHP_EOL;$blocked=true;}}
}else {$missing=true;echo 'ADD physician_queue using database/migration_physician_queue_hostinger_20261030.sql after backup and restore verification.'.PHP_EOL;}
echo $blocked?'BLOCK: resolve prerequisites; no writes were performed.'.PHP_EOL:'Metadata prerequisites present. This is not verification of backup, data or production behavior; no writes were performed.'.PHP_EOL;
if($missing)echo 'NOT READY: required deployment tables are missing; no writes were performed.'.PHP_EOL;
exit($blocked?1:($missing?2:0));
