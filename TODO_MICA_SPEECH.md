# MICA Natural Female Voice Conversation — Task List

## Objective

Improve MICA's speech behavior so she sounds like a real professional female
clinic assistant instead of reading raw AI text. Do NOT change existing AI logic.
Only improve speech processing, greetings, and voice behavior.

## Steps

- [ ] 1. Add shared `cleanSpeechText(text)` pipeline (emoji/markdown/HTML/URL/symbols removal, acronym expansion, number/date/time conversion, hidden metadata stripping, natural pauses)
- [ ] 2. Add `summarizeForSpeech(text)` for responses > 120 words (concise summary + "Would you like me to explain the rest?")
- [ ] 3. Use `cleanSpeechText` in BOTH `ttsViaServer()` and `speakWithNativeTTS()`
- [ ] 4. Update `showPersonalizedGreeting()` to use Philippine Time greeting + friendly personalized format
- [ ] 5. Stop speech when the user submits a new message (prevent voice overlap)
- [ ] 6. Verify chat window still displays original formatted response (unchanged)
