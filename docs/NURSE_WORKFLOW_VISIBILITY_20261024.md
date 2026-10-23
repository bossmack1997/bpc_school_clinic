# Nurse registration and Doctor review follow-up — 24 October 2026

## Repository and deployment evidence

Inspected the current worktree on `fix/reauthentication-session-response`, based on
`f955b30`, preserving the existing uncommitted reauthentication repair. Commit
`e516677` is an ancestor of this worktree and of local `origin/main`.
An authenticated-network `git ls-remote origin refs/heads/main` returned
`89111cca1fb6bde0d2c8929ea6a88a80e7ccd382`, matching local `origin/main`.
That main contains merge `89e06e0` for the clinical verification branch and
`89111cc` for the subsequent Student UI branch. The clinical feature is merged.
No production deployment, production database connection, account reassignment,
force push, record update or schema migration was performed in this follow-up.
The hosted deployment revision and the reporting user's account profile are
not available; a missing hosted menu cannot be attributed to either with certainty.

## Findings and changes

The existing Doctor-only route is `pages/doctor/nurse_verification.php`. The
sidebar already exposes Clinical Staff Verification under Clinical Supervision.
The route and service already support pending, authorized, rejected,
needs_information and revoked applications, private credentials and per-applicant
review history. The old Phase 1 route prohibition was removed in `e516677`.
There is no need for a second approval implementation.

The dashboard previously exposed review as a pending-count link. This follow-up
adds a prominent **Clinical Staff Verification** action in the Doctor dashboard
hero, visible without opening the mobile sidebar or having pending applications.
The missing-schema response previously omitted the Doctor shell entirely. It now
retains sidebar, mobile navigation, footer and an actionable explanation, while
keeping HTTP 503 and blocking all review mutations. It distinguishes technical
schema setup from clinical approval: MIS does not approve routine credentials.

Read-only local database inspection found all four clinical Nurse columns and
the review table, two explicit Doctor profiles, one MIS profile and one legacy
administrator profile. The three local Nurse accounts were email-unverified
(two active, one inactive). No completed email-verified Nurse registration was
present. These aggregate findings do not justify altering existing accounts.
A generic `co_admin` designation does not confer Doctor authority: an explicit
`admin_profiles.profile='doctor'` is required. Do not convert legacy administrators
or authorize Nurses merely to make a menu appear.

The previous session-response defect was also retained and tested: the central
profile guard returned raw `Reauthentication required.` JSON during normal
navigation, including login requests with an expired administrator session.
The pending repair provides HTML login redirects for navigation, structured API
401 responses, a shared session-expiry dialog, and CSRF-protected password
confirmation for sensitive MIS provisioning. Doctor clinical decisions retain
their existing own-password confirmation, CSRF, current application version,
transactional scope change, credential invalidation and audit logging.

## Registration and access trace

`auth/register.php` / `auth/register_hub.php` allow public Nurse registration.
`auth/verify_registration.php` uses `MICARegistrationValidator::verifyOtp` to
activate the basic account after email ownership verification and signs it in.
The restricted Nurse lands on `pages/nurse/account.php`, where credentials can
be submitted privately. Profile and settings remain available. The central
server-side scope guard blocks clinical pages, patient records, operations and
clinical APIs until an authorized Doctor approves the application.

Doctor approval requires active verified credentials, supporting documents,
appointment confirmation, review notes, own password, CSRF and a fresh application
version. It commits authorization and audit together, notifies the Nurse and
invalidates old credentials. After signing in again, existing Nurse routing
activates the operational dashboard, queue, intake/records, consultation,
inventory, QR, emergency and messaging functions within Nurse permissions.
MIS identity review cannot grant this scope. No MIS technical privileges are added.

## Actual validation

All data-writing tests use randomly named disposable databases with synthetic
accounts; no school records or real email recipients are used.

- `php tests/basic_registration_http.php`: **108 checks passed**, including
  Nurse registration, OTP, basic access restrictions and audited clinical activation.
- `php tests/mis_admin_http.php --doctor-clinical --staff-verification --reauthentication`:
  **180 checks passed**, including the new visible action and unavailable-shell
  assertions; valid/invalid/expired/reused invitations; all five role session
  flows; CSRF and password failures; authorized approval, unauthorized MIS review,
  replay prevention, rejection, information requests, revocation and history.
- PHP/JavaScript syntax scan: **300 files, zero failures**.
- `git diff --check`: passed.
- Browser validation results are recorded below after completion.

## Files changed in this follow-up

`pages/doctor/dashboard.php`, `pages/doctor/nurse_verification.php`,
`tests/mis_admin_http.php`, and this report. Existing reauthentication work remains
in `assets/js/auth-session.js`, `assets/js/mis-admin.js`, `auth/confirm_password.php`,
`auth/login.php`, `includes/auth.php`, `includes/head.php`,
`includes/permission_profiles.php`, `includes/security.php`,
`pages/admin/admin_accounts.php`, and the three reauthentication/cookie test files.

## Deployment requirements

Deploy a reviewed revision containing `e516677` and this follow-up's code/assets
as one release. Confirm the hosted Doctor account has the explicit Doctor profile
and that its dashboard URL is `/pages/doctor/dashboard.php`; the review URL is
`/pages/doctor/nurse_verification.php`. Confirm deployed PHP, asset versions and
OPcache reflect that release. Repository merge alone is not deployment evidence.

On the target database, run the read-only clinical preflight and verification
scripts. If schema is absent, back up and verify restore first, then apply the
guarded `database/migration_nurse_clinical_authorization_hostinger.sql` in an
approved maintenance window. Do not use the weaker legacy PHP migration for
production. Preserve existing review evidence and reconcile legacy grants with
the responsible Doctor; do not infer approval from an old scope flag.
Configure real SMTP and verify OTP delivery and expiry on the target environment.
Repeat hosted role, navigation, review and secure-cookie checks over HTTPS.
Local HTTP browser tests do not establish production TLS/cookie/proxy behavior.
