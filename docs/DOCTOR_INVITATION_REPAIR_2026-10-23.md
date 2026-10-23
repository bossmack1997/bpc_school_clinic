# Doctor invitation repair — 2026-10-23

## Confirmed root cause and evidence

The Add Doctor form contains `<input name="action" value="invite_doctor">`. A named form control shadows the native `HTMLFormElement.action` property. The old `fetch(form.action || window.location.href, ...)` therefore passed an HTMLInputElement as its URL, stringified as `[object HTMLInputElement]`, rather than the form's configured endpoint. The browser requests an incorrect relative route and receives a non-JSON response, triggering the exact reported unexpected-response message.

This was reproduced by the actual Chrome suite: the native property is an HTMLInputElement; the pre-fix submission received a non-JSON response instead of the expected CSRF JSON error. Direct HTTP tests passed because they supplied the correct URL explicitly, so they missed the browser URL-resolution defect. The initial suspicion that the failing browser assertion was timing-related did not explain it; inspecting the response and form established the collision. The corrected browser test checks the URL and submits through the actual listener.

This establishes a local source/browser defect consistent with the report. No authenticated production request, Hostinger log, live deployed-file comparison or production database inspection was performed. Production incident attribution remains unconfirmed until deployed parity and a redacted network trace are checked. Missing schema, stale files, configuration, proxy errors and SMTP availability remain separate production checks.

## Changes in this repair

| File | Purpose |
| --- | --- |
| assets/js/mis-admin.js | Read the action attribute explicitly; preserve cookies/deadline/finally cleanup; block resubmission after ambiguous outcomes and offer account-list refresh; only classify structured 401 errors as session expiry. |
| pages/admin/admin_accounts.php | Install the response guard before bootstrap and recognize Accept JSON consistently with authentication. |
| includes/doctor_invitation_response.php | Scoped POST output guard for bootstrap exits, fatal/non-JSON failures and redirects; preserve valid JSON/status; return safe unknown-outcome JSON without diagnostics. |
| tests/mis_admin_browser.cjs | Reproduce named-control collision, verify endpoint, responsive layout, errors, retry policy, retained values and activation. |
| tests/doctor_invitation_response.php | Seven isolated contract/status/redaction cases, including output cleanup. |
| docs/PHASE1_HOSTINGER_DEPLOYMENT_MANIFEST.json | Refresh runtime hashes and include the new guard dependency. |
| docs/HOSTINGER_DOCTOR_INVITATIONS_PHASE1.md | Link current repair evidence. |
| docs/HOSTINGER_PHASE1_DEPLOYMENT_READINESS.md | Link current evidence and correct runtime file count. |
| docs/DOCTOR_INVITATION_REPAIR_2026-10-23.md | Investigation, results, limitations and operator release steps. |

No account/password/token/patient data, credentials, Nurse approval implementation or production configuration is changed. No duplicate Doctor implementation was introduced. No production SQL or deployment was performed.

## Local validation

All database suites use disposable local schemas or temporary tables and synthetic identities. SMTP tests use loopback capture only. Browser tests use isolated Chrome contexts and disposable HTTP fixtures. Test artifacts are excluded from the commit.

| Command | Actual result |
| --- | --- |
| `php tests/doctor_invitations.php` | 40 passed; signed IDs, migration rerun, preservation, authorization, validation, duplicate detection, single-use activation, expiry, mail failure and rollback. |
| `php tests/doctor_invitations.php --unsigned` | 40 passed; unsigned IDs and the same lifecycle checks. |
| `php tests/doctor_invitation_response.php` | 7 passed; bootstrap/redirect/fatal/malformed/clean/CSRF/valid response and status contracts. |
| `php tests/doctor_mail_transport.php` | 3 passed; PHPMailer loopback capture, expected content and closed-port failure. No external inbox delivery. |
| `php tests/basic_registration_http.php` | 107 passed; Student/Teacher/Nurse registration, OTP, permissions, password reset, Doctor activation, protected routes and attachment access. |
| `php tests/mis_admin_http.php` | 46 passed; MIS login, invitation/CSRF/validation/replay, SMTP failure, activation, Doctor login/profile/settings/password/logout and Phase 2 denial. |
| `php tests/mis_admin_http.php --browser` | Final run: 47 HTTP checks (including browser-suite completion) and 80 browser assertions passed, zero failures. Initial pre-fix browser runs failed on the real submission, exposing the URL collision. |

PHP lint passed for both changed runtime PHP files and the new PHP test; `node --check` passed for both changed JavaScript files. Unstaged and cached `git diff --check` passed. All 14 runtime manifest hashes matched local bytes. Responsive browser checks cover 320, 360, 375, 390, 430, 768, 1024 and 1440 pixels plus a reduced-height viewport. The existing layout needs no CSS changes; the mobile screenshot was visually inspected.

The tests do not certify every unrelated application feature or Hostinger's MariaDB 11.8.9 runtime. Existing migration/preflight/verification SQL remains unchanged; signedness, rerun safety, schema incompatibility rejection and record preservation were exercised locally.

## Hostinger steps — operator only, after separate production approval

1. Back up database and matched deployed code, verify the restore path and record the current deployed release. Do not expose dumps or credentials in public_html.
2. Privately match hPanel database, effective application DB_NAME and `SELECT DATABASE()` to `u962995739_bpc_clinic`. The user reports MariaDB 11.8.9; this task did not query production.
3. Run the complete `database/preflight_doctor_invitations_hostinger.sql` and inspect every result plus base table definitions/constraints, following the existing readiness guide. Check **admin_invitations**, not doctor_invitations. Check existing pending accounts/invitations before sending again; do not delete or reset them.
4. If admin_invitations or admin_profiles.official_designation is absent and all prerequisites pass, obtain migration approval and import the complete `database/migration_doctor_invitations_hostinger.sql` in one session. No migration is needed if compatible schema already exists. DDL commits independently: stop on any error, inspect partial state, preserve records and review before rerunning. Never substitute a Nurse or legacy migration.
5. Run `database/verify_doctor_invitations_hostinger.sql`; require expected constraints, no orphans/duplicate usable invitations and manual base-schema gates. See the existing readiness guide for signedness, defaults, foreign keys, custom triggers and rollback details.
6. Deploy the complete compatible runtime set in the refreshed manifest, including **includes/doctor_invitation_response.php** alongside admin_accounts.php. Verify SHA-256 parity and shared dependencies, preserve environment secrets, and refresh authorized PHP/CDN/browser caches. GitHub push alone does not update Hostinger.
7. Privately verify APP_URL, PHP dependencies, HTTPS cookies/session storage and actual SMTP provider credentials/sender. Existing PHPMailer supports Hostinger Email 465/ssl or 587/tls; select the pair matching the actual provider. Doctor mail uses 15-second connection and 20-second command limits, not a total server deadline. Keep debug disabled and never hardcode secrets.
8. Obtain approval for a controlled real-recipient invitation. Capture only HTTP status, content type, endpoint path and redacted deployment/log references. Confirm expected JSON: 200/202 success and delivery_status/redirect, 401 authentication, 403 CSRF/authorization, 409 conflict, 422 validation, 503 unavailable storage. Inspect existing pending outcomes before resend. Confirm actual inbox receipt, single-use password setup, Doctor login/dashboard/profile/settings/password/logout, MIS separation and Nurse-review 403. Test Student/Teacher registration and existing mail flows.

No live invitation count, external inbox receipt, production configuration, deployed parity, backup restore, schema or authenticated production workflow has been verified here. These remain production blockers.

## Release decisions

- Local implementation: GO; all final suites passed, including the actual fetch endpoint assertion and browser activation.
- GitHub main: GO for the user-authorized push of the nine reviewed files after the staged whitespace check. No force push; verify remote SHA and report the final commit URL in the delivery message. Fetch confirmed the starting branch matched origin/main at 4baaf04.
- Hostinger migration: script ready for operator preflight; actual execution NO-GO until target/schema/backup checks and approval.
- Production deployment: NO-GO until operator steps and authorized smoke tests pass. Nurse clinical approval stays disabled.
