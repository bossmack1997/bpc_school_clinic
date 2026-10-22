<?php
require_once __DIR__.'/../config/config.php';
require_role('nurse');
require_once __DIR__.'/../includes/nurse_dashboard_data.php';
header('Cache-Control: no-store, private');
try { json_response(true,['data'=>NurseDashboardData::snapshot(getDB())]); }
catch(Throwable $e){error_log('[Nurse dashboard] '.$e->getMessage());http_response_code(503);json_response(false,[],'Clinic activity is temporarily unavailable. Please try again.');}
