<?php
require_once __DIR__ . '/config/config.php';

// Prevent caching of the redirect logic
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');

if (is_logged_in()) {
    redirect(dashboard_url_for(current_user()['role']));
} else {
    attempt_remember_me_login();
    if (is_logged_in()) {
        redirect(dashboard_url_for(current_user()['role']));
    }
    redirect(APP_URL . '/auth/login.php');
}
