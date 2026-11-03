<?php
/** Included only in the guarded disposable HTTP fixture. */
$auditInsert=$db->prepare('INSERT INTO activity_logs(user_id,action,module,details,created_at) VALUES (?,?,?,?,?)');
$auditInsert->execute([$actor,'Updated profile info','profile','PRIVATE-PATIENT-OTP-TOKEN','2026-10-31 08:01:00']);
$auditInsert->execute([$actor,'Nurse clinical review','nurse_clinical_authorization','Account #123; decision rejected; reference retained in restricted review history.','2026-10-31 08:02:00']);
$auditInsert->execute([$actor,'Secret token PRIVATE-ACTION','medical_records','PRIVATE-PATIENT-OTP-TOKEN','2026-10-31 08:03:00']);
$auditCount=(int)$db->query('SELECT COUNT(*) FROM activity_logs')->fetchColumn();
$history=misRequest($c,'/api/activity_logs.php?action=list&from=2026-10-31&to=2026-10-31');
$historyData=json_decode($history['body'],true);
checkMis($history['status']===200 && $historyData['total']===3,'History date filters include recorded profile and clinical activity summaries');
checkMis(array_column($historyData['data'],'activity')===['Other recorded activity','Rejected Nurse credentials','Updated profile'],'History newest-first order and safe activity names reflect actual events');
checkMis(!str_contains($history['body'],'PRIVATE-')&&!array_key_exists('details',$historyData['data'][0])&&!array_key_exists('module',$historyData['data'][0])&&!array_key_exists('action',$historyData['data'][0]),'History API omits raw action, details, modules and sensitive contents');
$filtered=json_decode(misRequest($c,'/api/activity_logs.php?action=list&search=Rejected%20Nurse%20credentials&from=2026-10-31&to=2026-10-31')['body'],true);
checkMis($filtered['total']===1,'Search matches friendly activity wording');
$secretSearch=json_decode(misRequest($c,'/api/activity_logs.php?action=list&search=PRIVATE-PATIENT-OTP-TOKEN')['body'],true);
checkMis($secretSearch['total']===0,'Hidden audit details cannot be searched to reveal private contents');
checkMis(json_decode(misRequest($c,'/api/activity_logs.php?action=list&search=NoSuchActivity123')['body'],true)['total']===0,'History has a genuine empty search response');
$paged=json_decode(misRequest($c,'/api/activity_logs.php?action=list&page=999')['body'],true);
checkMis($paged['page']===$paged['pages']&&count($paged['data'])<=30,'History pagination clamps to the final page');
checkMis(misRequest($invitee,'/api/activity_logs.php?action=list')['status']===403,'Doctor cannot access MIS activity summaries');
checkMis(misRequest($c,'/api/activity_logs.php?action=list&search%5B%5D=bad')['status']===400,'History rejects malformed search filters');
checkMis(misRequest($c,'/api/activity_logs.php?action=list&from=2026-02-30')['status']===400,'History rejects impossible dates');
checkMis(misRequest($c,'/api/activity_logs.php?action=list&from=2026-11-02&to=2026-11-01')['status']===400,'History rejects a reversed date range');
checkMis($historyData['data'][0]['created_at']==='2026-10-31 08:03:00','History preserves the recorded Manila timestamp');
$historyPage=misRequest($c,'/pages/admin/activity_logs.php');
checkMis($historyPage['status']===200&&str_contains($historyPage['body'],'<th>Activity</th>')&&!str_contains($historyPage['body'],'<th>Module</th>')&&!str_contains($historyPage['body'],'<th>Details</th>')&&!str_contains($historyPage['body'],'logModule'),'History page has four columns and no module filter');
checkMis((int)$db->query('SELECT COUNT(*) FROM activity_logs')->fetchColumn()===$auditCount,'History reads preserve all audit rows');
