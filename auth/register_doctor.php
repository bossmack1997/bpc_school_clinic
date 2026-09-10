<?php
/**
 * auth/register_doctor.php
 * Retired route: only the four approved clinic roles may self-register.
 */
require_once __DIR__ . '/../config/config.php';
set_flash('info', 'Doctor registration has been retired. Please choose Student, Teacher, Nurse, or Administration.');
redirect(APP_URL . '/auth/register_hub.php');
