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
define('ELEVENLABS_VOICE_ID', getenv('ELEVENLABS_VOICE_ID') ?: 'EXAVITQu4vr4xnSDxMaL');
define('ELEVENLABS_API_ENDPOINT', 'https://api.elevenlabs.io/v1/text-to-speech/' . ELEVENLABS_VOICE_ID);

// ============================================================
// MICA Configuration
// ============================================================
// Philippine Timezone — used for the correct greeting based on
// Asia/Manila time regardless of the server/browser timezone.
define('MICA_TIMEZONE', getenv('MICA_TIMEZONE') ?: 'Asia/Manila');

// Wake words (comma-separated) that appear as suggestions for voice listening.
define('MICA_TTS_PROVIDER', !empty(ELEVENLABS_API_KEY) && ELEVENLABS_API_KEY !== 'your-elevenlabs-api-key-here' ? 'elevenlabs' : 'browser');

// Continuous conversation inactivity timeout (ms) — auto-stop
// listening after no clear speech is detected within this window.
define('MICA_CONTINUOUS_TIMEOUT_MS', (int)(getenv('MICA_CONTINUOUS_TIMEOUT_MS') ?: 8000));

// Minimum confidence (0-1) for a transcript to be processed.
// Low-confidence / background-noise transcripts are ignored.
define('MICA_MIN_CONFIDENCE', (float)(getenv('MICA_MIN_CONFIDENCE') ?: 0.35));

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

