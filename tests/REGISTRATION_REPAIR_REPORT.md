# Registration failure and password-control repair

Validated locally on 23 October 2026. No production accounts, medical records or production schema were changed.

## Reproduced failure and deployment repair

The registration INSERT requires `users.self_service_only`; OTP acceptance also requires `users.email_verified_at`. Against a disposable schema with the earlier migration absent, a valid Student registration produced the exact reported generic error. The local PHP log identified SQLSTATE 42S22 / MySQL 1054: unknown column `self_service_only` in the field list. No account was committed.

The existing SQL migration is MariaDB-style (`ADD COLUMN IF NOT EXISTS`). A portable, CLI-only PHP migration now checks actual columns and adds only the missing definitions, supporting MySQL and MariaDB without removing data or changing account statuses. Repeated execution is safe. The local database was already current; the migration repair was tested against a disposable older schema. Public registration does not run schema-changing statements or bypass the personal-services permission flag.

Deploy the code and run this through the authorized Hostinger terminal/deployment process from the project directory:

```sh
php database/migration_basic_otp_activation.php
php tools/diagnose_registration.php
```

The migration needs ALTER permission. It adds `email_verified_at DATETIME NULL` and `self_service_only TINYINT(1) NOT NULL DEFAULT 0` only if absent. Existing role scope and status are retained. No account is bulk-activated. Keep a normal deployment backup. If the schema is incomplete, registration now returns an accurate HTTP 503 update message and records the required migration in a safe server log.

Hostinger logs and private schema were not accessible. The reproduced failure explains a confirmed code path; it does not establish that the same missing column is the live server's current cause. Run the diagnostic on that server to confirm.

## Backend changes

- Validated HTML form submissions still create pending accounts through prepared statements and a transaction, hash the password, generate a cryptographically random six-digit OTP, store its hash and expiry, then use the existing email service after commit.
- A retry with the same username, email, role and correct saved password resumes the existing unverified pending account. It rotates the session ID and never changes role, verification, account status or clinical access. Resend remains CSRF-protected with its cooldown and retry/state limits.
- Mail failure or a throwing mail transport cannot falsely report that a committed account was rolled back. The pending account remains available for resend. The OTP page now actually renders the saved mail-failure notice; previously it never consumed that flash message.
- AJAX requests receive JSON success/redirect or safe validation/CSRF/server errors with appropriate status codes. The existing browser form remains an ordinary POST; no second frontend submit listener was introduced.
- Password confirmation is required server-side. Duplicate messages identify username/email accurately, including letter-case variants. Malformed scalar fields are rejected before rendering or string operations. Technical logs omit raw SQL parameters, email addresses, passwords and OTPs.
- Student/Teacher OTP activation, Nurse staff provisioning, public privileged-role exclusion, restricted-account checks and patient record authorization remain intact.

## Password controls and document privacy

The shared narrow-screen `.btn { flex: 1 1 auto }` allowed the visibility button to consume excess horizontal space. Scoped `#unifiedRegForm` rules now give each toggle a fixed 44px touch target and 19px icon, allow the input to grow, remove the inner button border and align the lock prefix, input and toggle. Both fields retain their values, accessible labels, pressed state and focus behavior when toggled. Confirm-password labels now distinguish that field correctly. No global button rules changed.

All existing fields and the optional Photo & Document section remain. New identity documents are stored beneath the existing HTTP-denied `storage/` tree, with a dedicated denial file. The legacy ID directory also denies public access; records and files were not deleted or moved. Direct identity-document URLs are intentionally unavailable; any future document viewer must use an authorized server endpoint. Image/document suffixes are selected from verified MIME types rather than user-supplied filename extensions. Profile photographs remain ordinary profile images.

## Validation results

- `php tests/basic_registration_http.php --legacy --browser`: 73 HTTP/integration checks passed, plus 41 actual Chrome assertions. Covers missing-schema failure, migration and idempotence, valid Student/Teacher/Nurse requests, password and OTP hashing, mail failure, resume without duplicates, invalid/missing fields, duplicate username/email, invalid CSRF, AJAX JSON, resend cooldown and replacement, correct/incorrect/expired OTP, activation timestamps and challenge consumption, login/dashboard routing, restricted staff provisioning and other-patient record protection.
- Chrome widths: 320, 360, 375, 390, 430, 768, 1024 and 1440px. Both eye controls measured 44px with 19px icons; toggling twice preserved values and focus, labels updated correctly, no page overflow, and upload fields remained optional. Reduced height (390 x 460) could scroll to the final certification control.
- `php tests/registration_activation.php`: 61 checks passed, including five-attempt OTP limits, suspended/rejected restrictions and rollback.
- `php tests/registration_login.php`: eligible, untrusted, suspended, rejected, unverified, unverified_active, otp_dashboard and bad_password all passed.
- PHP lint passed for all seven changed/new PHP files. Node syntax checks passed for the registration inline script and Chrome test script. Git whitespace checks passed.
- Local Apache direct requests to both private and legacy identity-document locations returned HTTP 403.

All account/mail fixtures used a disposable schema and `example.invalid` addresses; HTTP tests used a closed loopback SMTP port. Actual SMTP inbox receipt, physical Android/iOS keyboards and assistive technology were not verified. A reduced browser viewport is not a hardware keyboard test. The existing registration/login/forgot-password routes were retained, and password-reset code was not changed.

## Changed files

`auth/register.php`, `auth/verify_registration.php`, `includes/ai/MICARegistrationValidator.php`, `includes/mail.php`, `includes/registration_schema.php`, `database/migration_basic_otp_activation.php`, `assets/css/registration.css`, `assets/images/student_ids/.htaccess`, `storage/registration_documents/.htaccess`, `tests/basic_registration_http.php`, `tests/registration_browser.cjs`, this report.

## Production status

Git publication is separate from deployment. hPanel access, deployment configuration and production database migration execution remain unavailable. Do not treat a push as proof that registration is fixed live. After deploying, confirm the schema through the CLI diagnostic, then use an authorized synthetic account to verify real mail delivery, OTP activation and role-specific login. No production registration was submitted during this work.
