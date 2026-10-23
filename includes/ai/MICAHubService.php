<?php
/** Verified monitoring. No patient identities or clinical writes. See docs/mica-hub-audit.md. */
require_once __DIR__.'/MICAActionService.php';
require_once __DIR__.'/../medicine_inventory_service.php';
require_once __DIR__.'/../account_monitoring.php';
final class MICAHubService
{
    public function __construct(private PDO $db, private array $user) { date_default_timezone_set('Asia/Manila'); $this->db->exec("SET time_zone = '+08:00'"); }

    public function scan(): array
    {
        if (!in_array(canonical_role($this->user['role']??''), ['admin','nurse'], true)) throw new RuntimeException('Access denied.');
        if ((int)$this->db->query("SELECT GET_LOCK('bpc_mica_hub_scan',0)")->fetchColumn() !== 1) throw new RuntimeException('Another intelligence scan is running. Retry shortly.');
        $start=microtime(true); $scanId=null; $writerLocked=false;
        try {
            $this->db->exec("INSERT INTO mica_scans (started_at,status) VALUES (NOW(),'running')");
            $scanId=(int)$this->db->lastInsertId();
            // Read every monitored source before changing any alerts. Failure never means zero.
            $writerLocked=(int)$this->db->query("SELECT GET_LOCK('bpc_mica_action_write',5)")->fetchColumn()===1;
            if(!$writerLocked)throw new RuntimeException('Alert writer is busy. Retry shortly.');
            $this->db->beginTransaction();
            $conditions=$this->conditions();
            $service=new MICAActionService($this->db,$this->user);
            $stats=['evaluated'=>count($conditions),'new'=>0,'updated'=>0,'resolved'=>0,'invalidated'=>0];
            $seen=[];
            foreach($conditions as $c) {
                $q=$this->db->prepare("SELECT * FROM ai_actions WHERE action_type=? AND target_type=? AND target_role=? AND target_id <=> ? AND status IN ('new','in_progress') ORDER BY id");
                $q->execute([$c['action_type'],$c['target_type'],$c['target_role'],$c['target_id']??null]);
                $prior=$q->fetchAll();
                $id=$service->createAction($c); $seen[]=$id;
                $events=$this->db->prepare('SELECT COUNT(*) FROM mica_alert_events WHERE action_id=?');$events->execute([$id]);
                if(!(int)$events->fetchColumn())$this->event($id,'detected','Monitoring history begins with this successful source verification.');
                $this->event($id,'validated',$c['reason'].' '.$c['description']);
                $notified=$this->db->prepare("SELECT COUNT(*) FROM mica_alert_events WHERE action_id=? AND event_type='notification_created'");$notified->execute([$id]);
                if(!(int)$notified->fetchColumn()) {
                    $notify=$this->db->prepare("INSERT INTO notifications (user_id,type,title,message,link) SELECT id,'mica_hub',?,?,? FROM users WHERE status='active' AND role=?");
                    $notify->execute([$c['title'],mb_substr($c['description'],0,255),APP_URL.'/pages/ai_assistant.php?alert_id='.$id,$c['target_role']]);
                    if($notify->rowCount())$this->event($id,'notification_created',$notify->rowCount().' notification(s) persisted for active users in the responsible role. Delivery/read is separate.');
                }
                if (!$prior) {
                    $stats['new']++;
                } else {
                    if ($prior[0]['title']!==$c['title'] || $prior[0]['description']!==$c['description'] || $prior[0]['priority']!==$c['priority']) $stats['updated']++;
                    foreach(array_slice($prior,1) as $duplicate) { $this->close((int)$duplicate['id'],'invalidated','Equivalent active alert retained as #'.$id); $stats['invalidated']++; }
                }
            }
            $managed=['pending_review','registration_review','emergency_triage','hub_low_stock','expiring_medicine','low_stock_reorder','security_anomaly','inventory_anomaly','graduating_student','account_invalid_registration','account_identity_failure','account_duplicate_indicator','account_otp_failure','account_exception','duplicate_account'];
            $q=$this->db->query("SELECT id,action_type FROM ai_actions WHERE status IN ('new','in_progress')");
            foreach($q->fetchAll() as $row) if(in_array($row['action_type'],$managed,true) && !in_array((int)$row['id'],$seen,true)) {
                $invalid=in_array($row['action_type'],['registration_review','low_stock_reorder'],true);
                if($row['action_type']==='security_anomaly')$invalid=true;
                $this->close((int)$row['id'],$invalid?'invalidated':'resolved',$invalid?'Superseded by verified condition monitoring; related workflow records are preserved.':'Condition absent in a complete successful source verification.');
                $stats[$invalid?'invalidated':'resolved']++;
            }
            $stats['duration_ms']=(int)round((microtime(true)-$start)*1000);
            $this->db->prepare("UPDATE mica_scans SET status='success',completed_at=NOW(),duration_ms=?,result_json=? WHERE id=?")->execute([$stats['duration_ms'],json_encode($stats),$scanId]);
            $this->db->commit(); return $stats;
        } catch(Throwable $e) {
            if($this->db->inTransaction())$this->db->rollBack();
            if($scanId)$this->db->prepare("UPDATE mica_scans SET status='failed',completed_at=NOW(),result_json=? WHERE id=?")->execute([json_encode(['error'=>'Source verification or persistence failed. No alert reconciliation committed.']),$scanId]);
            throw $e;
        } finally { if($writerLocked)$this->db->query("SELECT RELEASE_LOCK('bpc_mica_action_write')"); $this->db->query("SELECT RELEASE_LOCK('bpc_mica_hub_scan')"); }
    }
    private function conditions(): array
    {
        $out=[];
        $add=function($type,$entity,$id,$role,$title,$description,$reason,$severity)use(&$out){$out[]=['action_type'=>$type,'target_type'=>$entity,'target_id'=>$id,'target_role'=>$role,'title'=>$title,'description'=>$description,'reason'=>$reason,'priority'=>$severity];};
        $pending=(new AccountMonitoringService($this->db))->counts()['verification'];
        if($pending)$add('pending_review','user',null,'admin',"$pending Account(s) Requiring Verification","$pending account(s) have pending email verification, activation or invitation setup.","Verified users.status = pending. Student, Teacher and Nurse basic accounts activate through OTP; investigate security concerns separately.",'high');
        $sos=$this->db->query("SELECT id,status FROM sos_emergencies WHERE status IN ('active','new','acknowledged','responding')")->fetchAll();
        foreach($sos as $s)$add('emergency_triage','sos_emergency',(int)$s['id'],'nurse','Active SOS #'.$s['id'],'Emergency status: '.$s['status'].'. Open Emergency Response.','Verified nonterminal SOS status. Only clinical personnel handle the incident.','critical');
        $items=(new MedicineInventoryService($this->db))->snapshot();
        foreach($items as $m) {
            if(in_array($m['stock_status'],['LOW STOCK','OUT OF STOCK'],true))$add('hub_low_stock','medicine',(int)$m['id'],'nurse','Stock warning: '.$m['medicine_name'],$m['usable_stock'].' '.$m['unit'].' usable; threshold '.($m['reorder_level']??'not configured').'.','Verified usable stock against configured minimum. Nurse reviews replenishment.',$m['usable_stock']<=0?'high':'medium');
            if($m['quantity']>0 && !empty($m['expiration_date'])) {
                $days=(int)(new DateTimeImmutable(date('Y-m-d')))->diff(new DateTimeImmutable($m['expiration_date']))->format('%r%a');
                if($days<=30)$add('expiring_medicine','medicine',(int)$m['id'],'nurse',($days<0?'Expired: ':'Approaching expiry: ').$m['medicine_name'],'Recorded expiry '.$m['expiration_date'].'; '.$m['quantity'].' '.$m['unit'].' recorded.','Positive recorded stock; expiry within 30 days or already past. Nurse verifies batch handling.',$days<0?'critical':($days<=7?'high':'medium'));
            }
        }
        foreach($this->db->query("SELECT id,failed_login_count FROM users WHERE failed_login_count>=5 AND last_failed_login>=DATE_SUB(NOW(),INTERVAL 24 HOUR)")->fetchAll() as $u) {
            $add('security_anomaly','user',(int)$u['id'],'admin','Authentication review: account #'.$u['id'],$u['failed_login_count'].' failed login attempts recorded.','Verified at least 5 failures with a failed attempt in the last 24 hours. Review security logs.','high');
        }
        foreach($this->db->query("SELECT id FROM students WHERE lifecycle_status='graduating' AND EXISTS(SELECT 1 FROM users WHERE users.id=students.user_id AND users.status='active')")->fetchAll() as $st) {
            $add('graduating_student','student',(int)$st['id'],'admin','Graduation review: student record #'.$st['id'],'Record explicitly marked graduating.','Verified lifecycle_status=graduating and active account. Administration verifies eligibility; no automatic deactivation.','informational');
        }
        foreach($this->db->query("SELECT ml.medicine_id,SUM(ml.quantity) AS total_out FROM medicine_logs ml JOIN medicines m ON m.id=ml.medicine_id WHERE ml.action='stock_out' AND ml.performed_at>=DATE_SUB(NOW(),INTERVAL 48 HOUR) AND m.is_archived=0 GROUP BY ml.medicine_id HAVING total_out>=40")->fetchAll() as $log) {
            $add('inventory_anomaly','medicine',(int)$log['medicine_id'],'nurse','Dispensing volume review: medicine #'.$log['medicine_id'],$log['total_out'].' units recorded as stock out in 48 hours.','Fixed review threshold: at least 40 units in 48 hours. This is a rule, not a statistical anomaly or clinical conclusion.','medium');
        }
        foreach($this->db->query("SELECT event_type,COUNT(*) AS total,COUNT(DISTINCT subject_key) AS subjects FROM account_security_events WHERE occurred_at>=DATE_SUB(NOW(),INTERVAL 24 HOUR) AND event_type IN ('invalid_registration','identity_failure','duplicate_indicator','otp_failure') GROUP BY event_type")->fetchAll() as $signal){
            $add('account_'.$signal['event_type'],'registration_signal',null,'admin','Registration security: '.str_replace('_',' ',$signal['event_type']),$signal['total'].' recorded event(s), '.$signal['subjects'].' distinct account indicator(s) in 24 hours.','Verified persisted validation/security events in the rolling 24-hour window; not proof of malicious intent. Review security evidence.','medium');
        }
        foreach($this->db->query("SELECT id,user_id FROM account_security_exceptions WHERE status='open'")->fetchAll() as $ex){
            $add('account_exception','security_exception',(int)$ex['id'],'admin','Unresolved account security exception #'.$ex['id'],'Account #'.$ex['user_id'].' has an open documented exception.','Verified account_security_exceptions.status=open. Authorized personnel review evidence; MICA cannot decide access.','high');
        }
        foreach(['students'=>'student_number','teachers'=>'employee_id','nurses'=>'employee_id'] as $table=>$field){
            foreach($this->db->query("SELECT MIN(user_id) AS user_id,COUNT(*) AS total FROM $table WHERE $field IS NOT NULL AND $field<>'' GROUP BY $field HAVING COUNT(*)>1")->fetchAll() as $dup){
                $add('duplicate_account','user',(int)$dup['user_id'],'admin','Duplicate institutional identifier indicator',$dup['total'].' '.$table.' records share an institutional identifier.','Verified duplicate identifier group; investigation required. No account is disabled automatically.','high');
            }
        }
        return $out;
    }
    private function close(int $id,string $status,string $reason): void {
        $this->db->prepare("UPDATE ai_actions SET status='resolved',executed_at=NOW(),executed_by=NULL WHERE id=?")->execute([$id]);
        $this->event($id,$status,$reason);
    }
    private function event(int $id,string $type,string $details): void {
        // Repeated validations with unchanged evidence do not fabricate new progress.
        $q=$this->db->prepare('SELECT details FROM mica_alert_events WHERE action_id=? AND event_type=? ORDER BY id DESC LIMIT 1');$q->execute([$id,$type]);
        if($q->fetchColumn()===$details)return;
        $this->db->prepare('INSERT INTO mica_alert_events (action_id,event_type,details) VALUES (?,?,?)')->execute([$id,$type,$details]);
    }
    public function lastAttempt(): mixed { return $this->db->query('SELECT * FROM mica_scans ORDER BY id DESC LIMIT 1')->fetch()?:null; }
    public function lastScan(): mixed { return $this->db->query("SELECT * FROM mica_scans WHERE status='success' ORDER BY id DESC LIMIT 1")->fetch()?:null; }
    public function detail(int $id,bool $markRead=false): ?array {
        // Same role and inventory policy as the list, including archived rows.
        $q=$this->db->prepare('SELECT * FROM ai_actions WHERE id=?');$q->execute([$id]);$a=$q->fetch();
        $role=canonical_role($this->user['role']??'');
        if(!$a||!in_array($role,['admin','nurse'],true)||!in_array($a['target_role'],[$role,'all'],true))return null;
        if(in_array($a['target_type'],['medicine','medicines','inventory'],true)&&!can_view_inventory($this->user,$this->db))return null;
        if($markRead) {
            $this->db->prepare('INSERT IGNORE INTO mica_alert_reads (action_id,user_id) VALUES (?,?)')->execute([$id,$this->user['id']]);
            $this->db->prepare("UPDATE notifications SET is_read=1 WHERE user_id=? AND type='mica_hub' AND link=?")->execute([$this->user['id'],APP_URL.'/pages/ai_assistant.php?alert_id='.$id]);
        }
        $q=$this->db->prepare('SELECT event_type,details,created_at FROM mica_alert_events WHERE action_id=? ORDER BY id');$q->execute([$id]);
        $a['events']=$q->fetchAll();
        $last=end($a['events']);
        $a['issue_status']=$last && $last['event_type']==='invalidated'?'invalidated':($a['status']==='resolved'?'resolved':($a['status']==='in_progress'?'monitoring':'active'));
        $a['navigation_url']=APP_URL.($a['target_type']==='medicine'?'/pages/medicine_requisition.php':($a['target_type']==='sos_emergency'?'/pages/nurse/emergencies.php':($a['target_type']==='security_exception'?'/pages/admin/account_exceptions.php':'/pages/admin/users.php'))); $a['evidence_note']='Evidence from the last successful scan; run a scan to verify current source state.';
        return $a;
    }
}
