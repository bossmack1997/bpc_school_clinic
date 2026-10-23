# Production artifact cleanup audit — 2026-10-23

Branch: `cleanup/production-artifacts-20261023`.

This is a verified, non-destructive cleanup pass, not a certification that all
production workflows have been tested. Existing local MIS/security changes were
preserved and excluded from the cleanup commit. No production server was used.

## File decisions

| File/group | Evidence and action |
| --- | --- |
| `registration_scroll_probe.php` | Standalone responsive registration diagnostic, no external source references found. Relocated to `tests/registration_scroll_probe.php`; iframe URL adjusted for its new directory. Removed from public root, retained as a development utility. |
| `temp_icon_audit.py` | Standalone icon diagnostic, no external source references found. Relocated to `tools/audit_icons.py`; removed hardcoded checkout path and excluded browser/runtime/vendor trees. |
| `assets/js/admin-settings.js` | No filename reference detected, but `settingsMenu` still exists in the locally modified settings page. Retained pending reconciliation with existing MIS work. |
| `assets/js/mica_audio_integration.js` | No active filename reference found. References `MicaAudioStreamer`; streamer references the worklet. Retained as an interconnected MICA subsystem, per feature preservation requirement. |
| `assets/js/mica_audio_streamer.js`, `mica_audio_worklet.js` | Referenced by the audio subsystem; retained. |
| `pages/style_guide.php` | Development utility already requires local environment and login. Retained. |
| `backup_pre_role_simplify.zip`, `backup_pre_mis_doctor_workflows_20261022.zip` | Recovery snapshots, not proven disposable; retained and HTTP access denied. Do not publish these archives. |
| `database/backup_before_admin_refactor_20261022.sql`, `database/bpc_clinic_system.sql` | Backup/recreation material; retained, existing database directory denial verified. Data-bearing tracked files need a separate retention/history review. |
| `database/migration_*`, preflight/verify scripts | Needed for database maintenance/recreation. Retained. No migration run by cleanup tools. |
| `setup/`, `tools/` | Useful maintenance scripts retained. HTTP access blocked; tools also have directory-level denial. |
| `tests/` | Useful regression suites retained; directory-level and root HTTP denial added. Execution documented in `tests/README.md`. |
| `scratch/`, logs, browser profiles | Ignored runtime artifacts; retained without recursive deletion. Private HTTP access verified. Some browser profile directories denied filesystem enumeration, so their contents were not inspected. |
| Patient images, identity uploads, medical attachments, audit history | Retained. A static filename search cannot establish whether a database-backed upload is unused. |
| Role pages, APIs, CSS, shared components, third-party dependencies | No additional removal approved by reference evidence. Retained, including dynamic and role-based entry points. |

No production feature, role navigation item, endpoint, medical record, account,
or audit record was deleted. The two root utilities were relocated, not discarded.
No broken application reference was found for either relocation. All other
unused-file findings are candidates, not confirmed dead code.

The offline source inventory covered 493 files before this report/README were
added, excluding vendor, runtime/browser profiles, Git and editor metadata.
The complete machine-readable inventory, syntax results and asset candidate list
are in ignored `scratch/cleanup-audit/sources.json`. The filename reference scan
does not prove absence of dynamic loading, external callers or CSS use.

## Database review — local only

Audited 67 tables in a read-only transaction without the application bootstrap.
All 65 declared foreign-key relationships had zero orphan rows. Row counts were
identical before and after regression suites. This verifies counts and declared
FK integrity, not every column value or application-level relationship.

| Candidate | Evidence | Decision |
| --- | --- | --- |
| `sections.id=47`, `SMOKE-A` | Exact smoke marker; zero `students.section`, `advisory_assignments.section_id`, and `sos_emergencies.section_id` matches. | Retain pending provenance, audit-text/history and legitimate-use review. |
| `sections.id=48`, `SMOKE-B` | Same checks; zero assignments. | Retain pending the same review. |
| `ai_actions.id` 9–14 | Title matches marker expression. | Unconfirmed; AI actions may represent genuine work. Retain. |
| `conversations.id=1` | Title matches marker expression. | Unconfirmed; inspect participation/history privately before any proposal. Retain. |
| `notifications.id` 97, 102, 107, 112, 117, 122, 127, 186, 195, 204, 217, 230, 251, 272, 285, 302 | Title matches marker expression. | Unconfirmed; could describe real events. Retain. |

No user accounts matched the searched markers in the inspected identity fields.
This does not establish that no dummy accounts exist. Search covered selected
identity/name/title fields, not every free-text column. No appointments,
consultations, inventory entries or clinical data were proven artificial.
Details stay in ignored `scratch/cleanup-audit/database.json` and
`database-after.json`; personal names, emails, credentials and message bodies
were not included in the report.

**Proposed permanent database deletion allowlist: empty. Approved removals: zero.**
SMOKE-A/B are review candidates only. No backup was needed for data cleanup
because no data cleanup was performed. Before any future deletion, establish
provenance, inspect all assignments, clinical/QR/queue transactions and audit
history; obtain explicit approval for exact IDs; verify a backup by restoring it
in isolation; then execute reviewed SQL inside a transaction. Never erase medical
history or audit logs. No live Hostinger connection or destructive live test ran.

## Development/production separation

- Apache blocks `tests/`, `tools/`, and `vendor/`, in addition to previously private
  directories. Directory-level denial also protects tests/tools.
- Root access rules deny archives and Python/CommonJS source artifacts. Existing
  SQL, log, environment, Markdown and diagnostic prefix restrictions remain.
- Existing configuration sets `display_errors=0` and production `APP_DEBUG=false`.
  Environment inference and all existing credentials were left unchanged. Verify
  the actual deployed environment independently; local CLI checks do not certify it.
- No secrets were added. Existing tracked SQL dumps and source archives may contain
  sensitive historical data; a complete secret/history audit remains outstanding.
- Apache protections require Apache and enabled overrides. Other web servers need
  equivalent rules; do not deploy tests, tools, source snapshots or browser profiles.

## Validation

| Check | Result |
| --- | --- |
| PHP and JS/CJS syntax | 277 files passed, zero failures. |
| Registration consent source checks | 9 passed. |
| Mobile messaging CSS source checks | 6 passed; not a visual browser test. |
| Doctor response contracts | 7 passed. |
| `tests/mis_admin_http.php` | 46 passed: MIS/Doctor login, roles, directory, invitations, profile/password, personal SOS scope. |
| `tests/doctor_invitations.php` | 40 passed with fake mail and isolated local schema. |
| `tests/basic_registration_http.php` | 107 passed: Student/Teacher/Nurse registration and role boundaries, OTP/login, reset, SOS and attachment ownership. |
| Existing uncommitted `tests/mis_complete_audit.php` | 48 passed: administrator access changes, incidents, settings, statistics, backup creation/hash/download, restore to isolated database and audit preservation. Suite itself excluded from cleanup commit. |
| Apache anonymous access | 403 for tests, tools, backup ZIP, SQL, scratch report and vendor autoloader; login/register 200; index 302. |
| Local DB post-check | Unchanged table counts, zero declared FK orphans. |

Initial consent/mobile checks encountered sandbox denial of the system PHP session
directory; rerunning with `session.save_path=scratch` passed cleanly. HTTP
registration tests used workspace-local TEMP/TMP. Regression suites created and
removed synthetic fixture databases/files only; none used live clinical records.
The backup suite verified a synthetic backup and isolated restore, not a backup
of the actual school database.

Remaining regression work: interactive responsive layouts across all roles,
queue/consultation lifecycle, QR camera and physical scan flow, full inventory and
messaging interactions, MICA live chat/audio, and real deployment email/push
delivery. Existing suites cover some role and clinical access boundaries but do
not establish end-to-end success for those workflows. No production-ready claim
should be based solely on this cleanup pass.

## Remaining recommendations

1. Review SMOKE section provenance and audit/history references; submit a separate
   exact-ID deletion proposal only after all relationship checks are complete.
2. Reconcile administrator settings JavaScript against the existing local MIS
   changes; decide MICA audio support before retiring its interconnected files.
3. Move retained source/database snapshots to verified private storage and perform
   a dedicated secret/history review without rewriting Git history automatically.
4. Complete the remaining staging/browser checks above with synthetic fixtures.
5. Push/open a PR only when authorized. Existing unrelated local changes remain
   outside the cleanup commit and need their own review.
