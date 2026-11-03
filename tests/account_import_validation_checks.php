<?php
// Included in the guarded, disposable provisioning HTTP fixture.
$baseImport=['role'=>'student','username'=>'normalize_probe','email'=>'normalize_probe@example.invalid','first_name'=>'Normalize','last_name'=>'Fixture','institutional_id'=>'MA23015625'];
$initialUsers=(int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn();
$normal=$provision->preview([$baseImport])[0];
checkMis($normal['status']==='ready'&&$normal['account']['institutional_id']==='MA23015625','Compact institutional ID is valid');
$spaced=array_replace($baseImport,['institutional_id'=>'MA 23015625']);
$normal=$provision->preview([$spaced])[0];
checkMis($normal['status']==='ready'&&$normal['account']['institutional_id']==='MA23015625'&&$normal['original_institutional_id']==='MA 23015625','Preview preserves original ID and normalizes formatting spaces');
checkMis(AccountProvisioningService::normalizeInstitutionalId("ma\u{00A0}23015625")==='MA23015625'&&AccountProvisioningService::normalizeInstitutionalId('MA-23015625')==='MA-23015625','ID normalization handles case and nonbreaking spaces while preserving punctuation');
$fileDuplicates=$provision->preview([$baseImport,array_replace($spaced,['email'=>'NORMALIZE_PROBE@example.invalid','username'=>'NORMALIZE_PROBE'])]);
checkMis($fileDuplicates[1]['status']==='duplicate'&&count($fileDuplicates[1]['field_errors'])===3,'Within-file checks independently detect normalized ID, email and username duplicates');
checkMis((int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn()===$initialUsers,'Validation and preview create no accounts');
require_once __DIR__.'/../includes/report_export_service.php';
$spaceWorkbook=ReportExportService::xlsx(['headers'=>AccountProvisioningService::COLUMNS,'rows'=>[array_values($spaced)]]);
try{
    $excelPreview=$provision->preview(AccountProvisioningService::readXlsx($spaceWorkbook))[0];
    checkMis($excelPreview['status']==='ready'&&$excelPreview['account']['institutional_id']==='MA23015625','Excel parsing uses the same ID normalization as manual and CSV input');
}finally{unlink($spaceWorkbook);}
$createdNormalized=$provision->create($actor,[$spaced]);$normalizedUser=$createdNormalized[0]['user_id'];
checkMis($db->query('SELECT student_number FROM students WHERE user_id='.$normalizedUser)->fetchColumn()==='MA23015625','Manual creation stores the same normalized institutional ID');
$existingId=$provision->preview([array_replace($baseImport,['username'=>'unique_id_probe','email'=>'unique_id_probe@example.invalid'])])[0];
checkMis($existingId['status']==='duplicate'&&$existingId['errors']===['This institutional ID is already registered.'],'Existing institutional ID has a specific independent error');
$existingEmail=$provision->preview([array_replace($baseImport,['institutional_id'=>'UNIQUE-EMAIL','username'=>'unique_email_probe','email'=>'NORMALIZE_PROBE@example.invalid'])])[0];
checkMis($existingEmail['errors']===['This email address is already in use.'],'Existing email duplicate is checked case-insensitively');
$existingUsername=$provision->preview([array_replace($baseImport,['institutional_id'=>'UNIQUE-USERNAME','username'=>'NORMALIZE_PROBE','email'=>'unique_username_probe@example.invalid'])])[0];
checkMis($existingUsername['errors']===['This username is already taken.'],'Existing username duplicate is checked independently and case-insensitively');
$multi=$provision->preview([array_replace($baseImport,['institutional_id'=>'BAD@ID','first_name'=>''])])[0];
checkMis($multi['status']==='invalid'&&count($multi['field_errors'])===4&&str_contains(implode(' ',$multi['errors']),'unsupported characters'),'Invalid status takes priority and retains all formatting and duplicate errors');
$invalidEmail=$provision->preview([array_replace($baseImport,['email'=>'bad','username'=>'fresh_email_probe','institutional_id'=>'FRESH-EMAIL'])])[0];
checkMis($invalidEmail['status']==='invalid'&&isset($invalidEmail['field_errors']['email'])&&!str_contains(implode(' ',$invalidEmail['errors']),'already'),'Invalid email is not mislabeled as duplicate');
$missing=$provision->preview([array_fill_keys(AccountProvisioningService::COLUMNS,'')])[0];
checkMis($missing['status']==='invalid'&&count($missing['field_errors'])===6,'Missing fields each receive validation guidance without blank duplicate errors');
// Legacy spaced IDs are compared in normalized form but never rewritten.
$db->prepare('UPDATE students SET student_number=? WHERE user_id=?')->execute(['MA 23015625',$normalizedUser]);
$legacy=$provision->preview([array_replace($baseImport,['username'=>'legacy_id_probe','email'=>'legacy_id_probe@example.invalid'])])[0];
checkMis($legacy['status']==='duplicate'&&$db->query('SELECT student_number FROM students WHERE user_id='.$normalizedUser)->fetchColumn()==='MA 23015625','Normalized duplicate comparison preserves legacy stored IDs');
$mixed=[array_replace($baseImport,['username'=>'mixed_student_probe','email'=>'mixed_student_probe@example.invalid','institutional_id'=>'MIX 001']),array_replace($baseImport,['role'=>'teacher','username'=>'mixed_teacher_probe','email'=>'mixed_teacher_probe@example.invalid','institutional_id'=>'EMP 001'])];
$mixedReport=$provision->importAccounts($actor,$mixed);
checkMis(array_column($mixedReport,'status')===['successful','successful'],'Mixed Student and Teacher bulk imports create only valid role-scoped accounts');
checkMis($db->query("SELECT t.employee_id FROM teachers t JOIN users u ON u.id=t.user_id WHERE u.username='mixed_teacher_probe'")->fetchColumn()==='EMP001','Teacher import uses the same normalized Employee ID');
$repeat=$provision->importAccounts($actor,$mixed);
checkMis(array_column($repeat,'status')===['skipped','skipped'],'Repeated mixed import skips existing accounts');
checkMis($db->query('SELECT TIMESTAMPDIFF(SECOND,NOW(),expires_at) FROM account_activation_invitations WHERE user_id='.$normalizedUser)->fetchColumn()>86300&&isset($links['normalize_probe@example.invalid']),'Successful account creation retains the 24-hour secure email invitation');
$upload=tempnam(sys_get_temp_dir(),'bpc_normalization_');
try{
    $uploadRows=[array_replace($baseImport,['username'=>'http_space_probe','email'=>'http_space_probe@example.invalid','institutional_id'=>'HTTP 001']),array_replace($baseImport,['role'=>'teacher','username'=>'http_teacher_probe','email'=>'http_teacher_probe@example.invalid','institutional_id'=>'HTTP T001']),array_replace($baseImport,['institutional_id'=>'INVALID@ID'])];
    $csv=fopen($upload,'w');fputcsv($csv,AccountProvisioningService::COLUMNS);foreach($uploadRows as $row)fputcsv($csv,array_values($row));fclose($csv);
    $beforeHttp=(int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn();
    curl_setopt_array($c,[CURLOPT_URL=>$base.'/pages/admin/provision_accounts.php',CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>['action'=>'preview_csv','csrf_token'=>$provisionToken,'accounts_csv'=>new CURLFile($upload,'text/csv','accounts.csv')]]);
    $body=curl_exec($c);curl_setopt($c,CURLOPT_POSTFIELDS,'');
    checkMis(str_contains($body,'Ready: 2. Invalid: 1. Duplicates: 0.')&&str_contains($body,'Original: HTTP 001')&&str_contains($body,'Normalized: HTTP001'),'HTTP preview displays original/normalized IDs and accurate mixed-row counters');
    checkMis((int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn()===$beforeHttp,'HTTP file preview creates no accounts');
    preg_match('/name="nonce" value="([^"]+)"/',$body,$nonce);
    $confirmed=misRequest($c,'/pages/admin/provision_accounts.php',['action'=>'create','csrf_token'=>$provisionToken,'nonce'=>$nonce[1]??'']);
    checkMis($confirmed['status']===200&&str_contains($confirmed['body'],'Successful: 2. Skipped: 1. Failed: 0.')&&str_contains($confirmed['body'],'unsupported characters'),'Confirmation imports ready rows only and shows every skipped-row reason');
    checkMis((int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn()===$beforeHttp+2,'Confirmation preserves existing users and adds only two ready accounts');
}finally{unlink($upload);}
