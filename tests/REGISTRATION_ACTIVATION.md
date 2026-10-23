# Registration and activation validation

Historical policy report. The newer basic-account flow and deployment instructions are in [BASIC_OTP_ACTIVATION.md](BASIC_OTP_ACTIVATION.md); institutional matching no longer gates Student/Teacher sign-in.

The verification route previously committed email ownership before starting the activation transaction. A failure during eligibility/audit/QR processing could therefore leave an email-verified account pending. Login rejected that account without reevaluating trusted eligibility. Missing or mismatched roster records also legitimately keep accounts pending; email ownership alone never establishes enrollment or employment.

OTP verification, eligibility evaluation, activation and the activation audit now share one transaction. A failed activation rolls back OTP consumption as well. Successful password authentication individually reevaluates existing verified pending accounts. Suspended/rejected accounts cannot be restored by this path. An active-but-unverified public account is individually restricted and directed to OTP. Existing restricted administrator provisioning remains separate.

Automatic activation still requires the existing trusted institutional roster: unique identifier ownership, matching role, name and email, eligible roster status, no high-risk/rejected assessment, and a matching nursing license for nurses. No bulk activation, schema migration or new database status values are introduced. Existing deployments need their account-monitoring schema and accurate authorized roster data.

People Management distinguishes Email Verification Pending, Eligibility Verification Required, Active, Suspended and Rejected; adds corresponding filters; and shows escaped account verification and authentication history. Clinical activity is excluded from the directory history. Authorized suspension/restoration and exceptional eligibility reviews remain in Security Center.

## Modified application files

- `auth/register.php`: remove outdated routine administrator-approval wording from the teacher registration audit.
- `auth/verify_registration.php`: use the atomic verification result and report rolled-back verification failures safely.
- `auth/login.php`: individual eligibility reconciliation and accurate blocked-state messages.
- `includes/ai/MICARegistrationValidator.php`: atomic activation, consumed challenge cleanup and no resend after email verification.
- `includes/account_monitoring.php`: transaction composition, individual login reconciliation, idempotent audit, rejected-assessment preservation and status labels/filters.
- `includes/auth.php`: prevent unverified public accounts obtaining sessions or remember-me access.
- `pages/admin/users.php`: status labels, filters and account-only audit history.

## Reproducible tests

Run from the repository root with PHP CLI and a configured local database containing the current schema:

```powershell
php tests/registration_activation.php
php tests/registration_people.php
foreach ($case in @('eligible','unverified','untrusted','suspended','rejected','bad_password','unverified_active')) {
    php tests/registration_login.php $case
    if ($LASTEXITCODE -ne 0) { throw "Login regression failed: $case" }
}
```

Each test creates connection-local temporary tables cloned from the existing schema, shadows the relevant real tables, and uses synthetic identities. The tables disappear when the process exits. No real account is modified or activated. These files refuse HTTP execution.

Verified locally:

- 54 activation checks, including student/teacher/nurse trusted eligibility, untrusted staff restrictions, OTP expiry/retries/replay, individual pending-account recovery, state filters/statistics and full rollback after injected audit failure.
- Seven cases using the actual login controller.
- People Management history rendering, escaping and exclusion of clinical activity.
- Existing local `scratch/test_people_monitoring.php`: 51 checks passed, including registration-payload validation, authorized suspension/restoration, administrator protections, audit rollback and mail-failure/cooldown handling.
- Existing directory endpoint checks: authorized GET, rejected mutation, malformed filter and denied delegated access passed.
- PHP lint passed for all ten changed/new PHP files; `git diff --check` passed.

OTP mail dispatch was checked with an isolated injected transport. Actual SMTP acceptance and inbox delivery, real browser registration submission, and Hostinger production behavior were not verified. The code tests do not prove those external steps. No GitHub deployment workflow was found in this checkout; a Git push alone is not proof of Hostinger deployment.
