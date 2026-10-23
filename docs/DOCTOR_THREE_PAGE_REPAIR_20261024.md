# Doctor clinical pages: diagnosis, repairs and Hostinger deployment

Branch: `fix/doctor-clinical-pages-20261024`. Starting commit: `74bfc8b`.
The initial working tree was clean. The earlier global MICA work was already
committed and was preserved. No commit, merge, push or deployment was performed.

## What is confirmed, and what remains unverified

The production symptoms and missing review schema were supplied by the user.
Hostinger logs, deployed commit and live schema were not accessed in this pass.
The user will reproduce the patient failure after diagnostic logging is deployed.
This report does **not** claim that the production patient-directory root cause has
been identified or that the three production pages are fully repaired.

| Area | Evidence and resulting change |
| --- | --- |
| Staff verification / pending Nurse count | Missing review table/columns explain the reported unavailable review workflow. No production migration was run. The runtime readiness check also had a confirmed defect: it checked only the status column and table existence. It now checks all four Nurse review columns, `users.credential_version` and every consumed audit-table column, and fails closed on partial installations. |
| Today's active queue | Both Doctor service queries lacked an upper date bound and counted future active queue rows. Both now restrict rows to today's half-open date interval. Synthetic today/future rows reproduce and verify the fix. |
| Patient directory | The current query has five positional placeholders and supplies five values. Current PHP configuration disables PDO emulated prepares. Both patient types and combined filters pass native-prepare tests. Reusing a named placeholder reproduces `HY093` in isolation, but this is not proof that production executes such a query. No speculative query rewrite or schema alteration was made. |
| Patient diagnostics | Controller logging formerly recorded only SQLSTATE. Directory, overview and save failures now record a fixed operation identifier, SQLSTATE and numeric driver code without exception messages, SQL, bound values, patient details or credentials. Save database failures return 503 rather than a validation 422. |
| Dashboard diagnosis | Pending-review schema failures now log safely and show an actionable setup notice while other available sources remain visible. Other query failures remain separate from real zero values. |

The local read-only check found the review schema present and no active Doctor
profile, so local service checks against school accounts were skipped. It also
found one Student account without an associated Student profile and zero such
Teacher accounts. No profile was created or altered. These local observations do
not describe Hostinger. Full service testing used disposable synthetic fixtures.

## Source map

| Dashboard value/list | Source and meaning |
| --- | --- |
| Consultations today | `consultations.consultation_date` in `[CURDATE(), CURDATE()+1 DAY)`; encounters, not unique people |
| Today's active queue | `queue_numbers.created_at` in the same interval, status waiting/called/serving; not a physician-referral count |
| Pending Nurse verification | `NurseVerificationService::summaryCounts()` over explicit pending clinical statuses; unavailable until review schema is ready |
| Follow-ups | Up to ten consultation follow-up dates from today onward; Student/Teacher profile joins resolve `users.id`; no invented completion state |
| Nurse referrals | Up to ten active existing support referrals created by Nurses and explicitly addressed to Doctor/Physician |
| Emergency alerts | Up to ten nonterminal SOS records; the bounded list is not represented as a complete incident total |
| Medicine concerns | Existing inventory service's usable-stock calculation and configured thresholds, bounded to ten |
| Clinical activity | Up to ten consultation/clinical audit events, without exposing stored clinical notes |

`patients.php` remains a compatibility alias for `consultations.php`.
`patients()` searches user accounts joined by `students.user_id` or
`teachers.user_id`. `patient()` validates the selected account has the matching
profile. `records()` scopes encounters to that profile's ID; `overview()` scopes
documents likewise. No patient/profile association, Nurse-authored consultation
or existing document was rewritten. Existing physician authorship, optimistic
record version, CSRF, transaction and follow-up rules remain.

## Safe production diagnostic patch

The smallest patient-controller diagnostic deployment consists of:

1. New `includes/clinical_diagnostics.php`.
2. Modified `pages/doctor/consultations.php`, which explicitly loads that helper.

Deploy the helper first, then the controller, after normal staging review. This
pair does not require a database migration or the remaining service changes.
The alias `pages/doctor/patients.php` remains unchanged. Preserve the actual target
versions before replacement, and verify target differences before applying the
controller because the deployed commit has not been established.

Reproduce Doctor → Patient & Consultation Records once without selecting a patient,
then repeat with the failing search/filter or selected patient, if applicable.
Look in the server PHP error log for a line such as:

```text
Clinical database failure operation=doctor.patient.directory sqlstate=HY093 driver=0
```

The example is illustrative, not a captured Hostinger error. The corresponding
operations are `doctor.patient.directory`, `doctor.patient.overview` and
`doctor.patient.save`. `doctor.page.unhandled` covers otherwise uncaught page PDO
errors when the full patch is installed. Dashboard operations identify the source
key, for example `doctor.dashboard.followups`.

Provide only the operation, SQLSTATE and numeric driver code, plus the verified
deployed revision. Do not send raw exception text, SQL, request bodies, patient
screenshots, parameter values, credentials or full logs. SQLSTATE `HY093` would
direct investigation toward placeholders in the actual deployed query; `42S22`
with driver `1054` toward a referenced column. Other codes need their own diagnosis.
Do not enable emulated prepares globally as a workaround.

An optional CLI-only read-only checker is supplied:

```text
php tools/diagnose_doctor_clinical.php
```

It uses the application's existing environment configuration, starts a read-only
transaction, returns aggregate counts/readiness flags, and rolls back. It probes
Student/Teacher directories and one associated overview per type only when an
active Doctor profile exists. It never manufactures authority, prints clinical
rows, changes status or applies DDL. Do not make a web-accessible diagnostic route;
the file itself rejects HTTP before loading configuration. Aggregated output is
still internal operational information.

## Migration readiness and mandatory approval gate

Reviewed existing scripts, unchanged in this pass:

- `database/preflight_nurse_clinical_authorization_hostinger.sql`
- `database/migration_nurse_clinical_authorization_hostinger.sql`
- `database/verify_nurse_clinical_authorization_hostinger.sql`

The preflight is read-only and works when the review schema is missing. The guarded
migration checks compatibility, matches actor ID signedness, uses InnoDB, creates
the audit primary key and indexes on Nurse/Doctor references, and uses restrictive
delete/update foreign keys to retain review history. It blocks unsupported types,
orphans, duplicate profiles, unexpected FK shapes and custom structures requiring
review. Known audit cascades can be repaired without deleting history. Statements
recheck prerequisites after DDL, and completion is checked rather than assumed.

There is no `UPDATE`/`DELETE` of application records in the migration. Existing
explicit authorization statuses are retained. Newly added status columns default
to **pending**: installing this schema can restrict previously operational Nurses
until a Doctor reviews them. This access impact must be reviewed before approval;
never bulk-authorize Nurses or infer clinical authority from MIS identity approval.
Schema readiness alone does not approve credentials or clinical rollout.

Hostinger steps, in order:

1. Verify the actual domain/document root, target application revision, selected
   database, PHP version and database version. Record file hashes if no Git checkout
   exists. Confirm the target files correspond to the reviewed patch.
2. Take protected application/database backups and prove restoration in staging.
   Restrict backup access; do not export patient data into chat or the repository.
3. Run the exact preflight script in the explicitly selected database using the
   hosting SQL console or an approved database client. Save all result sets
   privately. Any BLOCK/custom-review result is a stop condition; inspect every
   result, including orphan/duplicate counts and custom constraints/triggers.
4. Review account-state impact, Nurse coverage, existing clinical statuses,
   contradictory status/scope pairs, required privileges and a maintenance window.
5. Obtain **explicit approval for production DDL and its authorization impact**.
   This pass has neither executed nor approved that step.
6. Only after approval, execute the reviewed migration as a whole and stop on any
   SQL error. Do not use “continue on error.” MySQL/MariaDB DDL can auto-commit;
   a transaction wrapper is not a rollback plan. Require `SCHEMA_COMPLETE` output.
7. Run the exact post-verification script. Require `PASS_SCHEMA_ONLY`, FK checks
   enabled, matching ID types, expected indexes/FKs, zero audit orphans/duplicate
   profile groups, and preserved record totals/status snapshots. Review every
   reported status/scope contradiction without automatically reconciling it.
8. Deploy the application patch as one staged release, preserving environment
   secrets. Refresh opcode caches if applicable. Verify both patient types,
   history/documents, physician-only encounter revisions, follow-up dates,
   dashboard source counts, review lifecycle and MIS/pending-Nurse denials.
   A real Doctor must verify credentials and appointment authorization.

Rollback the application using its saved pre-release files. Do not automatically
drop the review table/columns or erase review/audit records. A failed or partial
DDL deployment requires the reviewed recovery/restore procedure; resolve partial
schema in staging and rerun the guarded script only after approval. Verify clinical
access controls before reopening operations after rollback.

## Modified and new files

Modified: `includes/doctor_clinical_service.php`, `includes/doctor_page.php`,
`includes/nurse_verification_service.php`, `pages/doctor/consultations.php`,
`pages/doctor/dashboard.php`, `pages/doctor/nurse_verification.php`, and
`tests/mis_admin_http.php` (native prepares for direct fixture calls and a test hook).

New: `includes/clinical_diagnostics.php`, `tools/diagnose_doctor_clinical.php`,
`tests/doctor_three_page_checks.php`, and this report. No SQL scripts, QR scanner,
authentication permission framework or production records were changed.

## Verification status

Verified on PHP **8.2.12**:

- `php tests/mis_admin_http.php --doctor-clinical --staff-verification --doctor-three-pages`:
  **167 checks passed**. Covers both patient types, native prepared search,
  physician saves/follow-ups, Nurse record ownership, missing-source recovery,
  partial review schema, review lifecycle/session restrictions, privacy of
  diagnostic logs and the read-only diagnostic subprocess.
  Log: `scratch/doctor-three-security-final.log`.
- `php tests/nurse_clinical_authorization_sql.php`: **157 checks passed** against
  the exact three migration/preflight/post-verification scripts. Includes signed
  and unsigned IDs, reruns, preexisting review history, restrictive audit FKs,
  incompatible types, partial audit structures, orphans, duplicate profiles,
  custom checks/triggers and continued-DDL-failure recovery. No application or
  authorization records in the school database were changed.
  Log: `scratch/doctor-three-migration.log`.
- `php tests/basic_registration_http.php`: **108 checks passed**.
  Log: `scratch/doctor-three-registration.log`.
- All **10 changed/new PHP files** passed syntax checks; `git diff --check` passed.
- Final run with `--doctor-browser`: **169 HTTP/integration checks and 227 browser
  assertions passed**, including the two browser-suite wrapper checks. Browser
  widths: 320/360/375/390/430/768/1024/1440. All three target pages, selected patient
  history/draft interaction, mobile navigation, review dialogs, viewport fit,
  loading recovery and uncaught JavaScript checks passed.
  Log: `scratch/doctor-three-verified.log`.
  Screenshots of the 320-pixel command center and review modal were visually
  inspected. UTF-8 source/browser text displayed correctly; no speculative
  re-encoding or unrelated redesign was needed.

Tests use schema-only disposable databases and synthetic records. Production
patient search, production metrics, Hostinger migration permissions and deployment
remain unverified pending the target's sanitized diagnostics and explicit approval.
