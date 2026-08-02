<?php
/**
 * VoiceProviderInterface.php
 * --------------------------------------------------------------
 * Contract for speaker-verification providers.
 *
 * The system supports multiple providers (Hybrid on-device acoustic
 * fingerprint, Azure Speaker Recognition, Resemblyzer, NVIDIA NeMo, and
 * future APIs) through this single interface. The active provider is
 * selected by the VOICE_PROVIDER config constant and instantiated by
 * VoiceVerificationService. Provider-specific logic is never hardcoded
 * into MICA.
 *
 * IMPORTANT: Raw audio is only used transiently to build a template
 * reference. Providers MUST NOT require permanent raw-audio storage;
 * only the generated voice reference (embedding / template) is stored.
 */

interface VoiceProviderInterface
{
    /**
     * Whether the provider is currently available/reachable.
     * When false, Voice Mode is disabled and text chat remains functional.
     */
    public function isAvailable(): bool;

    /**
     * Generate a voice template reference from a transient audio sample.
     *
     * @param array $audio Audio payload from the client. MUST be normalized
     *                     by VoiceVerificationService before reaching here.
     *                     Typically ['sample_rate'=>int, 'frames'=>float[]].
     * @return string|null Voice reference (embedding/template). Null on failure.
     */
    public function createTemplate(array $audio): ?string;

    /**
     * Verify a live audio sample against a stored voice reference.
     *
     * @param array  $audio     Audio payload from the client (normalized).
     * @param string $reference The stored voice reference for the user.
     * @return bool True when the voice matches the reference.
     */
    public function verify(array $audio, string $reference): bool;
}

