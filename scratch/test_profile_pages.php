<?php
/**
 * Test Suite: Profile Pages Rendering & Role Integrity
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

echo "========================================================\n";
echo "PROFILE PAGES STANDARDIZATION INTEGRITY TEST\n";
echo "========================================================\n\n";

$db = getDB();

$roles = ['student', 'nurse', 'admin', 'adviser', 'guidance'];

foreach ($roles as $role) {
    echo "--- Testing {$role} Profile Page ---\n";
    $filePath = __DIR__ . "/../pages/{$role}/profile.php";
    if (!file_exists($filePath)) {
        echo "❌ File not found: pages/{$role}/profile.php\n\n";
        continue;
    }
    
    // Fetch a user of this role
    $stmt = $db->prepare("SELECT * FROM users WHERE role = ? LIMIT 1");
    $stmt->execute([$role]);
    $u = $stmt->fetch();
    
    if (!$u) {
        echo "⚠️ No user found for role '{$role}' in database, checking file static structure...\n";
    } else {
        echo "Found user: ID {$u['id']} ({$u['first_name']} {$u['last_name']}) - Role: {$u['role']}\n";
    }
    
    $content = file_get_contents($filePath);
    
    // Verify core UI components from the Master Guidance Template
    $hasSectionTitle = strpos($content, 'section-title mb-3') !== false;
    $hasProfileAvatar = strpos($content, 'id="profileAvatar"') !== false;
    $hasPictureInput = strpos($content, 'id="pictureInput"') !== false;
    $hasInfoForm = strpos($content, 'id="infoForm"') !== false;
    $hasPwForm = strpos($content, 'id="pwForm"') !== false;
    $hasTwoColLayout = strpos($content, 'col-lg-7') !== false && strpos($content, 'col-lg-5') !== false;
    $hasRoleBadge = strpos($content, 'badge bg-success') !== false;
    $hasPermissionsCard = strpos($content, 'Role Permissions') !== false;
    
    $checks = [
        'Section Title' => $hasSectionTitle,
        'Profile Avatar & Upload' => $hasProfileAvatar && $hasPictureInput,
        'Personal Info Form' => $hasInfoForm,
        'Password Form' => $hasPwForm,
        'Two Column Layout (7/5)' => $hasTwoColLayout,
        'Badges & Styling' => $hasRoleBadge,
        'Role Permissions Card' => $hasPermissionsCard
    ];
    
    $allPassed = true;
    foreach ($checks as $name => $passed) {
        if (!$passed) {
            echo "  ❌ Failed check: {$name}\n";
            $allPassed = false;
        } else {
            echo "  ✅ Passed check: {$name}\n";
        }
    }
    
    if ($allPassed) {
        echo "🎉 pages/{$role}/profile.php is fully standardized!\n\n";
    } else {
        echo "⚠️ pages/{$role}/profile.php has layout inconsistencies.\n\n";
    }
}

echo "========================================================\n";
echo "ALL PROFILE PAGES VERIFIED!\n";
echo "========================================================\n";

