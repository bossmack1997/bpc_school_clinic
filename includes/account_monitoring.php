<?php
/** Shared account statuses and trusted identity eligibility; no AI access decisions. */
final class AccountMonitoringService
{
    public function __construct(private PDO $db) { date_default_timezone_set('Asia/Manila'); $this->db->exec("SET time_zone='+08:00'"); }
    public static function bucket(string $status): string {
        return match($status){'active'=>'active','pending'=>'verification',default=>'inactive'};
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
        if(!in_array($u['role'],['student','teacher','nurse'],true)||($u['risk_level']??'low')==='high')return false;
        $table=['student'=>'students','teacher'=>'teachers','nurse'=>'nurses'][$u['role']];
        $column=$u['role']==='student'?'student_number':'employee_id';
        $q=$this->db->prepare("SELECT $column FROM $table WHERE user_id=?");$q->execute([$u['id']]);$id=$q->fetchColumn();
        if(!$id)return false;
        $q=$this->db->prepare('SELECT first_name,last_name,role,institutional_email,status,license_number FROM institutional_roster WHERE identifier=?');$q->execute([$id]);$records=$q->fetchAll();$r=count($records)===1?$records[0]:null;
        if(!$r||!in_array($r['status'],['eligible','registered'],true)||$r['role']!==$u['role'])return false;
        $norm=fn($s)=>mb_strtolower(preg_replace('/\s+/u',' ',trim($s)));
        if($norm($r['first_name'])!==$norm($u['first_name'])||$norm($r['last_name'])!==$norm($u['last_name'])||strcasecmp(trim($r['institutional_email']),trim($u['email']))!==0)return false;
        if($u['role']==='nurse'){
            $q=$this->db->prepare('SELECT license_number FROM nurses WHERE user_id=?');$q->execute([$u['id']]);$license=$q->fetchColumn();
            if(!$license||empty($r['license_number'])||strcasecmp(trim($r['license_number']),trim($license))!==0)return false;
        }
        // A roster identifier cannot already belong to another account of this role.
        $q=$this->db->prepare("SELECT COUNT(*) FROM $table WHERE $column=? AND user_id<>?");$q->execute([$id,$u['id']]);
        return (int)$q->fetchColumn()===0;
    }
    public function finishVerification(int $id): string {
        $this->db->beginTransaction();
        try {
            $q=$this->db->prepare('SELECT * FROM users WHERE id=? FOR UPDATE');$q->execute([$id]);$u=$q->fetch();
            if(!$u||!$u['email_verified'])throw new RuntimeException('Email verification is required.');
            // Never reactivate an existing suspended/rejected account through OTP.
            if($u['status']!=='pending'){$this->db->commit();return $u['status'];}
            $eligible=$this->eligible($u);
            $status=$eligible?'active':'pending';
            $reason=$eligible?'OTP verified; trusted roster identity and role matched.':'OTP verified; trusted identity or role eligibility requires authorized review.';
            $this->db->prepare('UPDATE users SET status=?,registration_status=?,validation_reason=? WHERE id=?')->execute([$status,$eligible?'approved':'review',$reason,$id]);
            self::event($this->db,$eligible?'identity_verified':'identity_review',(string)$id,$reason,$id);
            if($eligible && $u['role']==='student'){
                $q=$this->db->prepare('SELECT id FROM students WHERE user_id=?');$q->execute([$id]);$student=$q->fetchColumn();
                if($student)ensure_student_qr($this->db,(int)$student,$id);
            }
            if($eligible && $u['role']==='teacher'){
                $q=$this->db->prepare('SELECT id FROM teachers WHERE user_id=?');$q->execute([$id]);if($teacher=$q->fetchColumn())ensure_teacher_qr($this->db,(int)$teacher,$id);
            }
            $this->db->commit();return $status;
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    public function directory(array $filters,bool $includeAdmins): array {
        $where=[$includeAdmins?'1=1':"u.role<>'admin'"];$params=[];
        $status=$filters['tab']??'all';
        if($status==='pending')$where[]="u.status='pending'";
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
        $select="SELECT u.id,u.first_name,u.last_name,u.username,u.role,u.admin_type,u.email,u.email_verified,u.status,u.registration_status,u.validation_reason,u.created_at,u.last_login,COALESCE(s.student_number,t.employee_id,n.employee_id) AS identifier,
        (EXISTS(SELECT 1 FROM student_qrs sq WHERE sq.student_id=s.id AND sq.status IN ('active','regenerated')) OR EXISTS(SELECT 1 FROM teacher_qrs tq WHERE tq.teacher_id=t.id AND tq.status IN ('active','regenerated'))) AS has_qr";
        $q=$this->db->prepare($select.$joins.$clause." ORDER BY u.created_at DESC,u.id DESC LIMIT 25 OFFSET $offset");$q->execute($params);
        return ['rows'=>$q->fetchAll(),'total'=>$total,'page'=>$page,'pages'=>$pages];
    }
}
