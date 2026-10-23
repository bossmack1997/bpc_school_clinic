<?php
/** Physician encounters reuse consultations and preserve nursing ownership. */
final class DoctorClinicalService
{
 public function __construct(private PDO $db,private array $user){if(!has_permission('consultations_manage',$user,$db)||permission_profile($user,$db)!=='doctor')throw new RuntimeException('Verified Doctor profile required.');}
 public function summary(): array {
  $today=(int)$this->db->query('SELECT COUNT(*) FROM consultations WHERE consultation_date>=CURDATE() AND consultation_date<CURDATE()+INTERVAL 1 DAY')->fetchColumn();
  $queue=(int)$this->db->query("SELECT COUNT(*) FROM queue_numbers WHERE created_at>=CURDATE() AND status IN ('waiting','called','serving')")->fetchColumn();
  $sos=(int)$this->db->query("SELECT COUNT(*) FROM sos_emergencies WHERE status IN ('active','new','acknowledged','responding')")->fetchColumn();
  require_once __DIR__.'/medicine_inventory_service.php';$inventory=(new MedicineInventoryService($this->db))->snapshot();
  $attended=(int)$this->db->query("SELECT COUNT(DISTINCT CONCAT(COALESCE(student_id,0),':',COALESCE(teacher_id,0))) FROM consultations WHERE consultation_date>=CURDATE() AND consultation_date<CURDATE()+INTERVAL 1 DAY")->fetchColumn();
  // Phase 1 does not load or activate the Nurse clinical review workflow.
  return ['today_consultations'=>$today,'queue_awaiting_completion'=>$queue,'pending_consultations'=>$queue,'patients_attended'=>$attended,'active_clinical_cases'=>$queue,'active_emergencies'=>$sos,'active_nurses'=>(int)$this->db->query("SELECT COUNT(*) FROM users WHERE role='nurse' AND status='active'")->fetchColumn(),'stock_warnings'=>count(MedicineInventoryService::low($inventory)),'trend'=>$this->db->query('SELECT DATE(consultation_date) AS day,COUNT(*) AS consultations FROM consultations WHERE consultation_date>=CURDATE()-INTERVAL 6 DAY GROUP BY DATE(consultation_date) ORDER BY day')->fetchAll(),'generated_at'=>date('c')];
 }
 public function patients(string $search='',string $role='',string $identifier=''): array {
  if(!in_array($role,['','student','teacher'],true))throw new InvalidArgumentException('Choose Student, Teacher or all patient types.');
  $q=$this->db->prepare("SELECT u.id,u.first_name,u.last_name,u.role,CASE WHEN u.role='student' THEN s.student_number ELSE t.employee_id END AS identifier FROM users u LEFT JOIN students s ON s.user_id=u.id AND u.role='student' LEFT JOIN teachers t ON t.user_id=u.id AND u.role='teacher' WHERE u.role IN ('student','teacher') AND (?='' OR u.role=?) AND (CONCAT(u.first_name,' ',u.last_name) LIKE ? OR COALESCE(s.student_number,t.employee_id) LIKE ?) AND COALESCE(s.student_number,t.employee_id,'') LIKE ? ORDER BY u.last_name,u.id LIMIT 50");$like='%'.mb_substr(trim($search),0,100).'%';$q->execute([$role,$role,$like,$like,'%'.mb_substr(trim($identifier),0,100).'%']);return $q->fetchAll();
 }
 private function patient(int $id): array {
  $q=$this->db->prepare("SELECT u.id,u.role,u.first_name,u.last_name,s.id AS student_id,t.id AS teacher_id,COALESCE(s.student_number,t.employee_id) AS identifier,COALESCE(s.blood_type,t.blood_type) AS blood_type,COALESCE(s.allergies,t.allergies) AS allergies FROM users u LEFT JOIN students s ON s.user_id=u.id AND u.role='student' LEFT JOIN teachers t ON t.user_id=u.id AND u.role='teacher' WHERE u.id=? AND u.role IN ('student','teacher')");$q->execute([$id]);$p=$q->fetch();if(!$p||($p['role']==='student'&&!$p['student_id'])||($p['role']==='teacher'&&!$p['teacher_id']))throw new InvalidArgumentException('The selected patient was not found or has no clinical profile.');return $p;
 }
 public function records(int $patient): array {
  $p=$this->patient($patient);$column=$p['role']==='student'?'student_id':'teacher_id';$q=$this->db->prepare("SELECT id,consultation_date,chief_complaint,diagnosis,treatment,prescription,remarks,follow_up_date,clinician_user_id,nurse_id,record_version FROM consultations WHERE $column=? ORDER BY consultation_date DESC,id DESC LIMIT 50");$q->execute([$p[$column]]);return $q->fetchAll();
 }
 public function overview(int $patient): array {
  $p=$this->patient($patient);$field=$p['role']==='student'?'student_id':'teacher_id';
  $q=$this->db->prepare("SELECT id,consultation_id,file_type,description,uploaded_at FROM medical_records WHERE $field=? ORDER BY uploaded_at DESC,id DESC LIMIT 50");$q->execute([$p[$field]]);
  return ['patient'=>$p,'consultations'=>$this->records($patient),'documents'=>$q->fetchAll()];
 }
 public function draft(int $patient): string {
  $data=$this->overview($patient);$p=$data['patient'];
  $lines=['MICA DRAFT — recorded history summary for Doctor review. Not a diagnosis, prescription or approved clinical note.','Patient account #'.$p['id'].' ('.$p['role'].').','Recorded allergies: '.($p['allergies']?:'Not recorded; confirm with the patient.')];
  foreach(array_slice($data['consultations'],0,5) as $r)$lines[]=$r['consultation_date'].' — Recorded complaint: '.$r['chief_complaint'].'; recorded findings: '.($r['diagnosis']?:'Not recorded').'; follow-up date: '.($r['follow_up_date']?:'Not recorded').'.';
  if(!$data['consultations'])$lines[]='No consultations recorded for this patient. No clinical outcome can be inferred.';
  $lines[]='Source: up to five most recent stored consultations. Verify the full patient record before confirming any decision. Nothing has been saved to the clinical record.';
  $this->db->prepare("INSERT INTO activity_logs(user_id,action,module,details) VALUES (?,'MICA clinical draft prepared','clinical',?)")->execute([$this->user['id'],'Authorized patient account #'.$patient.'; draft only; no treatment or record mutation.']);
  return implode("\n",$lines);
 }
 /** Optional sources fail independently; unavailable is never reported as zero. */
 public function commandCenter(): array {
  $out=['unavailable'=>[],'generated_at'=>(new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format('Y-m-d H:i:s').' Asia/Manila'];
  $queries=[
   'today_consultations'=>"SELECT COUNT(*) AS count FROM consultations WHERE consultation_date>=CURDATE() AND consultation_date<CURDATE()+INTERVAL 1 DAY",
   'awaiting_review'=>"SELECT COUNT(*) AS count FROM queue_numbers WHERE status IN ('waiting','called','serving') AND created_at>=CURDATE()",
   'followups'=>"SELECT c.id,c.follow_up_date,c.chief_complaint,u.id AS patient_user_id,u.first_name,u.last_name FROM consultations c LEFT JOIN students s ON s.id=c.student_id LEFT JOIN teachers t ON t.id=c.teacher_id JOIN users u ON u.id=CASE WHEN c.patient_type='teacher' THEN t.user_id ELSE s.user_id END WHERE c.follow_up_date IS NOT NULL AND c.follow_up_date>=CURDATE() ORDER BY c.follow_up_date,c.id LIMIT 10",
   'escalations'=>"SELECT r.id,r.priority,r.status,r.created_at,s.user_id AS patient_user_id,u.first_name,u.last_name FROM support_referrals r JOIN users author ON author.id=r.created_by AND author.role='nurse' JOIN students s ON s.id=r.student_id JOIN users u ON u.id=s.user_id WHERE r.status IN ('pending','accepted','in_support','pending_assessment','referral_recommended','referred','follow_up_required') AND (LOWER(r.referred_to) LIKE '%doctor%' OR LOWER(r.referred_to) LIKE '%physician%') ORDER BY r.created_at DESC LIMIT 10",
   'alerts'=>"SELECT id,emergency_type,priority,status,created_at FROM sos_emergencies WHERE status IN ('active','new','acknowledged','responding') ORDER BY created_at DESC LIMIT 10",
   'activity'=>"SELECT id,action,module,created_at FROM activity_logs WHERE module IN ('consultations','clinical') ORDER BY created_at DESC,id DESC LIMIT 10"
  ];
  foreach($queries as $key=>$sql){try{$rows=$this->db->query($sql)->fetchAll();$out[$key]=in_array($key,['today_consultations','awaiting_review'],true)?(int)$rows[0]['count']:$rows;}catch(PDOException $e){error_log('Doctor source unavailable: '.$key.' SQLSTATE='.$e->getCode());$out[$key]=null;$out['unavailable'][]=$key;}}
  try{require_once __DIR__.'/medicine_inventory_service.php';$out['stock']=array_slice(MedicineInventoryService::low((new MedicineInventoryService($this->db))->snapshot()),0,10);}catch(PDOException $e){error_log('Doctor stock source unavailable SQLSTATE='.$e->getCode());$out['stock']=null;$out['unavailable'][]='stock';}
  return $out;
 }
 public function save(array $input): int {
  foreach(['id','record_version','patient_user_id'] as $key)if(isset($input[$key])&&(!is_scalar($input[$key])||!ctype_digit((string)$input[$key])))throw new InvalidArgumentException('Select a valid patient and encounter.');
  $id=(int)($input['id']??0);$fields=[];
  foreach(['chief_complaint'=>255,'diagnosis'=>255,'treatment'=>5000,'prescription'=>5000,'remarks'=>5000] as $key=>$max){if(!is_string($input[$key]??''))throw new InvalidArgumentException('Clinical fields must be text.');$v=trim($input[$key]??'');if(mb_strlen($v)>$max)throw new InvalidArgumentException('Clinical text exceeds the field limit.');$fields[$key]=$v;}
  if(mb_strlen($fields['chief_complaint'])<2)throw new InvalidArgumentException('Chief complaint is required.');
  $follow=$input['follow_up_date']??'';
  if(!is_string($follow)||($follow!==''&&(!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$follow)||!($date=DateTimeImmutable::createFromFormat('!Y-m-d',$follow))||$date->format('Y-m-d')!==$follow)))throw new InvalidArgumentException('Use a valid follow-up date.');
  $fields['follow_up_date']=$follow!==''?$follow:null;
  $this->db->beginTransaction();try{
   $q=$this->db->prepare("SELECT * FROM users WHERE id=? AND status='active' FOR UPDATE");$q->execute([$this->user['id']]);$actor=$q->fetch();if(!$actor||permission_profile($actor,$this->db)!=='doctor')throw new RuntimeException('Doctor authority has changed; sign in again.');
   if($id){$q=$this->db->prepare('SELECT * FROM consultations WHERE id=? FOR UPDATE');$q->execute([$id]);$row=$q->fetch();if(!$row||(int)$row['clinician_user_id']!==(int)$actor['id'])throw new RuntimeException('Only the author can revise this physician encounter. Nursing records remain preserved.');if((int)($input['record_version']??-1)!==(int)$row['record_version'])throw new RuntimeException('Consultation changed in another session. Reload before saving.');
    if(!array_key_exists('follow_up_date',$input))$fields['follow_up_date']=$row['follow_up_date'];
    if(isset($input['patient_user_id'])){$selected=$this->patient((int)$input['patient_user_id']);$field=$selected['role']==='student'?'student_id':'teacher_id';if((int)$row[$field] !== (int)$selected[$field])throw new InvalidArgumentException('The encounter does not belong to the selected patient.');}
    $this->db->prepare('UPDATE consultations SET chief_complaint=?,diagnosis=?,treatment=?,prescription=?,remarks=?,follow_up_date=?,record_version=record_version+1 WHERE id=?')->execute([...array_values($fields),$id]);
   }else{$p=$this->patient((int)($input['patient_user_id']??0));$this->db->prepare("INSERT INTO consultations(student_id,teacher_id,patient_type,nurse_id,clinician_user_id,visit_type,consultation_date,chief_complaint,diagnosis,treatment,prescription,remarks,follow_up_date) VALUES (?,?,?,NULL,?,'walk-in',NOW(),?,?,?,?,?,?)")->execute([$p['student_id'],$p['teacher_id'],$p['role'],$actor['id'],...array_values($fields)]);$id=(int)$this->db->lastInsertId();}
   $this->db->prepare("INSERT INTO activity_logs(user_id,action,module,details) VALUES (?,'Physician consultation saved','consultations',?)")->execute([$actor['id'],'Consultation #'.$id.'; clinical contents remain in the authorized record.']);$this->db->commit();return $id;
  }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
 }
}
