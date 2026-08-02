<?php
require_once __DIR__ . '/config/config.php';

if (is_logged_in()) {
    redirect(dashboard_url_for(current_user()['role']));
} else {
    attempt_remember_me_login();
    if (is_logged_in()) {
        redirect(dashboard_url_for(current_user()['role']));
    }
    redirect(APP_URL . '/auth/login.php');
}
