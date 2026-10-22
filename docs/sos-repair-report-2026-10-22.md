# SOS repair report — 22 October 2026

## A. Root cause

The header details modal in `assets/js/main.js` and notification-center modal in `includes/notification_ui.php` posted `action=sos_update_status` to `api/ai_assistant.php`. `MICAAuthorization::actionAllowed()` rejects that action for both nurse and admin, producing HTTP 403 before any emergency update. That API also had no implementation of the requested status action. The header's jQuery failure callback labelled every HTTP or JSON failure “Network error. Please try again.”

Evidence: both original modal callers and the MICA authorization/dispatch code were inspected. `scratch/verify_sos_root_cause.php` executed the authorization check: nurse = no; admin = no. The original browser click was not reproduced because no browser sessions were connected. Activity logging is not the cause of this request failure: the authorization rejection occurs before the emergency mutation or its audit write.

Both modals now use `api/emergencies.php?action=update_status` with its `op` contract. Legacy MICA SOS requests dispatch to that same endpoint before AI initialization; MICA permissions were not weakened.

## B. Files changed by this repair

The workspace already contained extensive changes, including SOS files; those were preserved. The list below identifies files touched in this task, not every dirty file in Git.

| Application file | Purpose |
|---|---|
| `api/emergencies.php` | Mutation method/CSRF validation, JSON error handling, shared create/status/cancel workflow, serialized adviser submissions, private reporter history, history mode, locked and accuracy-aware location updates. |
| `includes/sos_workflow.php` (new) | Shared transactional creation, responder ownership, transitions, idempotent retries, strict audit writes, GPS validation, and notification delivery after commit. |
| `api/ai_assistant.php` | Compatibility dispatch of old SOS callers into the emergency API; remove unreachable duplicate creation implementation. |
| `api/notifications.php` | Include teacher employee identity, actual reporter role, assigned responder name, and acknowledgment timestamps in existing staff-only details. |
| `assets/js/main.js` | Correct modal endpoint, loading and duplicate-action guards, resolution confirmation/notes, specific HTTP/JSON/offline errors, confirmed status updates, location validation and accuracy warnings. |
| `includes/notification_ui.php` | Use shared action handler, current status buttons, employee identity, responder/timestamp details, and GPS warnings. |
| `components/mica.php` | Manual location, reporter history, student/teacher status polling and resume, cancellation reason, submission guard, accuracy warnings and clearer submission errors. |
| `api/global_sos.php` | JSON CSRF and service-failure responses. |
| `includes/global_sos_service.php` | Include location accuracy in authorized alert snapshots. |
| `assets/js/global-sos.js` | Show accuracy warnings, request missing resolution notes, refresh after modal updates. |
| `pages/nurse/emergencies.php` | Request closed records as well as active records; require resolution notes and display server errors. |
| `pages/admin/emergencies.php` | Request closed records, correct resolved/active statistics, teacher SOS label, required notes and server errors. |

Added QA files: `scratch/test_sos_workflow_db.php`, `scratch/sos_test_stubs.php`, `scratch/test_sos_endpoint.php`, `scratch/test_sos_actions.js`, `scratch/test_sos_page.php`, `scratch/verify_sos_root_cause.php`, and this report. Rendered QA JavaScript and temporary repair scripts were removed after verification.

## C. Database

No schema changes or migration commands are required for the inspected installation. Its existing tables already contain the supported statuses, nullable student identity, responder/acknowledgment/cancellation columns, timestamps, notes, location history, and screen-notification receipts.

No existing records were deleted or normalized. The main mutation tests used connection-local MySQL temporary tables cloned from the actual schema; they disappear with the connection and never create patient alerts. Authenticated endpoint/page checks use existing accounts for reads; the deliberately unauthorized endpoint test writes an appropriate denied-attempt audit entry. Authentication helpers may update their usual activity timestamps.

Submission locking uses the reporter's user row. Status mutations lock the emergency row. Status and audit persistence share one transaction. Notifications run after commit; failures are logged and cannot roll back a saved emergency. Existing staff polling reads emergency state directly, so alert visibility does not depend on push delivery.

## D. Workflow verification

| Workflow | Executed evidence |
|---|---|
| Student SOS | Real MySQL temporary-table creation without GPS; manual location; repeated submission returns same record. Authenticated private history endpoint succeeds. |
| Teacher SOS | Real MySQL creation with null student ID and teacher role; accuracy stored as numeric meters; own cancellation succeeds. Authenticated private history endpoint succeeds. |
| Nurse | Acceptance, responding, resolution with notes, assigned identity/timestamps, duplicate-action suppression, conflicting second responder rejection, invalid/backward/closed transitions and required notes tested. |
| Administration | Authenticated history and global snapshot endpoints succeed; existing acknowledgment permission tested. No medical-record permission expansion. |
| Notifications | Simulated delivery outage throughout mutation tests leaves committed state intact. Existing notification receipt/polling fixture suites pass. Live FCM delivery was not exercised. |
| Activity logs | Actor role, emergency reference and transitions persisted in the same transaction. Deliberate audit SQL failure rolls back new emergency creation. |

Reporter history is available from the existing SOS modal; nurse/admin management requests include historical states. List responses remain capped at the existing 200 most recent records. Role permissions remain the existing nurse/admin responder design. A reporting user can cancel before clinic acceptance; later cancellation must go through the assigned clinic responder. Cancellation reasons and resolution notes are saved in existing columns. Older cancellation clients receive an explicit default reason.

## E. Tests executed

- PHP syntax checks: all ten touched PHP files passed.
- Node syntax checks: `assets/js/main.js`, `assets/js/global-sos.js`, and inline scripts extracted from rendered nurse/admin emergency pages passed.
- `php scratch/test_sos_workflow_db.php`: passed creation, no-GPS/teacher support, duplicates, permissions, ownership conflicts, transitions, notes, timestamps, audit metadata, notification outage and audit rollback checks using real MySQL temporary tables.
- `node scratch/test_sos_actions.js`: passed frontend parameter contract, GPS bounds, poor/unknown accuracy, HTTP error, invalid JSON, connection failure, resolution notes and button recovery checks.
- Existing `php scratch/test_global_sos.php` and `node scratch/test_global_sos_js.js`: passed role/status/receipt and polling/overlay/focus/offline fixture suites.
- `php scratch/test_sos_endpoint.php ROLE CASE`: student/teacher/nurse/admin history and admin snapshot returned JSON success; invalid CSRF = 403, wrong method = 405, forbidden student status mutation = 403, expired session = 401. Legacy MICA acceptance reached emergency lookup and returned JSON 404 for a deliberately nonexistent ID, instead of MICA authorization 403.
- Actual Apache HTTP request without authentication returned HTTP 401 and valid JSON.
- Authenticated CLI rendering of nurse/admin emergency pages completed; extracted inline JavaScript passed Node syntax validation.
- Targeted `git diff --check` passed.

Limits: no browser was connected, so browser console/network inspection, visual modal interactions, physical GPS permissions, desktop/tablet/mobile layout and installed-PWA behavior were not tested. Competing responders were tested sequentially against ownership checks; a truly simultaneous multi-connection acceptance stress test remains unexecuted. Notification success delivery and end-to-end real-account mutations were not tested, to avoid sending synthetic emergency alerts to real staff. Network errors were simulated in frontend tests, not caused by physically disconnecting a device. No claim is made that these unexecuted tests passed.

## F. Status

The incorrect status endpoint and misleading network-error handling are repaired. Creation, response, resolution, cancellation, teacher identity, private history, transactional audits and failed-delivery resilience pass the executed automated checks. Browser/device/PWA validation, simultaneous-request stress testing and live push delivery remain necessary before calling the complete deployment verified.
