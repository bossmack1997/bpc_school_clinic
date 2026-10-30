<?php
require_once __DIR__.'/../config/config.php';require_doctor();require_once __DIR__.'/../includes/doctor_clinical_service.php';
header('Content-Type: application/json');header('Cache-Control: no-store, private');
$service=new DoctorClinicalService(getDB(),current_user());$action=$_POST['action']??$_GET['action']??'';
try{
 if($action==='summary')json_response(true,['data'=>$service->summary()]);
 if($action==='patients'){
  foreach(['search','role','identifier'] as $field)if(!is_string($_GET[$field]??''))throw new InvalidArgumentException('Search filters must be text.');
  json_response(true,['data'=>$service->patients($_GET['search']??'',$_GET['role']??'',$_GET['identifier']??'')]);
 }
 if($action==='records'){
  $patient=filter_var($_GET['patient_user_id']??0,FILTER_VALIDATE_INT);if(!$patient||$patient<1)throw new InvalidArgumentException('Select a valid patient.');
  json_response(true,['data'=>$service->records($patient)]);
 }
 if($action==='document'){
  if($_SERVER['REQUEST_METHOD']!=='GET'){http_response_code(405);json_response(false,[],'Use GET.');}
  $patient=filter_var($_GET['patient_user_id']??0,FILTER_VALIDATE_INT);$id=filter_var($_GET['id']??0,FILTER_VALIDATE_INT);
  $overview=$service->overview((int)$patient);
  if($overview['documents']===null){http_response_code(503);json_response(false,[],'Clinical documents are temporarily unavailable. Please retry.');}
  if(!in_array((int)$id,array_map('intval',array_column($overview['documents'],'id')),true)){http_response_code(404);json_response(false,[],'Document unavailable for the selected patient.');}
  $query=getDB()->prepare('SELECT file_path FROM medical_records WHERE id=?');$query->execute([$id]);
  $directory=realpath(BASE_PATH.'/uploads/medical_records');$file=realpath(BASE_PATH.'/'.($query->fetchColumn()?:''));
  if(!$directory||!$file||!str_starts_with($file,$directory.DIRECTORY_SEPARATOR)||!is_file($file)){http_response_code(404);json_response(false,[],'Document file unavailable.');}
  header('Content-Type: application/octet-stream');header('X-Content-Type-Options: nosniff');header('Content-Disposition: attachment; filename="clinical-document-'.(int)$id.'.'.preg_replace('/[^a-zA-Z0-9]/','',pathinfo($file,PATHINFO_EXTENSION)).'"');readfile($file);exit;
 }
 if(in_array($action,['save','draft'],true)){
  if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);json_response(false,[],'Use POST.');}
  csrf_verify_or_die();
  if($action==='draft')json_response(true,['draft'=>$service->draft((int)($_POST['patient_user_id']??0))],'Draft prepared for Doctor review; no clinical record changed.');
  $id=$service->save($_POST);json_response(true,['id'=>$id],'Consultation saved and audited.');
 }
 http_response_code(400);json_response(false,[],'Unsupported clinical operation.');
}catch(PDOException $error){error_log('Doctor clinical API unavailable SQLSTATE='.$error->getCode());http_response_code(503);json_response(false,[],'Clinical data could not be saved or retrieved. Please retry.');}
catch(InvalidArgumentException $error){http_response_code(400);json_response(false,[],$error->getMessage());}
catch(RuntimeException $error){http_response_code(409);json_response(false,[],$error->getMessage());}
