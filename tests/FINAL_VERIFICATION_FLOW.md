# Final account verification revision ? 23 October 2026

Student, Teacher and Nurse use their existing complete registration forms, then email OTP, automatic basic activation and sign-in. Nurse basic accounts open `pages/nurse/account.php`; protected clinical pages redirect there and clinical APIs return 403. Existing authorized nurses keep their clinical scope. No routine MIS approval, enrollment matching or HR matching is required for basic activation.

## Preserved registration fields

All input/select/textarea controls, names, order and required attributes were compared against the preceding Git revision and match exactly. Role-specific JavaScript required flags and document labels also match. Photo & Document Verification remains present. The baseline already makes uploads optional; this revision does not alter that policy.

- Common: personal names, gender, date of birth, contact, email, username, password/confirmation, certification, profile picture and identity upload.
- Student: student number, course, year, section, emergency contact, blood type and allergies.
- Teacher: employee ID, department, advisory selections, emergency contact, blood type and allergies.
- Nurse: employee ID, PRC license, position, emergency contact and existing professional/personal sections.

Existing field validation, persistence statements and stored documents are preserved. Synthetic HTTP uploads verify profile persistence for all roles and identity-document mapping for Student/Nurse. A pre-existing limitation remains: Teacher identity uploads are saved on disk but the Teacher insert has no document-path mapping, and the existing teachers schema has no such column. This revision does not invent a new field or migrate that storage model.

## Registration error investigation

The preceding repair report (`REGISTRATION_REPAIR_REPORT.md`) documents reproduction of the generic initiation error as MySQL 1054 / SQLSTATE 42S22 when `users.self_service_only` was absent. OTP also needs `email_verified_at`. The current local schema has both. This revision retested the legacy schema guard, then the existing portable additive migration and its idempotence in a disposable database. Missing schema returns a clear update message without committing an account. Mail transport failure preserves the pending registration and reports failure with resend available. Hostinger private logs/schema are unavailable, so the live root cause remains unconfirmed.

## Verification and authorization

Nurses now share basic activation with Students/Teachers. Roster checks no longer gate Nurse basic registration. `self_service_only=1` is assigned on the server, and email verification never clears it. Role/status values supplied by the client cannot grant privilege. Login, restored sessions and dashboard routing accept basic nurses; protected endpoints enforce scope. The existing evidence-backed Security Center authorization mechanism remains available to trusted authorized staff and now supports granting clinical scope to an already-active basic Nurse, with audit history and credential-version invalidation. This is separate from email verification and basic login.

People Management shows active basic nurses as active and filters clinical-restricted nurses separately. Rejected/suspended/inactive accounts are not automatically reactivated. No public Co-Admin registration was introduced. Existing MIS-only invitations remain required for Doctor accounts.

## Validation

- 70 activation/security checks, eight actual login-controller scenarios, People Management rendering and 18 Doctor invitation service checks passed.
- Disposable HTTP database: Student/Teacher/Nurse registration, uploads, incorrect/expired OTP, activation, sessions, dashboards, Nurse basic login and clinical denial, authorized clinical provisioning, duplicate/CSRF validation, resend failure/cooldown, recovery and Doctor invitation password setup/login/dashboard.
- Password reset preserves role, blocked account status and scope, and consumes its challenge.
- Local schema compatibility and portable migration idempotence tested without changing permanent records.
- PHP lint, registration inline JavaScript syntax and Git whitespace review.
- 87 HTTP checks and 41 real Chrome checks at 320?1440px, password visibility controls and reduced-height scrolling passed. The separate legacy-schema run passed 84 checks before upload/browser coverage was added.

SMTP delivery uses isolated fake/closed-loopback transports in tests. Actual inbox delivery and Hostinger deployment are unverified. No production accounts, patient data or schema were modified. No new database migration is introduced by this revision. Existing deployments still need the prior basic-OTP migration if its columns are absent.

## Changed files

`auth/register.php`, `auth/login.php`, `includes/ai/MICARegistrationValidator.php`, `includes/account_monitoring.php`, `includes/account_security_exceptions.php`, `includes/auth.php`, `pages/nurse/account.php`, `pages/admin/users.php`, `api/patients.php`, `api/ai_assistant.php`, `api/mica_tts.php`, `tools/diagnose_registration.php`, `tests/registration_activation.php`, `tests/basic_registration_http.php`, and this report.

Changes are committed locally after verification. No push or deployment is performed by this revision; production status is not claimed.
