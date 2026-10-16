<?php
require_once __DIR__ . '/../config/config.php';

$dirs = ['admin', 'nurse', 'student', 'adviser'];
foreach ($dirs as $dir) {
    echo "=== PAGES IN pages/$dir ===\n";
    $files = glob(BASE_PATH . "/pages/$dir/*.php");
    foreach ($files as $f) {
        $name = basename($f);
        $content = file_get_contents($f);
        
        $roleMatch = [];
        if (preg_match('/require_role\(([^\)]+)\)/', $content, $m)) {
            $roleMatch[] = trim($m[1]);
        }
        $hasRequireLogin = str_contains($content, 'require_login()');
        $status = !empty($roleMatch) ? "ROLES: " . implode(', ', $roleMatch) : ($hasRequireLogin ? "REQUIRE_LOGIN ONLY (NO ROLE CHECK!)" : "NO AUTH CHECK!");
        
        echo sprintf("  %-25s : %s\n", $name, $status);
    }
}

