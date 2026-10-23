<?php
require_once __DIR__.'/security_monitoring.php';
final class SecurityIncidentService {
 public function __construct(private PDO $db){}
 public function transition(int $actor,int $event,string $stage,string $notes): void {
  if(!in_array($stage,['detected','under_review','action_taken','resolved'],true)||mb_strlen(trim($notes))<15||mb_strlen($notes)>255)throw new InvalidArgumentException('Select a stage and document findings in 15–255 characters.');
  $this->db->beginTransaction();
  try {
   $q=$this->db->prepare("SELECT * FROM users WHERE id=? AND role='admin' AND status='active' FOR UPDATE");$q->execute([$actor]);$u=$q->fetch();
   if(!$u||!has_admin_permission('manage_security',$u,$this->db))throw new RuntimeException('Security review authorization required.');
   $q=$this->db->prepare('SELECT * FROM account_security_events WHERE id=? FOR UPDATE');$q->execute([$event]);$e=$q->fetch();if(!$e)throw new RuntimeException('Event unavailable.');
   $q=$this->db->prepare('SELECT COUNT(*) FROM account_security_events WHERE subject_key=? AND event_type=? AND occurred_at BETWEEN DATE_SUB(?,INTERVAL 24 HOUR) AND ?');$q->execute([$e['subject_key'],$e['event_type'],$e['occurred_at'],$e['occurred_at']]);
   $severity=SecurityMonitoringService::severity($e['event_type'],(int)$q->fetchColumn());
   if(!in_array($severity,['medium','high','critical'],true))throw new RuntimeException('This event does not warrant a security incident.');
   $q=$this->db->prepare('SELECT * FROM security_incidents WHERE event_id=? FOR UPDATE');$q->execute([$event]);$incident=$q->fetch();
   $expected=['detected'=>'under_review','under_review'=>'action_taken','action_taken'=>'resolved'];
   if(!$incident){if($stage!=='detected')throw new RuntimeException('Record detection before reviewing an incident.');
    $this->db->prepare('INSERT INTO security_incidents(event_id,severity,reviewer_id,outcome) VALUES (?,?,?,?)')->execute([$event,$severity,$actor,trim($notes)]);
   }else{
    if(($expected[$incident['stage']]??null)!==$stage)throw new RuntimeException('Follow the incident stages in order. Resolved incidents are immutable.');
    $this->db->prepare("UPDATE security_incidents SET stage=?,reviewer_id=?,outcome=?,updated_at=NOW(),resolved_at=IF(?='resolved',NOW(),NULL) WHERE id=?")->execute([$stage,$actor,trim($notes),$stage,$incident['id']]);
   }
   $this->db->prepare("INSERT INTO activity_logs(user_id,action,module,details) VALUES (?,'Security incident transition','security',?)")->execute([$actor,mb_substr('Event #'.$event.'; '.$stage.'; '.trim($notes),0,255)]);$this->db->commit();
  }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
 }
}
