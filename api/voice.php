<?php
/**
 * api/voice.php
 * --------------------------------------------------------------
 * SINGLE Voice Verification Service endpoint.
 *
 * Actions:
 *  - status    : GET — enrollment status + provider availability
 *  - enroll    : POST — enroll (or re-enroll) a voice profile
 *  - re_enroll : POST — alias of enroll (explicit re-registration)
 *  - verify    : POST — verify a live 2–3s sample
 *  - test      : POST — alias of verify (Test Voice Verification)
 *  - delete    : POST — delete the user's voice profile
 *
 * SECURITY:
 *  - Reuses existing authentication (require_login) + role checks.
 *  - Always scoped to current_user()['id'] — never a client-supplied id.
 *  - CSRF-protected for all mutations.
 *  - Raw audio is never stored; only the generated reference is persisted
 *    by VoiceVerificationService.
 *
 * This is the ONLY voice-verification endpoint. MICA and the enrollment
 * card both call here — no duplicate endpoints.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/voice/VoiceVerificationService.php';
require_login();

header('Content-Type: application/json');

$db   = getDB();
$user = current_user();
$userId = (int)$user['id'];
$action = $_REQUEST['action'] ?? 'status';

$voiceService = new VoiceVerificationService($db);

// ================================================================
// ACTION: status — enrollment status + provider availability
// ================================================================
if ($action === 'status') {
    json_response(true, $voiceService->status($userId));
}

// All mutations require CSRF.
csrf_verify_or_die();

// ================================================================
// ACTION: enroll / re_enroll — create or replace voice profile
// --------------------------------------------------------------
// Expects: audio[sample_rate], audio[frames] (PCM float array),
//          captured from ~5–10 seconds of speech.
// ================================================================
if ($action === 'enroll' || $action === 're_enroll') {
    $audio = extractAudioPayload();
    $result = $voiceService->enroll($userId, $audio);

    if ($result['success']) {
        log_activity($userId, $action === 're_enroll' ? 'Voice Re-enrolled' : 'Voice Enrolled', 'voice', 'Voice profile registered');
    }
    json_response($result['success'], ['provider' => $voiceService->status($userId)['provider']], $result['message']);
}

// ================================================================
// ACTION: verify / test — verify a live 2–3s sample
// ================================================================
if ($action === 'verify' || $action === 'test') {
    $audio = extractAudioPayload();
    $result = $voiceService->verify($userId, $audio);

    if ($result['verified']) {
        log_activity($userId, 'Voice Verified', 'voice', 'Voice verification passed');
    } else {
        log_activity($userId, 'Voice Verification Failed', 'voice', 'Voice verification failed');
    }

    json_response($result['success'], ['verified' => $result['verified']], $result['message']);
}

// ================================================================
// ACTION: delete — remove the user's voice profile
// ================================================================
if ($action === 'delete') {
    $result = $voiceService->delete($userId);
    if ($result['success']) {
        log_activity($userId, 'Voice Profile Deleted', 'voice', 'Voice profile removed');
    }
    json_response($result['success'], [], $result['message']);
}

json_response(false, [], 'Unknown action');

/**
 * Extract + normalize the audio payload from the POST body.
 * The client sends FormData fields:
 *   audio[sample_rate] (int)
 *   audio[frames]      (array of float PCM values)
 */
function extractAudioPayload(): array {
    $audio = $_POST['audio'] ?? [];
    if (!is_array($audio)) {
        $audio = [];
    }

    // Defensive: convert frames to numeric array. JSON-decoded frames are
    // supported as a fallback via 'audio_json'.
    $frames = $audio['frames'] ?? null;
    if ($frames === null && !empty($_POST['audio_json'])) {
        $decoded = json_decode($_POST['audio_json'], true);
        if (is_array($decoded)) {
            $frames = $decoded;
        }
    }

    if (!is_array($frames)) {
        $frames = [];
    }

    $cleanFrames = [];
    foreach ($frames as $f) {
        if (is_numeric($f)) {
            $cleanFrames[] = (float)$f;
        }
    }

    return [
        'sample_rate' => (int)($audio['sample_rate'] ?? 16000),
        'frames' => $cleanFrames,
    ];
}

