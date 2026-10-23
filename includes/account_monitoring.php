<?php
require_once __DIR__.'/institutional_eligibility.php';
/** Shared account statuses and trusted identity eligibility; no AI access decisions. */
final class AccountMonitoringService
{
    public function __construct(private PDO $db) { date_default_timezone_set('Asia/Manila'); $this->db->exec("SET time_zone='+08:00'"); }
    public static function bucket(string $status): string {
        return match($status){'active'=>'active','pending'=>'verification',default=>'inactive'};
    }
    public static function statusLabel(array $user): string {
        return match($user['status']) {
            'pending' => !empty($user['email_verified']) ? (in_array($user['role']??'', ['student','teacher','nurse'],true)?'Activation Pending':'Staff Provisioning Required') : 'Email Verification Pending',
            'active' => 'Active',
            'inactive', 'suspended' => 'Suspended',
            'rejected' => 'Rejected',
            default => ucfirst($user['status'])
        };
    }
    /** Reconcile only the account whose password was just verified; never bulk activate. */
    public function reconcileForLogin(int $id): array {
        $this->db->beginTransaction();
        try {
            $q=$this->db->prepare('SELECT * FROM users WHERE id=? FOR UPDATE');$q->execute([$id]);$u=$q->fetch();
            if(!$u)throw new RuntimeException('Account no longer exists.');
            // Repair a legacy active-but-unverified public account only after password proof.
            if($u['status']==='active' && !$u['email_verified'] && $u['role']!=='admin') {
                $this->db->prepare("UPDATE users SET status='pending' WHERE id=?")->execute([$id]);
                self::event($this->db,'email_verification_required',(string)$id,'Unverified public account restricted until OTP and eligibility checks pass.',$id);
                $u['status']='pending';
            }
            if($u['status']==='pending' && $u['email_verified'])$this->finishVerification($id);
            $q->execute([$id]);$u=$q->fetch();
            $this->db->commit();return $u;
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    public function counts(bool $includeAdmins=true): array {
        $where=$includeAdmins?'':" WHERE role <> 'admin'";
        $rows=$this->db->query('SELECT status,COUNT(*) AS total FROM users'.$where.' GROUP BY status')->fetchAll();
        $out=['total'=>0,'active'=>0,'inactive'=>0,'verification'=>0];
        foreach($rows as $r){$out['total']+=(int)$r['total'];$out[self::bucket($r['status'])]+=(int)$r['total'];}
        return $out;
    }
    public static function event(PDO $db,string $type,string $subject,string $details,?int $userId=null): void {
        $db->prepare('INSERT INTO account_security_events (event_type,subject_key,user_id,details) VALUES (?,?,?,?)')->execute([$type,hash('sha256',strtolower(trim($subject))),$userId,mb_substr($details,0,255)]);
    }
    public function eligible(array $u): bool {
        return $this->eligibility($u)['eligible'];
    }
    public function eligibility(array $u): array {
        if(!in_array($u['role'],['student','teacher','nurse'],true))return InstitutionalEligibility::result('restricted_role');
        if(($u['risk_level']??'low')==='high'||($u['registration_status']??'')==='rejected')return InstitutionalEligibility::result('assessment_blocked');
        $table=['student'=>'students','teacher'=>'teachers','nurse'=>'nurses'][$u['role']];
        $column=$u['role']==='student'?'student_number':'employee_id';
        $q=$this->db->prepare("SELECT * FROM $table WHERE user_id=?");$q->execute([$u['id']]);$profiles=$q->fetchAll();
        if(count($profiles)!==1 || !trim((string)($profiles[0][$column]??'')))return InstitutionalEligibility::result('profile_missing');
        $profile=$profiles[0];$id=trim($profile[$column]);
        $source=InstitutionalEligibility::roster($this->db,$id);
        if(!isset($source['record']))return $source;
        $identity=InstitutionalEligibility::matchIdentity($source['record'],$u,(string)($profile['license_number']??''));
        if(!$identity['eligible'])return $identity;
        // A roster identifier cannot already belong to another account of this role.
        $q=$this->db->prepare("SELECT COUNT(*) FROM $table WHERE $column=? AND user_id<>?");$q->execute([$id,$u['id']]);
        return (int)$q->fetchColumn()===0?$identity:InstitutionalEligibility::result('identifier_claimed');
    }
    public static function pendingMessage(array $u): string {
        return in_array($u['role']??'', ['student','teacher','nurse'],true)
            ? 'Your email is verified. Please sign in to complete basic account activation.'
            : 'Your email is verified. Clinical staff access requires authorized provisioning; email verification does not grant clinical permissions.';
    }
    public function finishVerification(int $id): string {
        $ownsTransaction=!$this->db->inTransaction();
        if($ownsTransaction)$this->db->beginTransaction();
        try {
            $q=$this->db->prepare('SELECT * FROM users WHERE id=? FOR UPDATE');$q->execute([$id]);$u=$q->fetch();
            if(!$u||!$u['email_verified'])throw new RuntimeException('Email verification is required.');
            // Never reactivate an existing suspended/rejected account through OTP.
            if($u['status']!=='pending'){if($ownsTransaction)$this->db->commit();return $u['status'];}
            // Basic sign-in uses email ownership, never institutional roster matching.
            $eligible=in_array($u['role'],['student','teacher','nurse'],true) && $u['registration_status']!=='rejected';
            $status=$eligible?'active':'pending';
            $reason=$eligible?'Email OTP verified; basic self-service account activated.':'Email verified; authorized staff provisioning is required.';
            // Repeated login checks must not duplicate review events or erase a rejection.
            if($u['registration_status']!=='rejected' && ($eligible || $u['validation_reason']!==$reason)) {
                $this->db->prepare('UPDATE users SET status=?,registration_status=?,validation_reason=?,self_service_only=1 WHERE id=?')->execute([$status,$eligible?'approved':'review',$reason,$id]);
                self::event($this->db,$eligible?'basic_account_activated':'staff_provisioning_required',(string)$id,$reason,$id);
            }
            if($eligible && $u['role']==='student'){
                $q=$this->db->prepare('SELECT id FROM students WHERE user_id=?');$q->execute([$id]);$student=$q->fetchColumn();
                if($student)ensure_student_qr($this->db,(int)$student,$id);
            }
            if($eligible && $u['role']==='teacher'){
                $q=$this->db->prepare('SELECT id FROM teachers WHERE user_id=?');$q->execute([$id]);if($teacher=$q->fetchColumn())ensure_teacher_qr($this->db,(int)$teacher,$id);
            }
            if($ownsTransaction)$this->db->commit();return $status;
        }catch(Throwable $e){if($ownsTransaction && $this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    public function directory(array $filters,bool $includeAdmins): array {
        $where=[$includeAdmins?'1=1':"u.role<>'admin'"];$params=[];
        $status=$filters['tab']??'all';
        if($status==='pending')$where[]="u.status='pending'";
        if($status==='email_pending')$where[]="u.status='pending' AND u.email_verified=0";
        if(in_array($status,['eligibility_pending','activation_pending'],true))$where[]="u.status='pending' AND u.email_verified=1 AND u.role IN ('student','teacher','nurse')";
        if($status==='staff_pending')$where[]="u.status='active' AND u.email_verified=1 AND u.role='nurse' AND u.self_service_only=1";
        if($status==='suspended')$where[]="u.status IN ('inactive','suspended')";
        if($status==='rejected')$where[]="u.status='rejected'";
        if($status==='active')$where[]="u.status='active'";
        if($status==='inactive')$where[]="u.status NOT IN ('active','pending')";
        $role=$filters['role']??'';
        if(in_array($role,['student','teacher','nurse','admin'],true)){$where[]='u.role=?';$params[]=$role;}
        $term=mb_substr(trim((string)($filters['search']??'')),0,120);
        $joins=' FROM users u LEFT JOIN students s ON s.user_id=u.id LEFT JOIN teachers t ON t.user_id=u.id LEFT JOIN nurses n ON n.user_id=u.id ';
        if($term!==''){$where[]="(CONCAT(u.first_name,' ',u.last_name) LIKE ? OR u.username LIKE ? OR u.email LIKE ? OR COALESCE(s.student_number,t.employee_id,n.employee_id) LIKE ?)";$params=array_merge($params,array_fill(0,4,'%'.$term.'%'));}
        $clause=' WHERE '.implode(' AND ',$where);
        $q=$this->db->prepare('SELECT COUNT(*)'.$joins.$clause);$q->execute($params);$total=(int)$q->fetchColumn();
        $pages=max(1,(int)ceil($total/25));$page=max(1,min($pages,(int)($filters['page']??1)));$offset=($page-1)*25;
        // Monitoring must remain readable during an additive authentication-schema rollout.
        // This projection never grants permissions or changes verification/account state.
        $columns=$this->db->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN);
        $verifiedAt=in_array('email_verified_at',$columns,true)?'u.email_verified_at':'NULL AS email_verified_at';
        $selfService=in_array('self_service_only',$columns,true)?'u.self_service_only':'NULL AS self_service_only';
        $select="SELECT u.id,u.first_name,u.last_name,u.username,u.role,u.admin_type,u.email,u.email_verified,$verifiedAt,$selfService,u.status,u.registration_status,u.validation_reason,u.created_at,u.last_login,COALESCE(s.student_number,t.employee_id,n.employee_id) AS identifier,
        (EXISTS(SELECT 1 FROM student_qrs sq WHERE sq.student_id=s.id AND sq.status IN ('active','regenerated')) OR EXISTS(SELECT 1 FROM teacher_qrs tq WHERE tq.teacher_id=t.id AND tq.status IN ('active','regenerated'))) AS has_qr";
        $q=$this->db->prepare($select.$joins.$clause." ORDER BY u.created_at DESC,u.id DESC LIMIT 25 OFFSET $offset");$q->execute($params);
        return ['rows'=>$q->fetchAll(),'total'=>$total,'page'=>$page,'pages'=>$pages];
    }
}
