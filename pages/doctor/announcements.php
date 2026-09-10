<?php
require_once __DIR__ . '/../../config/config.php';
require_role('doctor');
$pageTitle = 'Clinic Announcements';
$roleDir = 'doctor';
include __DIR__ . '/../nurse/announcements.php';