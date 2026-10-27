<?php
if(!isset($database)||!preg_match('/^bpc_mis_test_[a-f0-9]{10}$/D',$database)||$db->query('SELECT DATABASE()')->fetchColumn()!==$database)throw new RuntimeException('Disposable fixture required.');
require_once __DIR__.'/../includes/report_export_service.php';
$exportRequest=['type'=>'consultations','from'=>date('Y-m-01'),'to'=>date('Y-m-d'),'format'=>'csv','csrf_token'=>$token];
$exportCsv=misRequest($invitee,'/api/report_exports.php',$exportRequest);
checkMis($exportCsv['status']===200&&str_contains($exportCsv['headers']['content-type']??'','text/csv')&&str_starts_with($exportCsv['body'],"\xEF\xBB\xBFconsultation_id,"),'Authorized Doctor downloads UTF-8 clinical CSV');
checkMis(str_contains($exportCsv['body'],'CLIN-ST-01')&&str_contains($exportCsv['body'],'CLIN-TE-01'),'Clinical export includes Student and Teacher encounters');
$exportExcel=misRequest($invitee,'/api/report_exports.php',array_replace($exportRequest,['format'=>'xlsx']));
checkMis($exportExcel['status']===200&&str_starts_with($exportExcel['body'],'PK'),'Authorized Doctor downloads real Excel OOXML archive');
$exportFile=tempnam(sys_get_temp_dir(),'bpc_export_test_');
try{file_put_contents($exportFile,$exportExcel['body']);$z=new ZipArchive();checkMis($z->open($exportFile)===true&&str_contains($z->getFromName('xl/worksheets/sheet1.xml'),'CLIN-TE-01')&&$z->locateName('[Content_Types].xml')!==false,'Excel contains valid worksheet and package manifest');$z->close();}finally{unlink($exportFile);}
checkMis(misRequest($invitee,'/api/report_exports.php',array_replace($exportRequest,['type'=>'accounts']))['status']===403,'Doctor cannot export MIS account inventory');
$misExportToken=misField(misRequest($c,'/pages/admin/provision_accounts.php'),'csrf_token');
checkMis(misRequest($c,'/api/report_exports.php',array_replace($exportRequest,['csrf_token'=>$misExportToken]))['status']===403,'MIS cannot export clinical records');
checkMis(misRequest($c,'/api/report_exports.php',array_replace($exportRequest,['type'=>'accounts','csrf_token'=>$misExportToken]))['status']===200,'MIS exports privacy-limited account inventory');
checkMis(misRequest($invitee,'/api/report_exports.php',array_replace($exportRequest,['from'=>'2026-02-30']))['status']===422,'Export rejects impossible dates');
checkMis(misRequest($invitee,'/api/report_exports.php',array_replace($exportRequest,['csrf_token'=>'invalid']))['status']===403,'Export requires CSRF');
checkMis(misRequest($invitee,'/api/report_exports.php',array_replace($exportRequest,['type'=>'inventory']))['status']===200,'Doctor inventory export uses read-only scope');
checkMis(misRequest($invitee,'/api/report_exports.php',array_replace($exportRequest,['type'=>'queue']))['status']===200,'Doctor queue export returns aggregated summary');
checkMis(ReportExportService::csvCell(' =HYPERLINK("https://example.invalid")')==="' =HYPERLINK(\"https://example.invalid\")",'CSV formula injection is neutralized');
$auditResponse=misRequest($c,'/api/activity_logs.php?action=list');$audit=json_decode($auditResponse['body'],true);
checkMis($auditResponse['status']===200&&!str_contains($auditResponse['body'],'ip_address')&&!in_array('Physician assessment completed',array_column($audit['data'],'action'),true),'Technical log UI excludes IP fields and clinical audit records');
checkMis(misRequest($invitee,'/api/activity_logs.php?action=list')['status']===403,'Doctor cannot retrieve technical audit logs');
checkMis((int)$db->query("SELECT COUNT(*) FROM activity_logs WHERE action='Physician assessment completed'")->fetchColumn()>0,'Clinical audit history remains stored');
