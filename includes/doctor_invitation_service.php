<?php
/** Restricted Doctor provisioning. Tokens leave this service only through the mail transport. */
final class DoctorInvitationService
{
    private $send;
    public function __construct(private PDO $db,?callable $send=null){$this->send=$send??'send_doctor_invitation_email';date_default_timezone_set('Asia/Manila');$db->exec("SET time_zone='+08:00'");}
    private function authorize(int $id): void {
        $q=$this->db->prepare('SELECT * FROM users WHERE id=? FOR UPDATE');$q->execute([$id]);$actor=$q->fetch();
        if(!$actor || $actor['status']!=='active' || !is_primary_admin($actor) || permission_profile($actor,$this->db)!=='mis')throw new RuntimeException('MIS Primary Administrator authorization is required.');
    }
    private function challenge(int $user,int $actor): array {
        $token=bin2hex(random_bytes(32));$expires=date('Y-m-d H:i:s',time()+86400);
        $q=$this->db->prepare('INSERT INTO admin_invitations(user_id,token_hash,created_by,expires_at) VALUES (?,?,?,?)');$q->execute([$user,hash('sha256',$token),$actor,$expires]);
        return ['id'=>(int)$this->db->lastInsertId(),'token'=>$token,'expires'=>$expires];
    }
    private function deliver(array $challenge,array $person): array {
        $sent=false;$mailError=null;
        try {$sent=(bool)($this->send)($person['email'],trim($person['first_name'].' '.$person['last_name']),app_url('auth/setup_account.php?token='.rawurlencode($challenge['token'])),$challenge['expires'],$person['appointment_reference'],$mailError);}
        catch(Throwable $e){error_log('Doctor invitation transport failed; no token or recipient logged.');}
        $status=$sent?'sent':'failed';
        $this->db->prepare('UPDATE admin_invitations SET delivery_status=?,delivery_error=? WHERE id=?')->execute([$status,$sent?null:'Email transport did not confirm delivery.',$challenge['id']]);
        return ['delivery_status'=>$status];
    }
    public function create(int $actor,array $input): array {
        $limits=['first_name'=>80,'middle_name'=>80,'last_name'=>80,'email'=>120,'username'=>50,'contact_number'=>30,'official_designation'=>150,'professional_license'=>150,'appointment_reference'=>255,'verification_status'=>20];$p=[];
        foreach($limits as $key=>$limit){if(isset($input[$key])&&!is_scalar($input[$key]))throw new InvalidArgumentException('Invalid invitation input.');$p[$key]=trim((string)($input[$key]??''));if(mb_strlen($p[$key])>$limit)throw new InvalidArgumentException('One or more invitation fields exceed the allowed length.');}
        $p['email']=strtolower($p['email']);
        if(!$p['first_name']||!$p['last_name'])throw new InvalidArgumentException('First and last name are required.');
        if(!filter_var($p['email'],FILTER_VALIDATE_EMAIL))throw new InvalidArgumentException('A valid email address is required.');
        if(!preg_match('/^[a-zA-Z0-9_]{3,50}$/D',$p['username']))throw new InvalidArgumentException('Username must be 3-50 letters, numbers or underscores.');
        if(!$p['official_designation']||!$p['appointment_reference'])throw new InvalidArgumentException('Professional designation and appointment reference are required.');
        if(!in_array($p['verification_status'],['pending','verified'],true))throw new InvalidArgumentException('Select a valid appointment verification status.');
        $this->db->beginTransaction();
        try {
            $this->authorize($actor);
            $q=$this->db->prepare('SELECT id FROM users WHERE username=? OR email=? LIMIT 1');$q->execute([$p['username'],$p['email']]);
            if($q->fetch())throw new DomainException('An account or invitation already uses this email or username.');
            $approved=$p['verification_status']==='verified';
            $q=$this->db->prepare("INSERT INTO users(role,admin_type,username,email,password_hash,first_name,middle_name,last_name,phone,profile_picture,status,email_verified,registration_status,validation_reason,validation_confidence,risk_level) VALUES ('admin','co_admin',?,?,?,?,?,?,?,'assets/images/default-avatar.png','pending',0,?,?,?,'low')");
            $q->execute([$p['username'],$p['email'],password_hash(bin2hex(random_bytes(32)),PASSWORD_DEFAULT),$p['first_name'],$p['middle_name']?:null,$p['last_name'],$p['contact_number']?:null,$approved?'approved':'review',$approved?'Doctor appointment verified; secure password setup pending':'Doctor appointment verification and secure password setup pending',$approved?1:.5]);$id=(int)$this->db->lastInsertId();
            $this->db->prepare("INSERT INTO admin_profiles(user_id,profile,official_designation,appointment_reference,clinical_registration,verified_by,verified_at) VALUES (?,'doctor',?,?,?,?,NOW())")->execute([$id,$p['official_designation'],$p['appointment_reference'],$p['professional_license']?:null,$actor]);
            $challenge=$this->challenge($id,$actor);
            $this->db->prepare("INSERT INTO activity_logs(user_id,action,module,details) VALUES (?,'Created Doctor account invitation','admin_profiles',?)")->execute([$actor,"Doctor account #$id provisioned; password setup remains private to the appointee."]);
            $this->db->commit();
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
        // A delivery/status failure cannot truthfully roll back the already committed account.
        try {$result=$this->deliver($challenge,$p);}catch(Throwable $e){error_log('Doctor invitation delivery status unavailable.');$result=['delivery_status'=>'pending'];}
        return ['user_id'=>$id]+$result;
    }
    public function resend(int $actor,int $id): array {
        $this->db->beginTransaction();
        try {
            $this->authorize($actor);
            $q=$this->db->prepare("SELECT u.*,p.appointment_reference FROM users u JOIN admin_profiles p ON p.user_id=u.id WHERE u.id=? AND u.role='admin' AND u.admin_type='co_admin' AND u.status='pending' AND p.profile='doctor' FOR UPDATE");$q->execute([$id]);$person=$q->fetch();
            if(!$person)throw new DomainException('Only a pending Doctor invitation can be resent.');
            $this->db->prepare('UPDATE admin_invitations SET used_at=COALESCE(used_at,NOW()) WHERE user_id=? AND used_at IS NULL')->execute([$id]);
            $challenge=$this->challenge($id,$actor);
            $this->db->prepare("INSERT INTO activity_logs(user_id,action,module,details) VALUES (?,'Resent Doctor account invitation','admin_profiles',?)")->execute([$actor,"Account #$id; previous invitation invalidated."]);
            $this->db->commit();
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
        try {$result=$this->deliver($challenge,$person);}catch(Throwable $e){error_log('Doctor invitation delivery status unavailable.');$result=['delivery_status'=>'pending'];}
        return ['user_id'=>$id]+$result;
    }
}
