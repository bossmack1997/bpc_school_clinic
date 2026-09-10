<?php
require_once __DIR__ . '/../../config/config.php';
require_role('doctor');
$pageTitle = 'Notifications';
$roleDir = 'doctor';
include __DIR__ . '/../nurse/notifications.php';