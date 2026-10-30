<?php
/** Read-only schema checks. No migration is run by this CLI command. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../config/config.php';
$db=getDB();$schema=$db->query('SELECT DATABASE()')->fetchColumn();
$blocked=false;$missingDeploymentTables=[];
echo 'Selected database: '.$schema.PHP_EOL;
$required=[
 'users'=>['id','role','status','credential_version','username','email','password_hash','email_verified','email_verified_at','self_service_only','registration_status'],
 'students'=>['id','user_id'], 'teachers'=>['id','user_id'],
 'nurses'=>['user_id','clinical_authorization_status'],
 'queue_numbers'=>['id','student_id','teacher_id','patient_type','status'],
 'walk_in_visits'=>['id','queue_id','assessment','temperature','respiratory_rate','oxygen_saturation'],
 'consultations'=>['id','student_id','teacher_id','clinician_user_id','record_version','vital_signs','follow_up_date'],
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
foreach(['account_activation_invitations'=>['id','user_id','token_hash','created_by','expires_at','used_at','delivery_status','created_at'],'account_provisioning_lock'=>['id']] as $table=>$requiredColumns){
 $q=$db->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND BINARY TABLE_NAME=BINARY ?');$q->execute([$table]);$columns=$q->fetchAll(PDO::FETCH_COLUMN);
 if(!$columns){$missingDeploymentTables[]=$table;echo 'ADD '.$table.' using the account provisioning migration.'.PHP_EOL;continue;}
 foreach(array_diff($requiredColumns,$columns) as $column){echo 'BLOCK existing '.$table.' missing '.$column.PHP_EOL;$blocked=true;}
 echo 'REVIEW existing '.$table.' engine, types and constraints against the migration.'.PHP_EOL;
}
$q=$db->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND BINARY TABLE_NAME=BINARY 'physician_queue'");$q->execute([$schema]);
if($q->fetchColumn()){
 $q=$db->query('SHOW COLUMNS FROM physician_queue');$columns=$q->fetchAll(PDO::FETCH_COLUMN);
 foreach(['id','queue_id','patient_user_id','referred_by','assigned_doctor_id','consultation_id','priority','reason','status','record_version','created_at','started_at','completed_at'] as $column)if(!in_array($column,$columns,true)){echo 'BLOCK existing physician_queue missing '.$column.PHP_EOL;$blocked=true;}
 echo 'REVIEW existing physician_queue definition, constraints and indexes against the migration; IF NOT EXISTS does not repair an incompatible table.'.PHP_EOL;
}else {$missingDeploymentTables[]='physician_queue';echo 'ADD physician_queue after backup and restore verification.'.PHP_EOL;}
echo $blocked?'BLOCK: resolve prerequisites; no writes were performed.'.PHP_EOL:'Metadata prerequisites present. This is not verification of backup, data or production behavior; no writes were performed.'.PHP_EOL;
if($missingDeploymentTables)echo 'MIGRATIONS REQUIRED: '.implode(', ',$missingDeploymentTables).'. Clinical readiness is not confirmed.'.PHP_EOL;
// Deployment automation can require installed tables; default remains a
// backwards-compatible prerequisite check before additive migrations.
exit($blocked?1:($missingDeploymentTables&&in_array('--require-ready',$argv,true)?2:0));
