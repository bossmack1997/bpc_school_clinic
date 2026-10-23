# Hostinger Nurse authorization preflight: explicit schema context

Updated file: `database/preflight_nurse_clinical_authorization_hostinger.sql`.
Fixed target: **`u962995739_bpc_clinic`**. No migration or production query was
executed by this change.

The previous metadata checks used `DATABASE()`. When phpMyAdmin selected
`information_schema`, they inspected that schema and reported missing application
structures. All checks now reference the intended schema explicitly, including
foreign-key targets, workflow columns, triggers, constraints and indexes.
Schema/table comparisons use binary comparisons to respect exact spelling and the
production `lower_case_table_names=0` setting. Actual identifiers remain lowercase.

The first result separately shows `selected_database`, `intended_database`, server
version, case setting and `target_result`. `TARGET_VERIFIED` requires the exact
visible schema and its lowercase `users`/`nurses` base tables. It proves target
identity only, not that every remaining check has passed. An unverified target
returns `BLOCK_TARGET_NOT_VERIFIED`, and the final metadata summary returns `BLOCK`.

`DATABASE()` remains only in the informational selected-database field. The file
contains SELECT statements only: no USE, SET, prepared execution, DDL, writes or
permission changes. It works with `information_schema`, another database or no
database selected, without changing that selection. Generated orphan/duplicate
queries explicitly qualify every application table with the intended schema.
Unverified base tables suppress the generated Nurse data-check SQL.

## Reading remaining results

| Result | Meaning and next action |
| --- | --- |
| `BLOCK_TARGET_NOT_VERIFIED` | Exact schema/base tables are absent, wrong-case, not base tables or not visible to the connected account. Verify server/account/schema and existing access; the script does not grant permissions or try another schema. |
| Column `BLOCK` | A required existing prerequisite is missing, or a present column has incompatible type, nullability, default, primary key or generated definition. A partly present audit table with missing expected columns also blocks. Review the reported definition. |
| Engine/table `BLOCK` | Required base tables are absent, views or incompatible engines. Supported tables must use InnoDB. |
| FK `BLOCK` | Unexpected/composite/duplicate relationships, wrong actor columns, foreign target schema/table/column or unsupported rules. Review without dropping history. |
| Custom-review rows | Triggers, unexpected audit columns, unique/check constraints or incoming audit foreign keys require explicit review. The metadata summary remains blocked. |
| Foreign-key checks `BLOCK` | The SQL connection has foreign-key checks disabled. Stop and investigate before migration. |
| `ADD` | A supported new review structure is absent. This is expected on an unmigrated database and is not itself an error or approval. |
| `REPAIR` | A recognized unsafe audit cascade needs the existing reviewed migration's repair. This preflight does not change the FK. |
| `MISSING_WORKFLOW_DEPENDENCY` | A separately reported application column is missing. Even if structural metadata is otherwise ready, the review workflow is not ready. Inspect these results and the application dependency tables. |

`METADATA_READY_ONLY` is deliberately not a deployment approval. Inspect every
result set and execute the returned qualified SELECT checks only after prerequisite
metadata passes. Audit/Nurse orphan counts must be zero; the duplicate-profile query
must return no rows. These data checks are emitted for review, not automatically
executed by the preflight. An absent audit table is an expected ADD; a malformed
existing audit structure is a stop condition. Do not hide BLOCK results or replace
them with zero counts.

## Running it safely

1. Execute the entire updated preflight in phpMyAdmin. Confirm intended database
   `u962995739_bpc_clinic` and `TARGET_VERIFIED`, regardless of the selected context.
2. Review all metadata, custom-review, workflow and generated data-check results.
3. Keep the migration approval gate. This change neither applies the migration nor
   authorizes Nurse accounts, deletes records or alters permissions/FKs.
4. **The migration and post-verification scripts are unchanged and still depend on
   the selected application database.** Before any separately approved migration,
   explicitly select the production application database and verify its context.
   A successful preflight does not switch phpMyAdmin away from `information_schema`.

This is intentionally a production-targeted script. For another environment,
review and replace the fixed schema consistently in a separate copy; do not
reintroduce an implicit current-database fallback.

## Validation

The existing SQL suite rebinds the fixed schema only to its guarded disposable
fixture names. Context-specific tests cover a selected application database,
`information_schema`, no selected database, absent target, schema case mismatch,
missing lowercase base table, malformed audit schema, generated orphan/duplicate
queries and preservation of synthetic audit rows. A metadata/migration smoke test
also checks compatibility with the unchanged migration and post-verification.

Results: **108 context/read-only checks passed**, **94 metadata/migration smoke
checks passed**, PHP lint passed and `git diff --check` passed.

Local test server: MariaDB **10.4.32**. The user-reported production server is
MariaDB **11.8.9**; live Hostinger execution remains unverified. Test results are
recorded in `scratch/nurse-preflight-context-final.log` and
`scratch/nurse-preflight-smoke.log`. PHP lint and `git diff --check` are included
in final validation. No production data or authorization states were changed.
