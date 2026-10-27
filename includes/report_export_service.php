<?php
/** Reporting formats are deliberately separate from restricted disaster recovery. */
final class ReportExportService
{
 public function __construct(private PDO $db,private array $actor){}
 public function data(string $type,string $from,string $to):array {
  foreach([$from,$to] as $date){$d=DateTimeImmutable::createFromFormat('!Y-m-d',$date);if(!$d||$d->format('Y-m-d')!==$date)throw new InvalidArgumentException('Use valid YYYY-MM-DD export dates.');}
  if($from>$to||(new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->days>366)throw new InvalidArgumentException('Choose an ordered date range of at most 366 days.');
  $q=$this->db->prepare('SELECT * FROM users WHERE id=?');$q->execute([$this->actor['id']]);$actor=$q->fetch();
  if(!$actor||$actor['status']!=='active')throw new RuntimeException('Current authorization required.',403);
  $profile=permission_profile($actor,$this->db);
  if($type==='accounts'){
   if($profile!=='mis'||!has_permission('manage_users',$actor,$this->db))throw new RuntimeException('MIS account export authorization required.',403);
   $headers=['account_id','username','role','first_name','last_name','email','status','email_verified','created_at'];
   $sql='SELECT id,username,role,first_name,last_name,email,status,email_verified,created_at FROM users WHERE created_at>=? AND created_at<DATE_ADD(?,INTERVAL 1 DAY) ORDER BY id LIMIT 5001';
  }else{
   if(!in_array($profile,['doctor','nurse'],true)||!has_permission('clinical_records_read',$actor,$this->db))throw new RuntimeException('Clinical reporting authorization required.',403);
   if($type==='consultations'){
    $headers=['consultation_id','consultation_date','patient_type','institutional_id','patient_name','chief_complaint','diagnosis','treatment','follow_up_date'];
    $sql="SELECT c.id,c.consultation_date,c.patient_type,COALESCE(s.student_number,t.employee_id),CONCAT(u.first_name,' ',u.last_name),c.chief_complaint,c.diagnosis,c.treatment,c.follow_up_date FROM consultations c LEFT JOIN students s ON s.id=c.student_id LEFT JOIN teachers t ON t.id=c.teacher_id JOIN users u ON u.id=CASE WHEN c.patient_type='teacher' THEN t.user_id ELSE s.user_id END WHERE c.consultation_date>=? AND c.consultation_date<DATE_ADD(?,INTERVAL 1 DAY) ORDER BY c.id LIMIT 5001";
   }elseif($type==='queue'){
    $headers=['date','patient_type','status','priority','ticket_count'];
    $sql="SELECT DATE(created_at),patient_type,status,priority,COUNT(*) FROM queue_numbers WHERE created_at>=? AND created_at<DATE_ADD(?,INTERVAL 1 DAY) GROUP BY DATE(created_at),patient_type,status,priority ORDER BY DATE(created_at),patient_type,status,priority LIMIT 5001";
   }elseif($type==='inventory'){
    $headers=['medicine_name','category','quantity','unit','reorder_level','expiration_date'];
    $sql='SELECT medicine_name,category,quantity,unit,reorder_level,expiration_date FROM medicines WHERE is_archived=0 AND created_at<DATE_ADD(?,INTERVAL 1 DAY) AND ?<=CURDATE() ORDER BY medicine_name,id LIMIT 5001';
   }else throw new InvalidArgumentException('Select a supported export type.');
  }
  $query=$this->db->prepare($sql);$query->execute($type==='inventory'?[$to,$from]:[$from,$to]);$rows=$query->fetchAll(PDO::FETCH_NUM);
  if(count($rows)>5000)throw new InvalidArgumentException('More than 5000 rows match. Refine the date range before downloading.');
  $this->db->prepare("INSERT INTO activity_logs(user_id,action,module,details) VALUES (?,'Downloaded report export','reports',?)")->execute([$actor['id'],$type.'; '.$from.' to '.$to.'; '.count($rows).' rows; no clinical text logged.']);
  return ['headers'=>$headers,'rows'=>$rows];
 }
 public static function csvCell(mixed $value):string {
  $text=(string)($value??'');return preg_match('/^[\s\x00-\x1F]*[=+\-@]/u',$text)?"'".$text:$text;
 }
 public static function xlsx(array $data):string {
  if(!class_exists('ZipArchive'))throw new RuntimeException('Excel export requires the PHP ZIP extension.');
  $path=tempnam(sys_get_temp_dir(),'bpc_export_');if($path===false)throw new RuntimeException('Export temporary storage unavailable.');
  try{
   $zip=new ZipArchive();if($zip->open($path,ZipArchive::OVERWRITE)!==true)throw new RuntimeException('Excel archive could not be created.');
   $zip->addFromString('[Content_Types].xml','<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
   $zip->addFromString('_rels/.rels','<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
   $zip->addFromString('xl/workbook.xml','<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Clinic report" sheetId="1" r:id="rId1"/></sheets></workbook>');
   $zip->addFromString('xl/_rels/workbook.xml.rels','<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
   $xml='<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
   foreach([$data['headers'],...$data['rows']] as $index=>$row){$xml.='<row r="'.($index+1).'">';foreach($row as $column=>$cell){$text=preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u','',(string)($cell??''));$xml.='<c r="'.chr(65+$column).($index+1).'" t="inlineStr"><is><t xml:space="preserve">'.htmlspecialchars($text,ENT_XML1|ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'</t></is></c>';}$xml.='</row>';}
   if(!$zip->addFromString('xl/worksheets/sheet1.xml',$xml.'</sheetData></worksheet>')||!$zip->close())throw new RuntimeException('Excel export could not be finalized.');
   return $path;
  }catch(Throwable $e){if(is_file($path))unlink($path);throw $e;}
 }
}
