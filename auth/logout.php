<?php
require_once __DIR__ . '/../config/config.php';
logout_user();
redirect(APP_URL . '/auth/login.php');
