<?php
require_once __DIR__ . '/config/config.php';
require_login();
redirect(dashboard_url_for(current_user()['role']));
