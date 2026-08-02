<?php
/**
 * VoiceVerificationService.php
 * --------------------------------------------------------------
 * Reusable facade for voice enrollment + verification.
 *
 * Responsibilities:
 *  - Select the active provider (VOICE_PROVIDER config) via the
 *    VoiceProviderInterface contract (HybridVoiceProvider by default;
 *    future providers: azure | resemblyzer | nemo | ...).
 *  - Persist the generated voice reference in voice_profiles
 *    (one active profile per user).
 *  - Enroll / re-enroll / delete / test-verify a voice profile.
 *  - Normalize client-supplied audio payloads before handing them to
 *    the provider (defensive: ensures frames are numeric arrays).
 *
 * SECURITY:
 *  - Always scoped to the authenticated user id passed in — never trusts
 *    a client-supplied user id.
 *  - Only the voice reference (template) is stored; raw audio is never
 *    persisted.
 *  - Provider availability is checked before any operation; if the
 *    provider is down, Voice Mode must be disabled and text chat remains
 *    functional.
 */

require_once __DIR__ . '/VoiceProviderInterface.php';
require_once __DIR__ . '/HybridVoiceProvider.php';

class VoiceVerificationService
{
    private PDO $db;
    private VoiceProviderInterface $provider;

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->provider = $this->buildProvider();
    }

    /**
     * Factory: select provider based on VOICE_PROVIDER config.
     * New providers are added here without touching MICA or the API.
     */
    private function buildProvider(): VoiceProviderInterface
    {
        $providerName = defined('VOICE_PROVIDER') ? strtolower(VOICE_PROVIDER) : 'hybrid';

        return match ($providerName) {
            // Future providers would be registered here, e.g.:
            // 'azure' => new AzureSpeakerProvider($this->db),
            // 'resemblyzer' => new ResemblyzerProvider(...),
            // 'nemo' => new NemoProvider(...),
            default => new HybridVoiceProvider(),
        };
    }

    /**
     * The active provider's availability. When false, Voice Mode must be
     * disabled and users directed to Text Chat.
     */
    public function isAvailable(): bool
    {
        return $this->provider->isAvailable();
    }

    /**
     * Get the active voice profile for a user (null when none/disabled).
     */
    public function getProfile(int $userId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT id, user_id, voice_reference, provider, is_active, created_at, updated_at
             FROM voice_profiles
             WHERE user_id = ? AND is_active = 1
             LIMIT 1"
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Whether the user already has an active voice profile.
     */
    public function hasProfile(int $userId): bool
    {
        return $this->getProfile($userId) !== null;
    }

    /**
     * Enroll (or re-enroll) a voice profile for the authenticated user.
     * Replaces any existing active profile (one active per user).
     *
     * @param int   $userId Authenticated user id (never client-supplied).
     * @param array $audio  Normalized audio payload ['sample_rate'=>int, 'frames'=>float[]].
     * @return array{success: bool, message: string, reference?: string}
     */
    public function enroll(int $userId, array $audio): array
    {
        if (!$this->isAvailable()) {
            return [
                'success' => false,
                'message' => 'Voice verification service is temporarily unavailable. You may continue using Text Chat.',
            ];
        }

        $audio = $this->normalizeAudio($audio);
        if ($audio === null) {
            return ['success' => false, 'message' => 'Invalid audio sample. Please try again.'];
        }

        $reference = $this->provider->createTemplate($audio);
        if ($reference === null) {
            return ['success' => false, 'message' => 'Could not create a voice template from your sample. Please try again in a quiet place.'];
        }

        // Upsert — replace any existing active profile (one per user).
        $existing = $this->getProfile($userId);
        if ($existing) {
            $stmt = $this->db->prepare(
                "UPDATE voice_profiles
                 SET voice_reference = ?, provider = ?, is_active = 1, updated_at = NOW()
                 WHERE id = ?"
            );
            $stmt->execute([$reference, $this->providerName(), $existing['id']]);
        } else {
            $stmt = $this->db->prepare(
                "INSERT INTO voice_profiles (user_id, voice_reference, provider, is_active)
                 VALUES (?, ?, ?, 1)"
            );
            $stmt->execute([$userId, $reference, $this->providerName()]);
        }

        return [
            'success' => true,
            'message' => 'Voice enrolled successfully.',
            'reference' => $reference,
        ];
    }

    /**
     * Verify a live sample against the authenticated user's stored reference.
     *
     * @param int   $userId Authenticated user id.
     * @param array $audio  Normalized audio payload.
     * @return array{success: bool, verified: bool, message: string}
     */
    public function verify(int $userId, array $audio): array
    {
        if (!$this->isAvailable()) {
            return [
                'success' => false,
                'verified' => false,
                'message' => 'Voice verification service is temporarily unavailable. You may continue using Text Chat.',
            ];
        }

        $profile = $this->getProfile($userId);
        if (!$profile) {
            return [
                'success' => false,
                'verified' => false,
                'message' => "No voice profile found.\nPlease complete Voice Enrollment before using Voice Mode.",
            ];
        }

        $audio = $this->normalizeAudio($audio);
        if ($audio === null) {
            return ['success' => false, 'verified' => false, 'message' => 'Invalid audio sample. Please try again.'];
        }

        // Detect outdated reference format so we can ask the user to re-enroll.
        $referenceData = json_decode(base64_decode($profile['voice_reference']), true);
        $refVersion = (int)($referenceData['vr'] ?? 0);
        if (defined('HybridVoiceProvider::REF_VERSION') && $refVersion !== HybridVoiceProvider::REF_VERSION) {
            return [
                'success' => false,
                'verified' => false,
                'message' => 'Your voice profile is outdated. Please re-register your voice in Profile > Voice Enrollment before using Voice Mode.',
            ];
        }

        $verified = $this->provider->verify($audio, $profile['voice_reference']);

        if ($verified) {
            return ['success' => true, 'verified' => true, 'message' => 'Voice verified.'];
        }

        return [
            'success' => true,
            'verified' => false,
            'message' => "Voice verification failed.\nOnly the authenticated account owner may use MICA Voice Assistant.",
        ];
    }

    /**
     * Delete the authenticated user's voice profile.
     */
    public function delete(int $userId): array
    {
        $stmt = $this->db->prepare("DELETE FROM voice_profiles WHERE user_id = ?");
        $stmt->execute([$userId]);
        return ['success' => true, 'message' => 'Voice profile deleted.'];
    }

    /**
     * Status payload used by the enrollment card / MICA mic gate.
     */
    public function status(int $userId): array
    {
        $profile = $this->getProfile($userId);
        return [
            'success' => true,
            'enrolled' => $profile !== null,
            'provider' => $this->providerName(),
            'available' => $this->isAvailable(),
            'provider_available' => $this->isAvailable(),
            'created_at' => $profile['created_at'] ?? null,
            'updated_at' => $profile['updated_at'] ?? null,
        ];
    }

    /**
     * Normalize a client-supplied audio payload into a safe, numeric
     * structure. Rejects non-array / non-numeric frames to prevent
     * provider errors from malformed input.
     */
    private function normalizeAudio(array $audio): ?array
    {
        $sampleRate = (int)($audio['sample_rate'] ?? 16000);
        if ($sampleRate <= 0) {
            $sampleRate = 16000;
        }

        $frames = $audio['frames'] ?? null;
        if (!is_array($frames) || empty($frames)) {
            return null;
        }

        $clean = [];
        $count = count($frames);
        for ($i = 0; $i < $count; $i++) {
            $v = $frames[$i];
            if (is_numeric($v) && is_finite((float)$v)) {
                $clean[] = (float)$v;
            }
        }

        if (count($clean) < 1600) {
            return null;
        }

        return [
            'sample_rate' => $sampleRate,
            'frames' => $clean,
        ];
    }

    private function providerName(): string
    {
        return defined('VOICE_PROVIDER') ? strtolower(VOICE_PROVIDER) : 'hybrid';
    }
}

