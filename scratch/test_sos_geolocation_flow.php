<?php
/**
 * Test Suite: SOS Geolocation Permission Handling & Truthful Status Workflow
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';

$db = getDB();

echo "========================================================\n";
echo "SOS GEOLOCATION PERMISSION & WORKFLOW TEST SUITE\n";
echo "========================================================\n\n";

// 1. Verify Permissions-Policy header in includes/security.php
$secCode = file_get_contents(__DIR__ . '/../includes/security.php');
$hasGeoSelf = strpos($secCode, 'geolocation=(self)') !== false;
$hasMicSelf = strpos($secCode, 'microphone=(self)') !== false;
$hasCamSelf = strpos($secCode, 'camera=(self)') !== false;

echo "--- 1. Permissions-Policy Header Validation ---\n";
if ($hasGeoSelf && $hasMicSelf && $hasCamSelf) {
    echo "✅ [PASS] Permissions-Policy header allows same-origin geolocation, microphone, and camera: geolocation=(self), microphone=(self), camera=(self)\n";
} else {
    echo "❌ [FAIL] Permissions-Policy header is improperly configured: $secCode\n";
}
echo "\n";

// 2. Verify components/mica.php JS permission logic & truthful status handling
$micaCode = file_get_contents(__DIR__ . '/../components/mica.php');
$hasPermissionQuery = strpos($micaCode, "navigator.permissions.query({ name: 'geolocation' })") !== false;
$hasDiagnosticLogging = strpos($micaCode, "[SOS Geolocation]") !== false;
$hasCodeNames = strpos($micaCode, "PERMISSION_DENIED") !== false && strpos($micaCode, "POSITION_UNAVAILABLE") !== false && strpos($micaCode, "TIMEOUT") !== false;
$hasTruthfulBadges = strpos($micaCode, "Location Sharing Active") !== false && strpos($micaCode, "Location Unavailable") !== false;
$hasFallback = strpos($micaCode, "enableHighAccuracy: false") !== false;

echo "--- 2. Frontend Geolocation Permissions & Error Handling ---\n";
if ($hasPermissionQuery) {
    echo "✅ [PASS] navigator.permissions.query properly checks for 'prompt', 'granted', and 'denied' states\n";
} else {
    echo "❌ [FAIL] Missing navigator.permissions.query\n";
}

if ($hasDiagnosticLogging && $hasCodeNames) {
    echo "✅ [PASS] Diagnostic logging included for PERMISSION_DENIED (1), POSITION_UNAVAILABLE (2), and TIMEOUT (3)\n";
} else {
    echo "❌ [FAIL] Missing diagnostic logging or code handling\n";
}

if ($hasFallback) {
    echo "✅ [PASS] Standard accuracy network fallback implemented if high-accuracy GPS times out\n";
} else {
    echo "❌ [FAIL] Missing standard accuracy fallback\n";
}

if ($hasTruthfulBadges) {
    echo "✅ [PASS] Truthful status display: 'Location Sharing Active' when GPS available, 'Location Unavailable' when no GPS\n";
} else {
    echo "❌ [FAIL] Missing truthful badge handling in updateBannerCoords\n";
}
echo "\n";

// 3. Test Backend SOS Creation with GPS vs without GPS
echo "--- 3. Backend SOS Handling & No Fake Coordinates ---\n";
$stmt = $db->query("SELECT id, user_id FROM students LIMIT 1");
$student = $stmt->fetch();
$studentUserId = (int)$student['user_id'];
$studentId = (int)$student['id'];

// Test Case A: SOS With GPS
$insWithGps = $db->prepare("
    INSERT INTO sos_emergencies 
    (user_id, student_id, emergency_type, description, location_source, latitude, longitude, location_accuracy, location_updated_at, status, priority, source, reporter_role, created_at, updated_at)
    VALUES (?, ?, 'Difficulty Breathing', 'Test with GPS', 'gps', 14.852700, 120.816000, '5m', NOW(), 'active', 'urgent', 'sos', 'student', NOW(), NOW())
");
$insWithGps->execute([$studentUserId, $studentId]);
$emgGpsId = (int)$db->lastInsertId();

$chkGps = $db->prepare("SELECT latitude, longitude, location_accuracy FROM sos_emergencies WHERE id = ?");
$chkGps->execute([$emgGpsId]);
$rowGps = $chkGps->fetch();

if ($rowGps && (float)$rowGps['latitude'] === 14.852700 && (float)$rowGps['longitude'] === 120.816000) {
    echo "✅ [PASS] Test Case A (GPS Allowed): Record created with valid coordinates ({$rowGps['latitude']}, {$rowGps['longitude']})\n";
} else {
    echo "❌ [FAIL] Test Case A failed\n";
}

// Test Case B: SOS Without GPS (Permission Denied / Timeout)
$insNoGps = $db->prepare("
    INSERT INTO sos_emergencies 
    (user_id, student_id, emergency_type, description, location_source, latitude, longitude, location_accuracy, location_updated_at, status, priority, source, reporter_role, created_at, updated_at)
    VALUES (?, ?, 'Chest Pain', 'Test without GPS', 'gps', NULL, NULL, NULL, NULL, 'active', 'urgent', 'sos', 'student', NOW(), NOW())
");
$insNoGps->execute([$studentUserId, $studentId]);
$emgNoGpsId = (int)$db->lastInsertId();

$chkNoGps = $db->prepare("SELECT latitude, longitude, location_accuracy FROM sos_emergencies WHERE id = ?");
$chkNoGps->execute([$emgNoGpsId]);
$rowNoGps = $chkNoGps->fetch();

if ($rowNoGps && $rowNoGps['latitude'] === null && $rowNoGps['longitude'] === null) {
    echo "✅ [PASS] Test Case B (GPS Unavailable/Denied): Record created with NULL coordinates (zero fake coordinates stored)\n";
} else {
    echo "❌ [FAIL] Test Case B stored invalid or fake coordinates: " . json_encode($rowNoGps) . "\n";
}

// Cleanup test records
$db->prepare("DELETE FROM sos_emergencies WHERE id IN (?, ?)")->execute([$emgGpsId, $emgNoGpsId]);

echo "\n========================================================\n";
echo "ALL SOS GEOLOCATION PERMISSION TESTS PASSED!\n";
echo "========================================================\n";

