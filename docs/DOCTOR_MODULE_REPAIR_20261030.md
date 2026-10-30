# Doctor module audit and repairs — October 30, 2026

## Scope and baseline

Worked on `feat/doctor-mis-workflows-20261029`. The initial working tree was clean.
No source AGENTS.md was found. Existing PHP/PDO services, shared components,
role profiles, invitations, MICA and review workflows were retained.
No school records, account credentials or existing review history were modified.
No production migration, deployment, commit or remote push was performed.

## Findings and changes

1. **Queue infrastructure:** the read-only local preflight confirms that
   `bpc_clinic_system` lacks `physician_queue`, `account_activation_invitations`
   and `account_provisioning_lock`. The existing physician migration supplies
   the missing table, indexes and restrictive foreign keys. No new migration is
   needed. This is local XAMPP evidence; production was not inspected. Ordinary
   clinical requests correctly do not create tables. Until the reviewed migration
   is applied, the local physician queue remains unavailable.
2. **Queue error diagnosis:** the page and API previously attributed every PDO
   failure to an uninstalled migration. Updated `pages/doctor/queue.php` and
   `api/physician_queue.php` to give neutral service-unavailable guidance. The
   page offers a GET reload, avoiding automatic replay of a clinical POST.
   Failures remain HTTP 503 and cannot look like a zero-patient queue.
3. **Messaging failures disguised as empty results:** PDOException inherits
   RuntimeException. `DoctorMessagingService::threads()` caught membership query
   errors as denied conversations, filtering them out. Rethrow PDO exceptions
   before filtering actual authorization failures. The message page now catches
   load failures, returns 503 with a reload action, suppresses empty states and
   the composer, and logs only sanitized diagnostics. Previously message-history
   errors could also expose exception text; that path is now handled separately.
4. **Physician messaging usability:** retained existing conversations and forms;
   added authorized contact names, latest-message previews, real unread counts,
   contact/conversation search, a mobile conversation-list view and back link.
   The active mobile view hides the list and keeps its composer accessible.
   A 30-second refresh receives updates while idle; it pauses for an unfinished
   reply, selected recipient, search, active input or hidden tab. This is polling,
   not a new realtime transport. Existing server authorization and CSRF remain.
5. **Existing functional coverage:** activation already grants the verified Doctor
   profile without another MIS approval. Nurse authorization remains a distinct
   Doctor review with password confirmation, eligibility checks, immutable events
   and session invalidation. Dashboard values already use independent database
   sources with unavailable states. Consultations and patient history retain
   authorship/patient/version boundaries. MIS clinical access stays denied.

Modified application files: `api/physician_queue.php`,
`includes/doctor_messaging_service.php`, `pages/doctor/queue.php`,
`pages/doctor_messages.php`. Regression coverage was added in
`tests/staff_verification_checks.php`.

## Database deployment and verification

Deployment requires explicit authorization. Do not run these steps as clinical
requests, and do not ask a Doctor to administer the database.

1. Back up application files, private uploads and database outside the web root;
   verify restoration in an isolated environment. Confirm the intended database.
2. Run the read-only CLI check:
   `php -d session.save_path=<private-writable-path> tools/preflight_physician_queue.php`.
   Its ADD output identifies unapplied migrations; a zero exit alone does not mean
   every table is installed. Inspect every ADD/BLOCK/REVIEW result.
3. Follow `docs/FINAL_CLINICAL_RELEASE_20261028.md` for prerequisite ordering,
   including existing consultation, Doctor invitation, Nurse clinical authorization
   and credential-correction migrations. Do not replay unrelated legacy migrations.
4. In the explicitly selected staging database apply
   `database/migration_physician_queue_20261028.sql` and, where missing,
   `database/migration_account_provisioning_20261028.sql`. Review the existing
   queue-policy migration as described in the release document. These changes
   must be tested in staging before authorized production application.
5. Run `database/verify_physician_queue_20261028.sql` and
   `database/verify_account_provisioning_20261028.sql`. Compare table definitions,
   signed key types, unique indexes and constraints. Orphan, inconsistent-state
   and duplicate-ticket checks must return no rows. IF NOT EXISTS does not repair
   an incompatible existing table.
6. Smoke-test verified Doctor activation/login, Nurse referral, physician claim
   and completion, linked consultation, unchanged nursing ticket ownership,
   credential review/revocation, messaging and MICA role boundaries.

Rollback: stop affected writes and restore the previous application version.
Keep additive tables and all populated handoffs/invitations/reviews intact;
never drop populated tables as a code rollback. If schema rollback is necessary,
use the separately verified full backup/restore plan during a maintenance window
and reconcile any records written since the backup before reopening access.

## Verification and limits

All database-dependent regressions use schema-only disposable local databases,
synthetic accounts and records, blocked outbound mail and loopback HTTP servers.
Their temporary databases and fixture documents are cleaned by the suite.

No actual SMTP delivery, production schema, live MICA model provider, real camera,
or physical mobile keyboard was verified. Browser emulation is not a physical
device test. Clinical policy and final clinical decisions remain human duties.
Screenshots referenced in the request were not attached; generated browser
screenshots provide current local evidence.

Final results:

- Broad HTTP suite: **437 checks passed, PHP exit 0**. Command:
  `php -d session.save_path=scratch tests/mis_admin_http.php --doctor-clinical --staff-verification --provisioning --student-ui --workflow-simplification --global-mica --exports --reauthentication --final-system-audit`.
  Evidence: `scratch/doctor-repair-final-20261030.log`.
- Chrome suite: **218 HTTP checks passed**, including successful Doctor browser
  workflows and staff-review browser checks. Command:
  `php -d session.save_path=scratch tests/mis_admin_http.php --doctor-clinical --staff-verification --doctor-browser`.
  Evidence: `scratch/doctor-repair-browser-20261030.log` and screenshots in
  `scratch/doctor-browser/`. Tested 320, 360, 375, 390, 430, 768, 1024 and 1440 px;
  overflow, sidebar, loader, MICA, patient draft and review-modal checks passed
  with no uncaught JavaScript exceptions. Visually inspected 320 and 1440 px
  dashboard screenshots. Browser messaging coverage visits the conversation-list
  state; active chat/back behavior and physical keyboard handling still need
  dedicated interactive browser testing. Active conversations and previews were
  verified over HTTP and service tests.
- PHP lint passed for all Doctor page files and changed PHP files; git diff
  whitespace check passed.
- The initial broad run stopped at a fixture prerequisite because `--student-ui`
  was omitted; corrected full execution above passed. A subsequent intermediate
  run passed 435 checks; the final run includes two additional preview/UI checks.
- PowerShell's merged native stderr produced a nonzero wrapper result on the
  browser command despite its successful test summary. Expected sanitized
  database-failure diagnostics come from deliberately removed fixture tables.
  No browser FAIL result was reported; do not equate the wrapper result with an
  independently captured PHP browser-process exit code.

Remaining release gates: apply reviewed schema migrations with explicit staging
and production authorization; verify controlled SMTP delivery, active chat on
physical mobile devices and production smoke tests. The missing local handoff
table prevents claiming that the configured school database queue is repaired
and operational today.
