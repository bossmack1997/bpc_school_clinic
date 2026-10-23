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
 public function patients(string $search=''): array {
  $q=$this->db->prepare("SELECT u.id,u.first_name,u.last_name,u.role,COALESCE(s.student_number,t.employee_id) AS identifier FROM users u LEFT JOIN students s ON s.user_id=u.id LEFT JOIN teachers t ON t.user_id=u.id WHERE u.role IN ('student','teacher') AND (CONCAT(u.first_name,' ',u.last_name) LIKE ? OR COALESCE(s.student_number,t.employee_id) LIKE ?) ORDER BY u.last_name,u.id LIMIT 50");$like='%'.mb_substr(trim($search),0,100).'%';$q->execute([$like,$like]);return $q->fetchAll();
 }
 private function patient(int $id): array {
  $q=$this->db->prepare("SELECT u.id,u.role,s.id AS student_id,t.id AS teacher_id FROM users u LEFT JOIN students s ON s.user_id=u.id LEFT JOIN teachers t ON t.user_id=u.id WHERE u.id=? AND u.role IN ('student','teacher')");$q->execute([$id]);$p=$q->fetch();if(!$p||($p['role']==='student'&&!$p['student_id'])||($p['role']==='teacher'&&!$p['teacher_id']))throw new InvalidArgumentException('Existing patient record required.');return $p;
 }
 public function records(int $patient): array {
  $p=$this->patient($patient);$column=$p['role']==='student'?'student_id':'teacher_id';$q=$this->db->prepare("SELECT id,consultation_date,chief_complaint,diagnosis,treatment,prescription,remarks,clinician_user_id,nurse_id,record_version FROM consultations WHERE $column=? ORDER BY consultation_date DESC,id DESC LIMIT 50");$q->execute([$p[$column]]);return $q->fetchAll();
 }
 public function save(array $input): int {
  $id=(int)($input['id']??0);$fields=[];
  foreach(['chief_complaint'=>255,'diagnosis'=>255,'treatment'=>5000,'prescription'=>5000,'remarks'=>5000] as $key=>$max){if(!is_string($input[$key]??''))throw new InvalidArgumentException('Clinical fields must be text.');$v=trim($input[$key]??'');if(mb_strlen($v)>$max)throw new InvalidArgumentException('Clinical text exceeds the field limit.');$fields[$key]=$v;}
  if(mb_strlen($fields['chief_complaint'])<2)throw new InvalidArgumentException('Chief complaint is required.');
  $this->db->beginTransaction();try{
   $q=$this->db->prepare("SELECT * FROM users WHERE id=? AND status='active' FOR UPDATE");$q->execute([$this->user['id']]);$actor=$q->fetch();if(!$actor||permission_profile($actor,$this->db)!=='doctor')throw new RuntimeException('Doctor authority has changed; sign in again.');
   if($id){$q=$this->db->prepare('SELECT * FROM consultations WHERE id=? FOR UPDATE');$q->execute([$id]);$row=$q->fetch();if(!$row||(int)$row['clinician_user_id']!==(int)$actor['id'])throw new RuntimeException('Only the author can revise this physician encounter. Nursing records remain preserved.');if((int)($input['record_version']??-1)!==(int)$row['record_version'])throw new RuntimeException('Consultation changed in another session. Reload before saving.');
    $this->db->prepare('UPDATE consultations SET chief_complaint=?,diagnosis=?,treatment=?,prescription=?,remarks=?,record_version=record_version+1 WHERE id=?')->execute([...array_values($fields),$id]);
   }else{$p=$this->patient((int)($input['patient_user_id']??0));$this->db->prepare("INSERT INTO consultations(student_id,teacher_id,patient_type,nurse_id,clinician_user_id,visit_type,consultation_date,chief_complaint,diagnosis,treatment,prescription,remarks) VALUES (?,?,?,NULL,?,'walk-in',NOW(),?,?,?,?,?)")->execute([$p['student_id'],$p['teacher_id'],$p['role'],$actor['id'],...array_values($fields)]);$id=(int)$this->db->lastInsertId();}
   $this->db->prepare("INSERT INTO activity_logs(user_id,action,module,details) VALUES (?,'Physician consultation saved','consultations',?)")->execute([$actor['id'],'Consultation #'.$id.'; clinical contents remain in the authorized record.']);$this->db->commit();return $id;
  }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
 }
}
