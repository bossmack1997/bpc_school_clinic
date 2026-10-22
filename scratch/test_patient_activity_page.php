<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
ini_set('session.save_path',__DIR__);
require __DIR__.'/../config/config.php';
$role=$argv[1]??'student';$route=$role==='teacher'?'adviser':'student';
$q=getDB()->prepare("SELECT * FROM users WHERE role=? AND status='active' LIMIT 1");$q->execute([$role]);$user=$q->fetch();
$_SESSION['user']=$user;$_SESSION['created']=time();$_SESSION['last_activity']=time();$_SESSION['last_db_activity_update']=time();
$_SERVER['SCRIPT_NAME']='/bpc_clinic_system/pages/'.$route.'/my_qr.php';$_SERVER['REQUEST_URI']=$_SERVER['SCRIPT_NAME'];
ob_start();register_shutdown_function(function()use($role){$html=ob_get_clean();
 $checks=[substr_count($html,'id="visitTimelineToggle"')===1,str_contains($html,'aria-expanded="false"'),str_contains($html,'class="collapse" id="visitTimelinePanel"'),strpos($html,'id="qrTimeline"')<strpos($html,'id="qrScanHistoryRows"'),str_contains($html,'js/patient-activity.js'),str_contains($html,'id="qrCanvas"'),str_contains($html,'id="printQrBtn"'),str_contains($html,'id="mica-assistant"')];
 if(in_array(false,$checks,true)){echo 'FAIL '.$role.': '.json_encode($checks).PHP_EOL;exit(1);}echo 'PASS '.$role.': rendered collapsed timeline, following QR history, shared script, preserved QR print and MICA controls.'.PHP_EOL;
});
require __DIR__.'/../pages/'.$route.'/my_qr.php';
