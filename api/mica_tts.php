<?php
/**
 * MICA Text-to-Speech API Endpoint
 *
 * Secure proxy for ElevenLabs TTS — never exposes API key to frontend.
 *
 * Usage: POST /api/mica_tts.php
 * Body: FormData { action: "synthesize", text: "string to speak", csrf_token: "..." }
 *
 * Returns: audio/mpeg binary stream on success
 *          JSON error response on failure
 */

require_once __DIR__ . '/../config/config.php';

// AJAX-friendly authentication check.
// Unlike require_login() which redirects (and breaks the audio/JSON
// response), this returns a proper JSON error when the session has
// expired or the request is unauthenticated.
if (!is_logged_in()) {
    attempt_remember_me_login();
}
if (!is_logged_in()) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Your session has expired. Please log in again.']);
    exit;
}
check_session_timeout();

$action = $_REQUEST['action'] ?? 'synthesize';

// ================================================================
// ACTION: synthesize — Convert text to speech via ElevenLabs
// ================================================================
if ($action === 'synthesize') {
    // JSON-friendly CSRF check instead of csrf_verify_or_die()
    // which dies with plain text and breaks the response contract.
    $csrfToken = $_POST['csrf_token'] ?? $_GET['csrf_token'] ?? '';
    if (!csrf_verify($csrfToken)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Invalid CSRF token. Please refresh the page and try again.']);
        exit;
    }

    $text = trim($_POST['text'] ?? '');
    if ($text === '') {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Text is required.']);
        exit;
    }

    // Prevent excessively long text
    if (mb_strlen($text) > 2000) {
        $text = mb_substr($text, 0, 2000);
    }

    $apiKey  = defined('ELEVENLABS_API_KEY') ? ELEVENLABS_API_KEY : '';
    $voiceId = defined('ELEVENLABS_VOICE_ID') ? ELEVENLABS_VOICE_ID : '';

    if (empty($apiKey) || $apiKey === 'your-elevenlabs-api-key-here') {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => 'ElevenLabs API key is not configured.',
        ]);
        exit;
    }

    if (empty($voiceId)) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => 'ElevenLabs voice ID is not configured.',
        ]);
        exit;
    }

    $endpoint = defined('ELEVENLABS_API_ENDPOINT')
        ? ELEVENLABS_API_ENDPOINT
        : "https://api.elevenlabs.io/v1/text-to-speech/{$voiceId}";

    $payload = json_encode([
        'text' => $text,
        'model_id' => 'eleven_multilingual_v2',
        'voice_settings' => [
            'stability' => 0.40,
            'similarity_boost' => 0.85,
            'style' => 0.50,
            'use_speaker_boost' => true,
        ],
    ]);

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: audio/mpeg',
            'xi-api-key: ' . $apiKey,
        ],
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT => 'BPC-Clinic-MICA/1.0',
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    $curlErrno = curl_errno($ch);
    curl_close($ch);

    // Handle cURL errors
    if ($curlErrno !== 0) {
        error_log("MICA TTS cURL error ({$curlErrno}): {$curlError}");
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => 'TTS service unavailable. Please try again.',
            'debug' => AI_DEBUG_MODE ? "cURL error: {$curlError}" : null,
        ]);
        exit;
    }

    // Handle HTTP errors
    if ($httpCode === 401) {
        error_log("MICA TTS auth error: Invalid ElevenLabs API key");
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => 'TTS authentication failed.',
        ]);
        exit;
    }

    if ($httpCode === 429) {
        error_log("MICA TTS rate limit exceeded");
        http_response_code(429);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => 'TTS rate limit exceeded. Please wait a moment.',
        ]);
        exit;
    }

    if ($httpCode !== 200) {
        error_log("MICA TTS HTTP {$httpCode}: " . mb_substr($response, 0, 200));
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => 'TTS service error (HTTP ' . $httpCode . ').',
            'debug' => AI_DEBUG_MODE ? mb_substr($response, 0, 300) : null,
        ]);
        exit;
    }

    // Validate we actually got audio back
    if (empty($response)) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => 'TTS returned empty response.',
        ]);
        exit;
    }

    // Success — return audio as binary stream
    header('Content-Type: audio/mpeg');
    header('Content-Length: ' . strlen($response));
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    echo $response;
    exit;
}

// ================================================================
// Invalid action
// ================================================================
http_response_code(400);
header('Content-Type: application/json');
echo json_encode(['success' => false, 'error' => 'Invalid action.']);
