# Phase 1 Hostinger deployment readiness

Current follow-up: [2026-10-23 invitation repair and fresh local tests](DOCTOR_INVITATION_REPAIR_2026-10-23.md). This readiness review is historical; the refreshed manifest now includes **14 runtime files**, including `includes/doctor_invitation_response.php`. Follow the repair report for current local evidence and GitHub status; production restrictions below remain in effect.

Review date: 2026-10-23 (Asia/Manila). **Current decision: NO-GO for production execution or deployment.** This is a static source/SQL review and an operator plan. No SQL was executed, including local SQL tests, during this readiness request. No production account, server, mailbox or credential store was accessed. No push or deployment occurred. No application or migration SQL files were changed by this readiness review.

The recorded production target is **u962995739_bpc_clinic** for **https://bpcschoolclinic.org**, from the owner-confirmed registration-schema document. Its current live binding is **not verified**. The earlier Phase 1 local results remain historical evidence: 40 signed-ID checks, 40 unsigned-ID checks, 47 HTTP checks, 63 browser assertions and 107 broader HTTP regression checks, plus loopback SMTP capture/failure. They were not rerun here because this request prohibits SQL execution before approval.

## 1. Final SQL review

| File | Static review | Operator meaning |
| --- | --- | --- |
| database/preflight_doctor_invitations_hostinger.sql | 8 SELECT statements; metadata only; no new-table dependency | Read-only before migration, including when admin_invitations is absent |
| database/migration_doctor_invitations_hostinger.sql | 14 top-level SET/SELECT/PREPARE/EXECUTE/DEALLOCATE statements; conditional CREATE TABLE IF NOT EXISTS and ALTER TABLE ADD COLUMN inside prepared strings | Creates the invitation table if absent and adds nullable official_designation if absent; no account/patient DML |
| database/verify_doctor_invitations_hostinger.sql | 11 SELECT/SHOW statements | Post-migration only; requires existing invitation/profile tables for direct checks |

The scripts use MariaDB-supported information_schema metadata, session user variables, IF/EXISTS/CONCAT, prepared CREATE/ALTER/SELECT, InnoDB, DATETIME defaults and utf8mb4_unicode_ci. Prepared CREATE TABLE and ALTER TABLE are supported even before MariaDB 10.6.2's broader prepared-statement support. Prior local execution was on MariaDB 10.4.32; the live Hostinger version, SQL mode and permissions remain unknown. The current scripts contain no stored procedure, DELIMITER requirement, hard-coded USE, ADD COLUMN IF NOT EXISTS, CREATE INDEX IF NOT EXISTS, foreign-key-check bypass or bulk UPDATE/INSERT/DELETE. Existing compatible structures are preserved; incompatible existing invitation columns block automatic DDL. Signedness is derived from users.id for newly created invitation actor columns. [MariaDB PREPARE reference](https://mariadb.com/docs/server/reference/sql-statements/prepared-statements/prepare-statement)

Import the entire migration in one phpMyAdmin session so its user variables and prepared statements retain their context. A green phpMyAdmin notification or a completion label is insufficient if ANY statement errored. CREATE/ALTER cause implicit commits; wrapping this migration in a transaction does not make it reversible. [MariaDB implicit-commit reference](https://mariadb.com/docs/server/reference/sql-statements/transactions/sql-statements-that-cause-an-implicit-commit)

### Limits of the automated preflight: manual gates remain mandatory

- It checks selected column definitions and uniqueness constraints, but does not exhaustively validate every users/activity_logs insert column, default, enum choice, extra required column or CHECK constraint on those base tables. Review complete definitions before GO.
- In particular, confirm users has PRIMARY KEY(id), the existing invitation table has PRIMARY KEY(id), and omitted fields such as credential_version have compatible defaults. AUTO_INCREMENT alone does not prove the expected single-column primary key. Require the reviewed unsigned INT credential_version default 0.
- Inspect users role/admin_type/status/registration_status enums and all provisioning fields used by DoctorInvitationService. Check lengths for names, username, email, phone and appointment fields. Inspect activity_logs(user_id,action,module,details,ip_address), its defaults and action lengths. Missing dependencies are NO-GO even if a summary says METADATA_READY_ONLY or PASS.
- Existing foreign keys are inventoried, not fully validated or repaired. Review their exact targets/actions, including cascades, against preservation requirements. No new cascading FK is introduced by this migration. Existing custom cascades are not certified safe merely because metadata checks pass.
- Trigger visibility depends on account privileges. An empty trigger report is not proof of no triggers if the operator cannot see them. Obtain owner/provider confirmation for custom triggers, constraints and relevant grants when visibility is limited; do not share credentials or password-bearing grant output.
- Preflight does not count invitation orphans/duplicates when the new table is absent. When an existing compatible table is present, run the two read-only aggregate queries below before DDL. Data problems require an owner-reviewed plan, never deletion of evidence.
- No hard-coded target-name assertion exists in the migration. A wrong selected database with a similar schema could pass. The three-way target check below is mandatory.

These are release gates, not changes applied by this review. Do not run database/migration_doctor_invitations.sql, either Nurse migration or an earlier mixed-purpose migration as a workaround.

## 2. Correct database selection: proposed read-only instructions

**Do not run these queries until production SQL access is explicitly approved.** They are provided for operator review only.

1. In hPanel open the dashboard for bpcschoolclinic.org, then Databases Management and phpMyAdmin for the recorded database. Hostinger documents selecting the database before opening phpMyAdmin. [Hostinger phpMyAdmin instructions](https://www.hostinger.com/support/1583545-how-to-access-phpmyadmin-at-hostinger/)
2. Privately inspect the effective application DB_NAME for the deployed site. Compare it with the hPanel database entry and selected phpMyAdmin database. config/config.php loads an external APP_ENV_FILE when configured, otherwise the project .env; existing process environment values take precedence. Do not copy the .env, DB_USER, DB_PASS or other secrets into chat/logs. A matching name in an unrelated configuration file is not enough.
3. Select u962995739_bpc_clinic in phpMyAdmin's left sidebar and review this SELECT in the SQL tab:

```sql
SELECT DATABASE() AS selected_database,
       CASE WHEN DATABASE() = 'u962995739_bpc_clinic'
            THEN 'MATCH_RECORDED_TARGET'
            ELSE 'STOP_WRONG_OR_NO_DATABASE' END AS target_check,
       VERSION() AS server_version,
       @@SESSION.sql_mode AS session_sql_mode,
       @@SESSION.time_zone AS session_time_zone,
       @@FOREIGN_KEY_CHECKS AS foreign_key_checks;
```

Require the exact recorded name and FK checks 1. If hPanel or effective DB_NAME disagrees, stop and have the owner confirm the actual binding; do not guess or change production configuration. Name/version output contains no passwords or patient/account records. This confirms the selected database name, not the website-to-database binding by itself.

4. Run the dedicated preflight after approval. Require no BLOCK/custom-review rows; ADD is acceptable only for the absent invitation table or nullable designation column. Verify the required base tables and complete definitions. Once those base tables are confirmed present, these are read-only:

```sql
SHOW CREATE TABLE users;
SHOW CREATE TABLE admin_profiles;
SHOW CREATE TABLE activity_logs;
```

5. If preflight confirms a compatible existing admin_invitations table, also inspect SHOW CREATE TABLE admin_invitations and run:

```sql
SELECT COUNT(*) AS duplicate_active_invitation_users
FROM (
  SELECT user_id FROM admin_invitations
  WHERE used_at IS NULL AND expires_at > NOW()
  GROUP BY user_id HAVING COUNT(*) > 1
) AS duplicates;

SELECT COUNT(*) AS orphan_invitation_actors
FROM admin_invitations ai
LEFT JOIN users appointee ON appointee.id = ai.user_id
LEFT JOIN users inviter ON inviter.id = ai.created_by
WHERE appointee.id IS NULL OR inviter.id IS NULL;
```

Both counts must be zero. Do not run table-specific queries when the table/columns are absent; the metadata-only preflight remains usable in that case. These aggregate queries do not expose account identifiers or tokens.

## 3. Exact deployment manifest

The machine-readable file **docs/PHASE1_HOSTINGER_DEPLOYMENT_MANIFEST.json** records relative paths, byte sizes and SHA-256 of the reviewed local bytes. It is an allowlist, not approval or proof of remote parity. Recompute/review it if any listed source changes. Do not upload the entire worktree.

### Changed web-runtime files to release together: 12 PHP + 1 JS

```text
api/profile.php
assets/js/mis-admin.js
auth/setup_account.php
components/sidebar.php
includes/csrf.php
includes/doctor_clinical_service.php
includes/doctor_invitation_service.php
includes/mail.php
includes/permission_profiles.php
pages/admin/admin_accounts.php
pages/admin/profile.php
pages/doctor/dashboard.php
pages/doctor/profile.php
```

### Required existing feature dependencies: compare before replacing

```text
assets/css/mis-admin-mobile.css
includes/head.php
pages/doctor/settings.php
includes/account_settings.php
```

No CSS was changed in Phase 1. The existing mis-admin-mobile.css is required for the modal's sizing/scroll/footer rules, and includes/head.php must load it when loadMisAdminStyles is set. Confirm these four files match the reviewed hashes; if missing/different, review their diff and add their replacement to the explicit approval. Do not overwrite a divergent live dependency blindly.

The existing BPC base application must also remain intact: config/config.php and config/database.php, includes/auth.php/functions.php/security.php, auth/login.php/logout.php, shared footer/assets and vendor/autoload.php with PHPMailer/SMTP/Exception classes. Verify compatible deployed versions and the tested PHP 8.2 runtime with required PDO MySQL, mbstring, OpenSSL and session functionality. This is a feature release, not a standalone application installer. Do not replace .env, shared base configuration or Composer/vendor versions as an unreviewed side effect.

### Operator SQL: three files, run privately in phpMyAdmin only after approval

```text
database/preflight_doctor_invitations_hostinger.sql
database/migration_doctor_invitations_hostinger.sql
database/verify_doctor_invitations_hostinger.sql
```

Do not publish SQL/dumps, tests, docs, scratch, backups or this manifest as public web assets. setup/migrate_doctor_invitations.php is an optional guarded CLI helper, not needed for phpMyAdmin deployment and not part of the required web upload. Do not run it here. Exclude all Nurse migrations, pages/doctor/nurse_verification.php, includes/nurse_verification_service.php and unrelated pending worktree edits from this release.

## 4. Production email configuration: verify privately, expose no credentials

Review the **effective** environment used by PHP, not only a local template. Never print credential values, entire environment dumps, .env contents, SMTP transcripts or raw invitation URLs. Record only present/missing, provider, chosen nonsecret port/encryption and pass/fail results in the release record.

| Setting | Requirement |
| --- | --- |
| APP_ENV | production |
| APP_URL | https://bpcschoolclinic.org, canonical site origin |
| DB_NAME | Privately confirmed recorded target; credential values never shared |
| MAIL_HOST | Explicit actual SMTP provider host; no accidental smtp.gmail.com fallback |
| MAIL_PORT | Explicit port matching encryption |
| MAIL_ENCRYPTION | Explicit PHPMailer value ssl or tls, matching the provider; do not use strings SSL/TLS or STARTTLS |
| MAIL_USERNAME | Present, valid mailbox/authentication identity; do not disclose the value |
| MAIL_PASSWORD | Present, correct mailbox/app credential for that provider; never display it |
| MAIL_FROM_ADDRESS | Present, provider-authorized sender/domain; verify privately |
| MAIL_FROM_NAME | Expected BPC institutional sender display name |

If this is a **Hostinger Email mailbox**, Hostinger documents smtp.hostinger.com with SSL on port 465, or TLS/STARTTLS on 587 as an alternative. In this installed PHPMailer, use **MAIL_PORT=465 + MAIL_ENCRYPTION=ssl**, or **MAIL_PORT=587 + MAIL_ENCRYPTION=tls**. Do not mix the pairs. If the actual service is Gmail, Titan or another provider, obtain that account's settings instead of assuming Hostinger Email. [Hostinger Email configuration](https://www.hostinger.com/support/4305847-set-up-hostinger-email-on-your-applications-and-devices/)

Important code finding: password-reset/OTP mail helpers default to SMTPS while the Doctor helper defaults to STARTTLS; host fallback is smtp.gmail.com and port fallback is 587. **Do not rely on defaults.** A correctly explicit MAIL_ENCRYPTION/MAIL_PORT pair is used by all three helpers and avoids the default mismatch. No production values have been read or changed here.

Confirm mailbox/sender permissions, TLS certificate validation, outbound SMTP connectivity, provider limits and domain SPF/DKIM/DMARC alignment through the authorized owner/provider. Keep SMTP debug off. Do not disable authentication or certificate checks. Inspect existing OTP/reset compatibility after the approved deployment because mail.php is shared. A sent status proves transport acceptance only; require receipt of an approved real-recipient invitation and its correct fragment link. Previous loopback capture did not establish external inbox delivery.

## 5. Backup and rollback plan

### Before any approved DDL/upload

- Record the selected site/database, server version, deployment timestamp, current file hashes, SQL hashes, restore owner and maintenance window.
- Export a full database snapshot containing structure and data, and download a matched copy of the deployed files/configuration. Keep credentials and backup files encrypted/access-controlled outside public_html. Include existing users/admin_profiles/admin_invitations/activity_logs and all clinical tables; partial table-only backups are not sufficient for a consistent full recovery.
- Quiesce all application/scheduled writers for a consistent snapshot and migration window, including in-flight invitation send/activation jobs and patient writes. Record when writes stop/resume. An automatic backup from an unknown earlier time is insufficient for this window.
- Verify the dump/file archive can be read and restored in an isolated non-production environment under separate approval before promising rollback. Record existing object presence and definitions. Confirm the actual Hostinger backup availability and restore permissions; do not assume the plan has a usable recent snapshot.

Hostinger provides separate file and database restore operations. A database restore returns it to the selected backup's state, so restoring an older snapshot can remove newer records. Provider restore availability and the exact selected backup must be checked privately. [Hostinger restore guide](https://www.hostinger.com/support/4283700-how-to-restore-backups-at-hostinger/)

### Partial DDL matrix

| Observed outcome | Safe response before reopening |
| --- | --- |
| Preflight BLOCK; no DDL succeeded | Keep current schema/code; resolve the mismatch through a reviewed plan |
| Invitation table created; designation ALTER failed | Preserve the new table and all rows. Keep onboarding closed. Capture error/current definitions, resolve cause, reapprove if scope changes, then rerun the whole file |
| Existing table already compatible; designation added | Treat DDL as committed. Run complete post-verification; no reverse ALTER is required for an application rollback |
| Connection/import failed; applied state unknown | Stop. Read definitions/preflight again after approval; never infer that the file was atomic or blindly run trailing statements |
| DDL reports completion but post-verification/data checks fail | NO-GO. Keep writers/onboarding closed; investigate while preserving data |

No DROP TABLE, DROP COLUMN, reset, truncate, bulk account change or destructive down-migration is included. Leaving additive compatible schema in place is normally safer than removing evidence. Rerunnability does not authorize automatic retry after an error. Metadata locks/space/privilege/time-limit issues need operator resolution; actual Hostinger DDL timing is unknown.

### Code rollback and invitations already issued

1. Disable invitation creation/resend/acceptance at the access layer or keep maintenance mode active before restoring a reviewed compatible runtime set. Include shared files/JS consistently; do not mix old/new service and acceptance-page versions.
2. Preserve current users, passwords, credential versions, invitation hashes, used_at, delivery status and audits. Never restore an older invitation snapshot merely to make old code work.
3. Old acceptance code may not understand the new fragment links already emailed. Keep onboarding closed until a compatible acceptance path or approved forward fix is available. Do not automatically resend, consume or revoke existing invitations; a controlled replacement requires an explicit authorized decision.
4. **An old permission_profiles.php/dashboard backup may remove the Phase 1 Nurse block and restore approval links.** Before reopening, verify the exact Nurse-review route remains denied (403) and no approval mutation can run. If the old release lacks the block, keep relevant routes closed until a separately approved exact-path server denial or compatible rollback package is installed. Do not silently re-enable Phase 2 as a rollback side effect.
5. Prefer code-only rollback/forward repair with additive schema retained. Full database restore is a last-resort separately approved recovery, with all writers stopped, a fresh pre-restore snapshot, and reconciliation of every post-backup account/clinical/audit change. Restoring an old used_at/password/credential snapshot can also resurrect consumed tokens or old credentials. Reconcile safely before reopening; no blanket restore is authorized by this checklist.

## 6. Phase 2 remains disabled

Verified in the current source:

- config/config.php invokes enforce_profile_route.
- includes/permission_profiles.php denies a Doctor request whose filename is nurse_verification.php before the page service executes.
- Doctor sidebar/dashboard have no Nurse approval links or counters.
- DoctorClinicalService summary does not load NurseVerificationService.
- No Doctor invitation SQL references the Nurse authorization schema or updates Nurse permissions.

The basic Nurse account page may still use the existing verification service to read status; that is not a Phase 1 approval endpoint. Mutating service methods retain Doctor authority checks, and their existing page entry point is blocked. The earlier HTTP suite confirmed a Doctor gets 403 on that page with Nurse schema absent. This review made no new SQL/HTTP production requests. Actual production denial remains unverified until the approved deployed file set and direct route test are checked.

## 7. Ordered execution checklist and gates

Every production query, upload, email smoke test, rollback or restore below requires explicit approval. This plan itself grants none.

1. **Approval gate:** approve the frozen runtime/dependency/SQL manifest, execution owner, maintenance window and distinct production SQL/deployment actions. No approval => NO-GO.
2. **Target gate:** establish the three-way hPanel/effective DB_NAME/SELECT DATABASE match to u962995739_bpc_clinic, correct site and actual MariaDB version. Any disagreement, unknown binding or wrong server => NO-GO.
3. **Read-only preflight gate:** run the dedicated preflight; inspect all result sets and complete base definitions. Require required InnoDB tables, correct id keys/types, defaults/enums, username/email/profile/token uniqueness, privileges/visibility and no unreviewed custom constraints. Only the documented ADD states are acceptable. BLOCK/unknown => NO-GO.
4. **Existing-data gate:** when the invitation table exists, require zero duplicate active-invitation users and zero orphan actors. Resolve unverified legacy pending accounts only through authorized review, not automatic SQL approvals. Unresolved data/approval issues => NO-GO for the affected workflow.
5. **Configuration gate:** privately verify APP_ENV/APP_URL, effective credentials present, explicit SMTP provider/port/encryption/sender, PHP/extensions, secure HTTPS session cookies, writable session storage, and reviewed base source dependencies. Do not expose secrets. Missing/mismatched configuration => NO-GO.
6. **Recovery gate:** prove backups/restoration path, quiesce writers, capture pre-change definitions/hashes and confirm rollback can keep Nurse approval denied and preserve issued invitations. Missing proof => NO-GO.
7. **DDL gate, after approval:** import the COMPLETE Doctor Hostinger migration in one phpMyAdmin session. Require READY_SCHEMA_ONLY, no errors and SCHEMA_COMPLETE. Any error => stop and use the partial-DDL matrix. Never disable FK checks or run Nurse/legacy migrations to bypass a failure.
8. **Post-DDL gate:** run the COMPLETE Doctor post-verification. Require every structural result PASS, no custom-review rows, expected complete definitions and zero orphan/duplicate counts. PASS is necessary; the manual dependency/constraint checks are still required. Compare protected baselines. Any discrepancy => NO-GO.
9. **Runtime release gate, after approval:** deploy only the 13 runtime files plus explicitly approved dependency replacements, preserving paths and existing credentials. Verify SHA-256/parity, correct head/CSS dependency and fresh content-hashed JS. Keep onboarding closed until the coherent set is present; account for host PHP OPcache/CDN caches through authorized host controls. No partial live upload testing.
10. **Approved smoke-test gate:** test genuine expired session (401 JSON), CSRF (403), invalid fields (422), conflicts/replays (409), required verified appointment, one controlled real invitation, actual inbox receipt, fragment-to-POST setup, secure password activation once, login/dashboard/profile/settings/password/logout, Doctor/MIS separation and direct Nurse approval denial (403). Test desktop/mobile controls and existing mail-dependent role flows. No real recipient test is authorized yet. Failure => NO-GO.
11. **Release gate:** only after all evidence and explicit approval are present may Phase 1 be reopened and described as production GO. Keep Phase 2 closed. Archive redacted outputs, hashes, receipts and approvals privately. Monitor pending/failed invitations without recording secrets. Do not start Phase 2 without a new explicit instruction/approval.

## 8. What cannot be verified without authorized production access

Live database/site binding; current server/SQL mode and metadata visibility; exact table/index/FK/trigger/default definitions and existing data; SQL/runtime grants; storage/DDL duration/locks; Hostinger PHP/extensions/session/cookie/cache settings; deployed code/vendor/hash parity; effective SMTP values and credential validity; outbound connectivity, sender/DNS authentication, delivery limits and inbox receipt; backup availability/consistency/restore success; outstanding legacy links; and actual live role/Nurse-route enforcement. These unknowns keep production **NO-GO**. Do not share credentials or account/patient records to resolve them; supply only approved redacted metadata/pass-fail evidence.
