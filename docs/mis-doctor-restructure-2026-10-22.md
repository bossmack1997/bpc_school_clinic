# MIS and Doctor co-administration restructuring — 22 October 2026

## Impact assessment

The existing four database roles remain intact. Administrative specialization is additive through `admin_profiles.profile`: `mis`, `doctor`, or `legacy`. This avoids rewriting user IDs or relationships. The affected surfaces are authentication/session refresh, permission enforcement, MIS/Doctor dashboards and navigation, Administrator Management, clinical services, MICA authorization, and additive schema objects. Primary risks were privilege crossover, public privileged registration, password disclosure, stale sessions, self-escalation, and accidental clinical disclosure to MIS.

Before the work, the project already contained `backup_pre_mis_doctor_workflows_20261022.zip` and `database/backup_before_admin_refactor_20261022.sql`. They were preserved. No table was dropped or truncated and no existing account, medical record, message, inventory transaction, SOS event, or audit row was deleted.

## Issues found and resolution

- The legacy `admin` role covered both technical and clinical concepts. `admin_profiles` now supplies the functional profile while retaining login compatibility.
- The generic Administrator form let MIS choose another administrator's password and described the account as having full privileges. It now provisions only a Doctor / Clinical Co-Administrator invitation. MIS never receives the Doctor's password.
- `auth/setup_account.php` was missing/empty. It now validates hashed, expiring, single-use invitations, requires an approved appointment, applies the shared password policy, and activates the account transactionally.
- `current_user()` refreshed only a subset of user columns and could lose identity fields from the session. The refresh query now includes every field used to rebuild the session.
- Admin dashboard routing did not distinguish a Doctor stored under the legacy `admin` category. `dashboard_url_for()` now routes a signed-in Doctor profile to the clinical dashboard.
- Doctor navigation was grouped as one oversized section and omitted MICA. It now follows Clinical Overview, Patient Care, Clinical Supervision, Communication, and Account groups.
- Public registration normalization already excludes `admin`; forged public Administrator submissions remain invalid.

## Database migration

`database/migration_doctor_invitations.sql` adds only:

- `admin_invitations`, holding SHA-256 token hashes, expiry/use timestamps, creator, and honest delivery status.
- `admin_profiles.official_designation`, added with `IF NOT EXISTS`.

The idempotent CLI runner is `setup/migrate_doctor_invitations.php`. It was run successfully twice to verify repeatability. Existing records were not rewritten.

## Permission matrix

| Capability | MIS | Doctor | Nurse | Student | Teacher |
|---|---:|---:|---:|---:|---:|
| User verification and account administration | Yes | No | No | No | No |
| Administrator appointments and succession | Yes | No | No | No | No |
| Security policies, audit, backup, integrations | Yes | No | No | No | No |
| Authorized patient records and consultations | No | Yes | Nursing scope | Own | Own/authorized |
| Clinical findings and treatment instructions | No | Yes | No | No | No |
| Medicine oversight | No | Yes | Operational management | No | No |
| SOS oversight/response | Technical status only | Clinical oversight | Operational response | Submit | Submit |
| MICA scope | Technical facts only | Authorized clinical facts | Operations | Personal/general | Personal/general |

Backend permission checks, not prompt text or hidden navigation, enforce these boundaries.

## Final navigation

MIS: Administration (Dashboard, People Management, Administrator Management); System Operations (System Monitoring, Security Center, Activity & Audit Logs, Backup & Recovery); Configuration (System Settings); Account (My Profile, Logout).

Doctor: Clinical Overview (Dashboard, Clinic Monitoring); Patient Care (Patient Records, Consultations); Clinical Supervision (Medicine Oversight, Emergency Oversight, Clinical Reports); Communication (Messages, MICA AI); Account (My Profile, Settings, Logout).

## Doctor invitation workflow

MIS records identity, official email/username/contact, professional designation/license, appointment reference, and verification status. The transaction creates a pending `admin/co_admin` account, assigns only the `doctor` profile, stores an invitation hash, and records a non-clinical audit event. Email delivery is attempted after commit. If delivery is unavailable, the interface reports that state and shows the one-time URL only to the authorized MIS session. A verified appointee uses the URL to set their own password. Activation locks the invitation and user rows, rechecks expiry/use/profile/appointment approval, hashes the password, activates the account, invalidates other invitations, and audits the activation without logging password contents.

## Succession

`AdminProfileService` requires a currently active Primary MIS account, password reauthentication, another eligible target, and an authority reference. Doctor departure revokes the profile at the approved time while records remain attached to the original clinician. MIS succession requires a distinct replacement and does not promote the Doctor automatically. `setup/run_admin_departures.php` applies authorized due departures; history is retained.

## Verification performed

- Additive invitation migration: passed; repeat run passed.
- Application PHP syntax: 202 files inspected; no syntax errors reported.
- Administrator page rendering after migration: HTTP 200 for Primary MIS.
- Co-admin boundaries: allowed reports with grant (200), denied without grant (403), denied clinical records despite report grant (403), denied Administrator Management despite user grant (403), allowed assigned settings (200).
- Student, Teacher, and Nurse dashboards: rendered HTTP 200.
- Student, Teacher, and Nurse messaging: rendered HTTP 200.
- People/account monitoring suite: 51/51 passed, including no public admin provisioning, duplicate prevention, self/primary protection, session invalidation, and audit rollback.
- Nurse dashboard, queue/consultation, MICA hub/API, messaging threads, global SOS, and PWA worker suites: passed.
- Invitation invalid-token page: rendered the controlled invalid-invitation state without a fatal error.

Not verified: real mailbox delivery, browser-driven password activation with a live appointee, responsive visual QA on physical devices, QR camera hardware, installed-PWA prompts, external model responses, push delivery, or a real leadership succession. Those actions require external services, hardware, or would alter real privileged accounts.

## Files added or directly refined in the final stabilization pass

- `includes/auth.php`
- `pages/admin/admin_accounts.php`
- `components/sidebar.php`
- `includes/doctor_clinical_service.php`
- `auth/setup_account.php`
- `database/migration_doctor_invitations.sql`
- `setup/migrate_doctor_invitations.php`
- this report

The worktree also contains the broader pre-existing restructuring across Doctor pages, permission/profile services, dashboards, APIs, MICA authorization, monitoring, backup, and regression fixtures. Those user-owned changes were preserved.

## Rollback

1. Put the application in maintenance mode and retain a new database backup before rollback.
2. Restore only reviewed application paths from `backup_pre_mis_doctor_workflows_20261022.zip`; do not use a blanket Git reset because the worktree contains unrelated changes.
3. Restore the database from `database/backup_before_admin_refactor_20261022.sql` only if a full data rollback is explicitly required. This is destructive to later legitimate data, so prefer a targeted schema rollback.
4. Targeted schema rollback, after confirming no pending invitations are needed: archive `admin_invitations`, then drop that table; optionally drop `admin_profiles.official_designation`. Do not drop `admin_profiles` or clinical columns.
5. Re-run syntax, access-control, people, consultation, MICA, SOS, messaging, and PWA tests.

