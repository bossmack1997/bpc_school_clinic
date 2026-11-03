# Doctor patient records and Nurse credentials investigation

Production status remains unverified. No production database connection, error
code or exact schema definitions were supplied. No configured school database
was migrated or edited. Existing combined workspace and Doctor-led review were
retained; no replacement patient records or clinical grants were created.

## Findings and changes

The Doctor error is the shared PDO failure response, not evidence that patient
history is empty. Local read-only directory, context, consultation and document
probes all pass. A production root cause cannot be identified from that message
alone. Existing parameterized searches support Student/Teacher names, IDs and
type; existing workspace and authorization regressions pass in synthetic data.
The missing local physician queue/provisioning tables are separate dependencies
and do not explain failure of those patient queries.

Nurse credential metadata detection previously classified every PDO error as a
missing migration without logging it. It now logs sanitized operation/SQLSTATE/
driver information and distinguishes missing tables from permission/connection
failures. The page now handles failed state reads with HTTP 503, disabled writes
and Retry, and checks availability before storing an upload. It no longer crashes
on that read or asserts that a migration will solve every database error.

Changed application files: includes/nurse_credentials_service.php and
pages/nurse/credentials.php. Added tests/nurse_credentials_availability.php and
database/diagnose_nurse_credentials_20261104.sql. No authorization rules changed.

## Validation

- 374 isolated HTTP checks passed: Doctor access, Student/Teacher records,
  associations, source failures, unauthorized access, Nurse review decisions,
  corrected resubmission and upload MIME rejection.
- Separate synthetic Nurse credential workflow passed (drafts, uploads,
  validation, submission, duplicate prevention, authorized-account changes,
  retained previous documents, audit and no automatic clinical authorization).
- Three metadata failure cases passed: missing table, denied access, lost
  connection; writes remain blocked and recovery messages differ appropriately.
- Changed PHP syntax and git whitespace checks passed.
- No live browser layout check or Hostinger end-to-end check was performed.

Evidence logs: scratch/credential-repair-tests.log and
scratch/nurse-credential-workflow.log (local, not committed).

## Hostinger verification and migration decision

Run database/diagnose_patient_records_hostinger_20261030.sql individually in
phpMyAdmin for the Doctor query dependencies. Run the new Nurse diagnostic SQL
and database/preflight_nurse_credential_correction.sql with the actual application
database selected. These are exact read-only queries; LIMIT 0/id=0 probes return
no clinical records. Keep results private and record failed probe, SQLSTATE and
numeric driver code. Correlate with authenticated failing requests and sanitized
server logs, including doctor.patient.directory/overview and nurse.credentials.*.

If both credential tables are absent and prerequisites pass, the existing
database/migration_nurse_credential_correction_20261024.sql creates them
additively. No new migration is justified without schema evidence. Existing
tables require exact definition review: IF NOT EXISTS does not repair incompatible
columns or keys. Back up and verify a restore before a separately approved
production migration; preserve submissions and review audit history.

After deployment, verify with authorized synthetic staging accounts: Student and
Teacher search/open history, empty and failed requests, Nurse draft/upload/
submit, Doctor approve/reject/request information/revoke, corrected resubmission,
document ownership, and retained approved documents. Production cannot be marked
fixed until these checks and the reported failing request succeed there.
