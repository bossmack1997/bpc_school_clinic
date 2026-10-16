<?php
/**
 * Test runner for all API endpoints in BPC Clinic System
 */
require_once __DIR__ . '/../config/config.php';
$db = getDB();

echo "=======================================================\n";
echo "TESTING API ENDPOINTS WITH DIFFERENT USER ROLES\n";
echo "=======================================================\n";

// Find test users for each role
$roles = ['admin', 'nurse', 'student', 'teacher'];
$testUsers = [];

foreach ($roles as $r) {
    $dbRole = ($r === 'teacher') ? ['teacher', 'adviser'] : [$r];
    $inClause = implode(',', array_map(fn($x) => "'$x'", $dbRole));
    $stmt = $db->query("SELECT * FROM users WHERE role IN ($inClause) AND status = 'active' LIMIT 1");
    $u = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($u) {
        $testUsers[$r] = $u;
        echo "Found test user for role '$r': {$u['username']} (ID: {$u['id']})\n";
    } else {
        echo "[!] No active test user found for role '$r'\n";
    }
}

$endpoints = [
    'api/queue.php?action=public_display' => ['unauth' => 200],
    'api/queue.php?action=get_status' => ['unauth' => 200, 'student' => 200, 'nurse' => 200],
    'api/inventory.php?action=list' => ['unauth' => 302, 'student' => 302, 'nurse' => 200, 'admin' => 200],
    'api/inventory.php?action=stats' => ['unauth' => 302, 'student' => 302, 'nurse' => 200, 'admin' => 200],
    'api/inventory.php?action=categories' => ['unauth' => 302, 'nurse' => 200, 'admin' => 200],
    'api/users.php?action=list_admins' => ['unauth' => 302, 'student' => 302, 'nurse' => 302, 'admin' => 200],
    'api/reports.php?action=generate' => ['unauth' => 302, 'student' => 302, 'nurse' => 200, 'admin' => 200],
    'api/settings.php?action=list' => ['unauth' => 302, 'student' => 302, 'nurse' => 302, 'admin' => 200],
    'api/sections.php?action=list' => ['unauth' => 302, 'student' => 302, 'admin' => 200, 'teacher' => 200],
    'api/my_advisory.php?action=list' => ['unauth' => 302, 'student' => 302, 'nurse' => 302, 'teacher' => 200],
    'api/notifications.php?action=list' => ['unauth' => 401, 'student' => 200, 'nurse' => 200, 'admin' => 200, 'teacher' => 200],
    'api/qr_codes.php?action=my_qr' => ['unauth' => 401, 'student' => 200],
    'api/ai_assistant.php?action=daily_summary' => ['unauth' => 401, 'student' => 200, 'nurse' => 200, 'admin' => 200],
];

echo "\nExecuting HTTP curl tests against localhost Apache...\n";
$baseUrl = 'http://localhost/bpc_clinic_system/';

foreach ($endpoints as $ep => $expected) {
    echo "\nEndpoint: $ep\n";
    
    // 1. Test unauthenticated
    $ch = curl_init($baseUrl . $ep);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_NOBODY, false);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    echo "  [Unauthenticated] HTTP Code: $httpCode (Expected: " . ($expected['unauth'] ?? 'any') . ")";
    if (isset($expected['unauth']) && $httpCode !== $expected['unauth']) {
        echo " -> MISMATCH!";
    } else {
        echo " -> OK";
    }
    echo "\n";
}

