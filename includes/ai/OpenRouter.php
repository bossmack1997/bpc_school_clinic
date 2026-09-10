<?php
/**
 * OpenRouter.php
 * 
 * Production-ready API client for OpenRouter AI models.
 * Uses cURL with comprehensive error handling.
 * 
 * Features:
 * - Reads API key from config/ai_config.php
 * - Timeout handling
 * - Invalid API Key detection (401)
 * - Server Error handling (5xx)
 * - Rate Limit handling (429)
 * - Exponential backoff retry
 * - Token usage tracking
 * - Model selection
 * 
 * Falls back gracefully — returns success:false on any error
 * so the caller can use rule-based fallback.
 */

class OpenRouter
{
    private string $apiKey;
    private string $apiEndpoint;
    private string $model;
    private int $timeout;
    private int $connectTimeout;
    private int $maxRetries;
    private bool $enabled;

    /**
     * Constructor — reads API key from config/ai_config.php constants
     * or from the optional $config array.
     *
     * @param array $config Optional config overrides
     */
    public function __construct(array $config = [])
    {
        // Load from config file constants first, then fall back to $config array
        $this->apiKey = $config['api_key'] ?? (defined('AI_OPENROUTER_API_KEY') && AI_OPENROUTER_API_KEY ? AI_OPENROUTER_API_KEY : '');
        $this->apiEndpoint = $config['endpoint'] ?? (defined('AI_OPENROUTER_ENDPOINT') ? AI_OPENROUTER_ENDPOINT : 'https://openrouter.ai/api/v1/chat/completions');
        $this->model = $config['model'] ?? (defined('AI_OPENROUTER_MODEL') ? AI_OPENROUTER_MODEL : 'openai/gpt-4o-mini');
        $this->timeout = $config['timeout'] ?? 30;
        $this->connectTimeout = $config['connect_timeout'] ?? 10;
        $this->maxRetries = $config['max_retries'] ?? 2;

        // Check if API is properly configured
        $this->enabled = !empty($this->apiKey)
            && $this->apiKey !== 'your-openrouter-api-key-here'
            && str_starts_with($this->apiKey, 'sk-or-');
    }

    /**
     * Send a chat completion request to OpenRouter API.
     *
     * @param string $systemPrompt The system prompt with full context
     * @param array  $messages     Array of message objects: ['role' => 'user'|'assistant', 'content' => string]
     * @return array{
     *   success: bool,
     *   content: ?string,
     *   error: ?string,
     *   error_type: ?string,
     *   model: ?string,
     *   tokens_in: int,
     *   tokens_out: int,
     *   http_code: int
     * }
     */
    public function complete(string $systemPrompt, array $messages): array
    {
        $res = $this->chat($systemPrompt, $messages);
        return [
            'success' => !empty($res['success']) && !empty($res['content']),
            'reply'   => $res['content'] ?? '',
            'model'   => $res['model'] ?? $this->model,
            'error'   => $res['error'] ?? null,
        ];
    }

    public function chat(string $systemPrompt, array $messages): array
    {
        if (!$this->enabled) {
            return $this->error('API is not configured. Set AI_OPENROUTER_API_KEY in config/ai_config.php.', 'config_error');
        }

        // Build request payload
        $payload = [
            'model' => $this->model,
            'messages' => array_merge(
                [['role' => 'system', 'content' => $systemPrompt]],
                $messages
            ),
            'temperature' => 0.7,
            'max_tokens' => 600,
            'top_p' => 0.9,
            'frequency_penalty' => 0.3,
            'presence_penalty' => 0.2,
            'stream' => false,
        ];

        // Build headers
        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->apiKey,
            'HTTP-Referer: ' . (defined('APP_URL') ? APP_URL : 'http://localhost/bpc_clinic_system'),
            'X-Title: BPC Clinic AI Assistant',
        ];

        // Attempt with retries and exponential backoff
        $attempts = 0;
        $lastError = null;
        $lastErrorType = null;

        while ($attempts < $this->maxRetries) {
            $attempts++;
            $result = $this->sendRequest($payload, $headers);

            if ($result['success']) {
                return $result;
            }

            $lastError = $result['error'];
            $lastErrorType = $result['error_type'];

            // Don't retry on auth or validation errors
            if (in_array($lastErrorType, ['auth_error', 'invalid_request'])) {
                break;
            }

            // Rate limit — wait and retry
            if ($lastErrorType === 'rate_limit') {
                $retryAfter = $result['retry_after'] ?? ($attempts * 2);
                usleep(min($retryAfter * 1000000, 5000000)); // Max 5s wait
                continue;
            }

            // Server error — exponential backoff
            if ($attempts < $this->maxRetries) {
                usleep(min(1000000 * $attempts, 3000000)); // 1s, 2s max 3s
            }
        }

        return $this->error(
            $lastError ?? 'Failed to get AI response after ' . $attempts . ' attempt(s).',
            $lastErrorType ?? 'unknown',
            $this->model
        );
    }

    /**
     * Send the actual HTTP request via cURL.
     *
     * @param array $payload  Request body
     * @param array $headers  HTTP headers
     * @return array
     */
    private function sendRequest(array $payload, array $headers): array
    {
        $jsonPayload = json_encode($payload);
        if ($jsonPayload === false) {
            return $this->error('Failed to encode request payload: ' . json_last_error_msg(), 'internal_error');
        }

        $ch = curl_init($this->apiEndpoint);
        if ($ch === false) {
            return $this->error('Failed to initialize cURL session', 'internal_error');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $jsonPayload,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => 'BPC-Clinic-AI/1.0',
            CURLOPT_HEADER         => false,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
        ]);

        $response = curl_exec($ch);

        // Get response info
        $httpCode    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $curlError   = curl_error($ch);
        $curlErrno   = curl_errno($ch);

        // Get response headers if available
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $responseHeaders = curl_getinfo($ch, CURLINFO_HEADER_OUT);

        curl_close($ch);

        // Handle cURL errors
        if ($curlErrno !== 0) {
            $errorMessages = [
                CURLE_OPERATION_TIMEDOUT     => 'Request timed out after ' . $this->timeout . ' seconds.',
                CURLE_COULDNT_CONNECT        => 'Could not connect to API server.',
                CURLE_COULDNT_RESOLVE_HOST   => 'Could not resolve API hostname.',
                CURLE_SSL_CONNECT_ERROR      => 'SSL connection error.',
                CURLE_GOT_NOTHING            => 'Server returned no data.',
            ];
            $errorMsg = $errorMessages[$curlErrno] ?? 'cURL error (' . $curlErrno . '): ' . $curlError;
            return $this->error($errorMsg, 'network_error', null, $httpCode);
        }

        // Handle HTTP error codes
        if ($httpCode === 0) {
            return $this->error('No response from server (HTTP 0).', 'network_error', null, $httpCode);
        }

        if ($httpCode === 401) {
            return $this->error('Invalid API key (HTTP 401). Please check AI_OPENROUTER_API_KEY in config/ai_config.php.', 'auth_error', null, $httpCode);
        }

        if ($httpCode === 403) {
            return $this->error('Access forbidden (HTTP 403). Your API key may not have access to this model.', 'auth_error', null, $httpCode);
        }

        if ($httpCode === 429) {
            $retryAfter = 5;
            // Try to parse Retry-After header
            if (preg_match('/retry-after:\s*(\d+)/i', $responseHeaders ?? '', $matches)) {
                $retryAfter = (int)$matches[1];
            }
            return $this->error('Rate limit exceeded (HTTP 429). Please wait before sending more requests.', 'rate_limit', null, $httpCode, $retryAfter);
        }

        if ($httpCode >= 500) {
            $serverErrors = [
                500 => 'Internal Server Error',
                502 => 'Bad Gateway',
                503 => 'Service Unavailable',
                504 => 'Gateway Timeout',
            ];
            $errorMsg = $serverErrors[$httpCode] ?? 'Server error (HTTP ' . $httpCode . ')';
            return $this->error($errorMsg . '. The AI service is temporarily unavailable.', 'server_error', null, $httpCode);
        }

        // Validate response body
        if ($response === false || $response === '') {
            return $this->error('Empty response from server.', 'empty_response', null, $httpCode);
        }

        // Parse JSON
        $data = json_decode($response, true);
        if ($data === null) {
            $jsonError = json_last_error_msg();
            $preview = mb_substr($response, 0, 200);
            return $this->error('Invalid JSON response: ' . $jsonError . '. Response preview: ' . $preview, 'parse_error', null, $httpCode);
        }

        // Check for API error responses
        if (isset($data['error'])) {
            $errorCode    = $data['error']['code'] ?? 'unknown';
            $errorMessage = $data['error']['message'] ?? json_encode($data['error']);

            // Map API error codes
            $errorType = match ($errorCode) {
                'invalid_api_key' => 'auth_error',
                'insufficient_quota' => 'quota_error',
                'rate_limit_exceeded' => 'rate_limit',
                'model_not_found' => 'model_error',
                'context_length_exceeded' => 'context_error',
                default => 'api_error',
            };

            return $this->error('API error (' . $errorCode . '): ' . $errorMessage, $errorType, null, $httpCode);
        }

        // Validate response structure
        if (!isset($data['choices'][0]['message']['content'])) {
            return $this->error('Invalid response structure: missing content.', 'parse_error', null, $httpCode);
        }

        // Extract content
        $content = $data['choices'][0]['message']['content'];
        $usedModel = $data['model'] ?? $this->model;

        // Track token usage
        $tokensIn  = $data['usage']['prompt_tokens'] ?? 0;
        $tokensOut = $data['usage']['completion_tokens'] ?? 0;

        return [
            'success'    => true,
            'content'    => $content,
            'error'      => null,
            'error_type' => null,
            'model'      => $usedModel,
            'tokens_in'  => $tokensIn,
            'tokens_out' => $tokensOut,
            'http_code'  => $httpCode,
            'retry_after' => null,
        ];
    }

    /**
     * Check if the API is configured and enabled.
     *
     * @return bool
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get the current model name.
     *
     * @return string
     */
    public function getModel(): string
    {
        return $this->model;
    }

    /**
     * Get the API key (for diagnostic/logging purposes).
     *
     * @return string
     */
    public function getApiKey(): string
    {
        return $this->apiKey;
    }

    /**
     * Set a different model for subsequent requests.
     *
     * @param string $model
     */
    public function setModel(string $model): void
    {
        $this->model = $model;
    }

    /**
     * Get available models from OpenRouter (cached for 1 hour).
     *
     * @return array List of available models
     */
    public function getAvailableModels(): array
    {
        if (!$this->enabled) return [];

        $cacheFile = sys_get_temp_dir() . '/bpc_ai_models.json';
        $cacheTTL = 3600;

        // Check cache
        if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTTL) {
            $cached = json_decode(file_get_contents($cacheFile), true);
            if (is_array($cached)) return $cached;
        }

        $ch = curl_init('https://openrouter.ai/api/v1/models');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $this->apiKey],
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) return [];

        $data = json_decode($response, true);
        $models = $data['data'] ?? [];

        // Filter to relevant models
        $filtered = array_map(function ($m) {
            return [
                'id'      => $m['id'],
                'name'    => $m['name'] ?? $m['id'],
                'pricing' => $m['pricing'] ?? [],
            ];
        }, $models);

        // Cache results
        file_put_contents($cacheFile, json_encode($filtered));

        return $filtered;
    }

    /**
     * Create a standard error response.
     *
     * @param string  $error     Error message
     * @param string  $errorType Error type identifier
     * @param ?string $model     Model name
     * @param int     $httpCode  HTTP status code
     * @param ?int    $retryAfter Seconds to wait before retry
     * @return array
     */
    private function error(
        string $error,
        string $errorType = 'unknown',
        ?string $model = null,
        int $httpCode = 0,
        ?int $retryAfter = null
    ): array {
        return [
            'success'     => false,
            'content'     => null,
            'error'       => $error,
            'error_type'  => $errorType,
            'model'       => $model ?? $this->model,
            'tokens_in'   => 0,
            'tokens_out'  => 0,
            'http_code'   => $httpCode,
            'retry_after' => $retryAfter,
        ];
    }
}

