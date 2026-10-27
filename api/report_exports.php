<?php
require_once __DIR__.'/../config/config.php';require_login();require_once __DIR__.'/../includes/report_export_service.php';
header('Cache-Control: no-store, private');header('X-Content-Type-Options: nosniff');
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);header('Allow: POST');json_response(false,[],'Use POST.');}csrf_verify_or_die();
try{
 foreach(['type','from','to','format'] as $key)if(!is_string($_POST[$key]??null))throw new InvalidArgumentException('Choose export type, date range and format.');
 $format=$_POST['format'];if(!in_array($format,['csv','xlsx'],true))throw new InvalidArgumentException('Choose CSV or Excel.');
 $data=(new ReportExportService(getDB(),current_user()))->data($_POST['type'],$_POST['from'],$_POST['to']);
 $filename='clinic-'.preg_replace('/[^a-z]/','',$_POST['type']).'-'.$_POST['from'].'-'.$_POST['to'].'.'.$format;
 if($format==='xlsx'){
  $file=ReportExportService::xlsx($data);try{header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');header('Content-Disposition: attachment; filename="'.$filename.'"');readfile($file);}finally{unlink($file);}exit;
 }
 header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="'.$filename.'"');echo "\xEF\xBB\xBF";$out=fopen('php://output','w');foreach([$data['headers'],...$data['rows']] as $row)fputcsv($out,array_map([ReportExportService::class,'csvCell'],$row),',','"','');fclose($out);exit;
}catch(InvalidArgumentException $e){http_response_code(422);json_response(false,[],$e->getMessage());}
catch(RuntimeException $e){http_response_code($e->getCode()===403?403:503);json_response(false,[],$e instanceof PDOException?'Report export unavailable. Please retry.':$e->getMessage());}
