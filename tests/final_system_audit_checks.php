<?php
/** Evidence collection only; reproduces gaps against disposable synthetic records. */
if (!isset($database,$uiStudent,$uiTeacher,$uiNurse,$uiIds) || !preg_match('/^bpc_mis_test_[a-f0-9]{10}$/D',$database) || $db->query('SELECT DATABASE()')->fetchColumn() !== $database) throw new RuntimeException('Disposable student UI fixture required.');
(function () use ($db,$uiStudent,$uiTeacher,$uiNurse,$uiIds,$c): void {
    $results = [];
    $id = (int)$db->query('SELECT id FROM students WHERE user_id='.(int)$uiIds['student'])->fetchColumn();
    $db->prepare("INSERT INTO vaccinations(student_id,vaccine_name,dose_number,date_administered,administered_by) VALUES (?,'AUDIT-SYNTHETIC-VACCINE','1',CURDATE(),'Synthetic fixture')")->execute([$id]);
    $vaccination = (int)$db->lastInsertId();
    $response = misRequest($uiTeacher, '/api/vaccinations.php?action=list&student_id='.$id);
    $results['teacher_unassigned_vaccination_read'] = ['expected'=>'403 or authorized scoped result without unrelated records','http_status'=>$response['status'],'unrelated_synthetic_record_returned'=>str_contains($response['body'],'AUDIT-SYNTHETIC-VACCINE')];
    $page = misRequest($uiNurse, '/pages/nurse/profile.php');
    preg_match('/name="csrf-token" content="([^"]+)"/', $page['body'], $token);
    $before = (int)$db->query('SELECT COUNT(*) FROM activity_logs')->fetchColumn();
    $response = misRequest($uiNurse, '/api/vaccinations.php', ['action'=>'delete','id'=>$vaccination,'csrf_token'=>html_entity_decode($token[1])]);
    $results['vaccination_delete_history'] = ['expected'=>'preserved clinical history plus server audit','http_status'=>$response['status'],'record_preserved'=>(bool)$db->query('SELECT COUNT(*) FROM vaccinations WHERE id='.$vaccination)->fetchColumn(),'activity_rows_added'=>(int)$db->query('SELECT COUNT(*) FROM activity_logs')->fetchColumn()-$before];
    $response = misRequest($uiStudent, '/api/activity_logs.php?action=list');
    $results['own_account_activity'] = ['expected'=>'scoped own account activity endpoint','technical_history_http_status'=>$response['status'],'note'=>'403 protects technical history; no own account activity action exists here. Existing patient timeline is a different clinical view.'];
    $response = misRequest($c, '/pages/admin/clinical_legacy_dashboard.php');
    $results['mis_legacy_clinical_dashboard'] = ['expected'=>'403 or redirect to technical dashboard','http_status'=>$response['status'],'clinical_activity_content'=>str_contains($response['body'],'Consultation volume and live queue')];
    require_once __DIR__.'/../includes/ai/MICAContextService.php';
    $user = $db->query('SELECT * FROM users WHERE id='.(int)$uiIds['student'])->fetch();
    $context = (new MICAContextService($db,$user))->getSystemContextString();
    $results['mica_static_operating_claim'] = ['unverified_24_hour_monitoring_claim'=>str_contains($context,'Emergency SOS monitored 24/7'),'fixed_weekday_schedule'=>str_contains($context,'Monday - Friday, 8:00 AM - 5:00 PM')];
    file_put_contents(__DIR__.'/../scratch/final-system-audit-evidence-20261029.json', json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    checkMis(true,'Final system audit observations saved using disposable records (findings are not fixes)');
})();
