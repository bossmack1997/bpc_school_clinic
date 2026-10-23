# MIS mobile, People Management and Doctor invitation fixes

Validated locally on 23 October 2026. No production account changes or schema migration are included.

## People Management root cause

The People Directory is a server-rendered GET page (`pages/admin/users.php`), not an AJAX endpoint. Its directory query unconditionally selected `users.email_verified_at` and `users.self_service_only`, introduced by an earlier additive migration. An older deployment schema therefore reaches the application's global exception handler, which emits the reported generic JSON response.

This was reproduced in a disposable local fixture before the fix: SQLSTATE 42S22 / MySQL 1054, unknown column `u.email_verified_at`. Both current and legacy schemas now pass. The read-only query projects NULL for optional fields absent from the database; this never changes authentication decisions or permissions. Other database failures render an HTTP 503 HTML alert inside People Management, with unavailable metrics instead of false zero counts. History failures have a separate inline message. Technical logs record SQLSTATE without account data or SQL parameters.

Hostinger's private database schema and PHP logs were unavailable, so this is a reproduced code-level failure, not a claim that production logs were inspected.

## Mobile changes

- The dashboard retains its existing desktop table and single bounded, newest-first LIMIT 10 query. Mobile renders compact action/icon/module/timestamp rows from that same result. Full history remains linked. Timestamps use Asia/Manila, with original database values preserved and output escaped. Unknown actions have a decorative fallback icon and visible action text.
- The existing invitation form is now the Bootstrap modal-content element. Its body scrolls independently, header/footer do not shrink, and scoped styles override the shared footer flex rules that caused oversized buttons. Narrow phones stack 44px buttons; wider screens retain horizontal buttons. Fields and backend names remain intact. Dynamic visualViewport sizing and safe-area padding accommodate reduced available height.
- Invitation errors appear inline, scroll into view, preserve entered values, and restore button state. A busy guard prevents duplicate handlers/submits; server-side nonce validation also rejects replay.

## Invitation security and delivery

Only an active MIS Primary Administrator can provision or resend a pending Doctor invitation. Input, identity uniqueness, CSRF and request nonce are validated on the server. Account, hashed invitation and audit insertion are atomic; email runs after commit using the existing mail service. A failed transport retains a pending account and accurately reports unconfirmed delivery. Resend revokes the old invitation. The previous fallback that displayed a raw setup link to MIS was removed.

The 256-bit invitation token expires after 24 hours and is stored only as SHA-256. Private appointee setup requires a verified clinical appointment and pending co-admin Doctor profile, locks and rechecks state, hashes the appointee's password, rotates credential version, consumes invitations and records an audit event. Suspended/rejected accounts cannot be revived by setup. Setup responses disable caching/referrer disclosure. No password or raw token is returned to MIS or written by these application logs. Hosting access-log policy remains outside available access.

## Validation

- `php tests/doctor_invitations.php`: 18 service checks passed, including validation, authorization, uniqueness, token hashing/expiry, clinical-only permissions, resend, transport failure and atomic audit rollback.
- `php tests/mis_admin_http.php --browser`: 21 HTTP checks plus the actual Chrome suite passed. HTTP covers CSRF, invalid fields, failed mail, private links, replay/duplicates, resend, password mismatch, acceptance, used/expired tokens, suspended/rejected/pending appointment blocks, clinical dashboard redirect, denial of People Management to Doctor, search/filter, legacy schema and HTML failure handling.
- Chrome rendered all three affected pages at 320, 360, 375, 390, 430, 768, 1024 and 1440px. Checks cover compact-versus-desktop audit layout, bounded rows, page overflow, People search/table containment, modal button sizes, visible footer, body scrolling and reachable final field. Reduced height (390 x 460) and real AJAX error recovery/duplicate prevention passed. Screenshots were inspected locally; synthetic artifacts stay under ignored `scratch/`.
- `php tests/registration_activation.php`: all 61 checks passed.
- `php tests/registration_login.php` with eligible, untrusted, suspended, rejected, unverified, unverified_active, otp_dashboard and bad_password: all eight cases passed.
- `php tests/registration_people.php` and `php tests/registration_people.php legacy`: both passed, including details, escaped history, clinical-detail exclusion and no routine approval action.
- `php tests/basic_registration_http.php`: all 51 HTTP checks passed, including basic OTP activation, restricted nurse provisioning, other-patient record protection and public privileged-role denial. An initial sandbox cleanup failure on a synthetic uploaded PDF was corrected by rerunning with filesystem permission; the rerun completed successfully and removed its fixture files.
- PHP lint passed for all 12 changed/new PHP files; Node syntax checks passed for both JavaScript files. `git diff --check` passed.

Physical Android/iOS keyboards, assistive technology and real SMTP inbox delivery were not tested. Transport success was tested through an injected isolated transport; actual HTTP mail failure used a closed loopback SMTP port. No real email recipients or patient records were used.

Browser suite prerequisite: launch a dedicated headless Chrome profile with remote debugging on loopback port 9337, then run the HTTP suite with `--browser`. It creates a schema-only disposable local database and synthetic authenticated users; it is blocked against a nonlocal database.

## Changed files

Application: `assets/css/mis-admin-mobile.css`, `assets/js/mis-admin.js`, `auth/setup_account.php`, `includes/account_monitoring.php`, `includes/admin_audit_summary.php`, `includes/doctor_invitation_service.php`, `includes/head.php`, `includes/mail.php`, `pages/admin/admin_accounts.php`, `pages/admin/dashboard.php`, `pages/admin/users.php`.

Tests/report: `tests/doctor_invitations.php`, `tests/mis_admin_browser.cjs`, `tests/mis_admin_http.php`, `tests/registration_people.php`, this report.

## Deployment limits

The public production login returned HTTP 200 on 23 October 2026 and identifies Hostinger hosting. No `.github` deployment workflow is present. hPanel deployment configuration, build/commit identity, authenticated production pages, private schema and logs remain unverified. A GitHub push alone does not prove the live site updated. Confirm the exact Git commit in hPanel and smoke-test the three MIS pages plus a synthetic authorized invitation after deployment; do not modify real accounts for testing.
