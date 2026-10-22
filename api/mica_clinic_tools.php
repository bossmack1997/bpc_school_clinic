<?php
require_once __DIR__.'/../config/config.php';
require_permission('clinical_records_read');
require_once __DIR__.'/../includes/mica_clinic_service.php';
require_once __DIR__.'/../includes/clinic_export.php';
$db=getDB();$user=current_user();$service=new MicaClinicService($db,$user);$action=(string)($_REQUEST['action']??'');
try {
    if($action==='student_search') json_response(true,['data'=>$service->students((string)($_GET['q']??''))]);
    if($action==='student_detail') json_response(true,['data'=>$service->student((int)($_GET['id']??0))]);
    if($action==='inventory_list') json_response(true,['data'=>$service->medicines((string)($_GET['q']??''),(string)($_GET['filter']??'all'))]);
    if($action==='inventory_history') json_response(true,['data'=>$service->inventoryHistory((int)($_GET['medicine_id']??0))]);
    if($action==='certificate_list') json_response(true,['data'=>$service->certificates((string)($_GET['q']??''))]);
    if($action==='report_preview') json_response(true,['data'=>$service->report((string)($_GET['type']??''),(string)($_GET['from']??date('Y-m-01')),(string)($_GET['to']??date('Y-m-d')))]);
    if($action==='report_history'){$stmt=$db->prepare("SELECT r.*,CONCAT(u.first_name,' ',u.last_name) generated_by_name FROM reports r LEFT JOIN users u ON u.id=r.generated_by ORDER BY r.created_at DESC LIMIT 50");$stmt->execute();json_response(true,['data'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);}
    if($action==='certificate_pdf'){
        $cert=$service->certificate((int)($_GET['id']??0));
        $report=['title'=>'Medical Certificate','from'=>$cert['date_issued'],'to'=>$cert['date_issued'],'rows'=>[array_intersect_key($cert,array_flip(['certificate_number','student_number','student_name','course','year_level','section','certificate_type','purpose','remarks','date_issued','issued_by_name']))]];
        log_activity($user['id'],'Downloaded certificate','certificates','Certificate #'.$cert['certificate_number']);
        ClinicExport::send($report,'pdf','BPC_Certificate_'.$cert['certificate_number']);
    }
    if(in_array($action,['report_export','inventory_export'],true)){
        if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);json_response(false,[],'Use POST.');}csrf_verify_or_die();
        $type=$action==='inventory_export'?'inventory':(string)($_POST['type']??'');$from=(string)($_POST['from']??date('Y-m-01'));$to=(string)($_POST['to']??date('Y-m-d'));$format=(string)($_POST['format']??'pdf');
        $report=$service->report($type,$from,$to);
        $db->prepare('INSERT INTO reports(report_type,period_from,period_to,generated_by) VALUES(?,?,?,?)')->execute([$type,$from,$to,$user['id']]);
        log_activity($user['id'],'Generated MICA report','reports',"$type $from to $to ($format)");
        ClinicExport::send($report,$format,'BPC_'.ucwords(str_replace('_','_',$type)).'_'.date('Y-m-d'));
    }
    json_response(false,[],'Unknown MICA clinic tool action.');
} catch(InvalidArgumentException $e){http_response_code(400);json_response(false,[],$e->getMessage());}
catch(Throwable $e){error_log('MICA clinic tools: '.$e->getMessage());http_response_code(500);json_response(false,[],'The requested clinic information could not be retrieved.');}
