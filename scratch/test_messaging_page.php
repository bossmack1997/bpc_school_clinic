<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('session.save_path',__DIR__);
$_SERVER['SCRIPT_NAME']='/bpc_clinic_system/pages/'.($argv[2] ?? 'student').'/messages.php';
$_SERVER['REQUEST_URI']=$_SERVER['SCRIPT_NAME'];
require __DIR__.'/../config/config.php';
$db=getDB();$role=$argv[1]??'student';$route=$argv[2]??'student';
if (!in_array($route,['student','adviser','nurse'],true)) exit(2);
$q=$db->prepare("SELECT * FROM users WHERE role=? AND status='active' LIMIT 1");$q->execute([$role]);$u=$q->fetch();
$_SESSION['user']=$u;$_SESSION['created']=time();$_SESSION['last_activity']=time();$_SESSION['last_db_activity_update']=time();
ob_start();register_shutdown_function(function()use($role,$route){
 $html=ob_get_clean();
 $checks=['role'=>$role,'route'=>$route,'chat_forms'=>substr_count($html,'id="chatForm"'),'textareas'=>substr_count($html,'id="messageInput"'),'shared_client'=>str_contains($html,'js/messenger-client.js'),'staff_section'=>str_contains($html,'id="availableStaffSection"'),'medical_notice'=>str_contains($html,'id="chatEmergencyBanner"'),'new_message'=>str_contains($html,'onclick="openNewMessageModal()"'),'sos_modal'=>str_contains($html,'data-bs-target="#sosModal"'),'sos_teacher'=>str_contains($html,'pages/adviser/emergencies.php'),'nurse_filters'=>str_contains($html,'data-filter="teacher"'),'clinical_tools'=>str_contains($html,'id="aiSummaryBox"'),'composer_row'=>str_contains($html,'class="messenger-compose-row"')];
 echo json_encode($checks).PHP_EOL;
 if ($role !== 'admin' && ($checks['chat_forms']!==1 || $checks['textareas']!==1 || !$checks['shared_client'] || !$checks['composer_row'])) { exit(1); }
 if ($role==='admin' && $checks['chat_forms']!==0) exit(1);
});
require __DIR__.'/../pages/'.$route.'/messages.php';
