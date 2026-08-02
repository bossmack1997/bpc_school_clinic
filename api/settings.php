<?php
require_once __DIR__ . '/../config/config.php';
require_role('admin');
header('Content-Type: application/json');

$db = getDB();
$user = current_user();
$action = $_REQUEST['action'] ?? '';

if ($action === 'list') {
    $stmt = $db->query("SELECT setting_key, setting_value FROM system_settings ORDER BY setting_key");
    $settings = [];
    foreach ($stmt->fetchAll() as $row) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
    json_response(true, ['data' => $settings]);
}

if ($action === 'update') {
    csrf_verify_or_die();
    $updates = $_POST['settings'] ?? [];
    if (empty($updates) || !is_array($updates)) json_response(false, [], 'No settings provided.');

    $stmt = $db->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = ?");
    foreach ($updates as $key => $value) {
        $stmt->execute([trim($value), trim($key)]);
    }
    log_activity($user['id'], 'Updated system settings', 'settings');
    json_response(true, [], 'Settings saved successfully.');
}

json_response(false, [], 'Unknown action');
