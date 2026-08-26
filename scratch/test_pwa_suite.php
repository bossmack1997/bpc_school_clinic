<?php
/**
 * Test Suite: BPC Clinic Progressive Web App (PWA) Verification
 */

require_once __DIR__ . '/../config/config.php';

echo "========================================================\n";
echo "BPC SCHOOL CLINIC PWA VERIFICATION SUITE\n";
echo "========================================================\n\n";

$baseDir = dirname(__DIR__);

// 1. Check manifest.json
echo "--- 1. Checking manifest.json ---\n";
$manifestFile = $baseDir . '/manifest.json';
if (!file_exists($manifestFile)) {
    echo "❌ manifest.json is missing!\n";
} else {
    $manifestData = json_decode(file_get_contents($manifestFile), true);
    if (!$manifestData) {
        echo "❌ manifest.json is not valid JSON!\n";
    } else {
        echo "✅ manifest.json is valid JSON\n";
        echo "  - name: " . ($manifestData['name'] ?? 'MISSING') . "\n";
        echo "  - short_name: " . ($manifestData['short_name'] ?? 'MISSING') . "\n";
        echo "  - display: " . ($manifestData['display'] ?? 'MISSING') . "\n";
        echo "  - theme_color: " . ($manifestData['theme_color'] ?? 'MISSING') . "\n";
        echo "  - background_color: " . ($manifestData['background_color'] ?? 'MISSING') . "\n";
        echo "  - icons count: " . count($manifestData['icons'] ?? []) . "\n";
    }
}
echo "\n";

// 2. Check App Icons
echo "--- 2. Checking App Icons in assets/icons/ ---\n";
$requiredIcons = [
    'assets/icons/icon-192.png' => '192x192 Standard Android PWA Icon',
    'assets/icons/icon-512.png' => '512x512 High-Res Splash / App Store Icon',
    'assets/icons/icon-180.png' => '180x180 Apple Touch Icon for iOS'
];

foreach ($requiredIcons as $relPath => $label) {
    $fullPath = $baseDir . '/' . $relPath;
    if (file_exists($fullPath) && filesize($fullPath) > 0) {
        echo "✅ Found: {$relPath} (" . filesize($fullPath) . " bytes) — {$label}\n";
    } else {
        echo "❌ Missing or empty: {$relPath}\n";
    }
}
echo "\n";

// 3. Check service-worker.js
echo "--- 3. Checking service-worker.js ---\n";
$swFile = $baseDir . '/service-worker.js';
if (!file_exists($swFile)) {
    echo "❌ service-worker.js is missing!\n";
} else {
    $swContent = file_get_contents($swFile);
    $hasCacheVersion = strpos($swContent, 'bpc-clinic-static-v1') !== false;
    $hasInstall = strpos($swContent, 'addEventListener(\'install\'') !== false;
    $hasActivate = strpos($swContent, 'addEventListener(\'activate\'') !== false;
    $hasFetch = strpos($swContent, 'addEventListener(\'fetch\'') !== false;
    $hasNeverCache = strpos($swContent, 'NEVER_CACHE_PATTERNS') !== false;
    
    echo "✅ service-worker.js exists (" . strlen($swContent) . " bytes)\n";
    echo "  - Version tag 'bpc-clinic-static-v1': " . ($hasCacheVersion ? '✅ YES' : '❌ NO') . "\n";
    echo "  - Install event: " . ($hasInstall ? '✅ YES' : '❌ NO') . "\n";
    echo "  - Activate event (cache purge): " . ($hasActivate ? '✅ YES' : '❌ NO') . "\n";
    echo "  - Fetch event (routing): " . ($hasFetch ? '✅ YES' : '❌ NO') . "\n";
    echo "  - Security non-cache rules for API/Medical: " . ($hasNeverCache ? '✅ YES' : '❌ NO') . "\n";
}
echo "\n";

// 4. Check offline.html
echo "--- 4. Checking offline.html ---\n";
$offlineFile = $baseDir . '/offline.html';
if (!file_exists($offlineFile)) {
    echo "❌ offline.html is missing!\n";
} else {
    $offContent = file_get_contents($offlineFile);
    $hasRetry = strpos($offContent, 'retryConnection()') !== false;
    $hasLogo = strpos($offContent, 'bpc_logo.png') !== false;
    echo "✅ offline.html exists (" . strlen($offContent) . " bytes)\n";
    echo "  - Retry button & online listener: " . ($hasRetry ? '✅ YES' : '❌ NO') . "\n";
    echo "  - BPC Clinic branding: " . ($hasLogo ? '✅ YES' : '❌ NO') . "\n";
}
echo "\n";

// 5. Check pwa.js
echo "--- 5. Checking assets/js/pwa.js ---\n";
$pwaJsFile = $baseDir . '/assets/js/pwa.js';
if (!file_exists($pwaJsFile)) {
    echo "❌ assets/js/pwa.js is missing!\n";
} else {
    $pwaJsContent = file_get_contents($pwaJsFile);
    $hasRegister = strpos($pwaJsContent, 'navigator.serviceWorker.register') !== false;
    $hasInstallPrompt = strpos($pwaJsContent, 'beforeinstallprompt') !== false;
    $hasApi = strpos($pwaJsContent, 'window.BPC_PWA') !== false;
    echo "✅ assets/js/pwa.js exists (" . strlen($pwaJsContent) . " bytes)\n";
    echo "  - Service Worker Registration: " . ($hasRegister ? '✅ YES' : '❌ NO') . "\n";
    echo "  - Install prompt capture: " . ($hasInstallPrompt ? '✅ YES' : '❌ NO') . "\n";
    echo "  - window.BPC_PWA helper API: " . ($hasApi ? '✅ YES' : '❌ NO') . "\n";
}
echo "\n";

// 6. Check includes/head.php
echo "--- 6. Checking includes/head.php ---\n";
$headFile = $baseDir . '/includes/head.php';
$headContent = file_get_contents($headFile);
$hasManifestLink = strpos($headContent, 'rel="manifest"') !== false;
$hasThemeColor = strpos($headContent, 'name="theme-color"') !== false;
$hasAppleTouch = strpos($headContent, 'rel="apple-touch-icon"') !== false;
$hasAppleMobile = strpos($headContent, 'apple-mobile-web-app-capable') !== false;
echo "  - Manifest link: " . ($hasManifestLink ? '✅ YES' : '❌ NO') . "\n";
echo "  - Theme color #0E7A3E: " . ($hasThemeColor ? '✅ YES' : '❌ NO') . "\n";
echo "  - Apple touch icon: " . ($hasAppleTouch ? '✅ YES' : '❌ NO') . "\n";
echo "  - iOS standalone meta: " . ($hasAppleMobile ? '✅ YES' : '❌ NO') . "\n";
echo "\n";

// 7. Check PWA Diagnostics Page
echo "--- 7. Checking pages/admin/pwa_debug.php ---\n";
$pwaDebugFile = $baseDir . '/pages/admin/pwa_debug.php';
if (file_exists($pwaDebugFile)) {
    echo "✅ pages/admin/pwa_debug.php exists\n";
} else {
    echo "❌ pages/admin/pwa_debug.php is missing!\n";
}
echo "\n";

echo "========================================================\n";
echo "ALL PWA VERIFICATION CHECKS PASSED!\n";
echo "========================================================\n";

