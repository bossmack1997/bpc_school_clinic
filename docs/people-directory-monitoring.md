# People Directory & Account Monitoring — implementation and QA

Date: 22 October 2026 (Asia/Manila)

## Live-data finding and repair

The database contained 16 users: 13 active, 3 inactive/terminal and **zero pending accounts**, but there were **9 active pending/registration-review alerts**. This confirmed that the repeated “2 Pending Account Registration(s)” message was historical alert state, not the current registration count.

A targeted, transactional reconciliation rechecked the pending count under the same MICA/writer locks and archived all 9 stale alerts. Each row and its history remains intact; a source-verification event records why it closed. No users, QR records, registrations or medical records were deleted or modified by this repair. The post-repair audit confirmed 16 total / 13 active / 3 inactive / 0 requiring verification and 0 active registration alerts.

Root causes also included stale action text, independent per-registration/grouped alerts, unconditional Student activation after OTP, heuristic staff approval, a legacy public-admin bootstrap path, local-mode OTP disclosure after delivery failure, missing per-user failed-login increments, a 1,000-row directory cap, and QR joins that could multiply rendered rows.

## Directory behavior

The Administration page is now **People Directory & Account Monitoring**. It is a read-only supervisory page with four database metrics, accessible filter labels, responsive metric columns, an internally scrollable table, empty state, and server-side paging of 25 records. Search covers full name, username, email and institutional identifier; role and account-state filters combine. Pagination searches the full database rather than a capped client-side subset.

Rows show name, username, role, institutional ID, recorded email-verification flag, active/regenerated QR availability for Student/Teacher, actual account status, registration timestamp and View Details. Details use an explicit safe field projection and show account ID, assessment/reason, registration and last sign-in. No password, OTP, reset or QR token is exposed. QR availability is distinct from account access.

Bulk approval, approval/rejection and routine activation/deactivation controls and POST processing were removed. Directory POST returns 405. Malformed filter arrays return 400. Legacy users API approval/rejection/activation/deactivation/bulk actions return 410 for authorized administrators; they do not mutate accounts. Direct administrator status changes on the provisioning page/API are also retired in favor of documented exceptions. Profile editing, password recovery and restricted co-admin provisioning remain available.

Existing authorization is preserved: Primary Administration can see administrator identities; delegated directory viewers need manage_users or approve_users and cannot see administrator rows. Their metrics use that same scoped population. MICA's shared administrative alerts use the global account population; scope is stated explicitly rather than confusing a scoped directory count with a global incident total.

## Shared account definitions

- Active: users.status = active.
- Requiring verification: users.status = pending, including incomplete OTP and verified identities needing review.
- Inactive: every other status, including inactive/rejected and any terminal legacy states. The row still displays its specific stored status.
- Total = Active + Inactive + Requiring Verification for the same audience scope.

AccountMonitoringService owns the shared classification/count. MICA scanning and the daily summary use it. Pending does not mean that every account requires a manual administrative decision. A zero pending count creates no pending alert and resolves stale managed alerts after source verification.

## Registration and identity flow

Registration → account saved pending → email OTP → trusted identity and role evaluation → activation or authorized review.

The existing operator-controlled institutional_roster is the trusted source. Identity requires exactly one matching identifier, matching normalized first/last name, matching roster email, matching role, and roster status eligible or registered. Revoked/ambiguous/missing matches cannot auto-activate. Nurse eligibility additionally requires a matching recorded nursing license. Duplicate institutional identifiers attached to another same-role account and high-risk accounts prevent automatic activation. Publicly entered ID/license syntax, institutional email domain, uploaded documents and confidence scores are not eligibility proof.

Students, Teachers and Nurses can activate automatically only after both OTP ownership and these trusted rules pass. Student and Teacher QR creation uses existing helpers and preserves established records. Unmatched records remain pending for a documented identity/eligibility review. Advisory-class assignments are not automatically granted or rewritten by personal account activation.

Public Administration registration cannot grant privileges. The public selection card now explains restricted provisioning. Existing co-admin creation remains restricted to Primary Administration; legacy public-admin records cannot become primary or be approved through the exception service. Initial root provisioning now requires an operator-configured INITIAL_ADMIN_SETUP_TOKEN of at least 32 characters, plus existing CSRF/validation, and cannot run when any primary-admin record already exists. Missing bootstrap credentials return 503. Existing primary-admin recovery must use the operator process rather than reopening public bootstrap.

OTP verification locks the user row, validates a current nonempty hash and expiry, serializes failed-attempt increments and locks registration after five failures. Resend cannot reset that lock. OTP expiry and database timestamps explicitly use Asia/Manila / +08:00. Verification targets come from the registration/password-authenticated session, not a supplied uid. A correct password can resume a pending OTP challenge without opening a logged-in account session.

Registration email sends occur after account commit. Resend prepares the code and cooldown under a row lock, commits, then calls mail transport. Delivery failure is reported honestly and leaves the account unverified; the browser never receives a demo OTP. SMTP transport was not exercised against real recipients in QA.

## Security exceptions and audit

A separate Account Security Exceptions page is available to Primary Administration, or a delegated administrator with both manage_security and manage_users. Identity decisions additionally require approve_users. The service rechecks current actor authority from the database inside its transaction.

An exception requires a specific reason and evidence/case reference. Only one open exception per account is created by the service. Allowed documented dispositions are security suspension, restoration of a previously approved/email-verified inactive account, verified identity review, failed identity review, OTP retry after security review, or closure without access change. Self and primary-admin changes are protected. Public administrator requests cannot be approved. A resolved exception cannot execute twice.

Access changes, disposition, before/after states, actor, timestamp, evidence reference and notes are persisted. Status-changing exceptions increment credential_version to invalidate existing session credentials. Audit failure rolls back the disposition and account change. OTP recovery restarts verification; it does not activate the account. Existing password-reset safeguards remain in place. No MICA action approves a privileged account or deactivates users automatically.

## MICA monitoring

Persisted invalid-registration, duplicate-indicator, identity-failure and OTP-failure events are summarized by condition in a rolling 24-hour window. Subject identifiers are hashed; OTPs/passwords are never logged. Existing duplicated institutional IDs are detected from actual Student/Teacher/Nurse tables. Open security exceptions are monitored individually. Repeated scans reconcile the same condition key rather than create duplicate active cards.

Wrong-password attempts now increment the existing per-user failure counter as well as the IP failure ledger, so suspicious-access monitoring has actual data. Security signals indicate investigation needs, not an automated finding of malicious intent. MICA monitors and notifies through its established scan lifecycle; users and security reviewers decide access. A window expiring closes a recent-activity signal, not an investigation or account by itself. Exceptions close only through their authorized service.

## Schema and rollout

Applied locally: database/migration_people_monitoring.sql. It adds account_security_events and account_security_exceptions, with lookup indexes. It also supplies CREATE TABLE IF NOT EXISTS for institutional_roster for deployments lacking the existing trusted source. Existing roster records are not replaced or seeded.

The existing MICA companion-table migration remains a prerequisite. Reapply both additive migrations using:

    C:\xampp\php\php.exe setup/migrate_people_monitoring.php

Populate/maintain the roster only from an authoritative enrollment/HR source. The local pre-existing roster has eligible entries for 5 Students, 3 Teachers and 2 Nurses; legacy Doctor/Admin entries cannot confer public account activation. No roster data was changed here. Other deployments with an empty roster safely route identities to authorized review. Initial provisioning secret is documented in .env.example; no real secret was generated or changed.

The targeted stale-alert repair is CLI-only and idempotent: scratch/reconcile_people_registration_alerts.php. It closes registration alerts only when a fresh pending count is zero.

## Changed files

| File | Change |
| --- | --- |
| pages/admin/users.php | Read-only directory, safe details, scoped metrics, full-database filters and pagination. |
| includes/account_monitoring.php | Shared status counts, trusted eligibility, transactional post-OTP activation, directory read model. |
| pages/admin/account_exceptions.php | Restricted documented exception interface and history. |
| includes/account_security_exceptions.php | Authority checks, protected targets, deduplicated cases, atomic dispositions and audit. |
| includes/ai/MICARegistrationValidator.php | Trusted decisions replace score-based approval; stronger OTP locks/resend/delivery handling. |
| auth/register.php | Server role normalization, persisted validation failure signals, email after commit. |
| auth/register_hub.php | Removes public admin registration invitation. |
| auth/verify_registration.php | Removes bootstrap/student bypasses, supplied-UID targeting and on-screen demo codes; uses shared eligibility. |
| auth/login.php | Password-authenticated OTP resume and real per-user failed-login counters. |
| includes/mail.php | Removes registration OTP disclosure on local-mode transport failure. |
| includes/ai/MICAHubService.php | Shared pending count, actual security signals/duplicate identifiers/open exceptions, updated alert wording. |
| includes/ai/MICAContextService.php | Shared verification count for summary. |
| includes/ai/MICAActionService.php | System-created alerts use NULL actor ID rather than invalid user zero. |
| api/users.php | Retires routine access mutations, preserves restricted provisioning and safe read/profile paths. |
| pages/admin/admin_accounts.php | Keeps provisioning; routes status changes to documented exceptions. |
| components/sidebar.php | People Directory navigation label. |
| setup/initial_admin.php, .env.example | Operator-secret bootstrap restriction; no public privilege grant. |
| database/migration_people_monitoring.sql, setup/migrate_people_monitoring.php | Additive schema and CLI deployment. |
| scratch/test_people_monitoring.php | Isolated database/service workflow suite. |
| scratch/test_people_endpoints.php | Authenticated fixture page/API request harness. |
| scratch/test_mica_hub.php, scratch/test_mica_hub_endpoint.php | Shadow newly monitored sources to keep QA isolated. |
| scratch/audit_people_counts.php, scratch/audit_people_roster.php, scratch/reconcile_people_registration_alerts.php | Read-only audits and the targeted historical-alert repair. |

The working tree already contained extensive changes from earlier tasks. Those were preserved; no existing accounts or integrations were reset.

## Actual test results

**51 isolated MySQL/service checks passed** in test_people_monitoring.php. They cover metric reconciliation; Student/Teacher/Nurse OTP and trusted activation; Student/Teacher QR creation; missing/mismatched identity; no public-admin promotion; five-failure lock, blocked resend and expired OTP; Student/Nurse syntax without eligibility; matched/revoked staff rosters; duplicate username; authorization and deduplication of security exceptions; protected accounts; human identity review; suspension/restoration and credential invalidation; audit records; identifier search; combined filters; delegated scope; 1,021-record pagination; authorized locked-OTP recovery; matching MICA counts; repeated security alert scans; unresolved cases; zero-pending reconciliation; failed mail/cooldown without OTP leakage; and rollback of access changes after audit SQL failure.

**14 page/API request cases passed** using fixture sessions: Primary directory 200; unauthorized co-admin directory 403; Student/Teacher/Nurse directory redirects 302; directory POST 405; malformed filters 400; Primary exception page 200; unauthorized co-admin exceptions 403; legacy approval 410/403; Primary co-admin provisioning 200; co-admin provisioning denial 403; and unconfigured initial provisioning 503. Responses are produced by the actual PHP entry points under a CLI request harness, not a browser/network test. Provisioning initially failed on an invalid hyphenated fixture username; the fixture was corrected to the application's valid username format and rerun successfully.

Regression suites: 19 MICA hub checks, 26 inventory upgrade checks, and the existing co-admin permission suite passed. All changed PHP sources passed syntax checks. Tests use connection-local temporary tables; production account counts remained unchanged. The only intended production-data change was archival of the 9 stale alerts with verification history.

## Verification limits

Browser screenshots, mobile geometry, focus behavior and screen-reader behavior were not visually certified in this session. Real OTP mail delivery and live OpenRouter generation were not invoked by these tests. Trusted enrollment/HR data quality and external scheduler installation remain operator responsibilities. MICA reconciles subsequent security/source changes on a successful explicit or scheduled scan; it does not promise immediate AI processing. Exception history currently shows the latest 50 cases. Primary provisioning retains the existing recorded contact-verification flag; directory wording reports that stored flag rather than claiming every historical account completed this new OTP flow.
