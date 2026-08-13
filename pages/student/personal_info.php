<?php
require_once __DIR__ . '/../../config/config.php';
require_role('student');
header('Location: ' . APP_URL . '/pages/student/profile.php');
exit;
