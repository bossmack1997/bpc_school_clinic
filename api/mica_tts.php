<?php
/**
 * MICA Text-to-Speech API
 * AI-Enhanced School Clinic Management System for Bulacan Polytechnic College Healthcare Service Improvement
 *
 * ElevenLabs TTS secure server-side proxy.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';

// ------------------------------------------------------------
// JSON ERROR HELPER
// ------------------------------------------------------------
function mica_tts_error(
    int $status,
    string $message,
    ?string $debug = null,
    bool $allowFallback = true
): never {
    // When upstream service is degraded/unavailable, return 200 with fallback instructions
    // so the browser does not log a 502 crash while switching gracefully to speech synthesis.
    if ($allowFallback && in_array($status, [500, 502, 503, 504, 402, 403, 429], true)) {
        http_response_code(200);
    } else {
        http_response_code($status);
    }

    // Make sure no previous output corrupts JSON/audio response.
    if (ob_get_length()) {
        ob_clean();
    }

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

    $response = [
        'success'  => false,
        'error'    => $message,
        'fallback' => 'browser',
    ];

    // Only expose technical information when explicitly enabled.
    if (
        defined('AI_DEBUG_MODE') &&
        AI_DEBUG_MODE === true &&
        $debug !== null
    ) {
        $response['debug'] = $debug;
    }

    echo json_encode($response);
    exit;
}


// ------------------------------------------------------------
// AUTHENTICATION
// ------------------------------------------------------------
try {

    if (!is_logged_in()) {
        attempt_remember_me_login();
    }

    if (!is_logged_in()) {
        mica_tts_error(
            401,
            'Your session has expired. Please log in again.'
        );
    }

    check_session_timeout();

} catch (Throwable $e) {

    error_log(
        'MICA TTS authentication error: ' . $e->getMessage()
    );

    mica_tts_error(
        500,
        'Authentication service error.',
        $e->getMessage()
    );
}


// ------------------------------------------------------------
// ACTION
// ------------------------------------------------------------
$action = $_POST['action']
    ?? $_GET['action']
    ?? 'synthesize';


// ------------------------------------------------------------
// SYNTHESIZE
// ------------------------------------------------------------
if ($action === 'synthesize') {

    // --------------------------------------------------------
    // CSRF
    // --------------------------------------------------------
    $csrfToken = $_POST['csrf_token']
        ?? $_GET['csrf_token']
        ?? '';

    if (!function_exists('csrf_verify')) {
        mica_tts_error(
            500,
            'CSRF verification function is unavailable.'
        );
    }

    try {

        if (!csrf_verify($csrfToken)) {
            mica_tts_error(
                403,
                'Invalid CSRF token. Please refresh the page and try again.'
            );
        }

    } catch (Throwable $e) {

        error_log(
            'MICA TTS CSRF error: ' . $e->getMessage()
        );

        mica_tts_error(
            500,
            'CSRF verification failed.',
            $e->getMessage()
        );
    }


    // --------------------------------------------------------
    // TEXT
    // --------------------------------------------------------
    $text = trim((string)($_POST['text'] ?? ''));

    if ($text === '') {
        mica_tts_error(
            400,
            'Text is required.'
        );
    }

    // Limit text length.
    if (mb_strlen($text, 'UTF-8') > 2000) {
        $text = mb_substr(
            $text,
            0,
            2000,
            'UTF-8'
        );
    }


    // --------------------------------------------------------
    // ELEVENLABS CONFIG
    // --------------------------------------------------------
    $apiKey = defined('ELEVENLABS_API_KEY')
        ? trim((string)ELEVENLABS_API_KEY)
        : '';

    $voiceId = defined('ELEVENLABS_VOICE_ID')
        ? trim((string)ELEVENLABS_VOICE_ID)
        : '';

    if ($apiKey === '') {
        error_log(
            'MICA TTS: ELEVENLABS_API_KEY is missing.'
        );

        mica_tts_error(
            500,
            'ElevenLabs API key is not configured.'
        );
    }

    if (
        $apiKey === 'your-elevenlabs-api-key-here' ||
        strtolower($apiKey) === 'your-elevenlabs-api-key-here'
    ) {
        error_log(
            'MICA TTS: Placeholder ElevenLabs API key detected.'
        );

        mica_tts_error(
            500,
            'ElevenLabs API key is not configured.'
        );
    }

    if ($voiceId === '') {
        error_log(
            'MICA TTS: ELEVENLABS_VOICE_ID is missing.'
        );

        mica_tts_error(
            500,
            'ElevenLabs voice ID is not configured.'
        );
    }


    // --------------------------------------------------------
    // CURL CHECK
    // --------------------------------------------------------
    if (!function_exists('curl_init')) {

        error_log(
            'MICA TTS: PHP cURL extension is not enabled.'
        );

        mica_tts_error(
            500,
            'PHP cURL extension is not enabled.'
        );
    }


    // --------------------------------------------------------
    // ELEVENLABS ENDPOINT
    // --------------------------------------------------------
    $endpoint = defined('ELEVENLABS_API_ENDPOINT')
        ? trim((string)ELEVENLABS_API_ENDPOINT)
        : '';

    if ($endpoint === '') {

        $endpoint =
            'https://api.elevenlabs.io/v1/text-to-speech/'
            . rawurlencode($voiceId);
    }


    // --------------------------------------------------------
    // REQUEST PAYLOAD
    // --------------------------------------------------------
    $payload = json_encode(
        [
            'text' => $text,

            'model_id' => 'eleven_multilingual_v2',

            'voice_settings' => [
                'stability' => 0.40,
                'similarity_boost' => 0.85,
                'style' => 0.50,
                'use_speaker_boost' => true,
            ],
        ],
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    if ($payload === false) {

        error_log(
            'MICA TTS: JSON encoding failed: '
            . json_last_error_msg()
        );

        mica_tts_error(
            500,
            'Unable to prepare TTS request.',
            json_last_error_msg()
        );
    }


    // --------------------------------------------------------
    // CURL REQUEST
    // --------------------------------------------------------
    $ch = curl_init($endpoint);

    if ($ch === false) {

        error_log(
            'MICA TTS: curl_init() failed.'
        );

        mica_tts_error(
            500,
            'Unable to initialize TTS connection.'
        );
    }


    curl_setopt_array(
        $ch,
        [
            CURLOPT_RETURNTRANSFER => true,

            CURLOPT_POST => true,

            CURLOPT_POSTFIELDS => $payload,

            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: audio/mpeg',
                'xi-api-key: ' . $apiKey,
            ],

            CURLOPT_CONNECTTIMEOUT => 10,

            CURLOPT_TIMEOUT => 30,

            CURLOPT_SSL_VERIFYPEER => true,

            CURLOPT_SSL_VERIFYHOST => 2,

            CURLOPT_USERAGENT =>
                'BPC-Clinic-MICA/1.0',

            CURLOPT_FOLLOWLOCATION => false,
        ]
    );


    $response = curl_exec($ch);

    $httpCode = (int)curl_getinfo(
        $ch,
        CURLINFO_HTTP_CODE
    );

    $curlError = curl_error($ch);

    $curlErrno = curl_errno($ch);

    curl_close($ch);


    // --------------------------------------------------------
    // CURL ERROR
    // --------------------------------------------------------
    if ($response === false || $curlErrno !== 0) {

        error_log(
            "MICA TTS cURL error ({$curlErrno}): {$curlError}"
        );

        mica_tts_error(
            502,
            'TTS service unavailable. Please try again.',
            "cURL {$curlErrno}: {$curlError}"
        );
    }


    // --------------------------------------------------------
    // ELEVENLABS AUTH / PAYMENT ERROR
    // --------------------------------------------------------
    if ($httpCode === 401) {
        error_log('MICA TTS: ElevenLabs returned HTTP 401 (Invalid API Key).');
        mica_tts_error(502, 'TTS authentication failed. Please check your ElevenLabs API key.');
    }

    if ($httpCode === 402) {
        error_log('MICA TTS: ElevenLabs returned HTTP 402 (Payment/Plan limitation - e.g. community voice or quota).');
        mica_tts_error(502, 'TTS plan or voice limitation on ElevenLabs. Using browser speech synthesis fallback.');
    }

    // --------------------------------------------------------
    // ELEVENLABS FORBIDDEN
    // --------------------------------------------------------
    if ($httpCode === 403) {
        error_log('MICA TTS: ElevenLabs returned HTTP 403.');
        mica_tts_error(502, 'TTS request was rejected by ElevenLabs.');
    }


    // --------------------------------------------------------
    // RATE LIMIT
    // --------------------------------------------------------
    if ($httpCode === 429) {

        error_log(
            'MICA TTS: ElevenLabs rate limit exceeded.'
        );

        mica_tts_error(
            429,
            'TTS rate limit exceeded. Please wait a moment.'
        );
    }


    // --------------------------------------------------------
    // OTHER HTTP ERRORS
    // --------------------------------------------------------
    if ($httpCode < 200 || $httpCode >= 300) {

        $errorBody = is_string($response)
            ? mb_substr($response, 0, 500, 'UTF-8')
            : '';

        error_log(
            "MICA TTS ElevenLabs HTTP {$httpCode}: "
            . $errorBody
        );

        mica_tts_error(
            502,
            'TTS service returned an error.',
            "ElevenLabs HTTP {$httpCode}: {$errorBody}"
        );
    }


    // --------------------------------------------------------
    // EMPTY RESPONSE
    // --------------------------------------------------------
    if (!is_string($response) || $response === '') {

        error_log(
            'MICA TTS: ElevenLabs returned an empty response.'
        );

        mica_tts_error(
            502,
            'TTS returned an empty audio response.'
        );
    }


    // --------------------------------------------------------
    // SUCCESS — RETURN MP3
    // --------------------------------------------------------
    if (ob_get_length()) {
        ob_clean();
    }

    header('Content-Type: audio/mpeg');

    header(
        'Content-Length: ' . strlen($response)
    );

    header(
        'Cache-Control: no-store, no-cache, must-revalidate, max-age=0'
    );

    header('Pragma: no-cache');

    header('Expires: 0');

    echo $response;

    exit;
}


// ------------------------------------------------------------
// INVALID ACTION
// ------------------------------------------------------------
mica_tts_error(
    400,
    'Invalid action.'
);