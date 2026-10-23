<?php
/**
 * AI Assistant API Endpoint
 * ------------------------------------------------------------------
 * AI-Enhanced School Clinic Management System for Bulacan Polytechnic College
 * Healthcare Service Improvement
 *
 * Handles:
 *  - chat / mica_chat: Conversational AI & natural language DB queries
 *  - daily_summary: Real-time clinic intelligence metrics & actions
 *  - recommended_actions: Role-filtered action cards
 *  - update_action_status: Status transition (reviewed, approved, rejected)
 *  - reorder_list / create_reorder / approve_reorder / reject_reorder: Smart reorders
 *  - deactivate_graduated: Student lifecycle graduation deactivation
 *  - run_automation: Triggers autonomous monitoring checks
 *  - sos_emergency / sos_update_status: Emergency alert lifecycle
 *  - history / clear_history: Conversation memory management
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../config/config.php';
$sosInput = json_decode(file_get_contents('php://input'), true);
if (!is_array($sosInput)) $sosInput = [];
$sosAction = $_POST['action'] ?? $sosInput['action'] ?? $_GET['action'] ?? '';
if (in_array($sosAction, ['sos_emergency','sos_update_status'], true)) {
    $_POST = array_merge($sosInput, $_POST);
    $_POST['action'] = $sosAction === 'sos_emergency' ? 'sos' : 'update_status';
    if ($sosAction === 'sos_update_status') $_POST['op'] = $_POST['status'] ?? '';
    require __DIR__ . '/emergencies.php';
    exit;
}
require_once __DIR__ . '/../includes/ai/AIService.php';
require_once __DIR__ . '/../includes/ai/MICAAuthorization.php';
require_once __DIR__ . '/../includes/ai/MICAHubService.php';

if (!function_exists('clean_mica_output')) {
    function clean_mica_output(string $text): string {
        $text = preg_replace('/^\$\s*/m', '', $text);
        $text = preg_replace('/\b\$\s+([A-Za-z])/m', '$1', $text);
        return trim($text);
    }
}

if (!is_logged_in()) {
    attempt_remember_me_login();
}
if (!is_logged_in()) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Your session has expired. Please log in again.']);
    exit;
}
require_login();

header('Content-Type: application/json');

$db = getDB();
$user = current_user();

// Read and merge JSON body with $_POST / $_REQUEST
$rawInput = file_get_contents('php://input');
$jsonData = [];
if (!empty($rawInput)) {
    $decoded = json_decode($rawInput, true);
    if (is_array($decoded)) {
        $jsonData = $decoded;
    }
}
$requestData = array_merge($_REQUEST, $jsonData);
$action = $requestData['action'] ?? '';
if($user['role']==='admin'&&$action==='deactivate_graduated')require_admin_permission('manage_users');
if(in_array($action,['reorder_list','create_reorder'],true))require_inventory_access();
if(in_array($action,['approve_reorder','reject_reorder'],true))require_inventory_access(true);

if (!MICAAuthorization::actionAllowed((string)($user['role'] ?? ''), (string)$action)) {
    http_response_code(403);
    json_response(false, [], 'This MICA action is not available for your role.');
}

header('Cache-Control: no-store');
if (!in_array($action,['history','daily_summary','recommended_actions','reorder_list','alert_details','archived_actions'],true)) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); json_response(false,[],'Use POST.'); }
    if(!csrf_verify($requestData['csrf_token']??'')){http_response_code(403);json_response(false,[],'Invalid CSRF token.');}
}
if(in_array($action,['chat','mica_chat'],true)){
    $requests=array_filter($_SESSION['mica_request_times']??[],fn($at)=>$at>time()-60);
    if(count($requests)>=20){http_response_code(429);json_response(false,[],'Please wait before sending more messages.');}
    $requests[]=time();$_SESSION['mica_request_times']=$requests;
    if(!is_string($requestData['message']??null)||mb_strlen($requestData['message'])>3000)json_response(false,[],'Messages must contain at most 3,000 characters.');
}

set_exception_handler(function(Throwable $e) {
    error_log('MICA endpoint: '.$e->getMessage());
    http_response_code(503);
    json_response(false, [], 'Clinic intelligence is unavailable. Verify the MICA migration and database connection, then retry.');
});
$hub = new MICAHubService($db, $user);
if ($action === 'alert_details' || $action === 'read_alert') {
    $detail=$hub->detail((int)($requestData['action_id']??0), $action==='read_alert');
    if (!$detail) { http_response_code(404); json_response(false, [], 'Alert unavailable for this account.'); }
    json_response(true, ['alert'=>$detail]);
}

// Initialize AI Service
$aiConfig = [
    'api_key'     => defined('AI_OPENROUTER_API_KEY') && AI_OPENROUTER_API_KEY ? AI_OPENROUTER_API_KEY : '',
    'endpoint'    => defined('AI_OPENROUTER_ENDPOINT') ? AI_OPENROUTER_ENDPOINT : 'https://openrouter.ai/api/v1/chat/completions',
    'model'       => defined('AI_OPENROUTER_MODEL') ? AI_OPENROUTER_MODEL : 'openai/gpt-4o-mini',
    'timeout'     => 30,
    'max_retries' => 2,
    'page'        => $requestData['page'] ?? '',
];
if (!empty($requestData['location']) && is_array($requestData['location'])) {
    $aiConfig['location'] = $requestData['location'];
}

$aiService = new AIService($db, $user, $aiConfig);

// Helper for JSON CSRF verification
function require_csrf_check() {
    global $requestData;
    if(!csrf_verify($requestData['csrf_token']??'')){http_response_code(403);json_response(false,[],'Invalid CSRF token.');}
}

// ================================================================
// ACTION: chat / mica_chat
// ================================================================
if ($action === 'chat' || $action === 'mica_chat') {
    $message = trim($requestData['message'] ?? '');
    if ($message === '') {
        json_response(false, [], 'Please type a message.');
    }
    if (in_array(canonical_role((string)$user['role']), ['student','teacher'], true) && !MICAAuthorization::studentPromptAllowed($message)) {
        http_response_code(403);
        json_response(false, [], 'I can help with clinic information, your own records and queue, procedures, health education, announcements, and emergency guidance.');
    }

    $result = $aiService->processMessage($message);
    $reply = clean_mica_output($result['reply']);

    $response = [
        'reply'   => $reply,
        'intent'  => $result['intent'],
        'source'  => $result['source'],
        'model'   => $result['model'],
        'actions' => $result['actions'] ?? []
    ];

    json_response(true, $response);
}

// ================================================================
// ACTION: daily_summary
// ================================================================
if ($action === 'daily_summary') {
    $summary = $aiService->getContextService()->getDailySummaryData();
    $aiSummary = ['generated_at'=>$summary['generated_at'], 'note'=>'Verified database summary; no AI prediction.'];
    $actions = $aiService->getActionService()->getRecommendedActions(null, 5);

    json_response(true, [
        'last_scan' => $hub->lastScan(),
        'last_attempt' => $hub->lastAttempt(),
        'metrics' => $summary,
        'ai_summary' => $aiSummary,
        'recommended_actions' => $actions
    ]);
}

// ================================================================
// ACTION: recommended_actions
// ================================================================
if ($action === 'recommended_actions' || $action === 'archived_actions') {
    $limit = min(max((int)($requestData['limit'] ?? 15), 1), 50);
    $actions = $aiService->getActionService()->getRecommendedActions(null, $limit, $action === 'archived_actions');
    json_response(true, ['data' => $actions]);
}

// ================================================================
// ACTION: update_action_status
// ================================================================
if ($action === 'update_action_status') {
    require_csrf_check();
    $actionId = (int)($requestData['action_id'] ?? 0);
    $status   = trim($requestData['status'] ?? '');
    if ($actionId <= 0 || !$status) {
        json_response(false, [], 'Invalid action parameters.');
    }
    $res = $aiService->getActionService()->updateActionStatus($actionId, $status);
    json_response($res['success'], [], $res['message']);
}

// ================================================================
// ACTION: reorder_list
// ================================================================
if ($action === 'reorder_list') {
    if (!in_array(canonical_role($user['role']), ['admin', 'nurse'], true)) {
        json_response(false, [], 'Access denied.');
    }

    $statusFilter = $requestData['status'] ?? 'all';
    $where = '1=1';
    $params = [];
    if ($statusFilter !== 'all') {
        $where .= ' AND r.status = ?';
        $params[] = $statusFilter;
    }

    $stmt = $db->prepare("
        SELECT r.*, m.medicine_name, m.generic_name, m.brand, m.unit, m.quantity as actual_stock,
               u1.first_name as req_first, u1.last_name as req_last,
               u2.first_name as app_first, u2.last_name as app_last
        FROM medicine_reorders r
        JOIN medicines m ON m.id = r.medicine_id
        LEFT JOIN users u1 ON u1.id = r.requested_by
        LEFT JOIN users u2 ON u2.id = r.approved_by
        WHERE $where
        ORDER BY r.created_at DESC
    ");
    $stmt->execute($params);
    json_response(true, ['data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

// ================================================================
// ACTION: create_reorder
// ================================================================
if ($action === 'create_reorder') {
    require_csrf_check();
    if (!in_array(canonical_role($user['role']), ['admin', 'nurse'], true)) {
        json_response(false, [], 'Access denied.');
    }

    $medId = (int)($requestData['medicine_id'] ?? 0);
    $suggestedQty = (int)($requestData['quantity'] ?? 0);
    $reason = trim($requestData['reason'] ?? 'Manual reorder request');

    if ($medId <= 0 || $suggestedQty <= 0) {
        json_response(false, [], 'Valid medicine and quantity are required.');
    }

    $res = $aiService->getActionService()->createReorderRequest($medId, $suggestedQty, $reason);
    json_response($res['success'], $res, $res['message']);
}

// ================================================================
// ACTION: approve_reorder
// ================================================================
if ($action === 'approve_reorder') {
    require_csrf_check();
    if (!in_array(canonical_role($user['role']), ['admin', 'nurse'], true)) {
        json_response(false, [], 'Access denied.');
    }

    $reorderId = (int)($requestData['reorder_id'] ?? 0);
    $approvedQty = (int)($requestData['approved_quantity'] ?? 0);
    $notes = trim($requestData['notes'] ?? '');

    if ($reorderId <= 0 || $approvedQty <= 0) {
        json_response(false, [], 'Valid reorder ID and approved quantity are required.');
    }

    $res = $aiService->getActionService()->approveReorderRequest($reorderId, $approvedQty, $notes);
    json_response($res['success'], [], $res['message']);
}

// ================================================================
// ACTION: reject_reorder
// ================================================================
if ($action === 'reject_reorder') {
    require_csrf_check();
    if (!in_array(canonical_role($user['role']), ['admin', 'nurse'], true)) {
        json_response(false, [], 'Access denied.');
    }

    $reorderId = (int)($requestData['reorder_id'] ?? 0);
    $notes = trim($requestData['notes'] ?? '');

    if ($reorderId <= 0) {
        json_response(false, [], 'Invalid reorder ID.');
    }

    $res = $aiService->getActionService()->rejectReorderRequest($reorderId, $notes);
    json_response($res['success'], [], $res['message']);
}

// ================================================================
// ACTION: deactivate_graduated
// ================================================================
if ($action === 'deactivate_graduated') {
    require_csrf_check();
    if ($user['role'] !== 'admin') {
        json_response(false, [], 'Access denied. Only Administrators can confirm student graduation deactivation.');
    }

    $studentId = (int)($requestData['student_id'] ?? 0);
    $notes = trim($requestData['notes'] ?? '');

    if ($studentId <= 0) {
        json_response(false, [], 'Invalid student ID.');
    }

    $res = $aiService->getActionService()->deactivateGraduatedStudent($studentId, $notes);
    json_response($res['success'], [], $res['message']);
}

// ================================================================
// ACTION: run_automation
// ================================================================
if ($action === 'run_automation') {
    if (!in_array(canonical_role($user['role']), ['admin', 'nurse'], true)) {
        json_response(false, [], 'Access denied.');
    }

    try { $results = $hub->scan(); }
    catch (RuntimeException $e) {
        if (in_array($e->getMessage(), ['Another intelligence scan is running. Retry shortly.','Alert writer is busy. Retry shortly.'], true)) {
            http_response_code(409); json_response(false, [], $e->getMessage());
        }
        throw $e;
    }
    json_response(true, ['data' => $results], 'Autonomous intelligence scan completed.');
}

// ================================================================
// ACTION: history / clear_history
// ================================================================
if ($action === 'history') {
    json_response(true, ['history' => $aiService->getChatHistory()->getMessages(), 'messages' => $aiService->getChatHistory()->getMessages()]);
}

if ($action === 'clear_history') {
    $aiService->getChatHistory()->clear();
    json_response(true, [], 'Chat history cleared.');
}

json_response(false, [], 'Unknown or unsupported action.');
