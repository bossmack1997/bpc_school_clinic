<?php
require_once __DIR__ . '/../config/config.php';

$files = ['patients.php', 'medical_records.php', 'vaccinations.php', 'walkin.php', 'emergencies.php', 'certificates.php', 'cron_automation.php', 'health_monitoring.php', 'announcements.php'];

foreach ($files as $fname) {
    $file = BASE_PATH . '/api/' . $fname;
    if (!file_exists($file)) continue;
    echo "=================================================\n";
    echo "FILE: $fname\n";
    echo "=================================================\n";
    $content = file_get_contents($file);
    $lines = explode("\n", $content);
    
    $actionLines = [];
    foreach ($lines as $idx => $line) {
        if (preg_match('/(require_role|require_login|is_logged_in|action|case\s+[\'\"])/i', $line)) {
            $actionLines[] = ($idx + 1) . ": " . trim($line);
        }
    }
    echo implode("\n", array_slice($actionLines, 0, 25)) . "\n";
}

