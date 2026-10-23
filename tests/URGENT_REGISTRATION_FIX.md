# Urgent registration response repair ? 23 October 2026

## Exact cause

`auth/register.php` checks `SHOW COLUMNS FROM users` and emits the reported system-update message when `email_verified_at` or `self_service_only` is missing (around lines 148?154 after this repair). Previously execution continued into `MICARegistrationValidator::validateRegistration()`, whose username rule emits the other reported message (`includes/ai/MICARegistrationValidator.php:124`). The final response concatenated both conditions and used HTTP 503 even for the accompanying field error. This is a confirmed control-flow bug, reproduced in the disposable legacy-schema HTTP test using username `invalid name`.

No incorrect username request mapping was found: the input, POST extraction and validator all use `username`. Backend trimming was already present. JavaScript previously checked presence and email syntax but did not enforce the documented username pattern; the form uses `novalidate`, so HTML attributes alone did not provide that check. JavaScript now trims the value and enforces the same 3?50 ASCII letter/number/underscore rule as PHP. All registration controls and role-required flags were compared to the preceding Git revision and preserved.

## Repair

Schema failures stop applicant validation and uploads. The system-update message remains meaningful and is not suppressed. JSON returns HTTP 503 with `error_code=schema_update_required`, without an unrelated username error. Safe server logs list the exact missing required columns.

Validation returns HTTP 422 with `field_errors` and an error list; known duplicate username/email conflicts return HTTP 409 with the matching field; CSRF returns HTTP 403 and `csrf_error`. Username/email errors render next to those inputs. Unexpected infrastructure failures log exception class, SQLSTATE, driver code and a random correlation reference without SQL statements, passwords, OTPs or applicant data. They return a safe error with the reference. Transaction failures retain their duplicate/database classification. Existing OTP/email recoverable states remain intact: saved accounts go to verification, transport failure is reported, and resend is available.

The approved Student/Teacher/Nurse OTP/basic activation and login flow, Nurse clinical authorization boundary and MIS-only Doctor invitations remain unchanged.

## Schema and production evidence

The local diagnostic was executed: every required column checked in users, students, teachers, nurses, sections, advisory_assignments, account_security_events and activity_logs exists. Diagnostics now cover every registration INSERT field and section query dependency, rather than only a subset. Real disposable HTTP registrations exercise their column types and constraints successfully.

The legacy-schema test removes only the two basic-OTP columns from an isolated database, verifies the failure, applies the existing portable additive migration and verifies successful registration afterward. The migration was rerun to verify idempotence. No additional migration or permanent database change is necessary locally.

Hostinger private logs/schema and deployed code revision are unavailable. A read-only web attempt to open the production registration page failed in the browsing tool. Therefore no live schema mismatch, current SQL exception, GitHub/Hostinger revision parity, inbox delivery or production fix is claimed.

On the authorized deployment terminal, run `php tools/diagnose_registration.php` first. If it confirms those two missing columns, run `php database/migration_basic_otp_activation.php`, then rerun diagnostics. Deploy matching auth and validator code together. Other missing schema dependencies need their corresponding existing migration; the basic-OTP migration only adds its two columns. Do not bypass the scope column or auto-activate existing accounts.

Rollback: restore the previous application revision while retaining the additive columns and all account values. Do not drop populated columns or undo account verification data. Back up the intended server before its normal authorized migration process. This repair executes no production SQL and no destructive migration.

## Executed validation

- Full legacy-schema and mobile-browser HTTP run: 98 checks passed, plus 41 actual Chrome layout/password/scroll assertions at widths 320?1440px.
- Activation/security regression: 70 checks passed.
- Follow-up HTTP run: 95 checks passed, including exact 409 duplicate response assertions.
- Alphanumeric usernames, underscores, whitespace trimming, invalid/short/long usernames, duplicate username/email, missing fields, inline errors, invalid CSRF, uploads, OTP expiry/retries, successful verification/login, Nurse clinical denial, password recovery and Doctor invitation setup are exercised with disposable accounts.
- PHP syntax, inline JavaScript syntax, unchanged form controls/required flags and Git whitespace review passed.
- OTP delivery success is tested through an injected isolated transport; actual SMTP inbox delivery is not verified. Real HTTP transport failures are deliberately simulated over loopback.

## Files changed

`auth/register.php`, `includes/ai/MICARegistrationValidator.php`, `tools/diagnose_registration.php`, `tests/basic_registration_http.php`, and this report. No registration field, upload requirement, database column, stored record or patient data was removed. No push or Hostinger deployment is performed.
