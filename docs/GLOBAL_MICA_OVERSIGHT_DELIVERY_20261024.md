# Medical oversight and global MICA delivery — 24 October 2026

## Outcome and original issues

The pre-edit findings are in `GLOBAL_MICA_OVERSIGHT_AUDIT_20261024.md`.
The existing Doctor command center and Nurse review system were retained rather
than rebuilt. This pass repairs the shared MICA boundaries around them: database
role `admin` previously conflated Doctor and MIS actions, alerts, suggestions and
summary counts; history had no user/profile scope; an appointment context query
contained concatenated SELECT statements. Personal history/queue and applicant
listing now have deterministic, ownership-scoped answers.

No role IDs, authentication system, registration tables or AI provider integrations
were replaced. No changes were made to QR scanning. No commit, merge, push or
deployment was performed.

## Nurse workflow and Doctor oversight

Nurse registration and email OTP activate only the restricted account portal.
The Nurse submits professional credentials through the existing account page.
The existing submission event notifies active Doctors. The Doctor reviews identity,
professional registration, supporting documentation and institutional appointment
information in Clinical Staff Verification. Approval requires CSRF, current form
version, explicit appointment confirmation, review reference/notes and password
reauthentication. Row locks and a transaction persist the decision, reviewer,
timestamp, history, scope/version changes, audit and Nurse notification. Duplicate
or stale decisions fail. Rejections retain the application. Authorization activates
operational access after the required session refresh; rejection/revocation removes
clinical access. MIS identity administration cannot grant clinical authorization.

This workflow already existed at the start of this pass and was regression-tested.
MICA now returns real pending counts and up to ten pending applicants, limited to
name, email, registration date and review status. It directs the Doctor to the
existing review page and never decides credentials. The lookup stays local and
leaves an audit event without copying credential contents into the audit log.

The existing Doctor dashboard retains real consultation counts, queue activity,
dated follow-ups, physician referrals, emergencies, inventory concerns, clinical
activity and the staff verification card. Dated follow-ups do not imply an invented
completion status. Doctor inventory is oversight; operational reorder service
methods now also check current Nurse permissions. MICA Doctor daily summaries use
Nurse clinical applications rather than general identity/OTP registration counts.

## Final MICA permission matrix

| Profile | Verified retrieval and assistance | Boundaries |
| --- | --- | --- |
| MIS | System health, accounts/authentication, security/backups guidance, allowlisted technical alerts | No clinical records, inventory or emergency details; no Nurse credential decisions |
| Doctor | Medical oversight, pending Nurse applications, authorized local clinical drafts, follow-ups, clinical alerts, stock/emergency summaries | No MIS administration, operational reorder writes or automatic credential approval |
| Authorized Nurse | Clinical tools, queue, inventory/requisitions, operational alerts and monitored events | Current clinical status checked; clinical actions still require the existing human workflows |
| Student | Own bounded history/queue, personal services, education, announcements and SOS guidance | Ownership resolved from authenticated user; no staff tools or other patient records |
| Teacher | Own bounded history/queue, personal services, education, announcements and SOS guidance | No implied advisory or student medical-record authority |
| Legacy admin | General navigation/chat with no data-grant inference | Clinical/technical retrieval denied until an explicit profile is assigned |
| Pending/rejected/revoked Nurse | Existing restricted account/credential portal | Protected MICA and operational routes remain denied |

The endpoint combines the existing action allowlist with current permission profiles
and clinical status. Alert lists and detail access share an explicit action-type
allowlist, including old `target_role=all` rows. Doctor alerts use clinical routes.
Caller-supplied roles cannot override alert retrieval scope. New technical scan
notifications exclude Doctors, and operational scan notifications exclude pending
Nurses. The existing scheduled engine runs via CLI; mixed-source scans are available
to authorized Nurses, not Doctor/MIS browser sessions. MIS/Doctor retain read-only
summaries. Existing direct account, inventory, emergency and review workflows retain
their own authorization and confirmation rules.

History is reset when user, profile, credential version or restriction scope changes.
Unattributed legacy history is discarded. Locally grounded answers remain visible
to their current user but are excluded from later provider conversation prompts,
preventing a subsequent general question from forwarding an earlier clinical or
applicant answer. Existing own-record provider context remains ownership-filtered.
Browser page/role metadata never grants tool permissions; queries use fixed SQL
and bound parameters. The model has no arbitrary SQL execution interface.

Shared floating MICA and hub suggestions now distinguish MIS and Doctor. Teacher
suggestions describe personal services. Suggested-question chips are keyboard
buttons; on small screens they scroll horizontally, and a shrinkable input keeps
the Send button visible. Existing voice controls and TTS configuration remain.

## Files

Modified application files:

- `api/ai_assistant.php`
- `assets/css/mica.css`, `assets/js/mica.js`, `components/mica.php`
- `includes/ai/AIService.php`, `includes/ai/ChatHistory.php`
- `includes/ai/MICAAuthorization.php`, `includes/ai/MICAActionService.php`
- `includes/ai/MICAContextService.php`, `includes/ai/MICAWorkflowService.php`
- `includes/ai/MICAHubService.php`, `includes/ai/MICAAutomationEngine.php`
- `includes/ai/PromptBuilder.php`, `pages/ai_assistant.php`

Modified tests: `tests/mis_admin_http.php`, `tests/doctor_browser.cjs`.
New tests: `tests/global_mica_checks.php`, `tests/mica_history_scope.php`.
New documents: this report and `GLOBAL_MICA_OVERSIGHT_AUDIT_20261024.md`.
Ignored scratch logs, probes and browser screenshots are verification artifacts,
not deployment files.

## Verification

- Final security/integration run:
  `php tests/mis_admin_http.php --doctor-clinical --staff-verification --global-mica`.
  **180 checks passed**, including the existing registration/review boundaries,
  clinical source failures, actual applicant projection/counts, ownership, prompt
  injection against a protected draft tool, stale clinical-status denial, direct
  API denials, profile-change history isolation, scheduled scanning and notification
  recipient restrictions. Log: `scratch/global-mica-security-final.log`.
- Browser run of the same fixture with `--doctor-browser`: **227 browser
  assertions passed** at 320/360/375/390/430/768/1024/1440 pixels. Doctor pages,
  staff review dialogs, profile metadata, suggestions, visible Send button, viewport
  fit and uncaught JS checks passed. Its 180 enclosing HTTP checks also passed;
  two later service-level denial assertions were then verified in the final HTTP
  run above. Log: `scratch/global-mica-final.log`.
- `php tests/basic_registration_http.php`: **108 passed**. Log:
  `scratch/global-mica-registration.log`.
- `php tests/mica_history_scope.php`: **7 passed**, including user/profile,
  credential/restriction changes and exclusion of local results from provider memory.
- Changed PHP files passed syntax checks, changed JS/CJS passed `node --check`,
  and `git diff --check` passed. Mobile chat screenshots were visually inspected.
- Generic text only, no patient data: OpenRouter returned a successful completion;
  ElevenLabs returned HTTP 200 and 21,776 bytes of audio. This verifies live provider
  transport, not a full microphone-to-speaker session through the authenticated UI.

Tests use schema-only disposable databases with synthetic users. During development,
a newly added audit helper used the default connection from a direct fixture call.
It was corrected to use its injected connection. Seven exact synthetic lookup audit
rows created by those earlier runs were removed by ID, timestamp and exact content;
the final check found zero such artifacts in the school database. Existing school
audit records were preserved. No school account, patient, inventory or message
records were modified by these tests. Initial regression failures were corrected
before the passing final runs reported above.

## Database, deployment and rollback

No new migration or production migration is included. Targets without the existing
Nurse clinical authorization schema still require the reviewed
`database/migration_nurse_clinical_authorization_hostinger.sql` and its documented
preflight/post-verification procedure. Missing review schema remains unavailable;
MICA does not synthesize a zero count. Preserve the separate Teacher-document
migration prerequisite described in the earlier Doctor delivery report.

Deploy first to staging: back up the current application and database and verify
restore, copy the application files above as one change, preserve environment
secrets, refresh PHP opcode caches as applicable, then run role-by-role checks.
Verify pending Nurse restriction, Doctor approval/rejection and reauthentication,
MIS clinical denial, personal history isolation, queue/QR/SOS, mobile MICA and
notification routing. Keep the scheduled scan on CLI with its existing configuration.
Do not deploy the scratch probes, fixture artifacts or synthetic credentials.

Rollback by restoring the previous application files from the staging/deployment
backup and refreshing opcode caches. No schema rollback is required for this pass.
Do not undo legitimate staff decisions, clinical records or audit history as part
of an application rollback. Chat history resets are intentional and cannot be
restored from an unattributed old session safely.

## Limits

No Hostinger/production rollout, institutional license verification, physical
microphone/camera/mobile-device test, SMTP/push delivery test or production-scale
load test was performed. Existing historical notification rows were retained; the
recipient filtering applies to newly emitted monitoring notifications. Provider
connectivity does not guarantee uptime or future quota. The existing approval UI
still requires institutional human verification, and external AI never certifies
a Nurse or executes clinical treatment.
