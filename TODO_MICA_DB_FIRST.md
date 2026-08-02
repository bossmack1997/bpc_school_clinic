# MICA DB-First & Personalization Implementation

## Approved Plan Steps

- [ ] 1. AIService: add `buildDataDrivenReply()` (DB-first answers for appointment/bmi/medical_record/health_monitoring/vaccination/medicine_availability/announcements/clinic_schedule)
- [ ] 2. AIService: add `buildDataGapNote()` — personalized "couldn't find X" note when DB has no data, prefixed to AI/rule-based general guidance
- [ ] 3. AIService: accept `page` from config + pass to PromptBuilder; personalize rule-based fallback with user's first name
- [ ] 4. PromptBuilder: add `page` param + CURRENT PAGE section in system prompt
- [ ] 5. api/ai_assistant.php: read `$_POST['page']`, pass into AIService config + response
- [ ] 6. assets/js/mica.js: append `page` to mica_chat FormData via `getCurrentPage()`
- [ ] 7. Lint all edited PHP (php -l) + JS (node --check)
- [ ] 8. Update CHANGELOG.md

## Rules Enforced

- No duplicate files/components — reuses DatabaseContext (single source of truth for queries)
- DB-first: answer from records when found (source= database), fall back to AI general guidance when not
- Clearly label factual data as "📋 From the clinic records"
- Never fabricate; use real logged-in user's first name
- Hybrid voice/text mode already correct — untouched
