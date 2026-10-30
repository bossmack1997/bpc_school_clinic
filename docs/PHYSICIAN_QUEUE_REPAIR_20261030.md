# Physician queue backend repair — October 30, 2026

## Confirmed cause

The configured local XAMPP database `bpc_clinic_system` connects successfully to
MariaDB 10.4.32. Read-only dependency queries confirm `physician_queue` is absent:
PDOException, SQLSTATE **42S02**, driver **1146** (table not found). Ticket, intake
and consultation column probes succeed. This is a deployment schema omission,
not another Doctor approval or feature permission requirement. Hostinger was not
connected or inspected; its actual schema must be confirmed with the supplied SQL.

`DoctorClinicalService::commandCenter()` also queries physician handoffs, so its
queue metrics depend on this same table. Assessment completion reuses its
transaction-aware `save()` method and existing consultations. No replacement
patient tables or fabricated queue data are needed.

The shared authentication guard runs first. For an authorized activated Doctor,
`GET api/physician_queue.php?action=list` calls `PhysicianQueueService::rows()`:
fresh actor authorization, then a join of physician handoffs, operational tickets,
patients and latest nursing intake. The missing handoff table raises PDOException.
The API returns HTTP 503; the page returns HTTP 503 HTML with a retry link.

Reproduced real authenticated HTTP in a schema-only disposable database:

```json
{"success":false,"message":"Physician queue setup is incomplete. System support must verify the deployed database schema. No clinical operation was confirmed.","error_code":"PHYSICIAN_SCHEMA_UNAVAILABLE"}
```

After applying the guarded migration in that same isolated database, the same
Doctor receives HTTP 200:

```json
{"success":true,"message":"","data":[]}
```

Unauthenticated requests return 401, MIS requests return 403. Missing-column
failures also return 503. No empty `data` array is returned for query failures.
Evidence: `scratch/physician-queue-http-evidence-20261030.json`.

## Backend and UI changes

- `api/physician_queue.php`: machine-readable schema/storage failure codes and
  malformed-operation HTTP 400. No raw exception, SQL or patient data in errors.
- `pages/doctor/queue.php`: guarded reload after validation/conflict errors;
  distinguish infrastructure failures, validation (422) and conflicts (409).
  Shared compact cards, readable statuses, expandable intake/vitals, GET retry
  and loading feedback. Empty state is emitted only for a successful zero-row
  result. Keep the named action button in POST data while showing busy feedback.
- `tools/diagnose_physician_queue.php`: CLI-only, read-only connection and column
  probes, output limited to dependency state and SQLSTATE/driver codes.
- `tools/preflight_physician_queue.php`: complete queue query/write dependencies,
  handoff key types/lifecycle values, InnoDB, unique links, priority index and five
  restrictive foreign keys. Missing tables now produce exit 2 rather than success.
  `--queue-only` isolates physician readiness from unrelated account provisioning.
- Three SQL files listed below: guarded additive installation and metadata/data
  verification without stored routines or privileged DEFINER declarations.
- `tests/physician_queue_schema_checks.php`, `tests/doctor_clinical_checks.php`,
  `tests/physician_queue_checks.php`, `tests/doctor_browser.cjs`, `tests/README.md`:
  schema, HTTP, preservation, priority, clinical lifecycle and responsive coverage.

No change to Doctor role assignment, login approval, Nurse credential eligibility,
CSRF protection or clinical authorship. Existing services retain the workflow:
saved Nurse intake -> Nurse referral -> Doctor claim -> documented decision and
consultation -> Nurse operational follow-up. Physician completion does not close
or take ownership of the nursing ticket. A stale or duplicate assessment claim
fails; only the assigned physician may complete it. Existing records are retained.

## Exact Hostinger SQL and prerequisites

Select the intended application database in phpMyAdmin first. Do not guess the
production database name from a local configuration or an old deployment note.

Run in order:

1. `database/preflight_physician_queue_hostinger_20261030.sql`
2. `database/migration_physician_queue_hostinger_20261030.sql`
3. `database/verify_physician_queue_hostinger_20261030.sql`

The migration creates the same handoff definition as the original reviewed
`database/migration_physician_queue_20261028.sql`; use the new guarded file for this
deployment, not both. It does not change account permissions, clinical records or
existing tables. It can be rerun without altering populated handoffs.

Exact new table:

- Signed INT auto-increment `id`; required signed INT `queue_id`, `patient_user_id`,
  `referred_by`; nullable signed INT `assigned_doctor_id`, `consultation_id`.
- `priority ENUM('normal','urgent','emergency') DEFAULT 'normal'`;
  `status ENUM('awaiting','in_progress','completed') DEFAULT 'awaiting'`.
- Required `reason VARCHAR(1000)`, `record_version INT DEFAULT 0`,
  `created_at DATETIME DEFAULT CURRENT_TIMESTAMP`; nullable DATETIME `started_at`,
  `completed_at`.
- Unique ticket and consultation links; index `(status,priority,created_at)`.
- Five `ON DELETE RESTRICT` references to `queue_numbers.id`, `users.id` (patient,
  referring Nurse and assigned Doctor), and `consultations.id`.
- InnoDB, utf8mb4, utf8mb4_unicode_ci.

The preflight SQL lists every required column in users, admin_profiles, nurses,
students, teachers, queue_numbers, walk_in_visits, consultations and activity_logs.
It checks base-table InnoDB engines and signed INT parent primary keys. If upstream
columns are missing or parent keys are incompatible, `prerequisites_ready=0` and
the migration executes only a BLOCKED result. Stop and review the relevant
existing migration rather than altering a production key or inventing patient data.

`CREATE TABLE IF NOT EXISTS` deliberately does not repair an incompatible existing
table. If a handoff table already exists, compare SHOW CREATE TABLE with the exact
definition and resolve any preflight/verification issues in staging. Do not drop
it or blindly recreate it. Post-verification shows indexes and foreign keys and
checks orphan links, invalid lifecycle states and duplicate ticket/encounter links.
Inconsistency/duplicate queries must return no rows.

## Deployment steps and rollback

1. Obtain authorization for the selected environment. Back up files and database
   outside the web root; verify restoration into an isolated database.
2. Reproduce the target schema in staging; execute the three SQL files in order.
   Confirm selected_database, prerequisites_ready=1, definitions, constraints and
   empty inconsistency results. Check database user's SELECT/INSERT/UPDATE rights
   for runtime and CREATE/REFERENCES rights for the authorized migration operator.
3. Run the CLI check where SSH is available:
   `php -d session.save_path=<private-writable-directory> tools/preflight_physician_queue.php --queue-only`.
   Require exit 0; exit 1 means incompatible metadata, exit 2 means missing table.
4. Upload the reviewed application changes together, retaining `.env`, private
   uploads and existing data. Keep tools/tests/scratch unavailable over HTTP.
5. With controlled test accounts, verify Nurse referral, correct Doctor queue
   ordering, claim, consultation completion, nursing follow-up and audit events.
   Verify authenticated list JSON and missing-session/role denials. Repeat the
   authorized deployment on production only after staging passes.

Rollback code to the previous version together during a controlled maintenance
window; preserve `physician_queue` and its populated referrals/consultation links.
No destructive rollback SQL is supplied. Never drop the handoff table after
clinical activity. A schema restore requires the verified backup, explicit
authorization and reconciliation of all clinical records written since backup.
MySQL DDL commits independently of application transactions; a SQL ROLLBACK does
not undo CREATE TABLE. A blocked prerequisite check performs no schema writes.

## Test results and limits

Initial relevant suite: **293 HTTP checks passed, PHP exit 0**:
`php -d session.save_path=scratch tests/mis_admin_http.php --doctor-clinical --staff-verification --provisioning`.
Evidence: `scratch/physician-queue-repair-20261030.log`.
Includes guarded migration refusal, two successful runs, preservation of populated
referrals/versions, schema preflight exit codes, 503-to-200 HTTP transition,
authorization/CSRF, emergency-before-urgent ordering, actual referral status,
ownership/concurrency, atomic completion, historical records and Nurse follow-up.

Final broad suite: **453 checks passed, PHP exit 0**:
`php -d session.save_path=scratch tests/mis_admin_http.php --doctor-clinical --staff-verification --provisioning --student-ui --workflow-simplification --global-mica --exports --reauthentication --final-system-audit`.
Evidence: `scratch/physician-queue-full-regression-20261030.log`.

Doctor Chrome checks passed at 320, 360, 375, 390, 430, 768, 1024 and 1440 px,
including real referral status, claim action, expanded intake, overflow, shared
navigation, MICA and absence of uncaught JavaScript exceptions. Queue screenshots:
`scratch/doctor-browser/queue-<width>.png`; visually inspected 320 and 1440 px.
Evidence: `scratch/physician-queue-browser-20261030.log`.
The combined browser/HTTP run finished with **234 checks passed, PHP exit 0**,
including the shared Nurse review modal checks.

PHP syntax checks passed for all changed/new PHP files; Node syntax and git diff
whitespace checks passed.

Validation uses disposable local schema copies and synthetic records; no school
records are copied into them. SQL was executed on local MariaDB 10.4.32. Direct
Hostinger/MySQL 8/phpMyAdmin execution and production permissions were not tested.
The SQL uses ordinary MySQL/MariaDB CREATE TABLE, metadata SELECT and PREPARE
syntax. Physical mobile keyboard, production SMTP and external AI delivery were
not tested. No production migration, school data change, deployment or push was
performed. The working tree was initially clean on `main`; no AGENTS.md was found
in the source search. Until authorized deployment of the missing table, the
configured local school queue remains unavailable and reports that truthfully.
