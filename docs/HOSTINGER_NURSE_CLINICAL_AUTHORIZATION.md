# Final Hostinger Nurse migration safety review

Review date: 2026-10-23 (Asia/Manila). **Production release status: NO-GO.** The SQL safeguards do not fix the application authorization defects below. No production connection, production SQL execution, push or deployment was performed.

## Files and execution boundaries

- `database/preflight_nurse_clinical_authorization_hostinger.sql`: dedicated read-only SELECT/information_schema report, including when the audit table or new columns do not exist. It returns supplementary read-only data-check SQL for existing tables. Run those statements as described below.
- `database/migration_nurse_clinical_authorization_hostinger.sql`: guarded additive DDL and session variables/prepared statements. No hard-coded database selection, stored procedures or disabled FK checks. No INSERT, UPDATE, DELETE, REPLACE or TRUNCATE of account, patient, clinical or audit records.
- `database/verify_nurse_clinical_authorization_hostinger.sql`: read-only post-migration checks. Missing objects are errors and mean NO-GO. This file is intentionally not the preflight.
- `tests/nurse_clinical_authorization_sql.php`: exact SQL against randomly named disposable local databases, with synthetic records only.
- `tests/nurse_authorization_safety_audit.php`: characterization tests reproducing current unsafe application behavior. A successful test run confirms the findings; it does not certify authorization safety.

## Authorization policy and observed implementation

The release policy proposed by this review is: a Nurse may use clinical operations only when the current database account is active, OTP verified, has staff scope (`self_service_only=0`), has exactly one Nurse profile explicitly authorized by a verified Doctor, and has an intact matching review audit. The required endpoint permission and patient/action scope must also pass. Missing schema, missing profile, unknown state, pending, needs_information, rejected or revoked must not grant clinical access. Every scope transition must be atomic with its review audit and invalidate credentials as required. Every clinical page, API, file download and direct service path must use the same server-side decision, including legacy routes.

**This policy is not fully implemented.** This review does not silently switch existing accounts to a new policy, because that could revoke working access without account-by-account review.

| Finding | Evidence in current code | Consequence |
| --- | --- | --- |
| Clinical status is bypassed by scope zero | `includes/auth.php` current_user/require_login/require_role and `includes/permission_profiles.php` has_permission do not consult Nurse clinical status | Pending, needs_information, rejected and revoked profiles with scope zero retain Nurse permissions. Reproduced with actual permission helpers. |
| Emergency and clinical routes inherit the same gap | `includes/auth.php` admin_can_view_emergencies/admin_can_respond_sos; `api/medical_records.php`, `api/nurse_dashboard.php`, and Nurse page role guards | Role/scope checks are server-side, but do not enforce the proposed authorization policy. No claim that every endpoint is secure can be made. |
| QR management bypasses even the common basic-scope route guard | `api/qr_codes.php` starts with is_logged_in; management actions use role checks. require_login is limited to timeline/history actions. `enforce_profile_route` returns for Nurses | Static code review shows a basic Nurse can reach Nurse QR-management branches without the basic-scope check. Endpoint HTTP coverage is still required after a fix. |
| Missing audit schema does not prevent authorization | `includes/nurse_verification_service.php` detectSchema and conditional hasReviewTable writes in authorize/requestInformation/reject/revoke | authorize succeeds without nurse_clinical_reviews. Reproduced locally. Schema detection checks only one Nurse column and table existence, not a complete compatible schema. |
| Missing Nurse profile does not prevent a grant | authorize treats an absent status row as pending and does not require its Nurse UPDATE to affect one row | User scope can be granted without a Nurse profile. Reproduced locally. |
| Legacy scope is displayed as authorization | normalizeStatus(null,0,'active') returns authorized; fallback list/summary queries also infer authorization | UI labels and counts are not verified evidence. Reproduced locally. |
| Credential handling differs across transitions | authorize/revoke increment users.credential_version; requestInformation/reject change scope without incrementing it | Consolidate credential invalidation and test session/remember-me/direct-route behavior. current_user rereads scope, but this does not close legacy role-only routes. |
| Alternate PHP migration is weaker | `database/migration_nurse_clinical_authorization.php` still uses hard-coded signed IDs and creates an audit table without FKs | Its bulk authorization UPDATE was removed earlier, but it is not an approved substitute for the Hostinger SQL. Do not run it for production rollout. |

Doctor APIs use the admin role plus the explicit Doctor profile; a Doctor database role is not assumed. Doctor-only entry points exist, but they do not resolve the Nurse access defects. The review has not performed an exhaustive HTTP/action-level security regression across the application. That remains a release requirement.

## Existing Nurse accounts and prior grants

No existing scope, authorization value, credential version, reviewer, timestamp, record or audit entry is rewritten. Newly added clinical status defaults to pending; missing credential_version is INT UNSIGNED NOT NULL DEFAULT 0. Existing values are preserved. Existing scope zero therefore continues to behave as before under current PHP; it is not proof of authorization.

Before enforcing the proposed policy, capture a restricted account-level baseline and reconcile each current clinical Nurse against independent credential/Doctor approval evidence. Previously verified Nurses need a deliberate, auditable transition retaining their approved access. Unverified or contradictory accounts need an explicit owner/Doctor decision; do not bulk-authorize from self_service_only and do not silently mass-revoke by deploying a new guard. Do not manufacture historical review records or assume an old authorized status is valid. If the earlier unsafe migration ran, investigate its grants separately. Missing evidence is a NO-GO for claiming the authorization state is verified.

No reconciliation SQL is included, and no account-level permission change is authorized by these scripts. Plan that work and the common server-side guard together before releasing the authorization feature.

## Schema and foreign-key decisions

The checked-in 2026-10-22 backup has signed INT user IDs, InnoDB users/nurses and unsigned INT credential_version. It is not proof of the current Hostinger schema. Live preflight output and SHOW CREATE TABLE definitions remain necessary.

Existing compatible columns are preserved. Missing supported columns are added without AFTER dependencies. Wrong types, signedness, lengths, defaults, nullability, generated columns, incompatible base engines, malformed existing audit tables, custom audit columns/unique indexes/CHECK constraints, relevant triggers or incoming audit FKs block automatic DDL for manual review. Existing users/nurses indexes and constraints are reported for inspection rather than rewritten. INT IDs with either signedness are supported; BIGINT IDs are an explicit blocker. The unsigned INT credential_version definition is required if present.

Audit FKs must consist of exactly one single-column constraint per actor column, each referencing the selected database's users.id at ordinal 1. Missing keys are ADD. Duplicate, composite, wrong-column, wrong-table, wrong-schema and unexpected actor relationships are BLOCK. A recognized single-column key with unsafe ON DELETE or ON UPDATE behavior is REPAIR, replaced with RESTRICT/RESTRICT in one ALTER. RESTRICT and NO ACTION are accepted as restrictive behavior. Nullable SET NULL actor columns fail column validation and require manual review; they are not automatically coerced.

New audit tables include both restrictive FKs in their CREATE TABLE statement. Existing tables missing keys can be completed only with zero orphan actors. Existing history is never removed to satisfy constraints. Direct audit references to users.id prevent an account deletion from cascading away review history, even though the existing nurses.user_id relationship may cascade. Direct DELETE/TRUNCATE/DROP of the audit table by a privileged operator is not prevented by these FKs; this migration does not add database privileges or immutable storage.

The migration checks audit orphans, missing Nurse users and duplicate Nurse profiles before any DDL. It checks each added column before allowing the next DDL step, checks each FK repair and recomputes final schema completion. A client continuing after a DDL error cannot obtain a successful completion result for an incomplete schema. DDL still auto-commits: stop on errors, inspect the partial schema and rerun the whole file only after resolving the cause. Quiesce reviews/account deletions for the complete window. Concurrent schema/data changes cannot be made safe by preflight alone.

## Exact local validation commands

Run from `C:\xampp\htdocs\bpc_clinic_system` in PowerShell:

```powershell
php -l tests/nurse_clinical_authorization_sql.php
php -l tests/nurse_authorization_safety_audit.php
php tests/nurse_clinical_authorization_sql.php
php tests/nurse_clinical_authorization_sql.php --foreign-key-edges
php tests/nurse_clinical_authorization_sql.php --metadata-smoke
php tests/nurse_authorization_safety_audit.php
```

Both test scripts check APP_ENV and DB_HOST before connecting, create disposable databases with validated random names, insert only synthetic fixtures, and remove those databases in finally blocks. They never import real patient/account data. See the results section for the completed run. MariaDB local validation does not certify an untested Hostinger/MySQL version or account privileges.

## Actual results from this review

All commands below completed with exit code 0 against **local MariaDB 10.4.32**. No production database was accessed. Assertion counts include per-statement read-only/no-DML checks as well as scenario assertions.

| Command | Actual result |
| --- | --- |
| `php -l tests/nurse_clinical_authorization_sql.php` | No syntax errors detected |
| `php -l tests/nurse_authorization_safety_audit.php` | No syntax errors detected |
| `php tests/nurse_clinical_authorization_sql.php` | All 155 checks passed. Production not accessed. |
| `php tests/nurse_clinical_authorization_sql.php --foreign-key-edges` | All 98 checks passed. Production not accessed. |
| `php tests/nurse_clinical_authorization_sql.php --metadata-smoke` | All 94 checks passed. Production not accessed. |
| `php tests/nurse_authorization_safety_audit.php` | All 10 findings reproduced. AUTHORIZATION RELEASE IS NO-GO. Production not accessed. |

The main run covered fresh feature installation, preflight without audit, signed/unsigned INT IDs, unchanged clinical records/scope/review evidence, exact two restrictive FKs, account delete/ID update rejection, reruns, recognized cascade repair, absent keys, orphan preservation, partial existing schema, duplicate/composite/wrong-table/unexpected keys, custom indexes/CHECKs/triggers, mismatched actor signedness, malformed partial audit schema, bad scope/credential types, wrong engines, duplicate/missing Nurse users, disabled FK checks, an injected DDL failure with client continuation, skipped later DDL, and full-file recovery.

The FK edge run additionally blocked wrong reference schema/column and nullable SET NULL definitions, completed one missing actor key without duplicating the other, and handled absent base tables without a preflight error. The final metadata smoke run exercised the final SQL files after adding the existing-foreign-key inventory: preflight without audit, migration completion, and post-verification. The characterization run confirmed current authorization gaps, not their remediation. No exhaustive HTTP security regression or live Hostinger compatibility check was performed.

## Production execution checklist

### Current decision: NO-GO for authorization release

- [ ] Resolve the scope/status and QR-route bypasses through one documented server-side guard and action-level regression tests.
- [ ] Require complete compatible schema and exactly one Nurse profile before any review mutation; require dedicated audit insertion and atomic rollback on failure.
- [ ] Complete owner/Doctor-reviewed transition decisions for existing clinical Nurses without inferred approvals or silent revocations.
- [ ] Verify credentials/sessions/remember-me invalidation and all clinical entry points after those changes.

### Conditional GO for a separately approved schema-only maintenance operation

- [ ] Obtain explicit approval before any production SQL execution. Confirm selected Hostinger database and version; export and verify a restorable backup.
- [ ] Establish maintenance mode or otherwise quiesce clinical reviews, account deletion and schema changes. Confirm ALTER/CREATE/REFERENCES privileges and adequate storage/time for DDL.
- [ ] Run the dedicated preflight file. Require the correct database, FK checks enabled, required base InnoDB tables, no BLOCK rows and no custom-review rows. ADD/REPAIR are supported schema work, not proof of clinical approval.
- [ ] Execute returned audit-orphan check SQL if the audit table exists; require zero. Execute returned Nurse orphan and duplicate-profile checks when prerequisite metadata passes; require zero missing users and no duplicate groups. Do not delete evidence to make results pass.
- [ ] Review reported users/nurses constraints/indexes and workflow dependencies, including email_verified_at/self_service_only/credential_version, admin_profiles, activity_logs, Doctor profile configuration, and audit-log transactional engine. Missing or incompatible dependencies are NO-GO for review workflows. No bypass of prior registration migration prerequisites is permitted.
- [ ] If any schema/version/custom constraint differs from tested fixtures, reproduce it in a disposable database and rerun the checks before approval. Investigate data contradictions separately from DDL.
- [ ] Run the COMPLETE migration once through phpMyAdmin. Require READY_SCHEMA_ONLY, no SQL errors, and SCHEMA_COMPLETE. These labels do not approve authorization release. Stop on any error; do not run selected trailing statements as a workaround.
- [ ] Run the separate COMPLETE post-verification file. Require every column/engine/FK result PASS, exactly one actor FK per column, exact users.id references, both restrictive delete/update rules, no custom-review rows, PASS_SCHEMA_ONLY, zero audit/Nurse orphans and zero duplicate profile groups. Inspect all SHOW CREATE TABLE output.
- [ ] Compare restricted before/after account, clinical and audit snapshots/counts. Existing values must be preserved; a newly added pending field must not be interpreted as verified approval or an approved revocation.
- [ ] Keep authorization feature rollout blocked until the policy defects and transition work are resolved. Preserve backup, SQL output, approvals and maintenance results. No push/deploy is authorized by this checklist.

Any SQL error, BLOCK, unknown schema, missing evidence, orphan, duplicate, unsafe remaining FK, missing workflow dependency, unreviewed custom constraint, failed regression or incomplete approval is **NO-GO**. This report does not declare the current production application safe.
