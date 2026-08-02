<?php
/**
 * HybridVoiceProvider.php
 * --------------------------------------------------------------
 * Default "hybrid" speaker-verification provider.
 *
 * This provider performs on-device speaker verification using a compact
 * acoustic fingerprint computed from a real FFT-based MEL-spectrum, then
 * compares it server-side with cosine similarity against the stored voice
 * reference.
 *
 * v2 improvements over v1:
 *  - Real radix-2 FFT power spectrum (phase-invariant) instead of a noisy
 *    single-bin DFT.
 *  - MEL-scaled band grouping (0 .. Nyquist) for perceptually-meaningful
 *    energy distribution.
 *  - Voice activity detection: near-silent windows are skipped so the
 *    fingerprint is built from speech only.
 *  - Mean + standard-deviation of mel bands across all speech windows —
 *    stable regardless of sample length (5s enroll vs 3s verify).
 *  - Correct effective sample-rate handling from the client.
 *
 * SECURITY:
 * - Raw audio is never persisted. Only the generated feature reference is
 *   stored in voice_profiles.voice_reference.
 * - Verification is always scoped to the authenticated session user's
 *   stored reference — never a client-supplied one.
 */

require_once __DIR__ . '/VoiceProviderInterface.php';

class HybridVoiceProvider implements VoiceProviderInterface
{
    /** Bump this when the feature format changes so old profiles are flagged for re-enrollment. */
    public const REF_VERSION = 2;

    /** Default cosine-similarity threshold (config-overridable via VOICE_SIMILARITY_THRESHOLD). */
    private float $threshold;

    /** Feature vector size. */
    private const VECTOR_SIZE = 40;

    /** FFT size (power of two). */
    private const FFT_SIZE = 256;

    /** Number of mel bands (each contributes mean + std => 2*MEL_BANDS features). */
    private const MEL_BANDS = 18;

    /** Hop between analysis windows. */
    private const HOP = 128;

    public function __construct(?float $threshold = null)
    {
        $this->threshold = $threshold
            ?? (defined('VOICE_SIMILARITY_THRESHOLD') ? (float)VOICE_SIMILARITY_THRESHOLD : 0.60);
    }

    /**
     * The hybrid provider runs entirely on-device + server-side PHP, so it
     * is always "available" unless explicitly disabled via VOICE_PROVIDER.
     */
    public function isAvailable(): bool
    {
        return defined('VOICE_PROVIDER') && strtolower(VOICE_PROVIDER) === 'hybrid';
    }

    /**
     * Build a compact voice reference (feature vector) from normalized audio.
     *
     * @param array $audio ['sample_rate'=>int, 'frames'=>float[]]
     * @return string|null Base64-encoded JSON reference, or null on failure.
     */
    public function createTemplate(array $audio): ?string
    {
        $vector = $this->extractFeatures($audio);
        if ($vector === null) {
            return null;
        }

        // Store only the generated reference — never raw audio.
        return base64_encode(json_encode([
            'vr' => self::REF_VERSION,
            'v'  => $vector,
            's'  => $audio['sample_rate'] ?? 16000,
            'p'  => 'hybrid',
        ]));
    }

    /**
     * Verify a live sample against a stored reference using cosine similarity.
     */
    public function verify(array $audio, string $reference): bool
    {
        $decoded = json_decode(base64_decode($reference), true);
        if (!is_array($decoded) || empty($decoded['v']) || !is_array($decoded['v'])) {
            return false;
        }
        // Reject outdated references (old feature format) — the service
        // reports a clear "please re-register" message.
        if ((int)($decoded['vr'] ?? 0) !== self::REF_VERSION) {
            return false;
        }

        $enrolledVector = array_map('floatval', $decoded['v']);
        $liveVector = $this->extractFeatures($audio);

        if ($liveVector === null || count($enrolledVector) !== count($liveVector)) {
            return false;
        }

        $similarity = $this->cosineSimilarity($enrolledVector, $liveVector);
        error_log("[VoiceVerify] v2 similarity={$similarity} threshold={$this->threshold}");

        return $similarity >= $this->threshold;
    }

    /**
     * Extract a robust, phase-invariant acoustic fingerprint:
     *   - Hann-windowed frames → real FFT → power spectrum
     *   - MEL band energies per window
     *   - Voice activity detection (skip silence)
     *   - Mean + standard deviation of mel bands across speech windows
     *   - Global RMS / ZCR / spectral centroid
     *   - Mean-centered + L2 normalized
     *
     * @param array $audio ['sample_rate'=>int, 'frames'=>float[]]
     * @return float[]|null Feature vector of length VECTOR_SIZE.
     */
    private function extractFeatures(array $audio): ?array
    {
        if (empty($audio['frames']) || !is_array($audio['frames'])) {
            return null;
        }

        $frames = array_values(array_map('floatval', $audio['frames']));
        $nFrames = count($frames);
        if ($nFrames < 1600) { // require at least ~0.4s of speech
            return null;
        }

        $sampleRate = (int)($audio['sample_rate'] ?? 16000);
        if ($sampleRate <= 0) {
            $sampleRate = 16000;
        }

        $fftSize = self::FFT_SIZE;
        $hop = self::HOP;

        // Precompute bin frequencies and mel band edges once.
        $binFreqs = [];
        for ($k = 0; $k <= $fftSize / 2; $k++) {
            $binFreqs[$k] = $k * $sampleRate / $fftSize;
        }
        $melEdges = $this->melBandEdges(0, $sampleRate / 2, self::MEL_BANDS, $binFreqs);
        $nBands = count($melEdges) - 1;

        // Precompute Hann window.
        $hann = [];
        for ($i = 0; $i < $fftSize; $i++) {
            $hann[$i] = 0.5 * (1 - cos(2 * M_PI * $i / ($fftSize - 1)));
        }

        $frameFeatures = [];

        for ($start = 0; $start + $fftSize <= $nFrames; $start += $hop) {
            $window = array_slice($frames, $start, $fftSize);
            for ($i = 0; $i < $fftSize; $i++) {
                $window[$i] *= $hann[$i];
            }

            $spectrum = $this->powerSpectrum($window);

            $bands = [];
            for ($b = 0; $b < $nBands; $b++) {
                $lo = $melEdges[$b];
                $hi = $melEdges[$b + 1];
                $sum = 0.0;
                for ($k = $lo; $k < $hi && $k <= $fftSize / 2; $k++) {
                    $sum += $spectrum[$k] ?? 0.0;
                }
                $bands[$b] = $sum / max(1, $hi - $lo);
            }

            // Voice activity detection: skip near-silent windows.
            $energy = sqrt(array_sum($bands) + 1e-9);
            if ($energy < 0.005) {
                continue;
            }

            $frameFeatures[] = $bands;
        }

        if (count($frameFeatures) < 4) {
            return null;
        }

        // Mean + std across speech windows.
        $nUsed = count($frameFeatures);
        $mean = array_fill(0, $nBands, 0.0);
        foreach ($frameFeatures as $ff) {
            foreach ($ff as $b => $v) {
                $mean[$b] += $v;
            }
        }
        foreach ($mean as $b => $v) {
            $mean[$b] = $v / $nUsed;
        }

        $std = array_fill(0, $nBands, 0.0);
        foreach ($frameFeatures as $ff) {
            foreach ($ff as $b => $v) {
                $d = $v - $mean[$b];
                $std[$b] += $d * $d;
            }
        }
        foreach ($std as $b => $v) {
            $std[$b] = sqrt($v / $nUsed);
        }

        // Log-compress + assemble vector.
        $feats = [];
        foreach ($mean as $v) {
            $feats[] = log(1 + $v);
        }
        foreach ($std as $v) {
            $feats[] = log(1 + $v);
        }

        // Global signal stats.
        $feats[] = $this->rms($frames);
        $feats[] = $this->zeroCrossingRate($frames);
        $feats[] = $this->spectralCentroid($frames, $sampleRate);

        // Pad / trim to exactly VECTOR_SIZE.
        while (count($feats) < self::VECTOR_SIZE) {
            $feats[] = 0.0;
        }
        $feats = array_slice($feats, 0, self::VECTOR_SIZE);

        // Mean-center + L2 normalize for cosine similarity.
        $m = array_sum($feats) / count($feats);
        foreach ($feats as $i => $v) {
            $feats[$i] = $v - $m;
        }
        $norm = sqrt(array_sum(array_map(fn($x) => $x * $x, $feats)));
        if ($norm < 1e-9) {
            return null;
        }
        return array_map(fn($x) => $x / $norm, $feats);
    }

    /**
     * Compute mel-band edge bin indices (linear in mel domain, mapped to FFT bins).
     */
    private function melBandEdges(float $fMin, float $fMax, int $nBands, array $binFreqs): array
    {
        $melMin = 2595 * log10(1 + $fMin / 700);
        $melMax = 2595 * log10(1 + $fMax / 700);
        $edges = [];
        for ($b = 0; $b <= $nBands; $b++) {
            $mel = $melMin + ($melMax - $melMin) * $b / $nBands;
            $freq = 700 * (pow(10, $mel / 2595) - 1);
            $best = 0;
            $bestDiff = PHP_FLOAT_MAX;
            foreach ($binFreqs as $k => $f) {
                $d = abs($f - $freq);
                if ($d < $bestDiff) {
                    $bestDiff = $d;
                    $best = $k;
                }
            }
            $edges[] = $best;
        }
        return $edges;
    }

    /**
     * Iterative radix-2 complex FFT. Returns [re, im] arrays of size n.
     */
    private function fft(array $re, array $im): array
    {
        $n = count($re);

        // Bit-reversal permutation.
        $j = 0;
        for ($i = 1; $i < $n; $i++) {
            $bit = $n >> 1;
            while ($j & $bit) {
                $j ^= $bit;
                $bit >>= 1;
            }
            $j ^= $bit;
            if ($i < $j) {
                $tmp = $re[$i]; $re[$i] = $re[$j]; $re[$j] = $tmp;
                $tmp = $im[$i]; $im[$i] = $im[$j]; $im[$j] = $tmp;
            }
        }

        // Cooley-Tukey butterflies.
        for ($len = 2; $len <= $n; $len <<= 1) {
            $angle = -2 * M_PI / $len;
            $wre = cos($angle);
            $wim = sin($angle);
            $half = $len >> 1;
            for ($i = 0; $i < $n; $i += $len) {
                $curRe = 1.0;
                $curIm = 0.0;
                for ($k = 0; $k < $half; $k++) {
                    $a = $re[$i + $k];
                    $b = $im[$i + $k];
                    $c = $re[$i + $k + $half];
                    $d = $im[$i + $k + $half];
                    $e = $c * $curRe - $d * $curIm;
                    $f = $c * $curIm + $d * $curRe;
                    $re[$i + $k] = $a + $e;
                    $im[$i + $k] = $b + $f;
                    $re[$i + $k + $half] = $a - $e;
                    $im[$i + $k + $half] = $b - $f;
                    $nwre = $curRe * $wre - $curIm * $wim;
                    $curIm = $curRe * $wim + $curIm * $wre;
                    $curRe = $nwre;
                }
            }
        }

        return [$re, $im];
    }

    /**
     * Power spectrum magnitude^2 (0 .. n/2 inclusive).
     */
    private function powerSpectrum(array $window): array
    {
        $n = count($window);
        $im = array_fill(0, $n, 0.0);
        [$re, $im] = $this->fft($window, $im);

        $spec = [];
        for ($k = 0; $k <= $n / 2; $k++) {
            $spec[$k] = ($re[$k] * $re[$k] + $im[$k] * $im[$k]) / $n;
        }
        return $spec;
    }

    private function rms(array $frames): float
    {
        $n = count($frames);
        if ($n === 0) {
            return 0.0;
        }
        $sum = 0.0;
        foreach ($frames as $x) {
            $sum += $x * $x;
        }
        return sqrt($sum / $n);
    }

    private function zeroCrossingRate(array $frames): float
    {
        $n = count($frames);
        if ($n < 2) {
            return 0.0;
        }
        $z = 0;
        for ($i = 1; $i < $n; $i++) {
            if (($frames[$i - 1] >= 0 && $frames[$i] < 0) || ($frames[$i - 1] < 0 && $frames[$i] >= 0)) {
                $z++;
            }
        }
        return $z / ($n - 1);
    }

    /**
     * Spectral centroid (Hz) computed from a Hann-windowed FFT of the first
     * portion of the signal — a stable timbre indicator.
     */
    private function spectralCentroid(array $frames, int $sampleRate): float
    {
        $frames = array_slice($frames, 0, 65536);
        $n = count($frames);
        if ($n < 256) {
            return 0.0;
        }

        $size = 256;
        while (($size << 1) <= $n && $size < 4096) {
            $size <<= 1;
        }

        $window = array_slice($frames, 0, $size);
        for ($i = 0; $i < $size; $i++) {
            $window[$i] *= 0.5 * (1 - cos(2 * M_PI * $i / ($size - 1)));
        }

        $spec = $this->powerSpectrum($window);
        $num = 0.0;
        $den = 0.0;
        for ($k = 0; $k <= $size / 2; $k++) {
            $freq = $k * $sampleRate / $size;
            $num += $freq * $spec[$k];
            $den += $spec[$k];
        }
        if ($den < 1e-9) {
            return 0.0;
        }
        return $num / $den;
    }

    /**
     * Cosine similarity between two equal-length vectors.
     */
    private function cosineSimilarity(array $a, array $b): float
    {
        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;
        $count = count($a);
        for ($i = 0; $i < $count; $i++) {
            $dot += $a[$i] * $b[$i];
            $normA += $a[$i] * $a[$i];
            $normB += $b[$i] * $b[$i];
        }
        if ($normA < 1e-9 || $normB < 1e-9) {
            return 0.0;
        }
        return $dot / (sqrt($normA) * sqrt($normB));
    }
}

