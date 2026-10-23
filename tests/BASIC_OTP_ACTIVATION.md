# Basic registration and OTP activation

This policy supersedes the institutional-matching activation requirements in the older registration reports.

Student and Teacher registration now follows: registration → email OTP → automatic basic activation and sign-in. No roster match or routine administrator approval is required. Correct OTP atomically records `email_verified_at`, consumes the challenge and activates the basic account. The real verification endpoint creates a fresh session and redirects to the correct dashboard. Incorrect/expired codes, exhausted retries, rejected assessments and suspended/rejected/disabled statuses remain blocked.

New public registrations and individually reconciled pending accounts use `self_service_only=1`. Basic Teachers cannot claim advisory classes, enumerate students, obtain advisory AI context or open other patients' workspaces. Student records remain scoped to the authenticated owner. Existing authorized staff accounts keep their prior scope; this release does not bulk change existing account permissions.

Nurse registration is a restricted staff request. Email ownership is verified automatically, but OTP never grants clinical access. Clinical provisioning stays in the existing permission-checked, evidence-backed, audited Security Center workflow. Public registration cannot obtain Admin, Doctor or Co-Admin roles.

Existing verified pending Student/Teacher accounts are reconciled individually only after their password is authenticated. This does not fabricate historical verification timestamps, reactivate blocked accounts or bulk activate production users.

Medical attachments now download through `api/medical_records.php?action=file&id=...`, with owner/authorized-clinic checks, path confinement, attachment disposition and no-store headers. `.htaccess` blocks direct URLs under `uploads/medical_records`. Lists, upload responses and MICA file links use the authorized endpoint.

## Deployment order

1. Back up the production schema through the normal authorized process.
2. Run `php tools/migrate_basic_otp.php --apply` on the intended server (on Hostinger CLI, enforce production configuration with `APP_FORCE_PRODUCTION=1`). Alternatively apply `database/migration_basic_otp_activation.sql` where its MariaDB syntax is supported.
3. Deploy the matching PHP and `.htaccess` files together. The migration adds nullable `email_verified_at` and `self_service_only` with legacy default 0; it performs no account activation, timestamp backfill, roster import or permission updates.
4. Verify private-file rewrite enforcement in Apache/LiteSpeed, test a synthetic Student/Teacher signup and confirm staff provisioning remains restricted. Reconcile each existing pending basic account through password-authenticated sign-in.

The migration was run locally only. No Hostinger database or production account was modified. Git publication is not deployment verification. hPanel deployment and production migration remain unverified because server access is unavailable.

## Tests

```powershell
php tests/registration_activation.php
php tests/registration_people.php
php tests/basic_registration_http.php
```

For local Apache private-file enforcement, set `BPC_APACHE_TEST_URL` to the local application base URL before running the HTTP suite. The normal HTTP suite uses a disposable database containing schema copies and synthetic fixtures only, a loopback PHP server and a deliberately closed loopback SMTP port. It tests the actual registration, OTP and login endpoints and follows their HTTP redirects to protected dashboards. Known test challenges simulate email receipt; no real email delivery is claimed. The database, server and synthetic attachment are cleaned up afterward. Tests refuse non-local database hosts.

The HTTP suite checks Student/Teacher automatic activation, session rotation, verification timestamps, consumed OTPs, incorrect/expired codes, individual pending-account reconciliation, wrong passwords, suspended/rejected accounts, blocked staff self-promotion, cross-patient records/workspaces and file downloads, own-file access, and rejected public administrator roles. The activation suite additionally checks retry/replay limits, transaction/audit rollback and authorized Nurse provisioning.

Actual SMTP inbox delivery, mobile/browser-engine behavior and Hostinger deployment are not verified by these local tests.

Validated locally: 52 real HTTP checks, 61 activation/security assertions, eight login-controller scenarios, People Management rendering and PHP syntax/whitespace checks. The HTTP suite also verifies authorized uploads return usable protected download URLs and direct-file denial through local Apache. The migration was run twice successfully to check idempotence.

Application changes span auth registration/login/verification; account monitoring, security exceptions and permissions; advisory/patient/record APIs; Teacher navigation and AI context; email wording; and private file delivery. The additive schema migration, read-only diagnostics, HTTP/regression tests and this deployment note are included with the change.
