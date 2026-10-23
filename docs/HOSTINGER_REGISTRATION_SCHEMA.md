# Hostinger registration schema repair

Production migration status: **NOT EXECUTED by this change**.

## Cause and required schema

Production database `u962995739_bpc_clinic` lacks `users.email_verified_at` and `users.self_service_only`, as confirmed by the database owner. Registration intentionally returns `503 schema_update_required` rather than omit the permission boundary. A GitHub push updates source code only; it cannot apply production DDL.

| Table | Column | Definition | Existing rows after adding | New index |
| --- | --- | --- | --- | --- |
| users | email_verified_at | DATETIME NULL DEFAULT NULL | NULL; no fabricated verification date | None |
| users | self_service_only | TINYINT(1) NOT NULL DEFAULT 0 | 0; existing scope retained | None |

Public registration explicitly inserts `self_service_only=1`. Successful OTP sets `email_verified_at` and activates the basic account. Basic Nurse sessions remain restricted from clinical pages/APIs. Existing accounts/passwords, role/status values and existing timestamps/scope values are not rewritten. No consultations, uploaded files, patient tables or registration fields are changed. These columns are read with existing user lookups; no extra index or uniqueness constraint is required.

The original SQL uses MariaDB-specific `ADD COLUMN IF NOT EXISTS`. The portable phpMyAdmin file uses INFORMATION_SCHEMA checks and prepared ALTER statements, supporting both MySQL and MariaDB without stored procedures or DELIMITER commands. Existing columns are left untouched; incompatible existing definitions are flagged by the separate verification query rather than silently rewritten. Only one operator should run the migration at a time.

## Exact phpMyAdmin procedure

1. In Hostinger hPanel, open Databases / phpMyAdmin for **u962995739_bpc_clinic**. In phpMyAdmin select that database in the left sidebar. Confirm its name before executing anything.
2. Create a backup: click **Export**, choose **Custom**, select **all tables**, choose **SQL**, include **structure and data**, and download the export to private storage. Confirm the download completed and includes users, consultations and patient/medical tables. Do not commit or upload the backup publicly. Separately retain the existing uploaded-document directories through the normal hosting file backup, since SQL exports do not contain uploaded files.
3. Open the **SQL** tab and paste the contents of `database/verify_basic_otp_activation_hostinger.sql`. Run it. The first result must show `selected_database = u962995739_bpc_clinic`. The two column rows will currently show MISSING. If the database name is wrong, stop and select the intended database. If a column is incompatible rather than missing, review that definition before proceeding.
4. Open **Import**, select **database/migration_basic_otp_activation_hostinger.sql**, keep format **SQL**, and execute the import. Alternatively paste the complete file into the SQL tab and execute it. The file explicitly selects `u962995739_bpc_clinic`; do not rename its target when using it in production. The database user needs ALTER permission. Do not import the PHP migration file through phpMyAdmin.
5. Run the separate **database/verify_basic_otp_activation_hostinger.sql** again through the SQL tab. Require exactly two **PASS** column rows, the correct database name, DATETIME/nullable/default NULL for email_verified_at, and TINYINT/not nullable/default 0 for self_service_only. Stop and report any SQL error or MISSING/INCOMPATIBLE result. Do not bypass the registration schema check.
6. The import may be repeated: existing columns are skipped, including a deployment where only one column was added successfully. MySQL DDL may implicitly commit and briefly lock the table; execute during a quiet period. Do not rely on wrapping the file in a transaction to undo DDL.
7. Confirm production is serving the verified application revision. Refresh `https://bpcschoolclinic.org/auth/register.php?role=student`. Using isolated test accounts and no real patient data, complete Student, Teacher and Nurse registration, receive actual email OTP, verify it, sign out and sign back in. Student/Teacher must reach their dashboards; Nurse must reach its basic account and remain denied clinical access until separately authorized. Invalid usernames must show field validation, not schema maintenance. Invalid/expired OTP and duplicate username/email must remain blocked.
8. Only after the database verification and real end-to-end production tests succeed should registration be reported fixed. Actual SMTP delivery and production activation are not established by the local tests.

## Read-only verification

Use the separate SQL file in step 3/5. It uses SELECT only, reports the selected database/version and both column definitions, and does not disclose account or patient information. It returns MISSING or INCOMPATIBLE where appropriate; it does not repair anything.

## Rollback / partial completion

The migration adds columns only and issues no UPDATE, DELETE, DROP, TRUNCATE, table reset or record restoration. It does not touch passwords or files. If code rollback is needed, restore the preceding application revision and retain both additive columns and their values. Do not drop populated columns or restore an old database backup over newer patient data. If an ALTER fails halfway, correct its reported permission/schema problem and rerun this idempotent file. Preserve the private backup for recovery through the authorized hosting process; this change does not require a destructive rollback script.

## Validation and publication

The SQL is tested directly against disposable local databases with neither column, either column alone, and both columns. Tests check repeat imports, existing synthetic passwords/statuses/doc paths/scope/timestamps, verification failures and PHP/SQL interoperability. PHP migration entry points share the same helper; the database entry point now reports incomplete execution as failure rather than a successful exit.

Executed locally: 56 direct SQL migration checks, 99 HTTP checks with the legacy-schema scenario, and 70 activation/security assertions passed. PHP lint and Git whitespace checks passed. These tests use the local MariaDB engine; a separate MySQL server was not available for an executed cross-engine run.

No production SQL was executed. No production database backup, migration or actual inbox/registration test is claimed. Publication status and the commit SHA are reported separately in the accompanying response.
