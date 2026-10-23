<?php
final class AdminProfileService
{
 public function __construct(private PDO $db){}
 private function authorize(int $actor,string $password): array {
  $q=$this->db->prepare("SELECT * FROM users WHERE id=? AND role='admin' AND admin_type='primary_admin' AND status='active' FOR UPDATE");$q->execute([$actor]);$u=$q->fetch();
  if(!$u||permission_profile($u,$this->db)!=='mis'||!password_verify($password,$u['password_hash']))throw new RuntimeException('Current MIS primary password and authorization required.');return $u;
 }
 private function reference(string $reference): void {if(mb_strlen(trim($reference))<10||mb_strlen($reference)>255)throw new InvalidArgumentException('An official appointment/authority reference of 10–255 characters is required.');}
 private function audit(int $actor,int $target,string $before,string $after,string $ref): void {
  $this->db->prepare('INSERT INTO admin_profile_history(user_id,actor_id,previous_profile,new_profile,reference) VALUES (?,?,?,?,?)')->execute([$target,$actor,$before,$after,$ref]);
  $this->db->prepare("INSERT INTO activity_logs(user_id,action,module,details) VALUES (?,'Administrative profile transition','admin_profiles',?)")->execute([$actor,"Account #$target: $before -> $after; authority: $ref"]);
 }
 public function assign(int $actor,int $target,string $profile,string $reference,string $license,string $password): void {
  $this->reference($reference);if(!in_array($profile,['mis','doctor','legacy'],true))throw new InvalidArgumentException('Unsupported permission profile.');
  if($profile==='doctor'&&(mb_strlen(trim($license))<5||mb_strlen($license)>150))throw new InvalidArgumentException('Verified professional registration reference is required.');
  $this->db->beginTransaction();try{$this->authorize($actor,$password);$q=$this->db->prepare("SELECT * FROM users WHERE id=? AND role='admin' FOR UPDATE");$q->execute([$target]);$u=$q->fetch();
   if(!$u||$target===$actor||$u['admin_type']==='primary_admin'||!in_array($u['status'],['active','inactive'],true)||!$u['email_verified'])throw new RuntimeException('Select another active or deactivated, identity-verified co-admin; primary and self profiles are protected.');
   $before=permission_profile($u,$this->db);
   $this->db->prepare('INSERT INTO admin_profiles(user_id,profile,appointment_reference,clinical_registration,verified_by) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE profile=VALUES(profile),appointment_reference=VALUES(appointment_reference),clinical_registration=VALUES(clinical_registration),verified_by=VALUES(verified_by),verified_at=NOW()')->execute([$target,$profile,$reference,$profile==='doctor'?trim($license):null,$actor]);
   $this->db->prepare('UPDATE users SET credential_version=COALESCE(credential_version,0)+1 WHERE id=?')->execute([$target]);$this->audit($actor,$target,$before,$profile,$reference);$this->db->commit();
  }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
 }
 public function succeed(int $actor,int $target,string $reference,string $password): void {
  $this->reference($reference);$this->db->beginTransaction();try{$this->authorize($actor,$password);
   $q=$this->db->prepare("SELECT * FROM users WHERE id=? AND role='admin' AND status='active' FOR UPDATE");$q->execute([$target]);$u=$q->fetch();
   if(!$u||$target===$actor||!$u['email_verified']||permission_profile($u,$this->db)!=='mis')throw new RuntimeException('Successor must be separately verified and assigned an active MIS profile. Doctor is never promoted automatically.');
   $this->db->prepare("UPDATE admin_departures SET status='cancelled' WHERE user_id=? AND status='scheduled'")->execute([$target]);
   $this->db->prepare("UPDATE users SET admin_type='primary_admin',credential_version=COALESCE(credential_version,0)+1 WHERE id=?")->execute([$target]);
   $this->db->prepare("UPDATE users SET admin_type='co_admin',status='inactive',credential_version=COALESCE(credential_version,0)+1 WHERE id=?")->execute([$actor]);
   $this->audit($actor,$target,'mis','primary_mis',$reference);$this->audit($actor,$actor,'primary_mis','inactive_mis',$reference);$this->db->commit();
  }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
 }
 public function departure(int $actor,int $target,string $effective,string $ref,string $password): int {
  $this->reference($ref);$dt=DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i',$effective,new DateTimeZone('Asia/Manila'));if(!$dt||$dt->format('Y-m-d\\TH:i')!==$effective)throw new InvalidArgumentException('Select a valid effective date/time.');
  if($dt<=new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))throw new InvalidArgumentException('Departure must be in the future. Use immediate deactivation for access removal now.');
  $this->db->beginTransaction();try{$this->authorize($actor,$password);$q=$this->db->prepare("SELECT * FROM users WHERE id=? AND role='admin' AND status='active' FOR UPDATE");$q->execute([$target]);$u=$q->fetch();
   if(!$u||$target===$actor||$u['admin_type']==='primary_admin')throw new RuntimeException('Use verified succession for primary MIS departure.');
   $q=$this->db->prepare("SELECT id FROM admin_departures WHERE user_id=? AND status='scheduled'");$q->execute([$target]);if($q->fetchColumn())throw new RuntimeException('A departure is already scheduled for this account.');
   $this->db->prepare('INSERT INTO admin_departures(user_id,authorized_by,effective_at,authority_reference) VALUES (?,?,?,?)')->execute([$target,$actor,$dt->format('Y-m-d H:i:s'),$ref]);$id=(int)$this->db->lastInsertId();$this->audit($actor,$target,permission_profile($u,$this->db),'departure_scheduled',$ref);$this->db->commit();return $id;
  }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
 }
 public function changeAccess(int $actor,int $target,string $action,string $reference,string $password): void {
  if(!in_array($action,['deactivate','reactivate','revoke','cancel_departure'],true))throw new InvalidArgumentException('Unsupported access action.');
  $this->reference($reference);
  $this->db->beginTransaction();
  try {
   $this->authorize($actor,$password);
   $q=$this->db->prepare("SELECT * FROM users WHERE id=? AND role='admin' FOR UPDATE");$q->execute([$target]);$u=$q->fetch();
   if(!$u||$target===$actor||$u['admin_type']==='primary_admin')throw new RuntimeException('Select another co-administrator. Primary and self accounts are protected.');
   $before=permission_profile($u,$this->db);
   if($action==='cancel_departure'){
    $q=$this->db->prepare("UPDATE admin_departures SET status='cancelled' WHERE user_id=? AND status='scheduled' AND effective_at>NOW()");$q->execute([$target]);
    if($q->rowCount()!==1)throw new RuntimeException('No future departure is available to cancel.');
   }else{
    if($action==='reactivate'){
     if(!in_array($u['status'],['inactive','suspended'],true)||!$u['email_verified']||$u['registration_status']!=='approved'||!in_array($before,['mis','doctor'],true))throw new RuntimeException('Reactivation requires a deactivated, verified account with an approved MIS or Doctor appointment. Reassign revoked profiles first.');
     $q=$this->db->prepare("SELECT COUNT(*) FROM admin_departures WHERE user_id=? AND status='scheduled'");$q->execute([$target]);
     if($q->fetchColumn())throw new RuntimeException('Cancel or complete the scheduled departure before reactivation.');
    }elseif($u['status']!=='active')throw new RuntimeException('Only an active account can be deactivated or revoked.');
    $next=$action==='reactivate'?'active':'inactive';
    $this->db->prepare('UPDATE users SET status=?,credential_version=COALESCE(credential_version,0)+1 WHERE id=?')->execute([$next,$target]);
    if($action==='revoke'){
     $this->db->prepare('DELETE FROM admin_permissions WHERE user_id=?')->execute([$target]);
     $this->db->prepare("UPDATE admin_profiles SET profile='legacy',verified_by=?,verified_at=NOW() WHERE user_id=?")->execute([$actor,$target]);
    }
    if($next==='inactive')$this->db->prepare("UPDATE admin_departures SET status='cancelled' WHERE user_id=? AND status='scheduled'")->execute([$target]);
   }
   $this->audit($actor,$target,$before,$action,$reference);$this->db->commit();
  }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
 }
 public function rejected(int $actor,int $target,string $action): void {
  $this->db->prepare("INSERT INTO activity_logs(user_id,action,module,details) VALUES (?,'Rejected administrator change','admin_profiles',?)")->execute([$actor,'Account #'.$target.'; operation: '.mb_substr($action,0,40)]);
 }
 public function applyDue(): int {
  $done=0;foreach($this->db->query("SELECT id FROM admin_departures WHERE status='scheduled' AND effective_at<=NOW() ORDER BY id")->fetchAll(PDO::FETCH_COLUMN) as $id){
   $this->db->beginTransaction();try{$q=$this->db->prepare("SELECT * FROM admin_departures WHERE id=? AND status='scheduled' FOR UPDATE");$q->execute([$id]);$d=$q->fetch();if(!$d){$this->db->commit();continue;}
    $q=$this->db->prepare('SELECT * FROM users WHERE id=? FOR UPDATE');$q->execute([$d['user_id']]);$u=$q->fetch();if(!$u||$u['admin_type']==='primary_admin')throw new RuntimeException('Departure target became a protected primary administrator; operator review required.');
    $this->db->prepare("UPDATE users SET status='inactive',credential_version=COALESCE(credential_version,0)+1 WHERE id=?")->execute([$u['id']]);$this->db->prepare("UPDATE admin_departures SET status='applied',applied_at=NOW() WHERE id=?")->execute([$id]);$this->audit((int)$d['authorized_by'],(int)$u['id'],permission_profile($u,$this->db),'inactive',$d['authority_reference']);$this->db->commit();$done++;
   }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
  }return $done;
 }
}
