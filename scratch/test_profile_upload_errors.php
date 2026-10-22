<?php
if (PHP_SAPI !== 'cli') exit(1);
ini_set('session.save_path', __DIR__);
require __DIR__ . '/../config/config.php';
$user = getDB()->query("SELECT * FROM users WHERE status='active' ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$user) { echo "SKIP no active user fixture\n"; exit; }
$_SESSION['user'] = $user;
$_SESSION['credential_version'] = (int)$user['credential_version'];
$_SESSION['created'] = $_SESSION['last_activity'] = time();
$_SESSION['csrf_token'] = 'profile-upload-test';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REQUEST_URI'] = '/api/profile.php';
$_SERVER['SCRIPT_NAME'] = '/api/profile.php';
$_POST = ['action'=>'upload_picture', 'csrf_token'=>'profile-upload-test'];
$_REQUEST = $_POST;
ob_start();
register_shutdown_function(function () {
    $json = json_decode(ob_get_clean(), true);
    $ok = is_array($json) && ($json['success'] ?? true) === false && str_contains($json['message'] ?? '', 'select an image');
    echo ($ok ? 'PASS' : 'FAIL') . " profile upload returns a useful JSON validation error\n";
    if (!$ok) exit(1);
});
require __DIR__ . '/../api/profile.php';
