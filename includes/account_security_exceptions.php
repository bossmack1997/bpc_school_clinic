<?php
require_once __DIR__.'/account_monitoring.php';
final class AccountSecurityExceptionService
{
    public function __construct(private PDO $db) {date_default_timezone_set('Asia/Manila');$this->db->exec("SET time_zone='+08:00'");}
    private function actor(int $id): array {
        $q=$this->db->prepare("SELECT * FROM users WHERE id=? AND role='admin' AND status='active' FOR UPDATE");$q->execute([$id]);$u=$q->fetch();
        if(!$u||(!is_primary_admin($u)&&!(has_admin_permission('manage_security',$u,$this->db)&&has_admin_permission('manage_users',$u,$this->db))))throw new RuntimeException('Security and user-management authorization required.');
        return $u;
    }
    public function open(int $actor,int $target,string $reason,string $evidence): int {
        if(mb_strlen(trim($reason))<15||mb_strlen(trim($evidence))<5||mb_strlen($reason)>2000||mb_strlen($evidence)>255)throw new InvalidArgumentException('Provide a specific reason (15–2000 characters) and evidence reference (5–255 characters).');
        $this->db->beginTransaction();
        try {
            $a=$this->actor($actor);$q=$this->db->prepare('SELECT * FROM users WHERE id=? FOR UPDATE');$q->execute([$target]);$u=$q->fetch();
            if(!$u||$target===$actor||($u['role']==='admin'&&(!is_primary_admin($a)||is_primary_admin($u))))throw new RuntimeException('This target is protected or unavailable.');
            $q=$this->db->prepare("SELECT id FROM account_security_exceptions WHERE user_id=? AND status='open'");$q->execute([$target]);$existing=$q->fetchColumn();
            if($existing){$this->db->commit();return (int)$existing;}
            $this->db->prepare('INSERT INTO account_security_exceptions (user_id,reason,evidence_reference,opened_by,before_status) VALUES (?,?,?,?,?)')->execute([$target,trim($reason),trim($evidence),$actor,$u['status']]);
            $id=(int)$this->db->lastInsertId();$this->audit($actor,'Opened account security exception',"Exception #$id; account #$target; evidence: $evidence");$this->db->commit();return $id;
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    public function resolve(int $actor,int $id,string $decision,string $notes): void {
        if(!in_array($decision,['suspend','restore','verify','reject','retry_verification','no_change'],true)||mb_strlen(trim($notes))<15||mb_strlen($notes)>2000)throw new InvalidArgumentException('Select a disposition and document verification or security findings (15–2000 characters).');
        $this->db->beginTransaction();
        try {
            $a=$this->actor($actor);$q=$this->db->prepare('SELECT * FROM account_security_exceptions WHERE id=? FOR UPDATE');$q->execute([$id]);$ex=$q->fetch();
            if(!$ex||$ex['status']!=='open')throw new RuntimeException('Exception is unavailable or already resolved.');
            $q=$this->db->prepare('SELECT * FROM users WHERE id=? FOR UPDATE');$q->execute([$ex['user_id']]);$u=$q->fetch();
            if(!$u||$u['id']===$actor||($u['role']==='admin'&&(!is_primary_admin($a)||is_primary_admin($u))))throw new RuntimeException('Protected account.');
            $next=$u['status'];$registration=$u['registration_status'];
            if($decision==='suspend'){if($next!=='active')throw new RuntimeException('Only active accounts can be suspended.');$next='inactive';}
            if($decision==='retry_verification'){
                if($u['role']==='admin'||$u['email_verified']||!in_array($next,['pending','rejected'],true))throw new RuntimeException('Only a non-admin unverified registration can restart OTP after security review.');
                $next='pending';$registration='review';
                $this->db->prepare('UPDATE users SET otp_hash=NULL,otp_expires_at=NULL,otp_attempts=0 WHERE id=?')->execute([$u['id']]);
            }
            if($decision==='restore'){if($next!=='inactive'||!$u['email_verified']||$u['registration_status']!=='approved')throw new RuntimeException('Only a previously approved, email-verified inactive account can be restored.');$next='active';}
            if(in_array($decision,['verify','reject'],true)){
                if($u['role']==='admin')throw new RuntimeException('Administration accounts require restricted provisioning, not registration approval.');
                if(!is_primary_admin($a)&&!has_admin_permission('approve_users',$a,$this->db))throw new RuntimeException('Identity review authorization is required.');
                if($next!=='pending')throw new RuntimeException('Only pending identity reviews can be decided.');
                if($decision==='verify'&&!$u['email_verified'])throw new RuntimeException('OTP email verification is mandatory.');
                $next=$decision==='verify'?'active':'rejected';$registration=$decision==='verify'?'approved':'rejected';
                if($decision==='verify' && in_array($u['role'],['teacher','nurse'],true)) {
                    $this->db->prepare('UPDATE users SET self_service_only=0 WHERE id=?')->execute([$u['id']]);
                }
            }
            if($next!==$u['status']){
                $this->db->prepare('UPDATE users SET status=?,registration_status=?,reviewed_by=?,reviewed_at=NOW(),validation_reason=?,credential_version=COALESCE(credential_version,0)+1 WHERE id=?')->execute([$next,$registration,$actor,trim($notes),$u['id']]);
                if($next==='active'&&$u['role']==='student'){$q=$this->db->prepare('SELECT id FROM students WHERE user_id=?');$q->execute([$u['id']]);if($student=$q->fetchColumn())ensure_student_qr($this->db,(int)$student,(int)$u['id']);}
            }
            $this->db->prepare("UPDATE account_security_exceptions SET status='resolved',disposition=?,resolved_by=?,resolved_at=NOW(),resolution_notes=?,before_status=?,after_status=? WHERE id=?")->execute([$decision,$actor,trim($notes),$u['status'],$next,$id]);
            $this->audit($actor,'Resolved account security exception',"Exception #$id; account #{$u['id']}; {$u['status']} -> $next; $decision; ".trim($notes));
            $this->db->commit();
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    private function audit(int $actor,string $action,string $details): void {
        $this->db->prepare("INSERT INTO activity_logs (user_id,action,module,details) VALUES (?,?,'account_security',?)")->execute([$actor,$action,$details]);
    }
}
