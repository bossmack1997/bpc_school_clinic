<?php
if(!isset($database)||!preg_match('/^bpc_mis_test_[a-f0-9]{10}$/D',$database)||$db->query('SELECT DATABASE()')->fetchColumn()!==$database)throw new RuntimeException('Disposable claim fixture required.');
$db->prepare("INSERT INTO users(username,email,password_hash,first_name,last_name,role,admin_type,status,email_verified,registration_status) VALUES ('race_physician','race_physician@example.invalid','unused','Race','Physician','admin','co_admin','active',1,'approved')")->execute();$raceDoctor=(int)$db->lastInsertId();
$db->prepare("INSERT INTO admin_profiles(user_id,profile) VALUES (?,'doctor')")->execute([$raceDoctor]);
$db->prepare("INSERT INTO queue_numbers(queue_number,student_id,patient_type,priority,status) VALUES ('RACE-PHYSICIAN',?,'student','normal','serving')")->execute([$studentId]);$raceTicket=(int)$db->lastInsertId();
$db->prepare("INSERT INTO walk_in_visits(queue_id,student_id,visit_number,visit_date,arrival_time,chief_complaint,assessment,status) VALUES (?,?,'RACE-INTAKE',CURDATE(),CURTIME(),'Synthetic claim race','Saved synthetic nursing assessment','in_consultation')")->execute([$raceTicket,$studentId]);
$referral=misRequest($nurse,'/api/physician_queue.php',['action'=>'refer','queue_id'=>$raceTicket,'reason'=>'Synthetic concurrency regression','priority'=>'normal','csrf_token'=>$nurseToken]);$raceId=(int)(json_decode($referral['body'],true)['id']??0);
checkMis($referral['status']===200&&$raceId>0,'Nurse creates synthetic referral for simultaneous Doctor claims');
$workers=[];
try{
 foreach([$doctor,$raceDoctor] as $claimant){$pipes=[];$process=proc_open([PHP_BINARY,'-d','session.save_path='.__DIR__.'/../scratch',__DIR__.'/physician_claim_worker.php',(string)$raceId,(string)$claimant],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);$workers[]=['process'=>$process,'pipes'=>$pipes];checkMis(trim(fgets($pipes[1]))==='READY','Concurrent physician worker is ready on a separate database connection');}
 foreach($workers as $worker){fwrite($worker['pipes'][0],"GO\n");fclose($worker['pipes'][0]);}
 $outcomes=[];
 foreach($workers as &$worker){$outcomes[]=trim(stream_get_contents($worker['pipes'][1]));fclose($worker['pipes'][1]);$stderr=stream_get_contents($worker['pipes'][2]);fclose($worker['pipes'][2]);$exit=proc_close($worker['process']);$worker['process']=null;checkMis($exit===0&&$stderr==='','Concurrent physician worker completes without database errors');}unset($worker);
 sort($outcomes);checkMis($outcomes===['CLAIMED','CONFLICT'],'Two simultaneous Doctor claims produce exactly one owner and one conflict');
 $state=$db->query('SELECT status,record_version,assigned_doctor_id FROM physician_queue WHERE id='.$raceId)->fetch();
 checkMis($state['status']==='in_progress'&&(int)$state['record_version']===1&&in_array((int)$state['assigned_doctor_id'],[$doctor,$raceDoctor],true),'Concurrent claim increments version once and retains the winning physician');
 $audit=$db->prepare("SELECT COUNT(*) FROM activity_logs WHERE action='Physician assessment started' AND details=?");$audit->execute(['Physician handoff #'.$raceId.'; clinical contents remain in the authorized record.']);checkMis((int)$audit->fetchColumn()===1,'Concurrent claim creates exactly one successful clinical audit event');
}finally{foreach($workers as $worker)if(is_resource($worker['process'])){proc_terminate($worker['process']);proc_close($worker['process']);}}
