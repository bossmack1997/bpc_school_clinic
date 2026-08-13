# MICA Production-Ready — Task Tracker

## Steps

- [ ] 1. `config/ai_config.php` — add MICA_WAKE_WORDS, MICA_CONTINUOUS_TIMEOUT_MS, MICA_GREETING_TIMEZONE constants.
- [ ] 2. `components/mica.php` — expose Filipino/Philippine greeting + wake-word config to JS (window.MICA_CONFIG).
- [ ] 3. `assets/js/mica.js` — Philippine-time greeting + natural pauses.
- [ ] 4. `assets/js/mica.js` — stop speech on new message submit (interrupt).
- [ ] 5. `assets/js/mica.js` — wake-word detection before voice activation.
- [ ] 6. `assets/js/mica.js` — noise/confidence gate.
- [ ] 7. `assets/js/mica.js` — enable continuous conversation mode with timeout.
- [ ] 8. `assets/js/mica.js` — mute + stop-speaking buttons in widget.
- [ ] 9. `assets/js/mica.js` — in-flight request cancellation / dedupe.
- [ ] 10. `assets/css/mica.css` — styles for mute/stop buttons + wake indicator.
- [ ] 11. `api/ai_assistant.php` — accept conversation context (memory) for MICA replies.
- [ ] 12. `includes/ai/PromptBuilder.php` — include conversation-memory summary in prompt.
- [ ] 13. Lint: `node --check assets/js/mica.js` + `php -l` on edited PHP.
- [ ] 14. Update `TODO_MICA_PRODUCTION.md` to reflect completion.
