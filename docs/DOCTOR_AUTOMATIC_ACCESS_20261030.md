# Automatic Doctor access — October 30, 2026

## Result and scope

MIS verifies the appointment and sends the privileged invitation. The recipient
uses the single-use token and CSRF-protected form to set a private password.
Activation atomically enables the verified account, clears stale basic-account
scope, increments the credential version and consumes outstanding invitations.
The Doctor signs in and is routed to the Doctor Dashboard. Assigned clinical
permissions require no individual MIS grants or second approval.

Worked on the existing `main` branch; the initial working tree was clean. No
school records, existing credentials or role assignments were rewritten. No
production migration, deployment, commit or push was performed.

## Audit findings and changes

The existing role map already granted Doctor clinical scope automatically.
There was no additional runtime MIS approval flag to remove from that map.
Legacy `admin_permissions` is used only for legacy administrators. Retained
that distinction and documented it in code; never infer Doctor authority from
`co_admin` alone. An explicit Doctor profile is still required.

Removed duplicate Doctor authorization calls from settings/profile wrappers:
the shared `doctor_page.php` guard still authenticates and authorizes each request.
Removed the duplicate profile lookup in DoctorClinicalService: its existing
`consultations_manage` permission is exclusively assigned to Doctors.
No middleware, CSRF check, Nurse review, clinical ownership check, password
confirmation, scheduled departure or session security control was removed.

Doctor activation now clears a stale `self_service_only` flag. That flag is a
Nurse/basic-account concern, not a Doctor feature switch. Existing activated
Doctors do not need data repair: tests confirm Doctor access even with this flag
set and historical registration status `review`, provided the account remains
active, verified and assigned Doctor scope. Rejected accounts remain blocked.
Appointment verification before issuing/accepting an invitation remains required;
historical reviewer metadata is not checked again to unlock Doctor pages.

Found and closed an admin verification exception: Doctor accounts share the
database `admin` role and previously bypassed email verification at login and
session validation. The shared verification rule now includes Doctors in explicit
login, remembered login and current-session checks. MIS and legacy administrator
behavior, and Student/Teacher/Nurse verification requirements, are unchanged.
An active unverified Doctor is blocked; this never auto-activates an invalid user.

## Changed files

- `includes/permission_profiles.php`: explicit shared verification requirement and
  comments defining automatic Doctor role scope.
- `includes/auth.php`, `auth/login.php`: consistent verified Doctor authentication;
  corrected obsolete comments about manual administrator grants.
- `includes/doctor_invitation_service.php`: atomic basic-scope normalization and
  preflight validation of that existing column.
- `auth/setup_account.php`: clear post-activation sign-in instructions, with no
  additional MIS approval request. Existing signed-in identity is preserved;
  activation does not silently replace an administrator's browser session.
- `includes/doctor_clinical_service.php`, `pages/doctor/profile.php`,
  `pages/doctor/settings.php`: remove duplicate checks while retaining role guards.
- `tools/preflight_physician_queue.php`: explicit missing-migration summary;
  `--require-ready` returns exit 2 when deployment tables are absent, exit 1 for
  incompatible prerequisites, and 0 when its metadata checks pass. Existing-table
  definitions/indexes still require the manual REVIEW checks it prints.
- `tests/doctor_automatic_access_checks.php`, `tests/mis_admin_http.php`,
  `tests/README.md`: isolated automatic-access regression fixture and usage.

## Validation

Broad disposable HTTP suite: **466 checks passed, PHP exit 0**:

```text
php -d session.save_path=scratch tests/mis_admin_http.php --doctor-clinical --staff-verification --provisioning --doctor-access --student-ui --workflow-simplification --global-mica --exports --reauthentication --final-system-audit
```

Evidence: `scratch/doctor-automatic-access-20261030.log`. Includes actual MIS
creation, recipient activation, Doctor login/dashboard redirect, every Doctor
page, Nurse invitations and credential review, referral/claim/completion,
messages, MICA, reports, reauthentication and other-role regression checks.
Automatic-access tests temporarily rename the legacy grants table in the
disposable database and prove it is unnecessary. No school data is copied.

Doctor invitation/migration suite: **40 checks passed, PHP exit 0**:
`php -d session.save_path=scratch tests/doctor_invitations.php`.
Evidence: `scratch/doctor-automatic-invitations-20261030.log`. Includes migration
rerun safety, preservation, invitation expiry/replay, verification and rollback.

Final focused suite: **308 checks passed, PHP exit 0**, including immediate
session denial after verification withdrawal and role revocation:
`php -d session.save_path=scratch tests/mis_admin_http.php --doctor-clinical --staff-verification --provisioning --doctor-access`.
Evidence: `scratch/doctor-automatic-access-focused-20261030.log`.
PHP lint and git whitespace validation passed for changed files. No new browser
or real SMTP test was performed in this turn; this change does not claim physical
device, email delivery or production verification.

## Schema requirements and release

No new migration is required for these access changes. The existing role mapping
and account columns are reused; no ordinary request executes DDL.

Read-only local readiness check:

```text
php -d session.save_path=scratch tools/preflight_physician_queue.php --require-ready
```

Actual local result: **exit 2**. `physician_queue`,
`account_activation_invitations` and `account_provisioning_lock` are absent.
This prevents claiming the configured local queue is operational. It is an
infrastructure dependency, not a missing Doctor permission or approval.

Follow `docs/DOCTOR_MODULE_REPAIR_20261030.md` and
`docs/FINAL_CLINICAL_RELEASE_20261028.md`: verify backup restoration, confirm target,
review existing prerequisites, apply `database/migration_physician_queue_20261028.sql`
and `database/migration_account_provisioning_20261028.sql` where missing in staging,
then execute their `verify_*.sql` checks and clinical smoke tests. Obtain explicit
production deployment authorization. Do not blindly replay unrelated migrations.
Doctors never run these deployment steps themselves.

Rollback application files together to the prior revision. Retain all activation,
clinical and audit data and populated additive tables. An old application revision
may restore its prior unverified-admin exception; review this security implication.
Schema rollback requires a separately authorized, verified recovery plan rather
than dropping populated clinical tables.
