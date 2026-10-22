# BPC School Clinic System Audit

Audit date: 2026-10-16 (Asia/Manila)

This report replaces an older audit draft whose proposed removals and alleged duplicate-code bugs were not supported by the current source. No application pages or data were removed.

## Inventory and dependency map

- 295 PHP files, 22 JavaScript files, 14 CSS files, and 28 SQL files were found by project inventory. The project has four role page areas: `pages/admin/`, `pages/nurse/`, `pages/student/`, and `pages/adviser/` (the Teacher role's directory).
- Shared request/authentication bootstrap is in `config/config.php`, `includes/auth.php`, and `includes/security.php`; shared shell and role navigation are in `includes/head.php`, `components/navbar.php`, `components/sidebar.php`, and `components/footer.php`.
- Page-to-API dependency groups: Admin pages call user/section, reporting, inventory, notification, QR, emergency, settings, and dashboard APIs. Nurse pages call patient/queue, QR, consultation/walk-in, medical-record, dispensing/inventory, messaging, certificate, report, emergency, and notification APIs. Student pages call profile, health, medical-record, certificate, QR, queue, message, announcement, and notification APIs. Teacher/Adviser pages call advisory/student, QR, queue, message, emergency, announcement, and notification APIs.
- The API layer is backed by the 53-table local schema. Core groups are identity (`users`, `students`, `teachers`, `nurses`, `advisory_assignments`, `sections`); care (`appointments`, `queue_numbers`, `consultations`, `medical_records`, `health_monitoring`, `vaccinations`, `walk_in_visits`, `medicine_dispensing`); QR (`student_qrs`, `teacher_qrs`, `qr_checkins`, `qr_scan_logs`, `dynamic_qrs`); inventory (`medicines`, `medicine_logs`, `inventory_logs`, `medicine_reorders`); communications and safety (`conversations`, `conversation_participants`, `messages`, `notifications`, `sos_emergencies`, `emergency_location_updates`); and administration/AI (`activity_logs`, `admin_permissions`, `admin_customizations`, `ai_logs`, `ai_actions`, `system_settings`).
- Configuration uses PDO in `config/database.php`; schema starts in `database/bpc_clinic_system.sql` and is extended by 26 migration files. There is no automatic migration runner or migration-history table.

### Page-to-API map (literal endpoint references)

| Role/page | API endpoints |
|---|---|
| Admin activity logs, announcements, dashboard, emergencies, profile, reports, users | `activity_logs`, `announcements`, `admin_dashboard`, `emergencies`, `profile`, `reports`, `users` |
| Nurse announcements, certificates, dashboard, dispensing, emergencies, health monitoring, medical records, messages, patient history, profile, queue, reports, walk-in | `announcements`, `certificates`, `queue`, `dispensing`, `emergencies`, `health_monitoring`, `students_api`, `medical_records`, `messages`, `profile`, `walkin`, `patients`, `reports` |
| Student announcements, certificates, dashboard, health monitoring, medical record, messages, QR, profile, queue, vaccination | `announcements`, `certificates`, `qr_codes`, `messages`, `health_monitoring`, `medical_records`, `profile`, `queue`, `vaccinations` |
| Teacher/Adviser announcements, consultations, emergencies, messages, advisory, QR, profile, queue, student detail | `announcements`, `students_api`, `emergencies`, `messages`, `my_advisory`, `qr_codes`, `profile`, `queue` |

### API-to-table map (direct SQL references)

| API | Tables referenced directly |
|---|---|
| `activity_logs` | `activity_logs`, `users` |
| `admin_dashboard` | `activity_logs`, `consultations`, `medicines`, `queue_numbers`, `sos_emergencies`, `users` |
| `ai_assistant` | `medicine_reorders`, `medicines`, `sos_emergencies`, `students`, `users` |
| `announcements` | `announcements`, `users` |
| `certificates` | `certificates`, `dynamic_qrs`, `students`, `users` |
| `dispensing` | `consultations`, `medicine_logs`, `medicines`, `nurses`, `students`, `users` |
| `emergencies` | `emergency_location_updates`, `nurses`, `sections`, `sos_emergencies`, `students`, `users` |
| `health_monitoring` | `health_monitoring`, `students`, `users` |
| `inventory` | `inventory_logs`, `medicine_dispensing`, `medicine_logs`, `medicines`, `nurses`, `users`, `walk_in_visits` |
| `medical_records` | `consultations`, `medical_records`, `medicine_dispensing`, `medicines`, `students`, `users`, `walk_in_visits` |
| `messages` | `conversation_participants`, `conversations`, `messages`, `students`, `users` |
| `my_advisory` | `advisory_assignments`, `sections`, `users` |
| `notifications` | `notifications`, `sos_emergencies`, `students`, `users` |
| `patients` | `certificates`, `consultations`, `medicine_dispensing`, `medicines`, `nurses`, `queue_numbers`, `sos_emergencies`, `student_qrs`, `students`, `users` |
| `qr_codes` | `appointments`, `certificates`, `consultations`, `health_monitoring`, `medicine_dispensing`, `medicine_logs`, `medicines`, `notifications`, `nurses`, `qr_scan_logs`, `queue_numbers`, `student_qrs`, `students`, `teachers`, `users`, `vaccinations`, `walk_in_visits` |
| `queue` | `certificates`, `consultations`, `health_monitoring`, `medicine_dispensing`, `medicine_logs`, `medicines`, `nurses`, `queue_numbers`, `students`, `teachers`, `users`, `walk_in_visits` |
| `reports` | `certificates`, `consultations`, `health_monitoring`, `medicine_logs`, `medicines`, `qr_scan_logs`, `reports`, `sos_emergencies`, `students`, `users`, `walk_in_visits` |
| `notifications/save-fcm-token` | `fcm_tokens` |
| `sections` | `advisory_assignments`, `sections`, `students`, `teachers`, `users` |
| `settings` | `system_settings` |
| `students_api` | `certificates`, `consultations`, `health_monitoring`, `nurses`, `sos_emergencies`, `students`, `teachers`, `users`, `vaccinations`, `wellbeing_profiles` |
| `users` | `advisory_assignments`, `ai_actions`, `doctors`, `nurses`, `students`, `teachers`, `users` |
| `vaccinations` | `students`, `users`, `vaccinations` |
| `walkin` | `certificates`, `consultation_medicines`, `consultations`, `medicine_dispensing`, `medicine_logs`, `medicines`, `nurses`, `queue_numbers`, `students`, `users`, `visit_logs`, `walk_in_visits` |
| `wellbeing` | `students`, `wellbeing_profiles` |

This is a static source map: dynamic/constructed URLs, shared-include endpoints, and tables accessed only through shared helper functions may not appear in the page rows.

## Issues discovered

| Area | Verified finding | Impact |
|---|---|---|
| Database / registration | The live database lacked seven nullable fields used by Teacher and Nurse registration: teacher emergency contact, blood type and allergies; nurse position and emergency contact. The existing `migration_registration_role_medical_details.sql` declares these fields. | Role registration could fail with unknown-column SQL errors. |
| Database configuration | `.env.example` described DB environment overrides, but `config/database.php` ignored them and always used hard-coded defaults. | Deployments could not configure the database as documented. |
| Documentation | `README.md` described an earlier phase and understated the required schema migrations. The prior `AUDIT.md` incorrectly asserted duplicate `log_activity()` execution and duplicate admin chart initialization; inspection found one execution and one chart constructor. | Setup and maintenance guidance was stale. |
| UI/CSS maintenance | Static count in the 13 `assets/css/` files: 327 literal hex/RGB/HSL color declarations and 694 repeated selector strings; 536 inline `style=` attributes across non-vendor, non-scratch PHP. Repeated selectors include responsive/role-specific rules and are not all conflicting duplicates. | Theme and responsive behavior need staged consolidation; this scan alone does not justify removing CSS. |
| Debug logging | FCM token endpoint logged session state and request URI on every request, beyond its useful error logs. | Unnecessary session/request metadata was written to the PHP error log. |
| Role cleanup | Inventory import actions repeated role allowlists after the endpoint already required Nurse/Admin and still named the retired `doctor` role. | The role checks disagreed with the supported four-role contract, though the outer guard already denied that role. |
| Test utilities | `scratch/test_all_workflows.php` contains operations that delete same-day active queue rows and temporarily change inventory quantities; it was not run. | It is unsafe to run against a database with real clinic data. |
| Runtime QA | No browser instance was available and role credentials were not supplied. PHP CLI audit scripts also reported that the sandbox cannot write session files under `C:\xampp\tmp`; Apache HTTP smoke checks succeeded. | Authenticated role workflows, console errors, and responsive rendering remain unverified. |

## Repairs completed

| File / system | Repair | Verification |
|---|---|---|
| Local MySQL schema | Applied the existing additive registration migration after confirming each column was absent and its target table/anchor columns existed. No existing rows were updated or deleted. | Re-ran migration/schema checks; all declared tables and checked columns exist, including the seven registration fields. |
| `config/database.php` | Read DB host/name/user/password/charset from environment variables, retaining XAMPP defaults when values are absent. Empty `DB_PASS` remains valid. | PHP syntax check and live local DB connection. |
| `.env.example` | Added application and database settings matching the configuration behavior; left secret values empty/placeholders. | Compared against config keys; no real `.env` contents were copied. |
| `README.md` | Replaced stale phase/roadmap instructions with current project layout, migration caveat, and XAMPP steps. | Manual source review. |
| `AUDIT.md` | Replaced unverified removal recommendations with the current findings and status report. | Manual source review. |
| `api/notifications/save-fcm-token.php` | Removed the unconditional session/request diagnostic log; kept actionable failure logging. | PHP syntax check included in the full lint. |
| `api/inventory.php` | Made import action checks use the canonical Nurse/Admin role set already enforced by the endpoint guard. | PHP syntax check and static review of the shared role guard. |

No files were removed or consolidated. Existing uncommitted project changes were preserved; work remains on the pre-existing `messages-responsive-redesign` branch.

## Final test results

- **PASS** — PHP syntax: all 136 non-vendor/non-scratch project PHP files passed `php -l` after the final code edits.
- **PASS** — JavaScript syntax: 19 project asset JavaScript files passed `node --check` (vendor scripts excluded).
- **PASS** — Sidebar link targets: every target in the role navigation checked by `scratch/check_sidebar_links.php` exists.
- **PASS** — Static `/pages/*.php` references checked by `scratch/check_url_targets.php` resolve to files.
- **PASS** — HTTP smoke: unauthenticated nurse dashboard redirected to login (302); unauthenticated inventory API returned JSON 401; login page returned 200.
- **PASS** — Database connection: local MariaDB connected and returned 53 tables.
- **PASS** — Migration check: checked tables/columns exist after the registration schema repair.
- **NOT TESTED** — Authenticated login/logout, registration submission, queue/consultation CRUD, inventory mutations, QR scan with a real session, messaging send/receive, AI provider behavior, and user-role access matrix.
- **NOT TESTED** — Browser layout and console on desktop/tablet/mobile; browser runtime discovery returned no available browser.
- **NOT TESTED** — Static checks cannot prove every SQL query, permission branch, or API contract works with all role-specific data.

## Remaining issues and exact next steps

1. Use a backed-up test database and one test account per role to exercise every listed workflow, especially registration, queue, inventory, QR, and messaging.
2. Run the complete PHP syntax check again after all current changes; test important page/API workflows in the browser and inspect the console/network panel.
3. Review `scratch/test_all_workflows.php` before any future run and make it transaction-safe or point it only at a disposable test DB.
4. Consolidate CSS colors/selectors and inline styles gradually, verifying each role at desktop, tablet, and mobile widths. The current counts are inventory signals, not a deletion list.
5. Apply the remaining migrations only after backing up the target database and comparing its schema; the repository has no migration tracker, and migration files are not all safe to rerun.

## How to run

Start Apache and MySQL in XAMPP, configure `.env` (or use the defaults in `config/database.php`), ensure the baseline schema plus required migrations are applied, then open `http://localhost/bpc_clinic_system/`. See [README.md](README.md) for the full local setup notes.
