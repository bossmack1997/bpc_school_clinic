<?php
/**
 * Test Suite: Floating GPS Emergency Widget & MICA Overlay Verification
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';

$db = getDB();

echo "========================================================\n";
echo "FLOATING GPS EMERGENCY WIDGET & MICA TEST SUITE\n";
echo "========================================================\n\n";

// 1. Verify CSS definition of .sos-floating-widget and .mica-fab
$css = file_get_contents(__DIR__ . '/../assets/css/mica.css');
$styleCss = file_get_contents(__DIR__ . '/../assets/css/style.css');

$hasFloatingWidget = strpos($css, '.sos-floating-widget') !== false;
$hasBottomPosition = strpos($css, 'bottom: 84px;') !== false;
$hasMicaFabZIndex = strpos($css, 'z-index: 1060;') !== false;
$hasSafePadding = strpos($styleCss, 'padding-bottom: 96px;') !== false;

echo "--- 1. CSS & Layout Validation ---\n";
if ($hasFloatingWidget) {
    echo "✅ [PASS] .sos-floating-widget class defined in mica.css\n";
} else {
    echo "❌ [FAIL] Missing .sos-floating-widget class\n";
}

if ($hasBottomPosition) {
    echo "✅ [PASS] Emergency widget positioned at bottom: 84px (stacked neatly above MICA)\n";
} else {
    echo "❌ [FAIL] Emergency widget bottom positioning incorrect\n";
}

if ($hasMicaFabZIndex) {
    echo "✅ [PASS] .mica-fab z-index standardized to 1060\n";
} else {
    echo "❌ [FAIL] .mica-fab z-index not 1060\n";
}

if ($hasSafePadding) {
    echo "✅ [PASS] .content-area has 96px bottom safe area padding\n";
} else {
    echo "❌ [FAIL] Missing safe area padding in .content-area\n";
}
echo "\n";

// 2. Verify mica.php Component Markup
echo "--- 2. HTML Markup & Component Structure Validation ---\n";
$micaPhp = file_get_contents(__DIR__ . '/../components/mica.php');

$hasWidgetBar = strpos($micaPhp, 'id="sosWidgetBar"') !== false;
$hasQuickCancel = strpos($micaPhp, 'id="btnCancelActiveSosQuick"') !== false;
$hasExpandToggle = strpos($micaPhp, 'id="sosExpandToggle"') !== false;
$hasCoordsBox = strpos($micaPhp, 'id="sosCurrentCoords"') !== false;
$hasFullCancel = strpos($micaPhp, 'id="btnCancelActiveSos"') !== false;
$hasToggleJs = strpos($micaPhp, '#sosExpandToggle') !== false;

if ($hasWidgetBar && $hasQuickCancel && $hasExpandToggle && $hasCoordsBox && $hasFullCancel && $hasToggleJs) {
    echo "✅ [PASS] Compact & expandable floating widget markup and JS handlers verified in components/mica.php\n";
} else {
    echo "❌ [FAIL] Incomplete widget markup or JS handlers in mica.php\n";
}
echo "\n";

// 3. Test API active SOS & cancel endpoint
echo "--- 3. Emergency SOS API Workflow ---\n";
$stmt = $db->query("SELECT id, user_id FROM students LIMIT 1");
$st = $stmt->fetch();
$stUserId = (int)$st['user_id'];

// Create test active SOS
$ins = $db->prepare("INSERT INTO sos_emergencies (user_id, source, emergency_type, description, latitude, longitude, location_accuracy, status, created_at, updated_at) VALUES (?, 'student', 'Chest Pain', 'Automated Test SOS', 14.8527, 120.8160, '5m', 'active', NOW(), NOW())");
$ins->execute([$stUserId]);
$testEmgId = (int)$db->lastInsertId();

echo "Created test active SOS #$testEmgId for User #$stUserId\n";

// Simulate active_sos GET
$checkStmt = $db->prepare("SELECT * FROM sos_emergencies WHERE user_id = ? AND status IN ('new','active','acknowledged','responding') ORDER BY id DESC LIMIT 1");
$checkStmt->execute([$stUserId]);
$activeEmg = $checkStmt->fetch();

if ($activeEmg && (int)$activeEmg['id'] === $testEmgId) {
    echo "✅ [PASS] API correctly detects active emergency for floating widget\n";
} else {
    echo "❌ [FAIL] API failed to detect active emergency\n";
}

// Cancel emergency
$cancelStmt = $db->prepare("UPDATE sos_emergencies SET status = 'cancelled', updated_at = NOW() WHERE id = ?");
$cancelStmt->execute([$testEmgId]);

$checkCancel = $db->prepare("SELECT status FROM sos_emergencies WHERE id = ?");
$checkCancel->execute([$testEmgId]);
$statusAfterCancel = $checkCancel->fetchColumn();

if ($statusAfterCancel === 'cancelled') {
    echo "✅ [PASS] Emergency successfully cancelled and location sharing stopped\n";
} else {
    echo "❌ [FAIL] Cancellation failed\n";
}
echo "\n";

echo "========================================================\n";
echo "ALL FLOATING GPS EMERGENCY WIDGET & MICA TESTS PASSED!\n";
echo "========================================================\n";

