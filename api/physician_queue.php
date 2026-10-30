<?php
require_once __DIR__.'/../config/config.php';
require_login();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
require_once __DIR__.'/../includes/physician_queue_service.php';
$action=$_POST['action']??$_GET['action']??'';
if(!is_string($action)){http_response_code(400);json_response(false,[],'Choose a valid handoff operation.');}
$actor=current_user();
if ($action==='refer') {
    if (($actor['role']??'')!=='nurse' || !has_permission('clinic_monitor',$actor)) admin_access_denied('Authorized Nurse required.');
} else { require_doctor(); }
try {
    $service=new PhysicianQueueService(getDB(),$actor);
    if ($action==='list' && $_SERVER['REQUEST_METHOD']==='GET') json_response(true,['data'=>$service->rows()]);
    if (!in_array($action,['refer','start','complete'],true)) {http_response_code(400);json_response(false,[],'Unsupported handoff operation.');}
    if ($_SERVER['REQUEST_METHOD']!=='POST') {http_response_code(405);header('Allow: POST');json_response(false,[],'Use POST.');}
    csrf_verify_or_die();
    $id=$action==='refer'?$service->refer($_POST):$service->transition($action,$_POST);
    json_response(true,['id'=>$id],'Physician handoff saved and audited.');
} catch (PDOException $e) {
    clinical_database_failure('physician_queue.'.$action,$e);http_response_code(503);
    $schemaError=in_array((int)($e->errorInfo[1]??0),[1146,1054],true);
    json_response(false,['error_code'=>$schemaError?'PHYSICIAN_SCHEMA_UNAVAILABLE':'PHYSICIAN_STORAGE_UNAVAILABLE'],
        $schemaError?'Physician queue setup is incomplete. System support must verify the deployed database schema. No clinical operation was confirmed.':'Physician workflow temporarily unavailable. Reload the queue before retrying; contact system support if this continues.');
} catch (InvalidArgumentException $e) {http_response_code(400);json_response(false,[],$e->getMessage());}
catch (RuntimeException $e) {http_response_code(409);json_response(false,[],$e->getMessage());}
