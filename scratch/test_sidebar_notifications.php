<?php
define('APP_URL','/clinic');$testRole='admin';function current_user(){return ['role'=>$GLOBALS['testRole']];}function e($s){return htmlspecialchars((string)$s);}function bpc_icon(...$args){return '';}
foreach(['admin','nurse','student','teacher'] as $testRole){$_SERVER['SCRIPT_NAME']='/pages/'.$testRole.'/dashboard.php';$_GET=[];ob_start();include __DIR__.'/../components/sidebar.php';$html=ob_get_clean();
 if(str_contains($html,'/announcements.php')||str_contains($html,'/notifications.php'))throw new Exception('Removed navigation item remains');
 if($testRole==='admin'&&str_contains($html,'Communication'))throw new Exception('Empty group remains');
 if($testRole==='admin'&&str_contains($html,'/emergencies.php'))throw new Exception('Admin emergency sidebar remains');
 if($testRole==='nurse'&&!str_contains($html,'/emergencies.php'))throw new Exception('Nurse emergency navigation affected');
 if($testRole==='student'&&!str_contains($html,'data-action="open-sos"'))throw new Exception('Student SOS affected');
}
echo "PASS: all role sidebar entries removed, empty admin groups removed, nurse emergency/student SOS navigation preserved.\n";
