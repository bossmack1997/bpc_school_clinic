<?php
/**
 * FCM (Firebase Cloud Messaging) server-side helpers
 *
 * Uses the legacy FCM HTTP API with the legacy server key.
 * Compatible with shared hosting (no Composer dependencies required).
 */

if (!defined('BASE_PATH')) {
    require_once __DIR__ . '/../config/config.php';
}

/**
 * Send an FCM push notification to one or more registration tokens.
 *
 * @param array|string $tokens   Single token or array of tokens
 * @param string       $title    Notification title
 * @param string       $body     Notification body
 * @param array        $data     Additional key/value data payload
 * @param string       $priority 'high' | 'normal'
 *
 * @return bool  true if FCM accepted the request, false on transport/config error
 */
function send_fcm_push($tokens, string $title, string $body, array $data = [], string $priority = 'high'): bool {
    $serverKey = getenv('FCM_SERVER_KEY');
    if (empty($serverKey)) {
        error_log('[FCM] FCM_SERVER_KEY is not configured.');
        return false;
    }

    $tokens = (array)$tokens;
    $tokens = array_values(array_filter(array_map(function ($t) { return trim((string)$t); }, $tokens)));
    if (!$tokens) {
        return false;
    }

    $payload = [
        'registration_ids' => $tokens,
        'notification' => [
            'title' => $title,
            'body'  => $body,
            'sound' => 'default',
            'icon'  => '/assets/images/icon-192x192.png',
            'badge' => '/assets/images/badge-72x72.png',
        ],
        'data'            => $data,
        'priority'        => $priority,
    ];

    $ch = curl_init('https://fcm.googleapis.com/fcm/send');
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER     => [
            'Authorization: key=' . $serverKey,
            'Content-Type: application/json',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_TIMEOUT        => 15,
    ]);

    $result = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($curlErr !== '') {
        error_log('[FCM] cURL error: ' . $curlErr);
        return false;
    }

    if ($httpCode !== 200) {
        error_log("[FCM] HTTP error: $httpCode - " . substr($result, 0, 500));
        return false;
    }

    $decoded = json_decode($result, true);
    if (!is_array($decoded)) {
        error_log('[FCM] Invalid JSON response.');
        return false;
    }

    if (isset($decoded['failure']) && (int)$decoded['failure'] > 0) {
        if (!empty($decoded['results']) && is_array($decoded['results'])) {
            foreach ($decoded['results'] as $i => $res) {
                $err = $res['error'] ?? '';
                if ($err) {
                    error_log("[FCM] Token error (index $i): $err");
                }
            }
        }
        return isset($decoded['success']) && (int)$decoded['success'] > 0;
    }

    return true;
}

/**
 * Send an FCM notification to all active tokens belonging to a user.
 *
 * @param int    $userId
 * @param string $title
 * @param string $body
 * @param array  $data
 *
 * @return bool
 */
function send_fcm_to_user(int $userId, string $title, string $body, array $data = []): bool {
    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT token FROM fcm_tokens WHERE user_id = ?");
        $stmt->execute([$userId]);
        $tokens = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (!$tokens) {
            return false;
        }
        return send_fcm_push($tokens, $title, $body, $data);
    } catch (Throwable $e) {
        error_log('[FCM] send_fcm_to_user failed: ' . $e->getMessage());
        return false;
    }
}
