<?php
if (PHP_SAPI !== 'cli') exit(1);
ini_set('session.save_path', __DIR__);
require __DIR__ . '/../config/config.php';
$db = getDB();
$user = $db->query("SELECT * FROM users WHERE role='admin' AND admin_type='co_admin' AND status='active' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$user) { echo "SKIP no active co-admin fixture\n"; exit; }
$db->exec('CREATE TEMPORARY TABLE doctor_test_profiles LIKE admin_profiles');
$db->exec('ALTER TABLE doctor_test_profiles RENAME TO admin_profiles');
$q = $db->prepare("INSERT INTO admin_profiles(user_id,profile,official_designation,appointment_reference,verified_by) VALUES (?,'doctor','School Physician','QA fixture',?)");
$q->execute([$user['id'], $user['id']]);
$_SESSION['user'] = $user;
$_SESSION['credential_version'] = (int)$user['credential_version'];
$_SESSION['created'] = $_SESSION['last_activity'] = time();
$redirect = dashboard_url_for('admin');
$profileOk = permission_profile(current_user(), $db) === 'doctor';
$redirectOk = str_ends_with($redirect, '/pages/doctor/dashboard.php');
ob_start();
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/pages/doctor/dashboard.php';
$_SERVER['REQUEST_URI'] = $_SERVER['SCRIPT_NAME'];
register_shutdown_function(function () use ($profileOk, $redirectOk) {
    $html = ob_get_clean();
    $ok = $profileOk && $redirectOk && http_response_code() !== 403 && str_contains($html, 'Clinical Oversight Dashboard');
    echo ($ok ? 'PASS' : 'FAIL') . " Doctor profile, redirect, and dashboard rendering\n";
    if (!$ok) exit(1);
});
require __DIR__ . '/../pages/doctor/dashboard.php';
