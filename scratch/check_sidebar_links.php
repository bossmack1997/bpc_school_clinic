<?php
require_once __DIR__ . '/../config/config.php';

$sbNav = [
    'admin' => [
        'sections' => [
            'Overview' => [
                ['dashboard', 'Dashboard', 'dashboard.php'],
            ],
            'Clinic Operations' => [
                ['shield-alert', 'Emergencies', 'emergencies.php'],
                ['qr', 'QR & Verification', 'qr_management.php'],
            ],
            'Management' => [
                ['users', 'Users & Roles', 'users.php'],
                ['shield-lock', 'Admin Accounts', 'admin_accounts.php'],
            ],
            'Inventory' => [
                ['pill', 'Medicine Stocks', 'inventory.php'],
            ],
            'Communication' => [
                ['megaphone', 'Announcements', 'announcements.php'],
                ['bell', 'Notifications', 'notifications.php'],
            ],
            'Reports' => [
                ['chart', 'Reports', 'reports.php'],
                ['history', 'Activity Logs', 'activity_logs.php'],
            ],
            'Settings' => [
                ['settings', 'Settings', 'settings.php'],
                ['lock', 'Security', 'security.php'],
            ],
            'Account' => [
                ['user', 'My Profile', 'profile.php'],
            ],
        ],
    ],
    'nurse' => [
        'sections' => [
            'Overview' => [
                ['dashboard', 'Dashboard', 'dashboard.php'],
            ],
            'Clinic Operations' => [
                ['list', 'Live Queue', 'queue.php'],
                ['stethoscope', 'Consultation Desk', 'walkin.php'],
                ['clipboard-list', 'Student Records', 'medical_records.php'],
                ['shield-alert', 'Emergency Center', 'emergencies.php'],
            ],
            'Inventory & Docs' => [
                ['pill', 'Medicine Inventory', 'inventory.php'],
                ['file-medical', 'Certificates', 'certificates.php'],
            ],
            'Reports & Comms' => [
                ['chart', 'Reports', 'reports.php'],
                ['chat', 'Messages', 'messages.php'],
                ['megaphone', 'Announcements', 'announcements.php'],
            ],
            'Account' => [
                ['user', 'Profile', 'profile.php'],
            ],
        ],
    ],
    'student' => [
        'sections' => [
            'Overview' => [
                ['dashboard', 'Dashboard', 'dashboard.php'],
            ],
            'Health Services' => [
                ['list', 'Clinic Queue', 'queue.php'],
                ['file-medical', 'Health Record', 'medical_record.php'],
                ['clipboard-check', 'Certificates', 'certificates.php'],
                ['heart-pulse', 'Health Monitoring', 'health_monitoring.php'],
                ['qr', 'Health Pass QR', 'my_qr.php'],
            ],
            'Emergency & Comms' => [
                ['chat', 'Messages', 'messages.php'],
                ['bell', 'Notifications', 'notifications.php'],
            ],
            'Account' => [
                ['user', 'My Profile', 'profile.php'],
            ],
        ],
    ],
    'teacher' => [
        'sections' => [
            'Overview' => [
                ['dashboard', 'Dashboard', 'dashboard.php'],
            ],
            'My Advisory' => [
                ['users', 'My Students', 'students.php'],
                ['list', 'Clinic Queue', 'queue.php'],
            ],
            'Emergency & Comms' => [
                ['shield-alert', 'Emergency', 'emergencies.php'],
                ['megaphone', 'Announcements', 'announcements.php'],
                ['chat', 'Messages', 'messages.php'],
            ],
            'Account' => [
                ['user', 'Profile', 'profile.php'],
            ],
        ],
    ],
];

echo "Checking sidebar target files existence...\n";
foreach ($sbNav as $role => $data) {
    $dir = ($role === 'teacher') ? 'adviser' : $role;
    foreach ($data['sections'] as $sec => $items) {
        foreach ($items as $item) {
            $file = $item[2];
            if (!$file) continue;
            $path = BASE_PATH . '/pages/' . $dir . '/' . $file;
            if (!file_exists($path)) {
                echo "[MISSING LINK] Role '$role' ($sec -> {$item[1]}): $path DOES NOT EXIST!\n";
            } else {
                echo "[OK] Role '$role': $dir/$file exists.\n";
            }
        }
    }
}

