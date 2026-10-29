# Workflow simplification — October 29, 2026

Branch: `feat/workflow-simplification-20261029`. Changes extend the existing
PHP/PDO, Bootstrap and JavaScript implementation. Base commit: `6c219ab`.
No production data changes.

## Audit and proposed phases

1. Verify existing account creation, activation, queue policy, clinical scope,
   exports and MICA using the disposable-database HTTP suite. These implementations
   already exist; their presence alone does not establish that they work.
2. Simplify role navigation and labels. Move entry points for protected technical
   security, monitoring, history and backups into MIS Settings. Retain existing
   permission checks and bookmarked routes. Doctor gets Dashboard, Clinic Queue,
   Consultations, Patient Records, Nurse Verification, Messages and Settings;
   oversight and reporting remain available from the dashboard. Nurse gets a
   direct Patient Records link while preserving credentials and daily operations.
3. Repair Student Profile failure states. Read only the signed-in account's
   `users.id` / `students.user_id` relationship. A missing mapping must show an
   explanation in the normal layout; a database failure must show an error and
   retry path. Neither case may substitute another student's record or allow an
   edit of an unlinked profile. Separate personal, school, contact and health
   information using the existing stored fields.
4. Replace invented wait estimates. Require at least five valid completed visits
   in the last two hours today, with duration standard deviation no greater than
   75% of the mean. Return no estimate for stale, sparse or inconsistent samples,
   query failures or no waiting patients ahead. This conservative heuristic is an
   estimate, not a service guarantee. Health Pass and MICA no longer reuse stored
   historical estimates. Student, Teacher and public monitor show unavailable
   estimates honestly. Keep confirmed clinical priority separate from reported urgency.
5. Validate changes and record precise results and deployment limits here. Prepare
   a reviewable change set and pull-request description. Do not merge or deploy.

## Affected architecture

- Shared navigation: `components/sidebar.php`.
- MIS controls: `pages/admin/settings.php`, protected `security.php`,
  `system_monitoring.php`, `activity_logs.php`, `backups.php`; their APIs retain
  current authorization. No security/audit records are removed.
- Clinical navigation: existing Doctor consultation workspace and dashboard,
  Nurse patient history, medicine inventory and credential pages.
- Student profile: server-rendered `pages/student/profile.php`; writes continue
  through `api/profile.php` using session identity and CSRF. Middle name now saves;
  array-shaped input, non-Student writes and edits to unlinked profiles are denied.
- Account setup: `AccountProvisioningService`, `account_activation_invitations`,
  `account_provisioning_lock`, `users`, `students`, `teachers`, `nurses`.
- Queue: clinic availability/policy services, operational tickets, physician
  handoffs and existing clinical records; `queue_numbers` service timestamps supply
  estimates. No triage priority rules or clinical history are changed.

## Requirement coverage

| Area | Existing workflow retained and checked | Refinement |
| --- | --- | --- |
| Accounts | MIS single/CSV/XLSX preview; atomic creation; no overwrite | Create Accounts label; ready/invalid/duplicate counts and per-row status |
| Activation | Hashed 24-hour single-use token; private password; expiry/replay protection; resend | Separate sent/failed/unknown mail status counts; receipt is never claimed |
| Security | Authentication, session invalidation, grants and protected audit | Security/monitoring/history/backup entry points grouped in Settings |
| Student Profile | Session account to `students.user_id`, no caller-selected identity | Layout recovery states, four simple sections, middle-name persistence, unlinked-write rejection |
| Queue | Server schedule, holiday, capacity and duplicate checks; Nurse confirms urgency | Join Queue labels, requested closed message, evidence-based estimates |
| Doctor | Consultation authorship, clinical decisions and Nurse review | Seven requested navigation entries; oversight/reports remain on dashboard |
| Nurse | Credential restriction, intake/interventions, dispensing and physician referral | Direct Patient Records link; announcement/export tools remain on dashboard |
| Exports | Role-authorized CSV/real XLSX, selected dates, literal cells and formula protection | Existing report links remain reachable |
| Backups | Separate SQL backup, hash verification, isolated recovery only | Settings distinguishes backups from report exports |
| MICA | Role-scoped real records; no independent clinical decisions or authorization | Consistent navigation labels and removal of stale wait estimates |
| Layout | Existing Bootstrap, inline navigation icons and responsive styles | Browser checks cover changed role pages; this is not a visual certification of every legacy route |

## Database and rollback

This refinement requires no new schema. Existing deployment prerequisites are
documented in `FINAL_CLINICAL_RELEASE_20261028.md`; do not automatically replay
historical migrations. Revert the code changes to undo this refinement; retain
all accounts, patient records, clinical history and security audit data.

## Validation

Baseline: 416 synthetic HTTP checks passed before changes. Backup/security suite:
48 checks passed, including an actual isolated restore preserving Unicode, binary
data and NULL values, rejection of active/nonempty targets, checksum tampering,
and clinical-role denial. No live records were copied into either test database.

Final combined regression command (run from the repository root):

```text
C:/xampp/php/php.exe -d session.save_path=scratch tests/mis_admin_http.php --doctor-clinical --staff-verification --doctor-three-pages --global-mica --student-ui --reauthentication --provisioning --exports --workflow-simplification --final-browser --student-ui-browser
C:/xampp/php/php.exe -d session.save_path=scratch tests/mis_complete_audit.php
```

The first command requires a separate loopback-only headless Chrome debugging
instance on port 9337. Logs and screenshots stay in ignored `scratch/` directories.
Results:

- 445 HTTP checks passed in the final run without browser flags:
  `scratch/workflow-http-final-20261029.log`.
- 176 Student/Teacher/Nurse browser assertions passed at 320, 360, 375, 390, 430,
  768, 1024 and 1440 pixels, including profile, QR, messages and announcements.
- 77 additional role browser assertions passed at 320, 390, 768 and 1440 pixels,
  including MIS account setup/Settings/Activity History, Doctor dashboard/queue/
  records/verification, Nurse queue/exports and both patient queue pages.
  Both browser results are in `scratch/workflow-final-20261029.log`.
- 48 backup/security checks passed in
  `scratch/workflow-backup-verification-20261029.log`.
- All 230 application PHP files passed syntax checks; changed PHP sources and
  the new test file also passed. Browser test JavaScript parses, `git diff --check`
  passes, and the browser suites found no uncaught JavaScript exceptions.
- Mobile profile and MIS Settings screenshots were visually inspected; browser
  assertions check horizontal overflow at the listed widths.

Intermediate failures were an obsolete navigation-label assertion, an unavailable
test Chrome instance, and a new included test overwriting the outer fixture's
Settings variable. The assertion was updated, isolated Chrome started, and the new
checks enclosed in a local function scope. The complete final HTTP run passed
after that fixture correction; both browser suites had already passed and no
product changes followed. An earlier in-flight test also retained the old wait
function in PHP memory; a fresh process verified the replacement and all four
estimate cases. These are recorded test failures, not claimed product repairs.

Local synthetic checks do not prove real SMTP receipt or speed, Hostinger PHP and
MariaDB compatibility, production-scale performance, real-device layouts or a
restore of the school's full database/uploads. Those remain staging checks.
No missing production student-to-account mapping has been guessed or rewritten;
MIS must repair an actual missing relationship after checking the official ID.
No migrations, deployment, merge or production-branch push were performed.
