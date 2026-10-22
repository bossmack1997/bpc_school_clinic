from pathlib import Path
import json,zipfile,hashlib
base=Path('scratch/backups/co-admin-permissions-20261022')
manifest=json.loads((base/'manifest.json').read_text());change=json.loads((base/'changed-files.json').read_text())
with zipfile.ZipFile(base/'code-before.zip') as archive:
 for name,expected in manifest.items():
  assert hashlib.sha256(archive.read(name)).hexdigest()==expected,name
print('PASS backup archive matches all pre-change file hashes.')
report='''# Co-admin permissions repair — 22 October 2026

## Root causes

1. The settings form allowed a co-admin holding `manage_settings` to edit permission sets, including their own. Institutional settings authority incorrectly implied privileged account authority.
2. Saving cleared every permission row before reinsertion, without a transaction. An exception or duplicate submitted key could leave a partial set; unrecognized historical keys were silently discarded.
3. The modal loaded permissions embedded in the page rather than fetching the current database state. There was no submit guard, stale-edit detection, or useful AJAX failure handling.
4. Saved permissions were not consistently enforced by the sidebar, direct pages, settings subpanels, or APIs. JSON authorization handling depended on an AJAX header instead of recognizing API paths.
5. Emergency Oversight had no selectable permission; co-admins inherited administrator clinical SOS actions. Several legacy privileged account and clinical routes also accepted the broad admin role without checking delegation.
6. `manage_sections` had a working API but no administration page or sidebar entry. Existing `approve_users` checks had no corresponding checkbox. General-settings co-admins could also see security and administrator panels that did not belong to their grant.

## Implementation and permission mapping

The existing settings page, permission modal, sidebar structure, dashboards, account IDs and schema remain in use. No permissions were automatically granted, reset, or migrated.

| Permission | Authorized surface |
|---|---|
| `manage_users` | Non-administrator user management; corresponding user API and alternate MICA account-deactivation entry point. |
| `approve_users` | Existing non-administrator registration approval capability; now represented in the same modal. |
| `manage_sections` | Existing sections API and a thin Sections & Classes page using that API. |
| `view_reports` | Institutional report page, legacy medical-record report alias, and report API. |
| `manage_security` | Security settings panel, legacy security URL and lockout handlers. |
| `manage_settings` | Institutional parameters and general-settings API; does not allow editing permissions. |
| `view_audit_logs` | Log panel, legacy activity-log URL and log API. The dashboard audit feed respects the same grant. |
| `view_emergencies` | Emergency Oversight, details/location/history and global alert snapshots. Co-admin access is read-only. |

Primary Administrator authority is checked against the database before loading or saving co-admin grants. The target must be another non-primary administrator. Saving locks actor/target/grant rows, validates supported keys, applies only grant/revoke deltas, and writes the audit in the same transaction. Unchanged grant timestamps and grantors, along with unmanaged historical keys, are preserved. Optimistic revisions reject conflicting stale edits with HTTP 409; an identical retry is a no-op. Audit failure rolls back all permission changes.

The modal captures selected inputs before disabling them, uses the existing CSRF token, prevents repeated submissions, loads fresh permissions on every opening, and shows success only after backend confirmation. The legacy native form uses the same service. All existing six options remain; Emergency Oversight and the already-used Account Approvals capability were added without replacing the modal.

Every access check re-reads the database on the next request. No session permission cache is used. Unauthorized pages and APIs return HTTP 403; APIs return valid JSON. Settings renders only allowed panels and does not load the activity-log script when the panel is absent. Sidebar entries are filtered using the same grants. The emergency page clears cached report details after authentication/authorization failure.

Privileged administrator accounts, permission editing and succession remain Primary-Administrator-only. The old `approve_admins` key is preserved if present but no longer bypasses privileged account restrictions. Unrepresented administration tools (inventory administration, QR administration/scanning, announcement mutations and PWA diagnostics) remain available to the Primary Administrator; they are not implicitly delegated through an unrelated checkbox. Co-admins cannot use clinical records, dispensing, clinic queue controls or SOS status mutations. Matching direct MICA mutation paths are guarded as well. The existing nurse and Primary Administrator capabilities were retained; student/teacher/nurse branches were not redesigned.

## Backups and database preservation

Before implementation, 137 application files were archived with SHA-256 hashes in:

- `scratch/backups/co-admin-permissions-20261022/code-before.zip`
- `scratch/backups/co-admin-permissions-20261022/manifest.json`
- `scratch/backups/co-admin-permissions-20261022/permissions-before.json`

The permission snapshot includes existing administrator classifications and all permission rows, without credentials. The backup directory has an Apache deny rule. The code archive was verified against every stored hash.

**No schema migration or deployment-time permission data update was needed.** The installed table already has a unique `(user_id, permission_key)` index. Final verification confirms the actual administrator IDs, roles, admin types, statuses and permission rows still match the before-image. Existing clinic records, users and files were not deleted. Grant/revoke SQL runs only when the Primary Administrator intentionally saves a permission change.

## Exact application files changed

'''
purposes={
'api/settings.php':'Primary-only read/save API and general-settings permission gate.',
'includes/admin_permission_service.php':'New transactional permission-delta service, validation, revisions and audit.',
'includes/auth.php':'Shared HTTP 403 permission enforcement and emergency monitoring/response helpers.',
'pages/admin/settings.php':'Preserved modal/panels, primary-only editor, shared save service, conditional panel loading and non-destructive succession grants.',
'assets/js/admin-settings.js':'Fresh modal loading, CSRF save, submit guard, errors and confirmed saved-state rendering.',
'components/sidebar.php':'Permission-aware links using the existing sidebar design.',
'pages/admin/sections.php':'New adapter page for the existing sections API; no new schema or alternate workflow.',
'includes/functions.php':'Filter administrative attention links by assigned permissions.',
'api/admin_dashboard.php':'Restrict dashboard audit feed to its audit grant.',
'api/users.php':'Consistent account action gates and primary-only privileged account control.',
'pages/admin/users.php':'Keep user management/approvals while excluding privileged account access for co-admins.',
'includes/emergency_oversight.php':'Return no clinical actions for monitoring-only callers.',
'assets/js/emergency-oversight.js':'Clear cached records/details on revoked or expired access.',
'api/emergencies.php':'Enforce emergency-read grant and reject co-admin clinical mutations.',
'components/global_sos.php':'Render alerts only with emergency access; mark monitoring-only state.',
'assets/js/global-sos.js':'Hide clinical controls for monitoring-only alerts.',
'components/navbar.php':'Hide unauthorized emergency navigation.',
'api/notifications.php':'Suppress unauthorized SOS detail enrichment; expose read-only action capability.',
'assets/js/main.js':'Respect monitoring-only metadata in existing SOS modal.',
'includes/notification_ui.php':'Respect monitoring-only metadata in notification-center SOS modal.',
'api/ai_assistant.php':'Guard proven alternate user/inventory mutation routes; chat flow unchanged.',
'includes/ai/MICAActionService.php':'Enforce the same delegated boundaries for direct action-service calls.',
}
for path in change['modified']+change['added']:
 purpose=purposes.get(path)
 if not purpose:
  if 'security' in path:purpose='Enforce the existing security grant at this entry point.'
  elif 'reports' in path or 'medical_records.php' in path:purpose='Enforce report or clinical-record authorization at this entry point.'
  elif 'admin_accounts' in path:purpose='Enforce Primary-Administrator-only access before rendering.'
  elif 'sections' in path:purpose='Enforce sections permission for admin callers; teacher branch retained.'
  elif 'activity_logs' in path:purpose='Enforce audit-log permission.'
  elif 'students.php' in path or 'pending_accounts.php' in path:purpose='Guard the existing user-management alias.'
  elif 'students_api' in path:purpose='Require delegated user-management permission for admin identity access.'
  elif 'global_sos.php' in path:purpose='Require emergency monitoring permission for admin snapshots.'
  elif 'emergencies.php' in path:purpose='Require the emergency monitoring permission for the page.'
  else:purpose='Close a directly related broad-admin bypass; preserve Primary Administrator and non-admin branches.'
 report+=f'- `{path}` — {purpose}\n'
report+='''
Added QA: `scratch/test_co_admin_permissions.php`, `scratch/test_co_admin_access.php`, `scratch/test_co_admin_modal.js`, `scratch/test_co_admin_regression_pages.php`, `scratch/verify_co_admin_data.php`, `scratch/check_co_admin_changes.py`. Existing oversight DOM regression tests were extended to cover revocation clearing cached records. Audit/backup support scripts are retained alongside the protected before-image.

## Executed permission tests

- Real MySQL temporary-table tests passed for saved-state loading, grants for every supported option, revocation using the same user/session object, preserved grant metadata/unknown keys, repeated identical saves, conflicting stale edits, invalid targets/keys, self-edit and primary-target rejection, and transactional rollback when audit insertion fails.
- Temporary-account session initialization and revalidation passed for Primary Administrator and co-admin. IDs, roles, admin types, statuses and credential hashes remained unchanged in those fixtures.
- Authenticated endpoint/page tests passed for allowed/revoked report, section, audit, settings and emergency routes; co-admin attempts to load/save permissions, save their own legacy form, inspect privileged accounts or perform clinical operations returned HTTP 403.
- Primary permission load/save returned valid JSON success. Invalid CSRF returned 403, wrong method 405, and deliberately broken temporary audit storage returned JSON 500 with no partially saved permissions.
- Sidebar tests passed with no grants and with explicit grants. General/security/audit co-admin settings rendered only their authorized panels. Emergency monitoring responses contained no clinical actions.
- MICA direct graduation and inventory paths rejected unauthorized co-admin operations before touching those records.
- Modal DOM tests passed for fresh checkbox/revision loading, selected values/CSRF capture before disabling inputs, repeated-click suppression, confirmed success, reopening with saved values, clearing all managed grants, offline messages and authority-denied loading.
- All 49 changed application files passed PHP or JavaScript syntax checks as applicable. Rendered Primary/co-admin settings and Sections page inline scripts passed Node syntax checks. Targeted whitespace checks passed.

## Regression results

- Student, teacher and nurse dashboards: PHP rendering passed, including the MICA surface.
- Student, teacher and nurse messaging pages: rendering passed. Existing grouped-thread tests passed with existing message counts preserved.
- Student and teacher personal QR pages: rendering passed. Existing patient scan/timeline scope tests passed with test writes rolled back.
- SOS: existing real-MySQL fixture workflow and frontend action/global alert suites passed, covering creation, no-GPS/teacher submission, duplicates, responder ownership, resolution, cancellation, audit rollback and failed-delivery resilience.
- Nurse dashboard data and chart/filter DOM suites passed.
- Notification header DOM tests passed.
- PWA service-worker cache/privacy/offline fixtures passed.
- MICA inventory threshold/workflow authorization fixtures passed after loading the authentication helper required by that older standalone test (`php -d auto_prepend_file=includes/auth.php scratch/test_mica_inventory.php`). Its initial standalone run failed because `canonical_role()` was not loaded; no product code was changed to mask that fixture dependency.
- Live administrator/permission before-image comparison passed after testing. No synthetic staff emergency alerts were sent.

## NOT VERIFIED

Real password-entry login/logout through a browser, visual modal interactions, responsive/device layout, actual QR-camera scanning, installed-PWA installation, external MICA model responses and live push delivery were not exercised. Session helpers, PHP rendering and automated fixtures are narrower evidence and are not substitutes for those end-to-end tests. No browser was available in this environment. Actual leadership succession was not executed because it would change real privileged account roles. No live administrator grants were changed merely to demonstrate the fix; mutation tests used temporary tables/accounts.

## Rollback

1. Retain the protected backup and `changed-files.json` before making further edits.
2. Extract `code-before.zip` to a separate review directory. Restore **only the paths listed under `modified` in `changed-files.json`**, comparing against any edits made after this repair. Do not use a blanket Git reset or overwrite the whole project.
3. The newly added service and Sections adapter can remain unreferenced after restoring their callers; deleting existing project files is unnecessary.
4. No schema rollback is required. No production permission rows changed during this implementation. If users intentionally change grants later, retain those changes unless an explicit per-account rollback is approved; do not blindly replace the live permission table with the before-image.
5. Re-run syntax checks and permission tests after restoration. Restoring the old code also restores its known authorization defects, so keep it as a recovery option rather than a security recommendation.
'''
Path('docs/co-admin-permissions-repair-2026-10-22.md').write_text(report,encoding='utf-8')
