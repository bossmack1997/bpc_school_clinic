<?php
/**
 * AI Assistant API Endpoint
 *
 * Handles:
 *  - chat: Process AI message (with SOS detection)
 *  - mica_chat: Process message with MICA voice metadata
 *  - clear_history: Clear chat history
 *  - history: Get chat history
 *  - sos_emergency: Submit SOS emergency alert
 *
 * Workflow: AJAX → this endpoint → AIService → IntentDetector → DatabaseContext
 *           → PromptBuilder → OpenRouter (with rule-based fallback) → JSON response
 *
 * Security:
 *  - Never exposes API keys to the browser
 *  - All AI API calls happen server-side
 *  - Frontend communicates only via AJAX to this endpoint
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/ai/AIService.php';

/**
 * Remove any unintended '$' characters or prefix artifacts from MICA's user-visible output.
 */
if (!function_exists('clean_mica_output')) {
    function clean_mica_output(string $text): string {
        $text = preg_replace('/^\$\s*/m', '', $text);
        $text = preg_replace('/\b\$\s+([A-Za-z])/m', '$1', $text);
        return trim($text);
    }
}

// AJAX-friendly authentication check.
// Unlike require_login() which redirects (and breaks JSON responses),
// this returns a proper 401 JSON response when the session has expired
// or the request is unauthenticated.
if (!is_logged_in()) {
    attempt_remember_me_login();
}
if (!is_logged_in()) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Your session has expired. Please log in again.']);
    exit;
}
check_session_timeout();

header('Content-Type: application/json');

$db = getDB();
$user = current_user();
$action = $_REQUEST['action'] ?? '';

// ================================================================
// Initialize AI config array
// ================================================================
$aiConfig = [
    'api_key'     => defined('AI_OPENROUTER_API_KEY') && AI_OPENROUTER_API_KEY ? AI_OPENROUTER_API_KEY : '',
    'endpoint'    => defined('AI_OPENROUTER_ENDPOINT') ? AI_OPENROUTER_ENDPOINT : 'https://openrouter.ai/api/v1/chat/completions',
    'model'       => defined('AI_OPENROUTER_MODEL') ? AI_OPENROUTER_MODEL : 'openai/gpt-4o-mini',
    'timeout'     => 30,
    'max_retries' => 2,
];

// ================================================================
// Determine if debug mode is enabled (only in development)
// ================================================================
$debugMode = defined('AI_DEBUG_MODE') && AI_DEBUG_MODE;

// ================================================================
// Emergency keyword detection (shared by chat and mica_chat)
// ================================================================
function detectEmergency(string $message): bool {
    static $emergencyKeywords = [
        'chest pain', "can't breathe", 'cannot breathe', 'difficulty breathing',
        'severe bleeding', 'unconscious', 'seizure', 'heart attack', 'stroke',
        'not breathing', 'choking', 'major injury', 'head injury', 'overdose',
        'poisoning', 'suicide', 'self-harm', 'hard to breathe', 'stop breathing',
    ];
    $lowerMsg = mb_strtolower($message);
    foreach ($emergencyKeywords as $kw) {
        if (str_contains($lowerMsg, $kw)) {
            return true;
        }
    }
    return false;
}

// ================================================================
// Process a chat message through the AI pipeline
// Returns array with reply data + service status
// ================================================================
function processChatMessage(PDO $db, array $user, array $aiConfig, string $message, bool $debugMode): array {
    // Initialize AI Service
    $aiService = new AIService($db, $user, array_merge($aiConfig, ['page' => $_POST['page'] ?? $_GET['page'] ?? '']));

    // Log incoming request
    error_log('[MICA Request] user_id=' . ($user['id'] ?? 'unknown')
        . ' | action=chat'
        . ' | message_length=' . mb_strlen($message)
        . ' | message_preview=' . mb_substr($message, 0, 100));

    // Process through AI pipeline
    $result = $aiService->processMessage($message);

    // Build debug info (NO API key is ever exposed)
    $debugInfo = [];
    if ($debugMode) {
        $openRouter = $aiService->getOpenRouter();
        $debugInfo = [
            'openrouter_enabled'       => $openRouter->isEnabled(),
            'openrouter_api_key_set'   => !empty($aiConfig['api_key']) ? true : false,
            'openrouter_endpoint'      => $aiConfig['endpoint'],
            'openrouter_model'         => $openRouter->getModel(),
            'selected_source'          => $result['source'],
            'detected_intent'          => $result['intent'],
            'ai_config_loaded'         => defined('AI_OPENROUTER_API_KEY'),
            'ai_config_key_empty'      => empty(AI_OPENROUTER_API_KEY),
        ];
    }

    // Determine service status for the frontend
    $openRouter = $aiService->getOpenRouter();
    $serviceStatus = 'ok';
    $serviceMessage = '';

    if (!$openRouter->isEnabled()) {
        $serviceStatus = 'fallback';
        $serviceMessage = 'Offline assistant response mode active.';
    } elseif ($result['source'] !== 'openrouter') {
        $serviceStatus = 'degraded';
        $serviceMessage = 'Assistant temporarily using offline responses.';
    }

    // Log response
    error_log('[MICA Response] user_id=' . ($user['id'] ?? 'unknown')
        . ' | source=' . $result['source']
        . ' | intent=' . $result['intent']
        . ' | model=' . ($result['model'] ?? 'N/A')
        . ' | service_status=' . $serviceStatus
        . ' | reply_length=' . mb_strlen($result['reply']));

    return [
        'result'         => $result,
        'service_status' => $serviceStatus,
        'service_message' => $serviceMessage,
        'debug'          => $debugInfo,
    ];
}

// JSON-friendly CSRF check (replaces csrf_verify_or_die which dies with plain text)
function require_csrf_json(): void {
    $token = $_POST['csrf_token'] ?? $_GET['csrf_token'] ?? '';
    if (!csrf_verify($token)) {
        http_response_code(403);
        json_response(false, [], 'Invalid CSRF token. Please refresh the page and try again.');
    }
}

// ================================================================
// ACTION: chat — Process a user message through the AI pipeline
// ================================================================
if ($action === 'chat') {
    require_csrf_json();

    $message = trim($_POST['message'] ?? '');
    if ($message === '') {
        error_log('[MICA Error] Empty message received from user_id=' . ($user['id'] ?? 'unknown'));
        json_response(false, ['service_status' => 'error'], 'Please type a message.');
    }

    $isEmergency = detectEmergency($message);

    $processed = processChatMessage($db, $user, $aiConfig, $message, $debugMode);
    $result = $processed['result'];

    $sosRequired = $isEmergency || ($result['intent'] === 'emergency');

    $responseData = [
        'reply'            => clean_mica_output($result['reply']),
        'intent'           => $result['intent'],
        'source'           => $result['source'],
        'model'            => $result['model'],
        'sos_required'     => $sosRequired,
        'emergency'        => $isEmergency,
        'service_status'   => $processed['service_status'],
        'service_message'  => $processed['service_message'],
    ];

    if ($debugMode && !empty($processed['debug'])) {
        $responseData['_debug'] = $processed['debug'];
    }

    json_response(true, $responseData);
}

// ================================================================
// ACTION: mica_chat — Process message with MICA voice response
// Identical to 'chat' but includes MICA-specific metadata for the
// voice-enabled frontend (user first name, greeting format).
// ================================================================
if ($action === 'mica_chat') {
    require_csrf_json();

    $message = trim($_POST['message'] ?? '');
    if ($message === '') {
        error_log('[MICA Error] Empty mica_chat message from user_id=' . ($user['id'] ?? 'unknown'));
        json_response(false, ['service_status' => 'error'], 'Please type a message.');
    }

    $isFirstMessage = filter_var($_POST['first_message'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $isEmergency = detectEmergency($message);

    $processed = processChatMessage($db, $user, $aiConfig, $message, $debugMode);
    $result = $processed['result'];

    $sosRequired = $isEmergency || ($result['intent'] === 'emergency');

    $userFirstName = $user['first_name'] ?? 'there';

    $phTime = new DateTime('now', new DateTimeZone(defined('MICA_TIMEZONE') ? MICA_TIMEZONE : 'Asia/Manila'));
    $timeOfDayInt = (int)$phTime->format('H');
    // PH time-based greeting per spec:
    //   05:00–11:59 → Good morning
    //   12:00–17:59 → Good afternoon
    //   18:00–04:59 → Good evening
    if ($timeOfDayInt >= 5 && $timeOfDayInt < 12) $greeting = 'Good morning';
    elseif ($timeOfDayInt >= 12 && $timeOfDayInt < 18) $greeting = 'Good afternoon';
    else $greeting = 'Good evening';

    $responseData = [
        'reply'            => clean_mica_output($result['reply']),
        'intent'           => $result['intent'],
        'source'           => $result['source'],
        'model'            => $result['model'],
        'sos_required'     => $sosRequired,
        'emergency'        => $isEmergency,
        'service_status'   => $processed['service_status'],
        'service_message'  => $processed['service_message'],
        'mica'             => true,
        'user_first_name'  => $userFirstName,
        'greeting' => $greeting . ', ' . $userFirstName . '. I am MICA, your Medical Intelligent Clinic Assistant from BPC. How can I help you today?',
    ];

    if ($debugMode && !empty($processed['debug'])) {
        $responseData['_debug'] = $processed['debug'];
    }

    json_response(true, $responseData);
}

// ================================================================
// ACTION: clear_history — Clear the user's chat history
// ================================================================
if ($action === 'clear_history') {
    require_csrf_json();

    $aiService = new AIService($db, $user, $aiConfig);
    $aiService->getChatHistory()->clear();

    error_log('[MICA] Chat history cleared by user_id=' . ($user['id'] ?? 'unknown'));
    json_response(true, [], 'Chat history cleared.');
}

// ================================================================
// ACTION: history — Get the user's chat history
// ================================================================
if ($action === 'history') {
    $aiService = new AIService($db, $user, $aiConfig);
    $history = $aiService->getChatHistory()->exportForApi(50);
    $stats = $aiService->getChatHistory()->getStats();

    json_response(true, [
        'history' => $history,
        'stats'   => $stats,
    ]);
}

// ================================================================
// ACTION: sos_emergency — Submit an SOS Emergency alert
// ================================================================
if ($action === 'sos_emergency') {
    require_csrf_json();

    $emergencyType = trim($_POST['emergency_type'] ?? '');
    $description   = trim($_POST['description'] ?? '');
    $latitude      = isset($_POST['latitude']) && $_POST['latitude'] !== '' ? (float)$_POST['latitude'] : null;
    $longitude     = isset($_POST['longitude']) && $_POST['longitude'] !== '' ? (float)$_POST['longitude'] : null;

    if (!$emergencyType) {
        json_response(false, ['service_status' => 'error'], 'Emergency type is required.');
    }

    // Validate emergency type
    $validTypes = ['Chest Pain', 'Difficulty Breathing', 'Severe Bleeding', 'Unconscious', 'Seizure', 'Heart Attack', 'Stroke', 'Choking', 'Other'];
    if (!in_array($emergencyType, $validTypes)) {
        $emergencyType = 'Other';
    }

    // Get student_id if the user is a student
    $studentId = null;
    $userRole = $user['role'] ?? '';
    if ($userRole === 'student') {
        try {
            $stmt = $db->prepare("SELECT id FROM students WHERE user_id = ?");
            $stmt->execute([$user['id']]);
            $studentId = $stmt->fetchColumn();
            if ($studentId !== false) {
                $studentId = (int)$studentId;
                if ($studentId <= 0) $studentId = null;
            } else {
                $studentId = null;
            }
        } catch (PDOException $e) {
            error_log('[MICA SOS Error] Failed to fetch student_id: ' . $e->getMessage());
            $studentId = null;
        }
    }

    // Validate GPS coordinates
    $validLat = null;
    $validLng = null;
    if ($latitude !== null && $latitude !== '' && is_numeric($latitude)) {
        $latF = (float)$latitude;
        if ($latF >= -90 && $latF <= 90) $validLat = $latF;
    }
    if ($longitude !== null && $longitude !== '' && is_numeric($longitude)) {
        $lngF = (float)$longitude;
        if ($lngF >= -180 && $lngF <= 180) $validLng = $lngF;
    }

    $accuracy = isset($_POST['accuracy']) && $_POST['accuracy'] !== '' ? trim($_POST['accuracy']) : (isset($data['accuracy']) ? trim($data['accuracy']) : null);
    $locationUpdatedAt = ($validLat !== null && $validLng !== null) ? date('Y-m-d H:i:s') : null;

    // Store the SOS emergency
    $stmt = $db->prepare("
        INSERT INTO sos_emergencies
            (user_id, student_id, emergency_type, description, location_source, latitude, longitude, location_accuracy, location_updated_at, status, source, reporter_role, ip_address, user_agent)
        VALUES (?, ?, ?, ?, 'gps', ?, ?, ?, ?, 'active', 'sos', 'student', ?, ?)
    ");
    $stmt->execute([
        $user['id'],
        $studentId,
        $emergencyType,
        $description,
        $validLat,
        $validLng,
        $accuracy,
        $locationUpdatedAt,
        $_SERVER['REMOTE_ADDR'] ?? null,
        $_SERVER['HTTP_USER_AGENT'] ?? null,
    ]);
    $emergencyId = (int)$db->lastInsertId();

    // If initial GPS coordinates are present, log to location history table
    if ($validLat !== null && $validLng !== null) {
        try {
            $db->prepare("
                INSERT INTO emergency_location_updates (emergency_id, latitude, longitude, accuracy, recorded_at)
                VALUES (?, ?, ?, ?, NOW())
            ")->execute([$emergencyId, $validLat, $validLng, $accuracy]);
        } catch (Throwable $e) {
            error_log('[MICA SOS Location Log Error]: ' . $e->getMessage());
        }
    }

    error_log('[MICA SOS] Emergency alert created: #' . $emergencyId . ' | type=' . $emergencyType . ' | user_id=' . ($user['id'] ?? 'unknown'));

    // Log the activity
    log_activity($user['id'], 'SOS Emergency Alert', 'emergency', "$emergencyType - #$emergencyId");

    // Notify ALL nurses
    $nurseStmt = $db->query("SELECT id, user_id FROM nurses");
    $nurses = $nurseStmt->fetchAll();
    foreach ($nurses as $nurse) {
        push_notification(
            $nurse['user_id'],
            'emergency',
            '🚨 SOS Emergency: ' . $emergencyType,
            (($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')) . ' needs immediate assistance! Type: ' . $emergencyType,
            APP_URL . '/pages/nurse/dashboard.php',
            (int)$emergencyId
        );
    }

    // Also notify admin users
    $adminStmt = $db->query("SELECT id FROM users WHERE role = 'admin'");
    $admins = $adminStmt->fetchAll();
    foreach ($admins as $admin) {
        push_notification(
            $admin['id'],
            'emergency',
            '🚨 SOS Emergency Alert: ' . $emergencyType,
            (($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')) . ' triggered an SOS. Type: ' . $emergencyType,
            APP_URL . '/pages/admin/dashboard.php',
            (int)$emergencyId
        );
    }

    // FCM push notification to staff (non-blocking)
    try {
        $sosUrl = APP_URL . '/pages/nurse/dashboard.php';
        $fcmTitle = '🚨 SOS ALERT';
        $fcmBody  = 'Emergency assistance has been requested. Tap to view the SOS request.';
        $fcmData  = [
            'sos_id'      => (string)$emergencyId,
            'sos_url'     => $sosUrl,
            'type'        => 'emergency',
            'emergency_type' => $emergencyType,
        ];
        send_emergency_fcm($db, $fcmTitle, $fcmBody, $fcmData);
    } catch (Throwable $e) {
        error_log('[MICA SOS] FCM send failed (non-blocking): ' . $e->getMessage());
    }

    json_response(true, [
        'emergency_id'     => $emergencyId,
        'message'          => 'Your emergency request has been successfully sent to the School Clinic. Please remain where you are while waiting for assistance.',
        'location_sent'    => !empty($latitude) && !empty($longitude),
        'service_status'   => 'ok',
    ]);
}

// ================================================================
// ACTION: sos_update_status — Accept / Resolve an SOS emergency
// Accept  → status = 'responded' (responded_by, responded_at)
// Resolve → status = 'resolved'  (resolved_at)
// Only nurses/admin can update; the student is notified via the
// existing push_notification() system.
// ================================================================
if ($action === 'sos_update_status') {
    require_csrf_json();
    require_role(['nurse', 'admin']);

    $emergencyId = (int)($_POST['emergency_id'] ?? 0);
    $newStatus   = trim($_POST['status'] ?? '');

    if ($emergencyId <= 0) {
        json_response(false, [], 'Invalid emergency request.');
    }
    if (!in_array($newStatus, ['accept', 'resolve'], true)) {
        json_response(false, [], 'Invalid status action.');
    }

    $stmt = $db->prepare("SELECT * FROM sos_emergencies WHERE id = ?");
    $stmt->execute([$emergencyId]);
    $emergency = $stmt->fetch();

    if (!$emergency) {
        json_response(false, [], 'SOS request not found.');
    }

    if ($newStatus === 'accept') {
        if ($emergency['status'] !== 'active' && $emergency['status'] !== 'responded') {
            json_response(false, [], 'This SOS request has already been ' . $emergency['status'] . '.');
        }
        $db->prepare("
            UPDATE sos_emergencies
            SET status = 'responded', responded_by = ?, responded_at = NOW()
            WHERE id = ?
        ")->execute([$user['id'], $emergencyId]);
        $statusLabel = 'accepted';
        $studentTitle = '✅ SOS Accepted';
        $studentMessage = 'The school nurse has accepted your emergency request. Help is on the way.';
    } else {
        if ($emergency['status'] === 'resolved' || $emergency['status'] === 'false_alarm') {
            json_response(false, [], 'This SOS request has already been ' . $emergency['status'] . '.');
        }
        $db->prepare("
            UPDATE sos_emergencies
            SET status = 'resolved', responded_by = COALESCE(responded_by, ?), responded_at = COALESCE(responded_at, NOW()), resolved_at = NOW()
            WHERE id = ?
        ")->execute([$user['id'], $emergencyId]);
        $statusLabel = 'resolved';
        $studentTitle = '✅ SOS Resolved';
        $studentMessage = 'Your SOS request has been resolved. Please feel free to visit the clinic if you need further assistance.';
    }

    log_activity($user['id'], 'SOS ' . ucfirst($statusLabel), 'emergency', "SOS #$emergencyId {$statusLabel}");

    // Notify the student who sent the SOS request
    if ($emergency['user_id'] && (int)$emergency['user_id'] !== (int)$user['id']) {
        push_notification(
            (int)$emergency['user_id'],
            'emergency',
            $studentTitle,
            $studentMessage,
            APP_URL . '/pages/student/dashboard.php',
            (int)$emergencyId
        );
    }

    // Notify other nurses/admins (except the responder) that the SOS is no longer active
    $sosStudentName = '';
    try {
        $studentInfoStmt = $db->prepare("
            SELECT u.first_name, u.last_name
            FROM sos_emergencies se
            JOIN students s ON s.id = se.student_id
            JOIN users u ON u.id = s.user_id
            WHERE se.id = ?
        ");
        $studentInfoStmt->execute([$emergencyId]);
        $sosStudentInfo = $studentInfoStmt->fetch();
        if ($sosStudentInfo) {
            $sosStudentName = $sosStudentInfo['first_name'] . ' ' . $sosStudentInfo['last_name'];
        }
    } catch (PDOException $e) {
        error_log('[SOS Update] student name lookup failed: ' . $e->getMessage());
    }

    // Update the status of existing nurse/admin SOS notifications for this emergency
    $db->prepare("UPDATE notifications SET is_read = 1 WHERE sos_emergency_id = ? AND type = 'emergency' AND user_id != ?")
        ->execute([$emergencyId, $user['id']]);

    json_response(true, [
        'emergency_id' => $emergencyId,
        'status'       => $statusLabel,
        'message'      => 'SOS ' . ucfirst($statusLabel) . '.',
    ]);
}

// ================================================================
// Legacy support (no action specified)
// ================================================================
require_csrf_json();
$message = trim($_POST['message'] ?? '');
if ($message === '') {
    json_response(false, ['service_status' => 'error'], 'Please type a message.');
}

$processed = processChatMessage($db, $user, $aiConfig, $message, $debugMode);
$result = $processed['result'];

$responseData = [
    'reply'            => $result['reply'],
    'intent'           => $result['intent'],
    'source'           => $result['source'],
    'service_status'   => $processed['service_status'],
    'service_message'  => $processed['service_message'],
];

if ($debugMode && !empty($processed['debug'])) {
    $responseData['_debug'] = $processed['debug'];
}

json_response(true, $responseData);
