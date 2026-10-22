<?php
/**
 * api/cron_automation.php
 * ------------------------------------------------------------------
 * AI-Enhanced School Clinic Management System for Bulacan Polytechnic College
 * Healthcare Service Improvement
 *
 * Background automation runner for MICA Clinic Intelligence.
 * Can be executed via:
 * 1. CLI: php api/cron_automation.php
 * 2. Scheduled web cron with secret token: api/cron_automation.php with a configured CRON_SECRET_TOKEN
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/ai/MICAAutomationEngine.php';

$isCli = (php_sapi_name() === 'cli');

if (!$isCli) {
    // Web request security verification
    $token = $_GET['token'] ?? $_SERVER['HTTP_X_CRON_AUTH'] ?? '';
    $expectedToken = defined('CRON_SECRET_TOKEN') ? (string)CRON_SECRET_TOKEN : (string)getenv('CRON_SECRET_TOKEN');
    $authorizedAdmin = false; // Interactive scans use the POST + CSRF protected assistant API.
    $authorizedToken = $expectedToken !== '' && is_string($token) && hash_equals($expectedToken, $token);
    if (!$authorizedAdmin && !$authorizedToken) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Unauthorized cron execution.']);
        exit;
    }
}

$db = getDB();
$engine = new MICAAutomationEngine($db);
$results = $engine->runAllTasks();

if ($isCli) {
    echo json_encode($results, JSON_PRETTY_PRINT).PHP_EOL;
} else {
    header('Content-Type: application/json');
    echo json_encode(['success'=>true,'data'=>$results]);
}
