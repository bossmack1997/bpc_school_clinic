<?php
/**
 * Save FCM registration token for the authenticated user.
 *
 * POST: token, device_type (optional), csrf_token (required)
 */

require_once __DIR__ . '/../../config/config.php';

header('Content-Type: application/json');

error_log('[FCM AUTH] session_status=' . session_status() . ' session_exists=' . (session_id() !== '' ? 'yes' : 'no') . ' user_in_session=' . (isset($_SESSION['user']) ? 'yes' : 'no') . ' cookie_sent=' . (isset($_COOKIE[session_name()]) ? 'yes' : 'no') . ' uri=' . ($_SERVER['REQUEST_URI'] ?? 'unknown'));

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authentication required.']);
    exit;
}

if (!validate_session()) {
    logout_user();
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Session expired.']);
    exit;
}

$db = getDB();
$userId = (int)current_user()['id'];

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');

$token = trim($_POST['token'] ?? '');
$deviceType = trim($_POST['device_type'] ?? 'web');
$csrfToken = trim($_POST['csrf_token'] ?? '');

if ($token === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'FCM token is required.']);
    exit;
}

if (!preg_match('/^[A-Za-z0-9_\-:.~%+=\/]{10,500}$/', $token)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid FCM token format.']);
    exit;
}

if (!csrf_verify($csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token.']);
    exit;
}

try {
    $db->prepare("
        INSERT INTO fcm_tokens (user_id, token, device_type)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE
            token = VALUES(token),
            device_type = VALUES(device_type),
            updated_at = NOW()
    ")->execute([$userId, $token, $deviceType]);

    http_response_code(200);
    echo json_encode(['success' => true, 'message' => 'FCM token saved.']);
    exit;
} catch (Throwable $e) {
    error_log('[FCM] Save token failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to save token. Please try again.']);
    exit;
}
