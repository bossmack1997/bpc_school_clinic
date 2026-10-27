<?php
/** Operational clinic availability is separate from MIS security settings. */
final class ClinicPolicyService
{
 public const KEYS=['clinic_open_time','clinic_close_time','clinic_operating_days','clinic_manual_status','clinic_closed_dates','clinic_queue_capacity'];
 public function __construct(private PDO $db){}
 public function save(array $actor,array $input):void {
  $values=[];foreach(self::KEYS as $key){if(!is_string($input[$key]??null))throw new InvalidArgumentException('Complete every clinic policy field.');$values[$key]=trim($input[$key]);}
  foreach(['clinic_open_time','clinic_close_time'] as $key)if(!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D',$values[$key]))throw new InvalidArgumentException('Use 24-hour HH:MM clinic times.');
  if($values['clinic_open_time']>=$values['clinic_close_time'])throw new InvalidArgumentException('Closing time must be after opening time.');
  if(!preg_match('/^[1-7](?:,[1-7])*$/D',$values['clinic_operating_days']))throw new InvalidArgumentException('Operating days use 1–7, separated by commas (Monday is 1).');
  if(!in_array($values['clinic_manual_status'],['scheduled','closed'],true))throw new InvalidArgumentException('Select scheduled availability or closed.');
  $capacity=filter_var($values['clinic_queue_capacity'],FILTER_VALIDATE_INT);if($capacity===false||$capacity<1||$capacity>1000)throw new InvalidArgumentException('Queue capacity must be between 1 and 1000 active tickets.');
  if(strlen($values['clinic_closed_dates'])>500)throw new InvalidArgumentException('Closure dates exceed the field limit.');
  foreach(array_filter(explode(',',$values['clinic_closed_dates'])) as $date){$d=DateTimeImmutable::createFromFormat('!Y-m-d',trim($date));if(!$d||$d->format('Y-m-d')!==trim($date))throw new InvalidArgumentException('Use comma-separated YYYY-MM-DD closure dates.');}
  $this->db->beginTransaction();try {
   $q=$this->db->prepare('SELECT * FROM users WHERE id=? FOR UPDATE');$q->execute([$actor['id']]);$current=$q->fetch();
   if(!$current||$current['status']!=='active'||$current['role']!=='nurse'||!has_permission('clinic_monitor',$current,$this->db))throw new RuntimeException('Authorized Nurse required.');
   $q=$this->db->prepare('INSERT INTO system_settings(setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');foreach($values as $key=>$value)$q->execute([$key,$value]);
   $this->db->prepare("INSERT INTO activity_logs(user_id,action,module,details) VALUES (?,'Updated clinic availability','clinical','Operating schedule, closures and queue capacity reviewed.')")->execute([$actor['id']]);$this->db->commit();
  }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
 }
}
