# Database migration readiness audit ? October 30, 2026

## Decision and evidence boundaries

**Production: no migration is currently demonstrated to be necessary.** The user
confirmed that `u962995739_bpc_clinic` has all three tables, all 22 inspected
columns, three primary keys, three unique constraints and seven foreign keys,
with existing referenced parent tables. This supersedes earlier reports that
production might lack these tables. Existence/counts are satisfied; exact schema
compatibility is **pending**, not failed or passed. The promised SHOW CREATE TABLE
results have not yet been supplied. No production connection or sanitized
production error output is available in this session.

**Local XAMPP: all three tables remain absent.** Read-only checks against
`bpc_clinic_system` returned SQLSTATE `42S02`, driver `1146` for each. Prerequisite
metadata passed the existing preflight, which exited 2 for missing deployment
tables. No migration was applied to that database. Latest instruction prohibits
migration execution; the plan below is for future separately authorized work.

No production database reads were executed from this session, no production
records/schema were changed, and no application code was deployed. No Git commit
or push was requested/performed for this continuation. All existing clinical and
audit rows were preserved.

## Exact repository contract

Sources:
- `database/migration_physician_queue_20261028.sql`
- `database/migration_physician_queue_hostinger_20261030.sql`
- `database/migration_account_provisioning_20261028.sql`

The guarded Hostinger physician CREATE TABLE body and original physician CREATE
TABLE body were compared after unescaping doubled SQL quotes and normalizing
whitespace: **identical**. They are alternatives, not sequential migrations.
The Hostinger version additionally checks prerequisites before executing DDL.
`IF NOT EXISTS` does not validate or repair an incompatible existing definition.

All INTs below are signed INT, not BIGINT/UNSIGNED. Integer display width such as
`int(11)` does not itself change the contract. For DATETIME, CURRENT_TIMESTAMP
versus current_timestamp() spelling is equivalent. Distinguish SQL NULL from a
literal string default. Auto-increment counter values and generated FK names
need not match.

| Table / column | Exact definition (keys below) |
| --- | --- |
| physician_queue.id | INT NOT NULL AUTO_INCREMENT |
| physician_queue.queue_id | INT NOT NULL; no explicit default |
| physician_queue.patient_user_id | INT NOT NULL; no explicit default |
| physician_queue.referred_by | INT NOT NULL; no explicit default |
| physician_queue.assigned_doctor_id | INT NULL; default NULL |
| physician_queue.consultation_id | INT NULL; default NULL |
| physician_queue.priority | ENUM('normal','urgent','emergency') NOT NULL DEFAULT 'normal' |
| physician_queue.reason | VARCHAR(1000) NOT NULL; no explicit default |
| physician_queue.status | ENUM('awaiting','in_progress','completed') NOT NULL DEFAULT 'awaiting' |
| physician_queue.record_version | INT NOT NULL DEFAULT 0 |
| physician_queue.created_at | DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP |
| physician_queue.started_at | DATETIME NULL; default NULL |
| physician_queue.completed_at | DATETIME NULL; default NULL |
| account_activation_invitations.id | INT NOT NULL AUTO_INCREMENT |
| account_activation_invitations.user_id | INT NOT NULL; no explicit default |
| account_activation_invitations.token_hash | CHAR(64) NOT NULL; no explicit default |
| account_activation_invitations.created_by | INT NOT NULL; no explicit default |
| account_activation_invitations.expires_at | DATETIME NOT NULL; no explicit default |
| account_activation_invitations.used_at | DATETIME NULL; default NULL |
| account_activation_invitations.delivery_status | ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending' |
| account_activation_invitations.created_at | DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP |
| account_provisioning_lock.id | INT NOT NULL; no AUTO_INCREMENT or explicit default |

Expected keys (full columns, BTREE; column order matters):

| Table | Required key |
| --- | --- |
| physician_queue | PRIMARY(id) |
| physician_queue | UNIQUE uq_physician_queue_ticket(queue_id) |
| physician_queue | UNIQUE uq_physician_queue_consultation(consultation_id) |
| physician_queue | INDEX idx_physician_queue_status(status,priority,created_at) |
| account_activation_invitations | PRIMARY(id) |
| account_activation_invitations | UNIQUE uq_account_activation_hash(token_hash) |
| account_activation_invitations | INDEX idx_account_activation_user(user_id,created_at) |
| account_provisioning_lock | PRIMARY(id) |

The user-confirmed PK/unique counts match. The two explicit nonunique composite
indexes have **not** been confirmed; neither is proven missing. Same columns in
another order, a prefix index, or UNIQUE(queue_id,another_column) do not satisfy
the corresponding contract. Equivalent key definitions with different names are
functionally compatible but should be recorded as naming differences. InnoDB may
automatically create extra indexes for foreign keys; these are not extra required
migration indexes. Extra UNIQUE constraints can restrict valid writes and need
review; extra nonunique indexes are usually a performance/storage difference.

Foreign keys (all reference the same application schema):

| Child | Parent |
| --- | --- |
| physician_queue.queue_id | queue_numbers.id |
| physician_queue.patient_user_id | users.id |
| physician_queue.referred_by | users.id |
| physician_queue.assigned_doctor_id | users.id |
| physician_queue.consultation_id | consultations.id |
| account_activation_invitations.user_id | users.id |
| account_activation_invitations.created_by | users.id |

Every FK explicitly uses ON DELETE RESTRICT. ON UPDATE is omitted in the migration
and has restrictive default semantics; RESTRICT/NO ACTION are compatible for
InnoDB here. CASCADE/SET NULL actions require review and are not the exact
contract. Existence of a parent table alone does not confirm the intended parent
column/schema, matching type/signedness, indexed parent key, or absence of orphan
rows introduced while FK checks were disabled.

All three tables require InnoDB BASE TABLEs. Physician queue and invitations
explicitly use utf8mb4 / utf8mb4_unicode_ci. The lock table specifies InnoDB only;
its charset/collation is inherited from the database and contains no text columns.
Do not report its differing inherited collation as a clinical incompatibility.
Text collation differences on other tables, particularly unique token_hash,
should be documented and reviewed, not automatically altered on populated tables.
No generated columns, automatic ON UPDATE timestamp behavior or triggers are
introduced by these migrations. Unexpected additions require behavior review.
The lock table must contain row id=1; table/PK existence alone does not provide
the provisioning serialization lock.

## Read-only production evidence package

Prepared `database/audit_critical_schema_hostinger_20261030.sql`:
metadata for engines/collations, all column definitions/defaults/extra attributes,
individual ordered index components including prefix length, exact FK targets and
DELETE/UPDATE rules, parent id definitions, three SHOW CREATE TABLE statements,
and count of the required lock row. It explicitly qualifies the intended schema;
it does not switch databases, run DDL, select tokens or return patient rows.
Unexpected triggers or extra constraints should also be reviewed from the DDL
and information_schema metadata if present.

Prepared `database/diagnose_patient_records_hostinger_20261030.sql`:
separate named LIMIT 0 probes for patient directory, patient context,
consultation history and document metadata. Run statements individually and
retain only failed probe name + SQLSTATE + numeric driver code. Successful
LIMIT 0 checks verify dependencies/permissions, not actual patient associations,
query performance or successful application authentication.

The exact DDL and production query failures remain needed to classify each
unknown requirement above. No speculative repair DDL has been prepared.

## Patient Records investigation

The three critical migration tables are not direct dependencies of the patient
directory/context/history/document queries. Their existence cannot prove Patient
Records works, and their local absence is not evidence of this production error's
cause. Both Student and Teacher joins must resolve even for a Student-only search.

Read-only local results:
- Patient directory, context, consultations and medical_records dependencies pass.
- Native PDO prepares are enabled.
- One Student account lacks a linked Student profile; no identity was printed or
  repaired. Teacher profile missing-count is zero. This is a local integrity
  finding, not a confirmed production finding or permission to manufacture data.
- No active local Doctor profile was available, so the broader CLI diagnostic
  explicitly skipped service-level patient checks instead of granting authority.
- Nurse review metadata is ready locally.

Found and repaired a diagnostic-only defect in
`tools/diagnose_doctor_clinical.php`: overview now returns null counts plus the
unavailable source list when documents or consultations fail. Previously
count(null) could terminate the diagnostic after the shared service's new
partial-source behavior. No clinical application behavior or record is changed.

For production, use the prepared SQL without deploying code. If existing CLI
access and a separately authorized diagnostic file are available, run
`tools/preflight_doctor_workspace.php` and the corrected
`tools/diagnose_doctor_clinical.php` with private writable session/log paths.
The latter uses a read-only transaction and rolls back, printing aggregate
readiness/counts, never patient content. Logs retain operation + SQLSTATE + numeric
driver code. Keep output private; do not share .env, DSNs, credentials, tokens,
patient names/IDs or raw exception messages. Existing aggregate profile integrity
checks may distinguish missing profile associations from schema failure.

If SQL probes pass, correlate a controlled authorized Doctor request with existing
server logs (operation `doctor.patient.directory`, `.overview`, `.consultations`,
`.documents`), deployed source revision, PHP/PDO behavior and selected database.
Verify Doctor activation/profile/email authority without changing grants. A 403,
404 missing profile, unavailable-source 503 and a genuine empty history require
different remedies. SQLSTATE HY093 can indicate parameter binding failure;
42S22/1054 indicates an unknown column; 42S02/1146 indicates missing table;
42000/1142 indicates denied table access. These are diagnostic interpretations,
not observed production errors. No production SQLSTATE/driver is yet available.

## Safe local-only migration plan (not executed)

1. Stop concurrent local clinical writes for backup/validation; confirm loopback
   host, APP_ENV=local and exact selected database `bpc_clinic_system`. Reject
   Hostinger database names/remote connections. Record server version and git SHA.
2. Use XAMPP mysqldump with a private option file (never inline passwords) to
   capture schema, data and triggers into a private directory outside the web
   root. Include any existing routines/events only after inventorying them; use
   matching privileges/options. For all-InnoDB tables use a consistent single
   transaction; otherwise use an appropriate maintenance/locking strategy.
   Hash the backup and check process success; a nonempty file is not proof.
3. Restore that backup to a separately named local isolated restore database,
   disconnected from the application and external mail/AI. Compare table counts,
   exact clinical/audit rows and relationships to the backup snapshot. Protect
   this copy as clinical data. A successful disposable schema test is not a
   verified full-data restore. Neither backup nor restore has been performed here.
4. Separately use the existing schema-only disposable HTTP test fixture with
   synthetic records to test migration refusal on incomplete prerequisites,
   successful creation, rerun preservation, queue claim/completion and account
   activation. Never point it at production. Existing tests do this isolation.
5. Review missing prerequisites individually before any migration. The guarded
   queue script requires nine InnoDB source tables and signed INT primary ids on
   users/queue_numbers/consultations, plus its complete required column list.
   Upstream clinical fields include clinician_user_id and record_version from
   migration_administrative_profiles.sql, Student/Teacher associations, vitals,
   activity logs and account-profile metadata. Do not replay historical whole
   migrations blindly: some have nonportable IF NOT EXISTS syntax or unrelated
   changes. Local consultations.nurse_id is already nullable, visit_type accepts
   walk-in, and clinician_user_id/record_version are present. Those attributes
   matter because physician saves have no Nurse author. Production remains unknown.
6. Once separately authorized and backup restore passes: execute the physician
   preflight, inspect all results, then guarded physician migration, then its
   verification SQL. Use the guarded script instead of the older unguarded file.
   There is no cross-dependency between the two new feature migrations: account
   provisioning requires existing users.id; physician queue additionally requires
   queue_numbers.id and consultations.id plus runtime prerequisites. Then run the
   account provisioning migration once and inspect results before rerunning it
   in staging; it creates two tables and inserts only lock id=1. It is not guarded
   as a multi-statement unit: partial DDL can remain if a later statement fails.
7. Verify the exact contract above, both composite indexes, all FK actions,
   lock row=1, no new orphans/duplicate links, and lifecycle consistency. Re-run
   preflight (require exit 0) and workspace probes. Compare every pre-existing
   clinical/audit record with the maintenance snapshot; use immutable row-level
   comparison as well as counts. Exercise synthetic workflows only in staging.
8. MySQL/MariaDB DDL commits independently. On failure stop and inspect; do not
   assume SQL ROLLBACK undoes CREATE TABLE. Never DROP/TRUNCATE clinical, audit or
   populated handoff tables as rollback. Reconcile any writes before an authorized
   restore. Production rollout remains a separate decision after exact evidence;
   if production matches, there is no reason to rerun CREATE TABLE there.

## Validation and changed files

Before the latest no-migrations instruction arrived, the already-started isolated
regression run completed: **350 checks passed, PHP exit 0**, including six new
partial-source diagnostic assertions. Its migrations ran only in disposable
synthetic databases, which the suite cleaned up; neither configured local school
schema nor production was migrated. No subsequent migration tests were launched.
Evidence: `scratch/database-readiness-20261030-tests.log`.

Both changed PHP files passed syntax checks. Git whitespace checks passed.
Read-only local validation executed all six metadata SELECTs and all five Patient
Records probe statements with the schema qualifier changed to the exact local
schema, inside a read-only transaction (exit 0). Three SHOW CREATE statements and
the lock-row query were skipped because local tables are absent; their Hostinger
execution remains pending. Evidence: `scratch/database-readiness-sql-validation-20261030.log`.
No PHP warnings remained after fixing the temporary harness's UTF-8 BOM.

Changes left uncommitted for review:
- this report;
- database/audit_critical_schema_hostinger_20261030.sql;
- database/diagnose_patient_records_hostinger_20261030.sql;
- tools/diagnose_doctor_clinical.php;
- tests/doctor_three_page_checks.php.

Production verification cannot be concluded until schema DDL and sanitized error
evidence arrive. Confirmed production table existence is not a production
migration requirement. Local missing tables are a distinct, reproducible setup
requirement, with execution deferred under the user's latest instruction.
