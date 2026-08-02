 <?php
/**
 * AI Health Assistant Configuration
 * 
 * This system ships with a safe, rule-based responder that works out of the box
 * with no external dependency (see includes/ai_assistant.php).
 * 
 * To upgrade to a real LLM via OpenRouter, set your API key below.
 * OpenRouter provides access to Claude, GPT, Gemini, and many other models
 * through a single API.
 * 
 * Get your free API key at: https://openrouter.ai/keys
 */

// ============================================================
// AI Debug Mode
// When enabled, the AI endpoint will return detailed debug information
// including HTTP status, API response, cURL errors, selected model,
// and whether the system used OpenRouter or the rule-based fallback.
// 
// Debug mode is automatically enabled when PHP display_errors is on (dev mode).
// In production, set this to false to prevent exposing sensitive information.
// ============================================================
define('AI_DEBUG_MODE', (bool)ini_get('display_errors'));

// ============================================================
// OpenRouter AI Configuration
// Your API key is set below for context-aware AI responses
// ============================================================
// API keys are read from the local .env file first (recommended),
// with legacy defaults retained as a fallback for existing installs.
define('AI_OPENROUTER_API_KEY', getenv('OPENROUTER_API_KEY') ?: getenv('AI_OPENROUTER_API_KEY') ?: '');
define('AI_OPENROUTER_ENDPOINT', getenv('OPENROUTER_ENDPOINT') ?: 'https://openrouter.ai/api/v1/chat/completions');
define('AI_OPENROUTER_MODEL', getenv('OPENROUTER_MODEL') ?: 'openai/gpt-4o-mini'); // Fast & affordable model

// Alternative models you can use:
// - 'anthropic/claude-3.5-sonnet'
// - 'openai/gpt-4o'
// - 'google/gemini-2.0-flash-001'
// - 'meta-llama/llama-3.3-70b-instruct'
// - 'mistralai/mistral-7b-instruct'
// - 'deepseek/deepseek-r1'

// ============================================================
// ElevenLabs Text-to-Speech Configuration
// Used by MICA (Medical Intelligent Clinic Assistant) for voice output.
// Get your API key at: https://elevenlabs.io
// ============================================================
define('ELEVENLABS_API_KEY', getenv('ELEVENLABS_API_KEY') ?: '');
define('ELEVENLABS_VOICE_ID', getenv('ELEVENLABS_VOICE_ID') ?: 'BZgkqPqms7Kj9ulSkVzn');
define('ELEVENLABS_API_ENDPOINT', ELEVENLABS_VOICE_ID ? 'https://api.elevenlabs.io/v1/text-to-speech/' . ELEVENLABS_VOICE_ID : 'BZgkqPqms7Kj9ulSkVzn ');

// ============================================================
// Voice Verification Service Configuration
// Provider abstraction — swap in future providers (Azure Speaker
// Recognition, Resemblyzer, NVIDIA NeMo, etc.) by implementing the
// VoiceProviderInterface and setting VOICE_PROVIDER accordingly.
// ============================================================
define('VOICE_PROVIDER', getenv('VOICE_PROVIDER') ?: 'hybrid');          // hybrid (default) | azure | resemblyzer | nemo
define('VOICE_SIMILARITY_THRESHOLD', (float)(getenv('VOICE_SIMILARITY_THRESHOLD') ?: 0.60)); // 0.0–1.0, higher = stricter (0.60 default for the v2 fingerprint)
define('VOICE_VERIFY_SAMPLE_SECONDS', (int)(getenv('VOICE_VERIFY_SAMPLE_SECONDS') ?: 3));    // ~2–3s verification sample
define('VOICE_ENROLL_SAMPLE_SECONDS', (int)(getenv('VOICE_ENROLL_SAMPLE_SECONDS') ?: 8));    // ~5–10s enrollment sample

// ============================================================
// Legacy API Configuration (for backward compatibility)
// ============================================================
define('AI_USE_EXTERNAL_API', !empty(AI_OPENROUTER_API_KEY));
define('AI_API_KEY', AI_OPENROUTER_API_KEY);
define('AI_API_ENDPOINT', AI_OPENROUTER_ENDPOINT);
define('AI_MODEL', AI_OPENROUTER_MODEL);

define('AI_SYSTEM_PROMPT', <<<PROMPT
You are the BPC School Clinic AI Health Assistant. You help students with general
wellness tips, explain common symptoms in plain language, explain clinic procedures,
and help them decide whether to book a clinic appointment.

Rules you must always follow:
- Never diagnose a disease or condition. Do not tell a student "you have X".
- For anything beyond general wellness info, or if symptoms sound concerning,
  urgent, or worsening, clearly recommend visiting or booking an appointment
  with the school nurse.
- Keep answers short, warm, and easy to understand for a student.
- If a student describes an emergency (difficulty breathing, chest pain, severe
  bleeding, loss of consciousness, thoughts of self-harm), tell them to seek
  immediate in-person help from the school nurse or emergency services right away.
PROMPT
);

