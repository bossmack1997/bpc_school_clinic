<?php
require_once __DIR__ . '/../config/config.php';

// Prevent browser caching of this page and any previous protected content
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');

logout_user();
redirect(APP_URL . '/auth/login.php');
