# MICA Production-Ready AI Voice Assistant — Implementation Plan

## Objective

Redesign MICA into a secure, natural, professional female clinic-assistant voice
experience while preserving the existing PHP/MySQL/Bootstrap/OpenRouter architecture,
all existing chat features, database relationships, and the global floating widget.

## Current State (Analysis)

The following are ALREADY implemented and working:

- `prepareSpeechText()` / `cleanSpeechText()` speech pipeline (emoji/markdown/HTML/URL/
  symbol removal, acronym expansion, natural date/time/number conversion, long-response
  summarization) used in both `speakWithNativeTTS` and `ttsViaServer`.
- Female voice auto-detection (`pickFemaleVoice`).
- Voice recognition security: `api/voice.php`, `VoiceVerificationService`,
  `HybridVoiceProvider`, `voice_profiles` table, `components/voice_enrollment.php`
  (enroll/re-enroll/test/delete, encrypted reference only, never raw audio).
- Glassmorphism UI, waveform, thinking/speaking/listening animations, volume & speed
  controls, minimize/expand, draggable FAB, replay, SOS modal, quick actions.
- Audio caching, native + ElevenLabs server TTS with fallback.

## Gaps to Implement

### Gap A — Philippine-time greeting + natural speech pauses (Req 7, 8, 10)

- `showPersonalizedGreeting()` uses local system time; must use Philippine Time
  (Asia/Manila) and the friendly BPC welcome format.
- Insert natural pauses (short sentence breaks) after commas/periods in speech text.

### Gap B — Wake word detection (Req 16)

- Implement configurable wake words ("hey mica", "hello mica", "hi mica") that activate
  voice listening only when the wake word is detected + voice verified.

### Gap C — Noise filtering / confidence gate (Req 17)

- Add a confidence/energy gate so only clear speech is processed. Ignore background noise
  (low energy, low confidence transcripts).

### Gap D — Continuous conversation mode (Req 18)

- Enable true continuous listening after each response when enabled, scoped to the
  registered user's voice, with an inactivity timeout.

### Gap E — Conversation memory (Req 19)

- Track recent session context (student name, current topic, last question, appointment
  being discussed) and pass it to the AI so it doesn't repeat questions.

### Gap F — Speech interruption on new message (Req 13)

- Stop/cancel TTS when the user submits a new message (text or voice) to prevent overlap.

### Gap G — UI additions: mute + stop speaking buttons (Req 21)

- Add a mute button and a dedicated "Stop speaking" button alongside the existing replay,
  volume, and speed controls.

### Gap H — Error handling polish (Req 20)

- Ensure friendly error messages + text-chat fallback for mic-permission, network,
  OpenRouter, and TTS failures (largely present; consolidate + surface toast).

### Gap I — Performance (Req 22)

- Prevent duplicate/overlapping API requests, cancel in-flight requests on interrupt,
  lazy-load voice resources, cache non-sensitive session data.

## Files to Edit

- `assets/js/mica.js` — all JS changes (greeting, wake word, noise gate, continuous mode,
  memory, interruption, mute/stop buttons, performance).
- `assets/css/mica.css` — styles for new mute/stop buttons + wake-word indicator.
- `components/mica.php` — expose Philippine-time greeting + wake-word config to JS.
- `config/ai_config.php` — add wake-word + continuous-timeout config constants.
- `includes/ai/PromptBuilder.php` — provide conversation-memory context to the AI.
- `api/ai_assistant.php` — accept + persist conversation memory (session context).

## Follow-up Steps

- Lint/verify JS with `node --check assets/js/mica.js`.
- Verify `php -l` on edited PHP files.
- Manual browser test of widget on desktop + mobile.
