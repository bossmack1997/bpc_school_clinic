<?php
require_once __DIR__ . '/../config/config.php';

$apiFiles = glob(BASE_PATH . '/api/*.php');

foreach ($apiFiles as $file) {
    $fname = basename($file);
    echo "=================================================\n";
    echo "FILE: $fname\n";
    echo "=================================================\n";
    $content = file_get_contents($file);
    $lines = explode("\n", $content);
    
    // Print first 40 lines or lines containing role, action, switch
    $actionLines = [];
    foreach ($lines as $idx => $line) {
        if (preg_match('/(require_role|require_login|is_logged_in|action|case\s+[\'\"])/i', $line)) {
            $actionLines[] = ($idx + 1) . ": " . trim($line);
        }
    }
    echo implode("\n", array_slice($actionLines, 0, 25)) . "\n";
}

