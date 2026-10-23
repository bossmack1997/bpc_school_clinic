<?php
/** A single validation and atomic audit boundary for editable system configuration. */
final class SystemSettingsService {
 public const KEYS=['school_name','clinic_name','school_address','clinic_contact','theme_mode','security_session_timeout','security_max_login_attempts','security_lockout_minutes','security_password_min_length'];
 public function __construct(private PDO $db){}
 public function save(int $actor,array $updates):void {
  if(!$updates)throw new InvalidArgumentException('No settings supplied.');
  $q=$this->db->prepare("SELECT * FROM users WHERE id=? AND role='admin' AND status='active'");$q->execute([$actor]);$u=$q->fetch();
  if(!$u)throw new RuntimeException('Active administrator required.',403);
  $ranges=['security_session_timeout'=>[300,86400],'security_max_login_attempts'=>[3,20],'security_lockout_minutes'=>[1,1440],'security_password_min_length'=>[8,72]];
  foreach($updates as $key=>$value){
   if(!in_array($key,self::KEYS,true)||!is_string($value)||mb_strlen($value)>500)throw new InvalidArgumentException('Unsupported setting or invalid value.');
   if(!has_admin_permission(str_starts_with($key,'security_')?'manage_security':'manage_settings',$u,$this->db))throw new RuntimeException('Configuration permission required.',403);
   if(isset($ranges[$key])){[$min,$max]=$ranges[$key];$number=filter_var($value,FILTER_VALIDATE_INT);if($number===false||$number<$min||$number>$max)throw new InvalidArgumentException('Security policy is outside the allowed range.');}
   if(in_array($key,['school_name','clinic_name'],true)&&(trim($value)===''||mb_strlen($value)>150))throw new InvalidArgumentException('Institution names must contain 1–150 characters.');
   if($key==='clinic_contact'&&mb_strlen($value)>40)throw new InvalidArgumentException('Contact must not exceed 40 characters.');
   if($key==='theme_mode'&&!in_array($value,['light','dark','auto'],true))throw new InvalidArgumentException('Select a supported theme.');
  }
  $this->db->beginTransaction();
  try{
   $q=$this->db->prepare('INSERT INTO system_settings(setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_at=NOW()');
   foreach($updates as $key=>$value)$q->execute([$key,trim($value)]);
   $this->db->prepare("INSERT INTO activity_logs(user_id,action,module,details) VALUES (?,'Updated system configuration','settings',?)")->execute([$actor,mb_substr('Changed keys: '.implode(', ',array_keys($updates)),0,255)]);$this->db->commit();
  }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
 }
}
