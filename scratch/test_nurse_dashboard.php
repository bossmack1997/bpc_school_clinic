<?php
require __DIR__.'/../includes/nurse_dashboard_data.php';
function check($ok,$message){if(!$ok)throw new RuntimeException($message);}
$rows=[['day'=>'2026-10-01','hour'=>8,'total'=>3],['day'=>'2026-10-12','hour'=>10,'total'=>4],['day'=>'2026-10-17','hour'=>0,'total'=>1],['day'=>'2026-10-17','hour'=>23,'total'=>2]];
$r=NurseDashboardData::ranges($rows,'2026-10-17');
check(count($r['today']['values'])===12&&array_sum($r['today']['values'])===3,'Today includes midnight and late evening');
check(array_sum($r['week']['values'])===7,'Calendar week excludes earlier month visits');
check(count($r['month']['values'])===31&&array_sum($r['month']['values'])===10,'Calendar month');
check(array_sum(NurseDashboardData::ranges([],'2026-10-17')['today']['values'])===0,'Empty activity');
check(count(NurseDashboardData::ranges([],'2028-02-29')['month']['values'])===29,'Leap month');
$r=NurseDashboardData::ranges([['day'=>'2025-12-29','hour'=>12,'total'=>2],['day'=>'2026-01-01','hour'=>13,'total'=>1]],'2026-01-01');
check(array_sum($r['week']['values'])===3&&array_sum($r['month']['values'])===1,'Week crossing calendar year');
$s= file_get_contents(__DIR__.'/../pages/nurse/dashboard.php');
check(strpos($s,'id="nurseGreeting"')<strpos($s,'id="nurseAttentionTitle"')&&strpos($s,'id="nurseAttentionTitle"')<strpos($s,'id="admActivityTitle"'),'Panel order');
foreach(['Live Clinic Queue','dbg-stats--4','dbg-monitor','Quick Actions'] as $old)check(!str_contains($s,$old),'Redundant layout removed');
foreach(["require_role('nurse')",'components/sidebar.php','components/navbar.php','components/footer.php','includes/patient_workspace_modal.php','includes/qr_scanner_modal.php','/pages/nurse/emergencies.php','/pages/nurse/inventory.php','/pages/nurse/queue.php'] as $marker)check(str_contains($s,$marker),'Preserved nurse feature: '.$marker);
echo "PASS: calendar today/week/month, all-hour totals, empty/leap/year boundaries, panel order, nurse authorization and preserved feature includes/routes.\n";
