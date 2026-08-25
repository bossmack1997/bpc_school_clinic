<?php
require_once __DIR__ . '/config/config.php';
$db = getDB();

echo "=== TESTING EMERGENCY / SOS WORKFLOW ===" . PHP_EOL;

// Find student user
$stuUser = $db->query("SELECT u.id, u.username, s.id AS student_id FROM users u JOIN students s ON s.user_id = u.id WHERE u.status = 'active' LIMIT 1")->fetch();
if (!$stuUser) {
    echo "FAILED: No active student found for test." . PHP_EOL;
    exit;
}

echo "Testing SOS trigger for Student User ID: {$stuUser['id']} (Student ID: {$stuUser['student_id']})" . PHP_EOL;

login_user($stuUser);

// Simulate SOS post request from student
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = [
    'action' => 'sos_emergency',
    'emergency_type' => 'Chest Pain',
    'description' => 'Test emergency via automated verification script',
    'latitude' => '14.85250',
    'longitude' => '120.81600',
    'csrf_token' => csrf_token()
];
$_REQUEST['action'] = 'sos_emergency';

ob_start();
include __DIR__ . '/api/ai_assistant.php';
$sosResJson = ob_get_clean();
$sosRes = json_decode($sosResJson, true);

if ($sosRes && isset($sosRes['success']) && $sosRes['success'] === true) {
    echo "SUCCESS: SOS emergency created successfully!" . PHP_EOL;
    $emgId = $sosRes['emergency_id'] ?? null;
    if ($emgId) {
        $emg = $db->query("SELECT * FROM sos_emergencies WHERE id = $emgId")->fetch();
        echo "Emergency Record #{$emg['id']} created with type '{$emg['emergency_type']}', status '{$emg['status']}'" . PHP_EOL;

        // Check if in-app notifications were created for nurses/admins
        $notifCount = $db->query("SELECT COUNT(*) FROM notifications WHERE sos_emergency_id = $emgId")->fetchColumn();
        echo "In-app notifications created: $notifCount" . PHP_EOL;

        // Cleanup test emergency record & notifications
        $db->exec("DELETE FROM notifications WHERE sos_emergency_id = $emgId");
        $db->exec("DELETE FROM sos_emergencies WHERE id = $emgId");
        echo "Test emergency record cleaned up." . PHP_EOL;
    }
} else {
    echo "FAILED: SOS emergency creation failed. Response: $sosResJson" . PHP_EOL;
}
