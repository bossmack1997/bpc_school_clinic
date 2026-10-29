# Doctor and MIS simplification — October 29, 2026

Branch: `feat/doctor-mis-workflows-20261029`; baseline `874352e`.

## Audit before changes

The latest request replaces MIS-only Nurse creation with Doctor-led individual
Nurse invitations. Reuse AccountProvisioningService token hashing, transactions,
duplicate checks, activation and password policy. MIS creates Students/Teachers;
MIS retains resend for technical recovery. Doctors receive a separate permission
for individual Nurse creation/resend, never general user/security administration.
Existing credential submission/review/history remains the authorization gate.

Security exceptions are not duplicate logs: they implement evidence-based account
suspension/recovery and staged incident review. Retain them inside Security
Settings, use Account Recovery wording, and retain legacy redirects and audit.
Doctor patients/consultations share a workspace; preserve that implementation.

The read-only local schema snapshot found physician_queue,
account_activation_invitations, account_provisioning_lock and
nurse_credential_submissions absent. Existing consultation patient/author/follow-up
columns and Nurse status columns were present. Missing reviewed migrations explain
unavailable cards; they are not zero counts. This is local XAMPP evidence, not a
Hostinger schema inspection. Validate reviewed prerequisites in isolated tests;
do not automatically modify production schema or school records.

## Intended implementation

1. Service-level role/count restrictions and a Doctor Nurse invitation page linked
   from Manage Nurses. Pending invitations and resend remain separate from clinical
   approval. Nurse setup requires a private initial password before login, using a
   single-use link rather than an emailed temporary password.
2. Doctor Dashboard with four real metrics: Today's Patients, Waiting for Doctor,
   Nurses to Review, Follow-up Visits. Count follow-ups independently of the limited
   display list. Keep failures local to each section and preserve oversight/history.
3. Manage Nurses/Reports navigation, clear MIS recovery wording, no duplicate
   dashboard navigation, responsive verification and invitation forms.
4. Disposable integration and browser tests, small commits, a local PR description.

## Database and rollback

The permission change needs no new schema: invitation created_by already links to
users, including Doctors. Required existing migrations must be reviewed/applied to
staging first: account provisioning, physician queue and Nurse credential correction.
Follow their existing preflight/verification documents. Never drop populated tables
on rollback. Code rollback preserves existing invitations, passwords, reviews and
clinical records but restores the earlier Nurse-provisioning policy; review access.

## Validation

The recovered working tree was preserved on the existing branch. No AGENTS.md
was found in the application source inventory. Existing migrations were retained.

The disposable local HTTP suite passed 422 checks with --doctor-clinical,
--staff-verification, --provisioning, --student-ui, --workflow-simplification,
--global-mica, --exports, --reauthentication and --final-system-audit.
All modified PHP files and the new create_nurse.php passed PHP syntax checks.
The suite copies schema only and creates synthetic records, blocks outbound mail,
and removes its disposable database. Results: scratch/continuation-tests.log.

Additional Doctor invitation HTTP regressions cover page permissions, CSRF,
forged roles, preview confirmation, failed transport, pending clinical status and
replayed preview rejection. Results: scratch/continuation-invitation-tests.log.
The focused --staff-verification --provisioning run passed all 144 checks.
Node syntax for doctor_browser.cjs and git diff --check also passed.

| Requirement | Status | Evidence / remaining action |
| --- | --- | --- |
| A Doctor Nurse management | COMPLETED (local integration) | create_nurse.php, account_provisioning_service.php and nurse_verification.php implement individual invitation plus credential review, requests, rejection, revocation and history. Verify actual email delivery on staging. |
| B MIS account management | COMPLETED (local integration) | provision_accounts.php and service tests cover CSV/XLSX preview, duplicates, creation, results and invitation; public registration returns 410. |
| C Private password setup | COMPLETED (local integration) | activate_account.php and provisioning tests cover token-bound password hashing, expiration, replay and CSRF. Reauthentication/password changes covered by broad suite; external reset delivery needs staging verification. |
| D Doctor Dashboard | COMPLETED (code/local integration) | dashboard.php and doctor_clinical_service.php supply four real metrics, separate follow-up count and per-source failure states. Missing local schema requires existing reviewed migrations. |
| E Nurse access distinction | COMPLETED (local integration) | Activated provisioned Nurses remain pending clinical review; inventory, QR and queue bypass attempts return 403; credentials page remains accessible. |
| F Doctor navigation | COMPLETED (local integration) | sidebar.php exposes all eight requested labels; workflow suite verifies links and retained supporting tools. SOS remains in shared top navigation. |
| G MIS security exception | COMPLETED (code/local integration) | security.php keeps account recovery and incident review with audit history; dashboard wording is simplified. This function is distinct from activity logs. |
| H Responsive UI and browser errors | NEEDS TESTING | HTTP rendering and source checks passed. Current continuation has not run real desktop/tablet/mobile browser layouts or JavaScript runtime checks. |
| Production schema/email | NEEDS TESTING | Local schema lacks existing prerequisites noted above. Review migrations and verify delivery on staging before release; Hostinger was not inspected. |

No production migration, school record deletion, deployment, commit or remote push
was performed during this continuation. Pending release checks are real browser
verification, staging migrations and SMTP delivery. Commit and open the PR after
those checks; preserve the recovered MICA changes and existing clinic workflows.
