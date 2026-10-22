<?php
require_once __DIR__ . '/config/config.php';

// Prevent caching of dashboard redirect page
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');

require_login();
$currentUser = current_user();
redirect(dashboard_url_for($currentUser['role'] ?? 'student'));
