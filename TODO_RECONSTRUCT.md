# BPC Clinic System — Full Reconstruction Progress Tracker

## Phase 1 — Audit (DONE)

- [x] Audited all PHP, JS, CSS, API, DB files
- [x] Identified MICA z-index conflict (1050 vs modal 1050)
- [x] Identified 3 duplicate sidebars
- [x] Identified duplicate AI pages (student/nurse ai_assistant.php)
- [x] Identified legacy ai_assistant.php + AIService duplicate engines
- [x] Identified dead `#chatInput` refs in walkin.php
- [x] Identified broken admin inventory page (missing modals)
- [x] Identified orphaned analytics.php, fix_db_context.php, scattered TODO files

## Phase 2 — Shared Components

- [ ] Build `components/sidebar.php` (single role-aware sidebar)
- [ ] Build `components/navbar.php` (from includes/topbar.php)
- [ ] Build `components/footer.php`
- [ ] Build `components/mica.php` (from includes/mica_widget.php)
- [ ] Update `includes/head.php` + `includes/footer_scripts.php`
- [ ] Update all `pages/**` include paths

## Phase 3 — MICA Global Rebuild

- [ ] Merge mica_chat.js + mica_voice.js into mica.js
- [ ] Raise z-index above modals (99999)
- [ ] Add draggable FAB, minimize/maximize/close
- [ ] Typing animation, auto-scroll, markdown
- [ ] Persistent session, clear/new conversation, timestamps
- [ ] Voice controls (volume/speed/replay) + waveform
- [ ] Real DB context integration (DatabaseContext)
- [ ] Single MICA component loaded globally

## Phase 4 — Navigation

- [ ] Role-aware sidebar menus (admin/nurse/student)
- [ ] Active page highlighting
- [ ] Responsive sidebar + mobile off-canvas

## Phase 5 — API + Security

- [ ] Fix notifications ownership check
- [ ] Standardize prepared statements in DatabaseContext
- [ ] API key via .env only
- [ ] Graceful AI error handling (never freeze UI)

## Phase 6 — UI Redesign

- [ ] Fix admin inventory page (missing modals)
- [ ] Fix walkin quick-actions (MICA.send instead of #chatInput)
- [ ] Consistent design tokens (green/white/blue)
- [ ] Rounded cards, glass effects, professional tables/forms

## Phase 7 — Final Test + Report

- [ ] Test login/logout, all modules, MICA, voice, print/export
- [ ] Final reconstruction report
