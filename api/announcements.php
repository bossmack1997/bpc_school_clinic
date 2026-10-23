<?php
require_once __DIR__.'/../config/config.php';require_login();
header('Content-Type: application/json');header('Cache-Control: no-store, private');
require_once __DIR__.'/../includes/announcement_service.php';
$service=new AnnouncementService(getDB(),current_user());$action=$_REQUEST['action']??'';
try {
    if($action==='list'){ $manage=($_GET['manage']??'')==='1';json_response(true,['data'=>$service->listing($manage),'publication_ready'=>$service->ready(),'can_manage'=>$service->canManage()]); }
    if(!in_array($action,['add','edit','delete','toggle_pin'],true)){http_response_code(400);json_response(false,[],'Unknown action.');}
    if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);json_response(false,[],'Use POST.');}
    csrf_verify_or_die();
    if(!$service->canManage()){http_response_code(403);json_response(false,[],'Only authorized Nurses manage routine clinic announcements.');}
    if(!$service->ready()){http_response_code(503);json_response(false,[],'Publication controls require the reviewed announcement migration.');}
    $id=$service->mutate($action,$_POST,$_FILES['image']??null);json_response(true,['id'=>$id],$action==='delete'?'Announcement archived.':'Announcement saved.');
}catch(InvalidArgumentException $e){http_response_code(422);json_response(false,[],$e->getMessage());}
catch(Throwable $e){error_log('Announcement request failed: '.get_class($e));http_response_code(503);json_response(false,[],'Announcements are temporarily unavailable. Please retry.');}
