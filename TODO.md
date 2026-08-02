# BPC Clinic System — Reconstruction Task List

## Phase A — Remove MICA from sidebar & consolidate AI pages

- [ ] A1: Remove 3 MICA nav entries from `components/sidebar.php`
- [ ] A2: Create consolidated `pages/ai_assistant.php` (role-aware)
- [ ] A3: Delete `pages/student/ai_assistant.php` + `pages/nurse/ai_assistant.php`

## Phase B — Hybrid AI Voice State Machine

- [ ] B1: `assets/js/mica.js` — `continuousMode:false`, `lastInputMode`, text never speaks, voice speaks then returns to idle, no auto-listen on open, female voice detection

## Phase C — Notification system upgrade

- [ ] C1: `api/notifications.php` — add `delete`, `unread_count`, SOS type
- [ ] C2: `components/navbar.php` — dropdown with mark-read/delete, unread badge (CRLF → PHP patch)
- [ ] C3: `assets/js/main.js` — polling every 20s, notification sound, SOS toast

## Phase D — SOS workflow

- [ ] D1: `api/ai_assistant.php` — `sos_list`, `sos_respond`, `sos_resolve`
- [ ] D2: SOS inbox UI (nurse notifications + admin dashboard) with Google Maps link, student info, accept/resolve

## Phase E — Cleanup

- [ ] E1: Delete temp scripts (`_*.php`, `_clean_output.txt`)
- [ ] E2: Update `config/ai_config.php` doc comment
- [ ] E3: Update this TODO.md with final status
