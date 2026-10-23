<?php
require_once __DIR__ . '/../../includes/doctor_page.php';
require_doctor();
// Clinical monitoring is provided by the existing oversight dashboard.
header('Location: ' . APP_URL . '/pages/doctor/dashboard.php');
exit;
