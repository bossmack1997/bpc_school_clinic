<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
ini_set('session.save_path',__DIR__);
require __DIR__.'/../config/config.php';require __DIR__.'/../includes/patient_activity.php';
$db=getDB();
foreach(['student','teacher'] as $role){
 $q=$db->prepare("SELECT id,role FROM users WHERE role=? AND status='active' LIMIT 1");$q->execute([$role]);$user=$q->fetch();
 $scope=patient_activity_scope($db,$user,[]);
 $scans=patient_activity_scans($db,$scope);$timeline=patient_activity_timeline($db,$scope);
 $all=[];for($offset=0;$offset<$timeline['total'];$offset+=10){$page=patient_activity_timeline($db,$scope,$offset,10);foreach($page['timeline'] as $event)$all[]=$event['event_key'];}
 if(count($all)!==$timeline['total']||count(array_unique($all))!==count($all))throw new RuntimeException('Timeline pagination lost or duplicated events.');
 if(count($scans['data'])>5||count($timeline['timeline'])>10)throw new RuntimeException('Pagination exceeded page size.');
 foreach([$scans['data'],$timeline['timeline']] as $index=>$rows){$last=PHP_INT_MAX;foreach($rows as $row){$time=strtotime($row[$index===0?'scan_time':'timestamp']);if($time>$last)throw new RuntimeException('History order');$last=$time;}}
 try{patient_activity_scope($db,$user,['patient_id'=>$scope['id']+100000]);throw new LogicException('Unauthorized patient accepted');}catch(RuntimeException $e){if($e->getCode()!==403)throw $e;}
 echo 'PASS '.$role.': own scope, ID tampering denied, scans='.$scans['total'].', events='.$timeline['total'].', newest-first and page limits.'.PHP_EOL;
}
$before=(int)$db->query('SELECT COUNT(*) FROM qr_scan_logs')->fetchColumn();
$db->beginTransaction();
try{
 $teacherId=(int)$db->query('SELECT id FROM teachers ORDER BY id LIMIT 1')->fetchColumn();
 $nurseId=(int)$db->query("SELECT id FROM users WHERE role='nurse' LIMIT 1")->fetchColumn();
 $scope=['type'=>'teacher','id'=>$teacherId];$initial=patient_activity_scans($db,$scope)['total'];
 log_qr_scan($db,null,'local-transaction-test','check_in',$nurseId,'success','Clinic Test',null,['queue_number'=>'QA'], 'camera',$teacherId);
 $history=patient_activity_scans($db,$scope);
 if($history['total']!==$initial+1||$history['data'][0]['queue_number']!=='QA')throw new RuntimeException('Teacher QR identity/reference logging failed.');
 log_qr_scan($db,null,'local-transaction-test','check_in',$nurseId,'success','Clinic Test',null,null,'walk_in',$teacherId);
 if(patient_activity_scans($db,$scope)['total']!==$initial+1)throw new RuntimeException('Manual walk-in incorrectly counted as QR scan.');
 $db->rollBack();
}catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
if((int)$db->query('SELECT COUNT(*) FROM qr_scan_logs')->fetchColumn()!==$before)throw new RuntimeException('Historical records changed.');
echo 'PASS: teacher scan logging, queue reference, manual-walk-in exclusion, full timeline pagination; '.$before.' existing scan logs preserved (test writes rolled back).'.PHP_EOL;
