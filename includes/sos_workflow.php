<?php
/** SOS mutations share one transaction, lock order, audit contract and notification boundary. */
function sos_fail(int $status, string $message, string $code): never {
    if (getDB()->inTransaction()) getDB()->rollBack();
    http_response_code($status);
    json_response(false, ['error_code'=>$code], $message);
}
function sos_accuracy($value): ?float {
    // Old clients send labels such as "500 meters". Keep these readable.
    if (!is_scalar($value) || !preg_match('/^\s*(\d+(?:\.\d+)?)\s*(?:m|meters)?\s*$/i', (string)$value, $m)) return null;
    $n=(float)$m[1]; return is_finite($n) && $n>=0 ? $n : null;
}
function sos_audit(PDO $db, array $user, int $id, string $from, string $to): void {
    $db->prepare('INSERT INTO activity_logs (user_id, action, module, details, ip_address) VALUES (?, ?, ?, ?, ?)')
       ->execute([$user['id'], $from===''?'SOS created':'SOS '.$to, 'emergency', json_encode(['actor_role'=>$user['role'],'emergency_id'=>$id,'from'=>$from,'to'=>$to]), $_SERVER['REMOTE_ADDR']??null]);
}
function sos_notify(PDO $db, int $id, string $status, int $reporter): void {
    // Persistence has committed. Push failures must never turn a saved SOS into a failed submission.
    try {
        $q=$db->prepare("SELECT id, role FROM users WHERE status='active' AND (role IN ('nurse','admin') OR id=?)");
        $q->execute([$reporter]);
        foreach($q->fetchAll() as $recipient) {
            $folder=['teacher'=>'adviser','student'=>'student','nurse'=>'nurse','admin'=>'admin'][$recipient['role']];
            $link=APP_URL.'/pages/'.$folder.'/'.(in_array($recipient['role'],['nurse','admin','teacher'],true)?'emergencies.php':'dashboard.php');
            try { push_notification((int)$recipient['id'],'emergency','SOS '.$status,'Emergency #'.$id.' is '.$status.'.',$link,$id,'emergency'); }
            catch(Throwable $e) { error_log('SOS notification '.$id.': '.$e->getMessage()); }
        }
    } catch(Throwable $e) { error_log('SOS recipients '.$id.': '.$e->getMessage()); }
}
function sos_create(PDO $db, array $user, array $input): array {
    $type=trim((string)($input['emergency_type']??'Emergency Assistance'));
    $description=trim((string)($input['description']??'Emergency assistance requested'));
    $location=trim((string)($input['location']??''));
    if ($type==='' || mb_strlen($type)>100 || mb_strlen($description)>2000 || mb_strlen($location)>255) sos_fail(422,'Check the emergency description and location.','SOS_INVALID_DETAILS');
    $lat=filter_var($input['latitude']??null,FILTER_VALIDATE_FLOAT);
    $lng=filter_var($input['longitude']??null,FILTER_VALIDATE_FLOAT);
    if ($lat===false || $lng===false || $lat < -90 || $lat>90 || $lng < -180 || $lng>180) {$lat=null;$lng=null;}
    $accuracy=sos_accuracy($input['accuracy']??$input['location_accuracy']??null);
    $db->beginTransaction();
    // Serialize submissions across separate sessions for the same reporter.
    $q=$db->prepare('SELECT id FROM users WHERE id=? FOR UPDATE');$q->execute([$user['id']]);
    $q=$db->prepare("SELECT id AS emergency_id,status,latitude,longitude FROM sos_emergencies WHERE user_id=? AND source='sos' AND (status IN ('new','active','acknowledged','responding','responded') OR created_at>=DATE_SUB(NOW(), INTERVAL 2 MINUTE)) ORDER BY id DESC LIMIT 1 FOR UPDATE");
    $q->execute([$user['id']]);
    if ($row=$q->fetch()) {$db->commit();$row['tracking_active']=in_array($row['status'],['new','active','acknowledged','responding','responded'],true);return $row;}
    $q=$db->prepare('SELECT id FROM students WHERE user_id=? LIMIT 1');$q->execute([$user['id']]);$student=$q->fetchColumn()?:null;
    $q=$db->prepare("INSERT INTO sos_emergencies (user_id,student_id,emergency_type,description,location,location_source,latitude,longitude,location_accuracy,location_updated_at,status,priority,source,reporter_role,ip_address,user_agent) VALUES (?,?,?,?,?,?,?,?,?,?,'active','urgent','sos',?,?,?)");
    $q->execute([$user['id'],$student,$type,$description,$location?:null,$lat!==null?'gps':'manual',$lat,$lng,$accuracy,$lat!==null?date('Y-m-d H:i:s'):null,$user['role'],$_SERVER['REMOTE_ADDR']??null,$_SERVER['HTTP_USER_AGENT']??null]);
    $id=(int)$db->lastInsertId();
    if($lat!==null)$db->prepare('INSERT INTO emergency_location_updates (emergency_id,latitude,longitude,accuracy) VALUES (?,?,?,?)')->execute([$id,$lat,$lng,$accuracy]);
    sos_audit($db,$user,$id,'','active');$db->commit();
    sos_notify($db,$id,'active',(int)$user['id']);
    return ['emergency_id'=>$id,'status'=>'active','tracking_active'=>true,'latitude'=>$lat,'longitude'=>$lng];
}
function sos_transition(PDO $db, array $user, int $id, string $op, array $input, bool $self=false): array {
    if($id<=0 || !in_array($op,['accept','acknowledge','respond','resolve','cancel'],true))sos_fail(400,'Invalid emergency action.','SOS_INVALID_ACTION');
    if(!$self && $user['role'] !== 'nurse') {
        log_activity($user['id'],'SOS unauthorized attempt','emergency',json_encode(['actor_role'=>$user['role'],'emergency_id'=>$id,'operation'=>$op]));
        sos_fail(403,'Only nurses can change this emergency.','SOS_FORBIDDEN');
    }
    $notes=trim((string)($input['resolution_notes']??$input['reason']??''));
    if(mb_strlen($notes)>2000)sos_fail(422,'Notes must be at most 2,000 characters.','SOS_INVALID_NOTES');
    $db->beginTransaction();
    $q=$db->prepare('SELECT * FROM sos_emergencies WHERE id=? FOR UPDATE');$q->execute([$id]);$em=$q->fetch();
    if(!$em)sos_fail(404,'Emergency not found.','SOS_NOT_FOUND');
    if($self && (int)$em['user_id']!==(int)$user['id'])sos_fail(403,'You cannot cancel this emergency.','SOS_FORBIDDEN');
    $target=['accept'=>'acknowledged','acknowledge'=>'acknowledged','respond'=>'responding','resolve'=>'resolved','cancel'=>'cancelled'][$op];
    $owner=(int)($em['assigned_to']?:$em['responded_by']?:$em['acknowledged_by']);
    if(!$self && $owner && $owner!==(int)$user['id'])sos_fail(409,'This emergency is assigned to another responder.','SOS_ALREADY_ACCEPTED');
    if($em['status']===$target) {$db->commit();return ['emergency_id'=>$id,'status'=>$target];}
    if(!in_array($em['status'],['new','active','acknowledged','responding','responded'],true))sos_fail(409,'This emergency is already closed.','SOS_CLOSED');
    if($self && !in_array($em['status'],['new','active'],true))sos_fail(409,'A responder has accepted this SOS. Contact the clinic to cancel.','SOS_ALREADY_ACCEPTED');
    if(in_array($op,['accept','acknowledge'],true) && !in_array($em['status'],['new','active'],true))sos_fail(409,'This emergency has already been accepted.','SOS_ALREADY_ACCEPTED');
    // Existing clients do not all prompt for notes; preserve a recorded cancellation reason.
    if($op==='cancel' && $notes==='')$notes=$self?'Cancelled by reporting user':'Cancelled by clinic responder';
    if($op==='resolve' && $notes==='')sos_fail(422,'Enter resolution notes before closing the emergency.','SOS_NOTES_REQUIRED');
    if(in_array($op,['accept','acknowledge'],true)) {
        $db->prepare("UPDATE sos_emergencies SET status=?, assigned_to=?, acknowledged_by=?, acknowledged_at=NOW(), updated_at=NOW() WHERE id=?")->execute([$target,$user['id'],$user['id'],$id]);
    } elseif($op==='respond') {
        $db->prepare("UPDATE sos_emergencies SET status=?, assigned_to=?, responded_by=?, responded_at=COALESCE(responded_at,NOW()), updated_at=NOW() WHERE id=?")->execute([$target,$user['id'],$user['id'],$id]);
    } elseif($op==='resolve') {
        $db->prepare("UPDATE sos_emergencies SET status=?, assigned_to=COALESCE(assigned_to,?), resolved_at=NOW(), resolution_notes=?, updated_at=NOW() WHERE id=?")->execute([$target,$user['id'],$notes,$id]);
    } else {
        $db->prepare("UPDATE sos_emergencies SET status=?, cancelled_by=?, cancelled_at=NOW(), notes=?, updated_at=NOW() WHERE id=?")->execute([$target,$user['id'],$notes,$id]);
    }
    sos_audit($db,$user,$id,$em['status'],$target);$db->commit();
    sos_notify($db,$id,$target,(int)$em['user_id']);
    $q=$db->prepare('SELECT acknowledged_at, responded_at, resolved_at, cancelled_at FROM sos_emergencies WHERE id=?');$q->execute([$id]);
    return array_merge($q->fetch(), ['emergency_id'=>$id,'status'=>$target,'assigned_to'=>$self?$owner:(int)$user['id'],'responder_name'=>$self?null:trim(($user['first_name']??'').' '.($user['last_name']??'')),'tracking_active'=>!in_array($target,['resolved','cancelled'],true)]);
}
