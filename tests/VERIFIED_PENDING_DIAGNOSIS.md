# Verified student login: findings and validation

## Exact blocking condition

After password verification, `auth/login.php` calls `reconcileForLogin()` for an email-verified pending account. `finishVerification()` keeps `users.status='pending'` and `registration_status='review'` when trusted eligibility is false. The pending branch then emits the reported message and deliberately does not create a session. No separate routine MIS approval flag is checked.

The earlier patch repaired transaction/retry handling. It did not supply institutional records. Eligibility cannot become true merely by retrying OTP or login when trusted evidence is missing or mismatched.

The local database has the expected account-monitoring columns and 12 roster entries (five students, three teachers, two nurses, one doctor and one admin). It has no email-verified pending accounts. The reported Hostinger account is therefore not present in this local reproduction. No authoritative enrollment import/synchronization integration was found in this checkout. The affected production account's specific failed check cannot be asserted without server diagnostics and access to the authoritative source.

## Corrections

- Registration and activation now use the same deterministic roster identity matcher. Name whitespace, including outer Unicode whitespace, and email padding are normalized consistently. Identifier, role, email ownership, names, eligibility, duplicate claims, risk/rejected assessment and nursing license checks remain enforced.
- Legacy activation queried a nursing-license roster column even for students. Student/teacher identity verification now works when that unrelated column is absent. Nurses still require the column and matching evidence.
- Missing/outdated evidence schema or unmatched records leaves access restricted. OTP ownership can be recorded without granting protected access. Audit/persistence failures still roll back activation and OTP consumption.
- Safe reason codes distinguish missing source, absent identifier, ambiguous identifiers, revoked eligibility, role/name/email/license mismatch, missing profile, duplicate claims and blocked assessments. Login/OTP messages identify the unmet check without disclosing another person's email address or raw database errors. Existing verified pending accounts are checked individually on their next password-authenticated login; there is no bulk repair.

## Production diagnostics

Run `php tools/diagnose_registration.php` on the affected server with its configured database/environment. On Hostinger CLI, use `APP_FORCE_PRODUCTION=1 php tools/diagnose_registration.php` to enforce production configuration checks. Do not paste credentials or `.env` contents into chat.

The command is read-only. It reports required-column presence, roster counts, aggregate failure codes for verified pending accounts, and source SHA-256 hashes normalized for Windows/Linux line endings. It does not output identities, passwords, OTP values, session tokens or patient records. Comparing the hashes with the checked-out release detects stale PHP files. The code cannot diagnose server OPcache state or inspect Hostinger's private PHP logs without server access.

If the result is `roster_missing`, the registrar/HR-authorized enrollment/staff source must supply the matching record. Public registrations must never create their own trusted roster entries. If the result is `source_unavailable`, an authorized operator must reconcile the schema using the existing `database/migration_people_monitoring.sql` and the actual deployed schema. This change does not run migrations, seed example identities into production, or modify any existing real account.

## Tests and limitations

- `php tests/registration_activation.php`: 64 checks passed covering activation, policy, exact failure reasons, normalized trusted identity, legacy schema compatibility, OTP retries/expiry/replay and rollback.
- `php tests/registration_login.php otp_dashboard`: validates a synthetic new student payload against trusted evidence, records unverified/pending state, verifies OTP, asserts active state, executes the real login controller, checks the student dashboard target, and renders the real protected student dashboard with the resulting session. Authorization succeeds and `My Clinic Services` renders. This is a CLI integration test, not a browser redirect or SMTP-delivery test.
- Other `tests/registration_login.php` cases preserve incorrect-password, untrusted, unverified, suspended and rejected blocking.
- Existing people workflow and detail-rendering regression suites continue to pass.
- All fixtures and schema mutations are connection-local temporary tables. No real production accounts or patient records were changed.

Public production check: `https://bpcschoolclinic.org/auth/login.php` returned HTTP 200 with the login form and `Cache-Control: no-store, no-cache, must-revalidate, max-age=0` via `hcdn`. This proves public availability, not the deployed authentication source version. No GitHub Actions, GitLab, cPanel or repository webhook deployment configuration was found. hPanel may manage deployment outside the repository, so automatic deployment remains unknown.

Hostinger database, source hashes, private error logs, OPcache/deployment synchronization and the affected user's successful login remain NOT VERIFIED without production access. Actual SMTP inbox delivery and browser-level redirect following are also not verified. The application changes and diagnostic command are independently reviewable; the production incident is not declared resolved.

Changed application files: `includes/institutional_eligibility.php`, `includes/account_monitoring.php`, `includes/ai/MICARegistrationValidator.php`, `auth/login.php`, `auth/verify_registration.php`. Diagnostics: `tools/diagnose_registration.php`. Regression changes: `tests/registration_activation.php`, `tests/registration_login.php`, and this report.
