<?php
/** Included by the disposable HTTP fixture with --student-ui --provisioning. */
if (!isset($database, $uiStudent, $uiNurse, $uiIds) ||
    !preg_match('/^bpc_mis_test_[a-f0-9]{10}$/D', $database) ||
    $db->query('SELECT DATABASE()')->fetchColumn() !== $database) {
    throw new RuntimeException('Disposable student/provisioning fixtures required.');
}

function workflowNav(array $response): string {
    if (!preg_match('/<nav class="sidebar-nav">(.*?)<\/nav>/s', $response['body'], $match)) {
        throw new RuntimeException('Shared navigation missing.');
    }
    return $match[1];
}

(function () use ($db, $uiStudent, $uiNurse, $uiIds, $c, $invitee, $actor, $base): void {
$settings = misRequest($c, '/pages/admin/settings.php');
$nav = workflowNav($settings);
checkMis($settings['status'] === 200 && str_contains($nav, 'Create Accounts'), 'MIS navigation uses Create Accounts');
foreach (['security.php', 'system_monitoring.php', 'activity_logs.php', 'backups.php'] as $route) {
    checkMis(!str_contains($nav, $route) && str_contains($settings['body'], 'href="'.$route.'"'), 'MIS Settings owns protected tool '.$route);
}
$doctorNav = workflowNav(misRequest($invitee, '/pages/doctor/dashboard.php'));
foreach (['Dashboard', 'Clinic Queue', 'Consultations', 'Patient Records', 'Manage Nurses', 'Reports', 'Messages', 'Settings'] as $label) {
    checkMis(str_contains($doctorNav, '<span>'.$label.'</span>'), 'Doctor navigation exposes '.$label);
}
checkMis(!str_contains($doctorNav, '/pages/admin/') && !str_contains($doctorNav, 'inventory.php'), 'Doctor main navigation stays focused on care');
$nursePage = misRequest($uiNurse, '/pages/nurse/dashboard.php');
checkMis(str_contains(workflowNav($nursePage), 'patient_history.php') && str_contains($nursePage['body'], 'href="announcements.php"') && str_contains($nursePage['body'], 'href="../report_exports.php"'), 'Nurse records and supporting clinic tools remain reachable');

$profile = misRequest($uiStudent, '/pages/student/profile.php?user_id='.$actor.'&student_id=999999');
foreach (['Personal Information', 'School Information', 'Contact Information', 'Health Information'] as $section) {
    checkMis($profile['status'] === 200 && str_contains($profile['body'], $section), 'Student profile renders '.$section);
}
checkMis(str_contains($profile['body'], 'UI-STUDENT-01') && !str_contains($profile['body'], 'value="mis@example.invalid"'), 'Profile ignores caller-supplied account IDs');
preg_match('/name="csrf-token" content="([^"]+)"/', $profile['body'], $profileTokenMatch);
$profileToken = html_entity_decode($profileTokenMatch[1]);
$savedProfile = misRequest($uiStudent, '/api/profile.php', ['action'=>'update_personal_info','csrf_token'=>$profileToken,'first_name'=>str_repeat('Alexandra',5),'last_name'=>str_repeat('Longname',5),'middle_name'=>'Updated Middle','user_id'=>$actor]);
checkMis($savedProfile['status'] === 200 && (json_decode($savedProfile['body'], true)['success'] ?? false) && $db->query('SELECT middle_name FROM users WHERE id='.(int)$uiIds['student'])->fetchColumn() === 'Updated Middle', 'Student middle name persists using session identity');
$studentProfileId = $db->query('SELECT id FROM students WHERE user_id='.(int)$uiIds['student'])->fetchColumn();
// Temporarily unlink only this synthetic profile, then restore it.
$db->prepare('UPDATE students SET user_id=? WHERE id=?')->execute([$actor, $studentProfileId]);
try {
    $profileWrite = misRequest($uiStudent, '/api/profile.php', ['action'=>'update_personal_info','csrf_token'=>$profileToken,'first_name'=>'MustNotSave']);
    checkMis($profileWrite['status'] === 409 && $db->query('SELECT first_name FROM users WHERE id='.(int)$uiIds['student'])->fetchColumn() !== 'MustNotSave', 'Unlinked student cannot partially update account information');
    $missing = misRequest($uiStudent, '/pages/student/profile.php');
    checkMis($missing['status'] === 200 && str_contains($missing['body'], 'not linked to your account') && str_contains($missing['body'], 'sidebar-nav') && !str_contains($missing['body'], 'id="infoForm"'), 'Missing student mapping renders safe explanation without editable replacement data');
} finally {
    $db->prepare('UPDATE students SET user_id=? WHERE id=?')->execute([$uiIds['student'], $studentProfileId]);
}
$db->exec('RENAME TABLE students TO workflow_students_unavailable');
try {
    $unavailable = misRequest($uiStudent, '/pages/student/profile.php');
    checkMis($unavailable['status'] === 503 && str_contains($unavailable['body'], 'Your profile could not be loaded.') && str_contains($unavailable['body'], 'Try again') && !str_contains($unavailable['body'], 'id="infoForm"'), 'Profile database failure returns recoverable HTML without partial editable data');
} finally {
    $db->exec('RENAME TABLE workflow_students_unavailable TO students');
}

$batch = [
    ['role'=>'student','username'=>'summary_ready','email'=>'summary_ready@example.invalid','first_name'=>'Ready','last_name'=>'Fixture','institutional_id'=>'SUMMARY-READY'],
    ['role'=>'student','username'=>'mis_fixture','email'=>'summary_duplicate@example.invalid','first_name'=>'Duplicate','last_name'=>'Fixture','institutional_id'=>'SUMMARY-DUPLICATE'],
    ['role'=>'student','username'=>'summary_invalid','email'=>'not-an-email','first_name'=>'Invalid','last_name'=>'Fixture','institutional_id'=>'SUMMARY-INVALID'],
];
$service = new AccountProvisioningService($db);
checkMis(array_column($service->preview($batch), 'status') === ['ready','duplicate','invalid'], 'Import separates ready, duplicate and invalid entries');
$csv = tempnam(__DIR__.'/../scratch', 'workflow_import_');
try {
    $handle = fopen($csv, 'wb'); fputcsv($handle, AccountProvisioningService::COLUMNS);
    foreach ($batch as $row) fputcsv($handle, array_map(fn($key) => $row[$key], AccountProvisioningService::COLUMNS));
    fclose($handle);
    curl_setopt_array($c, [CURLOPT_URL=>$base.'/pages/admin/provision_accounts.php', CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>['action'=>'preview_csv','csrf_token'=>misField(misRequest($c, '/pages/admin/provision_accounts.php'), 'csrf_token'),'accounts_csv'=>new CURLFile($csv, 'text/csv', 'accounts.csv')]]);
    $summary = curl_exec($c);
    checkMis(curl_getinfo($c, CURLINFO_RESPONSE_CODE) === 200 && str_contains($summary, 'Ready: 1. Invalid: 1. Duplicates: 1.') && !str_contains($summary, 'name="nonce"'), 'Bulk preview displays counts and prevents submitting a rejected batch');
} finally { unlink($csv); }

// A connection-local temporary table shadows the fixture table without touching
// its clinical history. HTTP requests use their own connections.
$db->exec("CREATE TEMPORARY TABLE queue_numbers (created_at DATETIME, status VARCHAR(20), served_at DATETIME, completed_at DATETIME)");
try {
    checkMis(calculate_queue_wait_time($db, 3) === null, 'Queue estimate stays unavailable without observed visits');
    for ($i=0; $i<5; $i++) $db->exec("INSERT INTO queue_numbers VALUES (NOW(),'completed',NOW()-INTERVAL 10 MINUTE,NOW()-INTERVAL 5 MINUTE)");
    checkMis(calculate_queue_wait_time($db, 3) === 15, 'Queue estimate uses five recent consistent observations');
    $db->exec("INSERT INTO queue_numbers VALUES (NOW(),'completed',NOW()-INTERVAL 65 MINUTE,NOW()-INTERVAL 5 MINUTE)");
    checkMis(calculate_queue_wait_time($db, 3) === null, 'Queue estimate stays unavailable when visit durations vary widely');
    $db->exec("UPDATE queue_numbers SET completed_at=NOW()-INTERVAL 3 HOUR, served_at=NOW()-INTERVAL 185 MINUTE");
    checkMis(calculate_queue_wait_time($db, 3) === null, 'Queue estimate rejects stale observations');
} finally { $db->exec('DROP TEMPORARY TABLE queue_numbers'); }

// Count all scheduled visits, independently of the ten-row dashboard list.
require_once __DIR__.'/../includes/doctor_clinical_service.php';
$doctorRecord=$db->query("SELECT * FROM users WHERE username='accept_http'")->fetch();
$dashboardService=new DoctorClinicalService($db,$doctorRecord);
$dashboard=$dashboardService->commandCenter();
checkMis($dashboard['unavailable']===[],'All Doctor Dashboard sources resolve after prerequisite migrations');
$db->beginTransaction();
try {
    $patientId=(int)$db->query('SELECT id FROM students WHERE user_id='.(int)$uiIds['student'])->fetchColumn();
    $insert=$db->prepare("INSERT INTO consultations(student_id,patient_type,clinician_user_id,consultation_date,chief_complaint,follow_up_date) VALUES (?,'student',?,NOW(),'Synthetic dashboard count',CURDATE()+INTERVAL 2 DAY)");
    for($i=0;$i<12;$i++)$insert->execute([$patientId,$doctorRecord['id']]);
    $counted=$dashboardService->commandCenter();
    checkMis($counted['followup_visits']===$dashboard['followup_visits']+12&&count($counted['followups'])===10,'Follow-up card counts every scheduled visit beyond limited display list');
} finally { $db->rollBack(); }
})();
