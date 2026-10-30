<?php
/** Restricted Doctor provisioning. Tokens leave this service only through the mail transport. */
final class DoctorInvitationService
{
    private $send;
    private $committed;
    public function __construct(private PDO $db,?callable $send=null,?callable $committed=null){$this->send=$send??'send_doctor_invitation_email';$this->committed=$committed;date_default_timezone_set('Asia/Manila');$db->exec("SET time_zone='+08:00'");}
    private function assertSchema(): void {
        $count=$this->db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('users','admin_profiles','admin_invitations','activity_logs') AND TABLE_TYPE='BASE TABLE' AND ENGINE='InnoDB'")->fetchColumn();
        if((int)$count!==4)throw new RuntimeException('Phase 1 invitation schema is unavailable or nontransactional.');
        foreach([['users','email'],['users','username'],['admin_profiles','user_id'],['admin_invitations','token_hash']] as [$table,$column]){
            $q=$this->db->prepare('SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND NON_UNIQUE=0 GROUP BY INDEX_NAME HAVING COUNT(*)=1 AND MAX(COLUMN_NAME)=? AND MAX(COALESCE(SUB_PART,0))=0');$q->execute([$table,$column]);
            if(!$q->fetchColumn())throw new RuntimeException('Phase 1 invitation uniqueness constraints are unavailable.');
        }
        // Validate write dependencies without reading account data or changing schema.
        $this->db->query('SELECT credential_version,remember_token,password_hash,self_service_only FROM users LIMIT 0')->closeCursor();
        $this->db->query('SELECT official_designation,appointment_reference,clinical_registration,verified_by,verified_at FROM admin_profiles LIMIT 0')->closeCursor();
        $this->db->query('SELECT token_hash,created_by,expires_at,used_at,delivery_status,delivery_error,created_at FROM admin_invitations LIMIT 0')->closeCursor();
    }
    private function authorize(int $id): void {
        $q=$this->db->prepare('SELECT * FROM users WHERE id=? FOR UPDATE');$q->execute([$id]);$actor=$q->fetch();
        if(!$actor || $actor['status']!=='active' || !is_primary_admin($actor) || permission_profile($actor,$this->db)!=='mis' || !has_permission('manage_users',$actor,$this->db))throw new RuntimeException('MIS Primary Administrator authorization is required.');
    }
    private function challenge(int $user,int $actor): array {
        $token=bin2hex(random_bytes(32));$expires=date('Y-m-d H:i:s',time()+86400);
        $q=$this->db->prepare('INSERT INTO admin_invitations(user_id,token_hash,created_by,expires_at) VALUES (?,?,?,?)');$q->execute([$user,hash('sha256',$token),$actor,$expires]);
        return ['id'=>(int)$this->db->lastInsertId(),'token'=>$token,'expires'=>$expires];
    }
    private function deliver(array $challenge,array $person): array {
        $sent=false;$mailError=null;
        try {$sent=(bool)($this->send)($person['email'],trim($person['first_name'].' '.$person['last_name']),app_url('auth/setup_account.php#token='.rawurlencode($challenge['token'])),$challenge['expires'],$person['appointment_reference'],$mailError);}
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
        if($p['contact_number']!==''&&!preg_match('/^[+0-9() .-]{7,30}$/',$p['contact_number']))throw new InvalidArgumentException('Enter a valid contact number.');
        if(!$p['official_designation']||!$p['appointment_reference'])throw new InvalidArgumentException('Professional designation and appointment reference are required.');
        if(!in_array($p['verification_status'],['verified'],true))throw new InvalidArgumentException('Verify the professional appointment before sending an invitation.');
        $this->assertSchema();
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
        if($this->committed)($this->committed)();
        try {$result=$this->deliver($challenge,$p);}catch(Throwable $e){error_log('Doctor invitation delivery status unavailable.');$result=['delivery_status'=>'pending'];}
        return ['user_id'=>$id]+$result;
    }
    public function resend(int $actor,int $id): array {
        $this->assertSchema();
        $this->db->beginTransaction();
        try {
            $this->authorize($actor);
            $q=$this->db->prepare("SELECT u.*,p.appointment_reference FROM users u JOIN admin_profiles p ON p.user_id=u.id WHERE u.id=? AND u.role='admin' AND u.admin_type='co_admin' AND u.status='pending' AND p.profile='doctor' FOR UPDATE");$q->execute([$id]);$person=$q->fetch();
            if(!$person || $person['registration_status']!=='approved')throw new DomainException('Only a verified pending Doctor invitation can be resent.');
            $recent=$this->db->prepare("SELECT id FROM admin_invitations WHERE user_id=? AND used_at IS NULL AND delivery_status<>'failed' AND expires_at>NOW() AND created_at>DATE_SUB(NOW(),INTERVAL 60 SECOND) LIMIT 1");$recent->execute([$id]);
            if($recent->fetch())throw new DomainException('An invitation was just issued. Wait one minute before resending.');
            $this->db->prepare('UPDATE admin_invitations SET used_at=COALESCE(used_at,NOW()) WHERE user_id=? AND used_at IS NULL')->execute([$id]);
            $challenge=$this->challenge($id,$actor);
            $this->db->prepare("INSERT INTO activity_logs(user_id,action,module,details) VALUES (?,'Resent Doctor account invitation','admin_profiles',?)")->execute([$actor,"Account #$id; previous invitation invalidated."]);
            $this->db->commit();
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
        if($this->committed)($this->committed)();
        try {$result=$this->deliver($challenge,$person);}catch(Throwable $e){error_log('Doctor invitation delivery status unavailable.');$result=['delivery_status'=>'pending'];}
        return ['user_id'=>$id]+$result;
    }
    public function inspect(string $token): ?array {
        if(!preg_match('/^[a-f0-9]{64}$/D',$token))return null;
        $q=$this->db->prepare("SELECT ai.id,ai.user_id,ai.expires_at,ai.used_at,u.email,u.first_name,u.last_name,u.status,u.registration_status,p.profile
            FROM admin_invitations ai JOIN users u ON u.id=ai.user_id JOIN admin_profiles p ON p.user_id=u.id
            WHERE ai.token_hash=? AND u.role='admin' AND u.admin_type='co_admin'");
        $q->execute([hash('sha256',$token)]);return $q->fetch(PDO::FETCH_ASSOC)?:null;
    }
    public static function eligibilityError(?array $invite): string {
        if(!$invite || $invite['profile']!=='doctor')return 'This account setup invitation is invalid.';
        if($invite['used_at'])return 'This account setup invitation has already been used.';
        if(strtotime($invite['expires_at'])<=time())return 'This account setup invitation has expired. Contact the MIS Administrator for a new invitation.';
        if($invite['status']!=='pending' || $invite['registration_status']!=='approved')return 'This account is not eligible for invitation activation. Contact the MIS Administrator.';
        return '';
    }
    public function accept(string $token,string $password,string $confirm): void {
        if(strlen($password)>72)throw new InvalidArgumentException('Password must be at most 72 bytes.');
        $errors=validate_password_strength($password);
        if($password!==$confirm)$errors[]='Passwords do not match.';
        if($errors)throw new InvalidArgumentException(implode(' ',array_unique($errors)));
        $invite=$this->inspect($token);$error=self::eligibilityError($invite);
        if($error!=='')throw new DomainException($error);
        $hash=password_hash($password,PASSWORD_DEFAULT);
        $this->assertSchema();
        $this->db->beginTransaction();
        try {
            // Same target-user-before-invitation lock order as resend.
            $lock=$this->db->prepare('SELECT id FROM users WHERE id=? FOR UPDATE');$lock->execute([$invite['user_id']]);
            $profileLock=$this->db->prepare('SELECT user_id FROM admin_profiles WHERE user_id=? FOR UPDATE');$profileLock->execute([$invite['user_id']]);
            $lock=$this->db->prepare('SELECT id FROM admin_invitations WHERE id=? FOR UPDATE');$lock->execute([$invite['id']]);
            $locked=$this->inspect($token);$error=self::eligibilityError($locked);
            if($error!=='')throw new DomainException($error);
            $consume=$this->db->prepare('UPDATE admin_invitations SET used_at=NOW() WHERE id=? AND used_at IS NULL AND expires_at>NOW()');$consume->execute([$locked['id']]);
            if($consume->rowCount()!==1)throw new DomainException('This invitation has expired or already been used.');
            $activate=$this->db->prepare("UPDATE users SET password_hash=?,status='active',email_verified=1,self_service_only=0,credential_version=COALESCE(credential_version,0)+1,remember_token=NULL WHERE id=? AND status='pending' AND registration_status='approved'");
            $activate->execute([$hash,$locked['user_id']]);
            if($activate->rowCount()!==1)throw new DomainException('This account is no longer eligible for activation.');
            $this->db->prepare('UPDATE admin_invitations SET used_at=COALESCE(used_at,NOW()) WHERE user_id=? AND id<>? AND used_at IS NULL')->execute([$locked['user_id'],$locked['id']]);
            $this->db->prepare("INSERT INTO activity_logs(user_id,action,module,details,ip_address) VALUES (?,'Doctor account activated','admin_profiles','Appointee set a private password using a single-use invitation.',?)")->execute([$locked['user_id'],$_SERVER['REMOTE_ADDR']??null]);
            $this->db->commit();
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }

}
