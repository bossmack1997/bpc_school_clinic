# Phase 1 ? Doctor / Clinical Co-Administrator invitations

Readiness addendum: [Hostinger deployment readiness and rollback plan](HOSTINGER_PHASE1_DEPLOYMENT_READINESS.md).

Review date: 2026-10-23, Asia/Manila. **Production recommendation: NO-GO until the live schema/configuration and approved external-email smoke test pass and deployment is explicitly approved.** Phase 1 is implemented and locally tested. No production SQL, production account changes, GitHub push or Hostinger deployment was performed. Phase 2 remains unavailable.

## Existing implementation and role model

This repair reuses the existing system:

| Step | Existing route/component |
| --- | --- |
| MIS authentication | `auth/login.php`, `includes/auth.php`, `includes/security.php` |
| Add Doctor / resend | `pages/admin/admin_accounts.php`, `assets/js/mis-admin.js` |
| Provisioning, resend, acceptance | `includes/doctor_invitation_service.php` |
| Email | `includes/mail.php`, existing PHPMailer installation |
| Private acceptance | `auth/setup_account.php` |
| Login destination | `dashboard_url_for('admin')` resolves the current Doctor profile |
| Clinical dashboard | `pages/doctor/dashboard.php`, `includes/doctor_clinical_service.php` |
| Profile/settings/password | `pages/doctor/profile.php`, existing `pages/doctor/settings.php`, shared `pages/admin/profile.php`, `api/profile.php`, `includes/account_settings.php` |
| Logout | `auth/logout.php` |

The Doctor is `users.role=admin`, `admin_type=co_admin`, with `admin_profiles.profile=doctor`. No Doctor database role or second Co-Admin system was introduced. MIS provisioning requires an active Primary Administrator, the MIS profile, and the existing `manage_users` capability. The controller and service both enforce authority. A Doctor profile does not grant MIS technical permissions, and a Primary MIS administrator does not inherit clinical authority. The service uses unique email/username constraints for concurrency-safe identity checks.

## Confirmed defects and root causes

### Reported session-expired message

The modal previously generated the same ?Your session may have expired? message for **every redirected or non-JSON response**. That includes server/configuration/schema errors and unexpected redirects. The message did not establish that the PHP session actually expired. Cookies were already requested with `credentials: same-origin`; they remain enabled. Genuine unauthenticated or expired requests now receive tested HTTP 401 JSON and a specific session message. CSRF failures remain HTTP 403 and do not disable validation. Other server/format/network errors have separate safe messages.

The original local HTTP suite passed 26 checks before these changes. The exact response/logs behind the historical Hostinger incident were not available, so its production transport/configuration cause cannot be certified from the generic message. The corrected code-level classification is verified with real HTTP and browser response tests.

### Stuck Send Invitation button

The old fetch had no deadline. Invitation delivery ran synchronously with PHPMailer defaults and retained the PHP session lock; a stalled SMTP/network operation could leave the fetch pending, preventing finally from running. Feedback setup also occurred outside the guarded try block. The repair wraps setup/serialization/request/response handling, uses a 45-second abort deadline, restores loading/aria-busy state in finally, and prevents duplicate handlers/submits. The mailer has a 15-second connection timeout and a 20-second SMTP command time limit. SMTP commands and DATA can take multiple intervals; this is not a hard total server deadline.

After account commit, the controller persists a rotated replay nonce and releases the session lock **before** email. It briefly reopens the session for the resulting flash message. A timed-out browser request can have committed an account already; the message instructs MIS to refresh the account list before resending. Request replay, identity uniqueness and resend cooldown prevent blind duplicate invitations.

### Profile, settings and activation gaps

- `pages/doctor/profile.php` was empty. It now uses a Doctor-only wrapper around the established profile UI.
- The shared profile displayed MIS responsibilities to Doctors. It now shows the stored professional designation, appointment reference and registration information, with clinical-only responsibility text.
- The Doctor personal-settings form called `save_account_settings`, whose role identity service supports Student, Teacher and Nurse profiles. It now uses the existing self-scoped `update_info` endpoint; personal input types and schema-sized email length are validated. The established password-change service remains in use.
- Invitation acceptance logic lived only in the page. It is consolidated in the existing service so token validation, eligibility, password strength/confirmation/72-byte bound, row locking, activation, token consumption and audit rollback are testable together. The page retains CSRF validation and safe HTML errors.
- Pending appointment verification previously generated links that could not activate. New invitations require an explicitly verified appointment before any account/email is created. Existing unverified pending records are preserved and cannot be resent/activated without a separately authorized appointment review. No migration silently approves them.

## Invitation lifecycle and security behavior

MIS enters identity and appointment details and explicitly confirms appointment verification. The service creates a pending, email-unverified Co-Admin with an unknowable random initial password hash, a verified Doctor profile, and a 24-hour cryptographically random invitation. Only SHA-256 of the 32-byte random token is stored. Creation and its activity audit are transactional. The mail transport is called after commit. MIS receives account/delivery status, never a password, raw token or setup URL.

New links use `auth/setup_account.php#token=...`. The browser clears the fragment and bridges it to the same page by CSRF-protected POST before displaying the password form. The fragment is absent from normal HTTP request URLs and referrers. Password submission remains POST with CSRF. Cache-Control no-store and Referrer-Policy no-referrer are retained. New fragment links require JavaScript; a noscript message explains that requirement.

Older query-token links remain supported to avoid breaking already-issued invitations. Those legacy query strings can appear in upstream access/security logs. Protect/review existing logs and use the explicit resend workflow when replacing a legacy link; no existing invitation is invalidated by the schema migration. Application logs and ordinary MIS API responses do not contain tokens. Provider request-body logging/WAF configuration has not been audited; handle invitation POST bodies as secrets.

Acceptance locks the target account, professional profile and invitation, rechecks pending/approved Doctor eligibility and expiration, conditionally consumes exactly one unused token, activates the account with the appointee's password, clears remember-me credentials, increments credential_version, invalidates other outstanding invitations for that account and writes the activity audit in the same transaction. Used/expired/ineligible links cannot change a password. Audit failure rolls back activation or provisioning.

Resend is restricted to verified pending Doctors. It consumes old outstanding links and issues a new secret, transactionally with an audit event. A recently issued usable invitation has a one-minute resend cooldown; explicitly failed mail can be retried without that cooldown. A duplicate create is a conflict. Failed SMTP leaves an honestly reported pending account and supports resend; there is no manual-token fallback in MIS. A successful SMTP return means acceptance by the transport, not verified inbox delivery.

Runtime writes require all four participating tables to be InnoDB, expected write dependencies and unique identity/token constraints. Unavailable storage fails before provisioning. Full schema compatibility still requires the operator preflight and post-verification below.

## Phase 2 boundary

No Nurse migration was executed or added to this release. No Nurse account scope/status or clinical/patient record was changed. Doctor summary no longer loads the Nurse verification service, and the Doctor menu/dashboard no longer advertises Nurse approval. Direct access to the existing Nurse verification page is denied server-side during Phase 1. Existing Doctor profile/account structure can support a later reviewed Phase 2. Existing Nurse authorization risks recorded in the separate Nurse review are not claimed to be remediated here.

## Narrow database requirements

Use only:

1. `database/preflight_doctor_invitations_hostinger.sql` ? read-only metadata/constraint inventory, safe when admin_invitations is absent.
2. `database/migration_doctor_invitations_hostinger.sql` ? guarded creation of the existing admin_invitations table shape if missing, plus nullable admin_profiles.official_designation if missing. No account/patient DML, no hard-coded production USE, no destructive conversion and no Nurse dependency.
3. `database/verify_doctor_invitations_hostinger.sql` ? separate read-only post-verification, including duplicate usable invitations and orphan actors.

Prerequisites are existing compatible users/admin_profiles/activity_logs, credential_version, unique user email/username, unique profile ownership, transactional engines and required base columns. Signed and unsigned INT user IDs are supported by deriving the new invitation actor types from users.id. The base administrative profile system is reused, not rebuilt. If those base tables/columns are absent, stop and review their schema separately; do not blindly run earlier mixed-purpose migrations.

An existing invitation table must match the supported shape, including unique token hash and required columns. Missing/nullable legacy token columns, plaintext required legacy token columns, incompatible types, custom unique/CHECK constraints, relevant triggers or other BLOCK output require manual review. Existing rows are not backfilled, deleted, rehashed or reset. Foreign keys and complete column metadata are reported for review, not rewritten. The invitation table has no new cascading account FK: its history is not deleted by a newly introduced cascade. Orphan/duplicate existing data requires an owner-reviewed plan, not deletion of evidence.

The old `database/migration_doctor_invitations.sql` is retained as history; it contains MariaDB-specific IF NOT EXISTS ALTER/index syntax and is **not the Hostinger execution path**. The CLI helper now requires --apply and uses the guarded Hostinger file, checks completion and reports that post-verification is still required. Do not execute it on production without separate explicit approval.

## Exact changed files for Phase 1

Application/runtime:

- `api/profile.php`
- `assets/js/mis-admin.js`
- `auth/setup_account.php`
- `components/sidebar.php`
- `includes/csrf.php`
- `includes/doctor_clinical_service.php`
- `includes/doctor_invitation_service.php`
- `includes/mail.php`
- `includes/permission_profiles.php`
- `pages/admin/admin_accounts.php`
- `pages/admin/profile.php`
- `pages/doctor/dashboard.php`
- `pages/doctor/profile.php`
- `setup/migrate_doctor_invitations.php`

New operator SQL: the three Doctor-only files listed above.

Tests/documentation: `tests/doctor_invitations.php`, `tests/mis_admin_http.php`, `tests/mis_admin_browser.cjs`, `tests/basic_registration_http.php` (fake mail token extraction updated for fragment links), new `tests/doctor_mail_transport.php`, and this report.

The worktree also contains earlier Nurse SQL/documentation and unrelated edits. They are **not** the Phase 1 deployment manifest. Do not stage or deploy the whole worktree. Existing `pages/doctor/settings.php` and `includes/account_settings.php` are reused dependencies; verify that Hostinger has the reviewed versions. No changes were required to the existing responsive modal CSS.

## Exact validation commands

Run in `C:\xampp\htdocs\bpc_clinic_system`:

```powershell
php -d error_log=scratch/doctor-phase1-fixture-errors.log tests/doctor_invitations.php
php -d error_log=scratch/doctor-phase1-fixture-errors.log tests/doctor_invitations.php --unsigned
php tests/doctor_mail_transport.php
php tests/mis_admin_http.php --browser
php tests/basic_registration_http.php
```

The -d option directs expected negative-case mail logs to a local fixture log; failing assertions still exit nonzero. The tests require APP_ENV=local and a loopback database, create disposable schema copies without real account/patient rows and use synthetic identities. PHPMailer capture connects only to loopback SMTP; all recipients are example.invalid. The HTTP suite uses a closed local mail endpoint/empty SMTP credentials and a fake mail callback only for capturing synthetic activation links. Servers, test databases and temporary fixtures are cleaned up. The browser suite uses an isolated CDP browser context and tests 320, 360, 375, 390, 430, 768, 1024 and 1440px plus reduced height.

`--browser` requires Node 22+ and a disposable local headless Chrome CDP endpoint on port 9337. This review reused an existing local test endpoint. If reproducing without one, start your own hidden test Chrome (do not use a personal browser profile):

```powershell
$doctorChromeProfile = Join-Path (Get-Location) 'scratch/doctor-chrome-tests'
$doctorTestChrome = Start-Process -FilePath 'C:/Program Files/Google/Chrome/Application/chrome.exe' -ArgumentList '--headless=new','--disable-gpu','--no-first-run','--remote-debugging-port=9337',"--user-data-dir=$doctorChromeProfile" -WindowStyle Hidden -PassThru
php tests/mis_admin_http.php --browser
Stop-Process -Id $doctorTestChrome.Id
```

Do not stop a pre-existing browser process; the final Stop-Process is only for the process created in this example. This document does not itself authorize running operator commands against production.

## Actual validation results

All completed runs exited successfully after the fixes. Local environment: PHP 8.2, MariaDB 10.4.32, Node 22 and Chrome 155. No Hostinger database or real mailbox was used.

| Command | Actual result |
| --- | --- |
| `php -d error_log=scratch/doctor-phase1-fixture-errors.log tests/doctor_invitations.php` | All 40 Doctor Phase 1 checks passed; fake transport only |
| `php -d error_log=scratch/doctor-phase1-fixture-errors.log tests/doctor_invitations.php --unsigned` | All 40 checks passed with unsigned user/profile IDs |
| `php tests/doctor_mail_transport.php` | Three checks passed: real PHPMailer loopback handoff, expected captured invitation content, closed-port failure; external inbox delivery not verified |
| `php tests/mis_admin_http.php --browser` | 47 MIS/Doctor HTTP checks passed; 63 browser assertions also passed |
| `php tests/basic_registration_http.php` | All 107 real HTTP regression checks passed; no live records or real recipients |

The Doctor schema tests exercise absent invitation table, missing designation column, existing account preservation, reruns, signed/unsigned references, incompatible existing token-column blocking and account rollback on audit failure. Service checks include required/invalid/array input, unverified appointments, unauthorized roles, duplicate identity, random hashed expiring tokens, inviter/time, truthful failure, resend cooldown/invalidation, password strength/matching/byte bound, private activation, expiry and replay. The transaction now also locks the professional profile before activation.

HTTP checks cover invalid/missing invitation CSRF and invalid activation CSRF, valid pending flow, nonce replay/duplicate clicks, duplicate identity, resend, expired/absent and actually timed-out MIS sessions, invalid/expired/used/ineligible links, private activation, Doctor login/dashboard/profile/settings/password/logout, Student/Teacher/Nurse/Doctor provisioning denial, MIS clinical denial, Phase 2 route denial and existing MIS directory behaviors. The HTTP fixture explicitly removes Nurse authorization tables/columns before running the Doctor flow.

Browser checks exercise eight widths and reduced height, scroll/footer/button accessibility, duplicate events, close/reopen, same-origin credentials, real HTTP validation failure, simulated HTML/malformed JSON/401/network/timeout/cross-origin destination responses, correct loading recovery, and actual fragment-to-POST private activation in an isolated browser context. Mobile 390px and desktop 1440px screenshots were visually inspected; inputs and action controls were accessible.

The broader registration suite retained Student/Teacher/Nurse OTP/basic-account boundaries, authorized Nurse operations, recovery, uploads and public privileged-role rejection. Its synthetic mail capture was updated to parse the new fragment link. These checks do not constitute an exhaustive clinical regression or provider deliverability certification. They do establish that the targeted invitation flow and tested existing roles work locally.

## Hostinger execution and deployment checklist

**Current production status: NO-GO.** Code is locally reviewable; live schema parity, provider mail configuration/delivery and explicit release approval remain outstanding. A loopback SMTP capture does not prove Hostinger can deliver to a real Doctor inbox.

1. Obtain explicit approval for the precise Phase 1 file manifest and separately for production SQL. Back up the selected database and the current deployed files; verify a restore path. No push/deploy/SQL is approved by this report.
2. In Hostinger phpMyAdmin select the intended production database. Run ONLY preflight_doctor_invitations_hostinger.sql. Confirm DATABASE()/version, four InnoDB base tables as applicable, identity/profile unique indexes, credential_version and the complete existing write-column defaults/constraints. ADD is permitted only for the supported missing invitation table or designation column. Any BLOCK, custom-review row, unknown required insert column or unexpected FK/constraint is NO-GO. Reproduce a differing schema in a disposable local database before proceeding.
3. Review required users fields used by provisioning/activation and their lengths/defaults; admin_profiles verified appointment fields; activity_logs insertion columns/engine; and old invitation definitions. Verify there are no duplicate usable invitation users or orphan actors if the existing table is present. Run the read-only aggregate checks from the post-verification file only when the referenced existing columns are present. Do not reset accounts or remove history to satisfy checks.
4. Quiesce invitation creation/resend/acceptance for the window. After approval, run the COMPLETE migration_doctor_invitations_hostinger.sql in phpMyAdmin. Require READY_SCHEMA_ONLY, zero SQL errors and SCHEMA_COMPLETE. DDL auto-commits. On any error stop, inspect the partial state, rerun preflight and resolve the cause before rerunning the whole file. Do not use the old MariaDB-specific migration or either Nurse migration.
5. Run COMPLETE verify_doctor_invitations_hostinger.sql. Require all structural results PASS, correct SHOW CREATE definitions, zero duplicate usable invitation users and zero orphan actors. Compare existing account/clinical/audit counts/values with the protected baseline. No existing account values should have changed.
6. Confirm production APP_ENV=production, canonical APP_URL=https://bpcschoolclinic.org, HTTPS session cookie behavior, writable isolated session storage, and the existing SMTP host/port/credentials/TLS/from-domain configuration. Keep mail debugging disabled. Protect access/security logs and invitation POST bodies. Provider SMTP acceptance and inbox delivery remain separate checks.
7. After approval upload only the reviewed Phase 1 runtime manifest, preserving paths and existing files. Keep the current Doctor settings/password service dependencies consistent. Run operator SQL through phpMyAdmin; do not publish tests, reports, backups or SQL dumps as public website assets. The CLI setup helper is optional and not the phpMyAdmin deployment mechanism. Do not deploy pending Nurse changes.
8. Check the actual protected page response, cookie, CSRF/nonce handling and content-hashed mis-admin.js URL. Verify a genuine expired session yields 401 JSON; CSRF yields 403 JSON; invalid fields yield 422; duplicates/replays yield 409. A non-JSON/provider error must be investigated as a server response, not assumed to be an expired session.
9. With approval and a designated real Doctor recipient, perform ONE controlled invitation. Confirm no raw token/password appears in MIS responses/logs. Verify SMTP acceptance and actual received email, fresh fragment link, private password setup, single-use consumption, Doctor login/dashboard/profile/settings/password/logout, denied MIS technical access and denied Nurse approval route. Do not claim delivery on the basis of a sent flag alone.
10. Release only after those checks pass and approval remains valid. A test failure, unknown schema, mail failure, unexpected permissions or unresolved legacy pending appointment is NO-GO for the affected workflow. Pause rollout; restore code from the reviewed backup if needed. Do not drop populated invitation/history tables as a rollback shortcut. Preserve receipts/results and stop after Phase 1; Phase 2 requires a new explicit instruction/approval.
