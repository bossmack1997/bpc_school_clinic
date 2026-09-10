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
 * 2. Scheduled web cron with secret token: api/cron_automation.php?token=bpc_clinic_cron_secret
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/ai/MICAAutomationEngine.php';

$isCli = (php_sapi_name() === 'cli');

if (!$isCli) {
    // Web request security verification
    $token = $_GET['token'] ?? $_SERVER['HTTP_X_CRON_AUTH'] ?? '';
    $expectedToken = defined('CRON_SECRET_TOKEN') ? CRON_SECRET_TOKEN : 'bpc_clinic_cron_secret';
    
    if (!is_logged_in() && $token !== $expectedToken) {
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
    echo "==============================================================\n";
    echo "  MICA CLINIC INTELLIGENCE: SCHEDULED AUTOMATION RUN          \n";
    echo "==============================================================\n";
    echo "Timestamp: {$results['timestamp']}\n";
    echo "Low Stock Items Scanned: {$results['low_stock']['count']} (Reorders Created: {$results['low_stock']['reorders_created']})\n";
    echo "Expiring Medicines Scanned: {$results['expiring_medicines']['total']} (Expired: {$results['expiring_medicines']['expired']}, <=7d: {$results['expiring_medicines']['within_7d']}, <=30d: {$results['expiring_medicines']['within_30d']})\n";
    echo "Graduating Students Checked: {$results['graduating_students']['count']}\n";
    echo "Appointment Reminders Sent: {$results['appointment_reminders']['reminders_sent']}\n";
    echo "Pending Requests: Users ({$results['pending_requests']['pending_users']}), Appointments ({$results['pending_requests']['pending_appointments']})\n";
    echo "Inventory Anomalies Detected: {$results['inventory_anomalies']['count']}\n";
    echo "Automation run completed successfully.\n";
} else {
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'data' => $results, 'message' => 'MICA background automation tasks executed.']);
}