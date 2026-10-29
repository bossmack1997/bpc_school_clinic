# Final system audit: findings and implementation proposal

Date: October 29, 2026 (Philippines). Branch:
`feat/full-system-audit-20261029`. Application baseline: `73a5d24`.

**Release assessment: not ready for production approval.** Two clinical privacy/
history failures were reproduced against synthetic records. Additional account,
audit, recovery and MICA gaps remain. A passing regression suite does not negate
these findings.

The request ends: “Start by auditing the existing repository and reporting the
findings. Then implement the approved changes phase by phase.” This delivery is
the audit and reviewable implementation proposal. Application behavior has not
been changed in this phase. No feature, patient record, account, attachment,
database table or audit event has been removed. No migration or deployment ran.
The earlier workflow refinement remains intact. No push is authorized or attempted.

## Scope and evidence

- Inventoried 355 tracked, non-minified application/schema source files: 242 PHP,
  31 JavaScript, 24 CSS and 58 SQL files. Root entry points, setup routes, all role
  page folders, shared components, APIs and authentication routes are included.
- Listed all 148 tracked PHP entry routes: 40 APIs, 10 auth routes, 90 pages,
  6 setup routes and 2 root entry points. Each has its purpose/title, observed
  local guards and retention decision in
  [the route inventory](FINAL_SYSTEM_ROUTE_INVENTORY_20261029.md).
- [The source inventory](FINAL_SYSTEM_SOURCE_INVENTORY_20261029.json) records
  source hashes, includes, table references, API action candidates, terminology
  candidates, write candidates and direct activity inserts. No missing literal
  `__DIR__` includes were found. Dynamic references still require runtime review.
- Read-only local schema/aggregate checks covered 67 InnoDB tables and 65 declared
  foreign-key columns. No declared foreign-key orphans were found. Application
  mapping anomalies exist despite that result; see F07.
- 446 disposable HTTP regression/observation assertions completed for accounts,
  activation, sessions, clinical scope, queue policies, profile ownership, exports,
  MICA and audit observations.
  Audit observations deliberately report defects rather than silently treating
  them as passing security requirements.
- [Sanitized audit evidence](FINAL_SYSTEM_AUDIT_EVIDENCE_20261029.json) includes
  observed HTTP outcomes and aggregate schema checks, without patient identities.
- Offline syntax scan: 330 PHP/JavaScript checks passed, no failures. The tool
  reported no unreferenced asset candidates; this does not establish that every
  asset is needed or that every browser handler works.
- Previous baseline validation remains applicable to unchanged application code:
  445 HTTP checks, 253 browser assertions and 48 backup/security checks. Previous
  browser coverage included 320–1440px viewports. No new complete browser run or
  visual certification of all 148 routes is claimed in this phase.
- Bundled/vendor code and minified libraries were not manually audited. Historical
  database/source backup contents were not opened or copied into reports. Secret
  values, identities and medical records are excluded from committed evidence.

## Prioritized findings

### F01 — High: Teacher can read another student's vaccination record

`api/vaccinations.php` protects Student reads by session identity, but the other
branch does not restrict Teachers to their own or explicitly authorized records.
A Teacher with no assignment to the synthetic Student received HTTP 200 and the
synthetic vaccination row when requesting that student's ID. Requesting the list
without an ID also enters the unrestricted non-Student branch.

Proposed change: allow the signed-in Student's own rows and authorized clinical
staff only. Teacher personal services must never imply access to Student health
records. Use a separate explicitly scoped advisory policy if the institution
actually authorizes a specific advisory health view. Add direct-request tests
for basic Teachers, advisory Teachers, MIS, Doctor, Nurse and Student.

### F02 — High: permanent clinical deletion lacks preserved history

The Nurse vaccination delete action removed the synthetic record, returned HTTP
200 and added **zero** Activity History rows. `api/medical_records.php` also has
a source-confirmed path that unlinks an attachment and deletes its row without
a corresponding retained correction/version record. That file-delete path was
not executed against school documents.

Proposed change: preserve originals; use a recorded correction/void workflow with
author, reason, time and related record reference. Define who can void a record;
do not broaden Nurse diagnosis/prescribing permissions. Retain historical medical
documents. `api/certificates.php` already returns 410 before its legacy delete
branch: the later DELETE text is unreachable and is **not** evidence that the
current certificate endpoint permits deletion.

### F03 — High: legacy clinical dashboard bypasses intended MIS separation

`pages/admin/clinical_legacy_dashboard.php` checks only the `admin` database role
before querying consultations, SOS, medicine and queue activity. Functional MIS
and Doctor profiles share that stored role. The main MIS dashboard and its API
already separate their data, but this old direct page does not have the same
functional boundary. A focused direct-route observation returned HTTP 200 for MIS;
the template queries and renders clinical activity without the required boundary.

Proposed change: preserve bookmarks by redirecting to the permitted current
dashboard or deny access before any clinical query. Check every retained alias
by functional profile, not by sidebar visibility.

### F04 — High: Activity History does not meet the requested event contract

Local `activity_logs` contains only `id`, `user_id`, `action`, `module`, `details`,
`ip_address`, `created_at`. There is no structured result or related-record key,
and displayed role/name come from the current user join rather than a recorded
event snapshot. There are 26 direct INSERT sites as well as `log_activity()`.
The helper catches insertion errors and continues, so some successful writes can
lose their audit record. Failure outcomes are not consistently recorded.

`api/activity_logs.php` is correctly restricted to technical activity and hides
IP addresses. It does not provide an own-account activity endpoint or a dedicated
Doctor clinical audit query. Patient visit/QR timelines provide useful clinical
history but are not a complete sign-in/password/account activity history.
Some legacy log details include medical values, such as a recorded BMI, rather
than only a protected record reference.

Proposed change: one server event service with explicit action, result,
actor reference/snapshot, module and related record. Sensitive mutations and
their success audit must commit together; rejection events must survive rollback
without logging secrets or clinical narrative. Add append-only event rows rather
than rewriting historical entries or manufacturing historical outcomes. Show
legacy outcome as “Not recorded.” Provide separate own, technical and clinical
queries with user/action/date filters and Philippine time. An additive migration
and versioned deployment contract are required.

### F05 — High: legacy administrator creation bypasses preferred recipient setup

Routine Student/Teacher/Nurse accounts and privileged Doctor invitations use
expiring hashed setup tokens. Retain that stronger option: the request explicitly
prefers setup links over emailed passwords. Recipients set a private initial
password before dashboard access; no temporary-password first-login mode is needed
for that flow.

However, the restricted `api/users.php?action=create_admin_account` path accepts a
password chosen by the current Primary Administrator and writes
`email_verified=1` immediately. It creates a co-admin without the recipient setup
flow or an explicit MIS functional profile. It is Primary-only, so this is not
an anonymous account-creation exploit. No current page caller was found in the
static search; direct API access still exists and cannot be dismissed as unused.

Proposed change: restricted MIS appointment/invitation with current Primary MIS
reauthentication, authority reference, recipient email ownership, pending status
and explicit profile. Reuse the token/password policy. Link Doctor/MIS appointment
entry points from Create Accounts without mixing them into ordinary bulk role
selection. Preserve current accounts and primary succession protections.

### F06 — High requirement gap: spreadsheet restore is not implemented

The existing BackupService supports verified SQL exports and restoration into a
separate empty database after MIS password confirmation. It rejects overwriting
the active/nonempty database. Existing report CSV/XLSX files are not restorable
relational backups. There is no upload/version/relationship preview/import flow.

The SQL service exports base tables; it does not itself package uploaded medical
documents, Nurse credentials, views, routines, triggers or scheduled events. It
must not be advertised as complete application recovery by itself. The existing
deployment guide correctly requires file/assets preservation separately.

Proposed change: a documented versioned package with a manifest, explicit supported
table mappings and row/reference checks, using multiple CSV tables or worksheets
rather than a flat spreadsheet. Never export passwords, password hashes, OTPs,
activation tokens, sessions or provider secrets to these workbooks. Clinical
exports remain restricted to their authorized scope. Define supported record
types before presenting the file as restorable. Uploaded role/account data must
never grant privileges merely because a spreadsheet requests them.

Restore first into a separate recovery database: strict archive/upload limits,
schema/version checks, formula rejection, duplicate/conflict/orphan preview,
reauthentication, plan-bound confirmation, recovery point and audit result.
Uploads must remain private and expire. A production cutover remains a separate
explicit approval; do not add a one-click overwrite route. Preserve the existing
tested full database mechanism and a separate asset recovery procedure.

### F07 — High data integrity issue: profile relationships need verified repair

The read-only local aggregate check found:

- One Student account with no `students` profile.
- One `teachers` profile mapped to a user whose stored role is not Teacher.
- No duplicate user-to-profile mappings in Student, Teacher or Nurse tables.
- No orphan rows for declared foreign keys; every local table reports InnoDB.

These are local findings, not claims about Hostinger. They explain why foreign-key
validity alone cannot establish correct profile loading or role ownership. The
new Student error state handles an absent mapping safely; it cannot determine
the correct missing relationship. MIS must compare official IDs and appointment/
enrollment evidence. Prepare an exact proposed mapping and affected relationships
for review; never attach an arbitrary profile or create replacement clinical data.
No record identifiers or personal data are included in this report.

### F08 — High: MICA includes unverified operating and emergency claims

The active `MICAContextService` inserts fixed weekday hours and the statement
“Emergency SOS monitored 24/7” into general role context. The disposable fixture
reproduced both strings. This can conflict with Nurse-configured hours and does
not demonstrate actual round-the-clock staffing. Its location description is also
fixed rather than read from verified settings.

Proposed change: reuse clinic availability/settings and verified emergency contact
information. State unavailable data explicitly. Keep the emergency reporting path
without promising monitoring or response coverage that is not established.
The older DatabaseContext also contains fixed schedules and legacy fallbacks,
but the current AIService uses MICAContextService: these two source paths must not
be confused when fixing the active behavior.

### F09 — Medium: navigation differs from the latest requested structure

The new request supersedes the earlier preference to put all MIS history/backup
entry points under Settings. MIS now needs direct **Activity History** and
**Backup & Restore**, alongside Dashboard, Create Accounts, Users and Settings.
Security controls still belong in Settings. Doctor already has the seven requested
entries. Nurse keeps Professional Credentials as a necessary authorization path;
it can move under Profile/Settings while restricted Nurses retain direct access.
Student/Teacher need **Join Queue**, **My Clinic Visits**, **My Profile** wording.
Teacher advisory tools must remain available only where separately authorized,
without appearing as general personal clinical access.

Proposed change: reuse existing routes, relabel and relocate links. Do not build
duplicate clinic-visit pages or remove existing medical history. Preserve the
top emergency control, messaging and required Nurse credential correction access.

### F10 — Medium: duplicated views need consolidation, not blanket deletion

- Doctor `patients.php` delegates to `consultations.php`: one underlying workspace,
  not two independently duplicated record stores. Retain the alias and its context.
- Admin pending-account, medical-record, account-exception and QR-scanner aliases
  preserve old links; redirect them to authorized current destinations.
- Embedded QR scanner, scanner modal and health-pass display serve different
  contexts. Their camera handling can share one implementation after testing,
  but the active `includes/qr_scanner_ui.php` is not approved for deletion.
- `api/users.php` retains obsolete create branches after an earlier blocking gate.
  Confirm the gates and all callers before removing unreachable code.
- Existing incident review has a real staged workflow in SecurityIncidentService.
  Retain it for authorized MIS, with simple “Review Details” wording where accurate.
  Do not remove it merely because the UI mentions investigation.
- Setup routes are intentionally blocked by Apache rules. Initial installation
  procedures need operator documentation; removing the protection is not an
  appropriate way to repair a setup link.

### F11 — Review required: tracked backup snapshots and full UI coverage

Git tracks historical ZIP/SQL backup artifacts. Apache rules deny web access, but
that does not protect their contents in repository history. Their contents were
not opened during this audit. An authorized confidential provenance review must
establish whether they include private data or obsolete configuration. Do not
publish additional copies, purge Git history or delete recovery evidence
automatically.

The static inventory identifies all routes; it does not prove every old button,
camera/device interaction, print/download dialog, icon or popup works. Complete
per-role browser walkthroughs and Hostinger testing remain release requirements.

## Feature decisions before implementation

| Feature | Purpose and users | Current evidence | Proposed decision |
| --- | --- | --- | --- |
| Public registration routes | Historical onboarding | GET/POST retirement checked | Retain denial/legacy activation compatibility; no public creation |
| Routine account setup | MIS creates Student/Teacher/Nurse | Manual/CSV/XLSX, duplicate preview and token tests | Retain; unify clear Create Accounts entry points |
| Doctor appointment | Primary MIS authorizes physician account | Invitation, setup, scope tests | Retain separate appointment authority |
| MIS appointments/succession | Restricted system responsibility | Primary checks exist; recipient setup gap F05 | Modify creation; retain succession/history |
| Password setup/reset/sign in | All account holders | Token expiry/replay, hashes and session checks | Retain setup links; remove technical labels only |
| Technical security/incident review | Authorized MIS | Real review stages, protected routes | Retain inside Settings |
| Activity History | Own users, MIS technical, Doctor clinical | Partial coverage; missing contract F04 | Extend one event service and scoped views |
| People directory | MIS identity administration | Filtering/access/failure-state checks | Retain as Users; keep official ID relationships |
| Student/Teacher profiles | Account-owned information | Ownership/error tests; local mapping anomalies | Retain; verified mapping repair after review |
| Clinic admission/triage | Patients request; Nurse prioritizes | Closed/holiday/capacity/duplicate/priority tests | Retain; Join Queue wording |
| Physician handoffs | Nurse refers; Doctor assesses | Claim/version/ownership/finalization tests | Retain; no automatic Nurse privilege escalation |
| Nursing intake/vitals | Authorized nursing services | Scope guards and intake regressions | Retain assessments and authorized interventions |
| Nursing first aid/monitoring | Nurse daily operations | Existing workflows; individual legacy screens not all replayed | Retain; test Doctor-unavailable scenarios |
| Consultation/visit history | Doctor, Nurse and own patient scope | Shared workspace and clinical tests | Merge navigation only; retain authorship/history |
| Vaccination history | Own Student and authorized clinic staff | F01/F02 reproduced | Restrict reads; preserve corrections/history |
| Medical attachments | Protected clinical documentation | Authorized file endpoint; F02 source finding | Retain files; audited void/version workflow |
| Certificates | Physician medical approval; Nurse attendance only | Nurse restrictions and delete retirement checked | Retain required approvals and history |
| Inventory/dispensing | Nurse operations, Doctor oversight | Inventory/role/order tests in baseline | Retain original tools; MICA is an assistant |
| Announcements | Nurse publishes; audiences read | Publishing/audience/mobile baseline tests | Retain dashboard shortcut |
| Clinic messages | Authorized participants and clinical coordination | Participant/session/avatar/browser tests | Retain; no message contents in general audit |
| Emergency/SOS | Existing alert path for patients and responders | Permission/confirmation baseline tests | Retain; remove unverified monitoring guarantees |
| Advisory classes | Separately authorized Teacher duties | Ownership helpers exist | Keep authorized access, simplify personal menu |
| CSV/Excel reports | Role-authorized reporting | Valid OOXML/CSV/date/formula tests | Retain; never label ordinary exports full backups |
| SQL database recovery | Authorized MIS continuity | 48-check prior isolated recovery suite | Retain; improve Backup & Restore wording |
| Logical upload/restore | Authorized recovery of supported datasets | Not implemented | New reviewed package/preview/recovery phase |
| MICA chat/history/reports | Role-scoped assistance | Scope/history/failure tests; F08 active claim | Retain useful integrations; fix verified settings |
| QR scanner/modal/pass | Intake scan, shared modal, patient pass | Existing component dependencies and baseline QR tests | Share implementation only after device testing |
| Dashboards | Actual role-specific activity | Baseline role counts; F03 legacy exception | Retain current pages; guard/redirect legacy route |
| Settings/PWA/install | Role preferences and authorized configuration | Session/settings baseline, setup blocked intentionally | Retain; use clear installation instructions |

**Removed in this phase:** nothing. **Merged in this phase:** nothing.
**Retained:** all application functionality and historical records.
**Added:** reproducible audit tools, source/route inventories and synthetic
observations. The table describes proposed changes, not completed fixes.

## Proposed small implementation commits

1. **Privacy and history protection:** F01–F03 first. Restrict vaccination reads,
   preserve clinical deletion history, protect legacy pages, test direct requests
   under every role. Use additive void/version fields/tables if required; do not
   delete or rewrite old records. These are release blockers.
2. **Account and activity contract:** F04–F05. Restricted MIS invitations, unified
   server events, immutable sensitive history and scoped Activity History. Prepare
   forward migrations and data-preserving rollback instructions. Historical result
   fields remain unknown rather than guessed.
3. **Verified MICA and navigation:** F08–F10. Read actual operating settings,
   remove unverified emergency claims, align menus/wording with the latest request,
   preserve authorized shortcuts and test shared QR dependencies.
4. **Backup & Restore:** F06. Implement an explicitly documented supported package
   and preview into a separate recovery database. Verify recovery point/assets and
   referential integrity. Production overwrite/cutover is excluded without a
   separate exact reviewed plan and approval.
5. **Verified relationship repairs and final QA:** F07/F11. Review official mapping
   evidence, prepare exact repairs, complete remaining route/button/device checks,
   real SMTP receipt and Hostinger staging tests. No cleanup by filename or marker.

Suggested backup package scope for review: a versioned manifest with separate
supported account identity/reference, clinic visit and inventory datasets. Secrets,
session data and access grants are excluded from spreadsheet imports. Each dataset
must have its own authorization and referential rules before implementation.

## Reproducible checks and limitations

```text
python tools/audit_final_sources.py
C:/xampp/php/php.exe tools/audit_final_schema.php
python tools/audit_cleanup_sources.py
C:/xampp/php/php.exe -d session.save_path=scratch tests/mis_admin_http.php --doctor-clinical --staff-verification --doctor-three-pages --global-mica --student-ui --reauthentication --provisioning --exports --workflow-simplification --final-system-audit
```

`audit_final_schema.php` refuses non-loopback database hosts, uses a read-only
transaction, and emits schema metadata/aggregate counts only. Keep raw local
schema output under ignored `scratch/`; do not attach database dumps. The HTTP
suite creates and destroys schema-only disposable databases with synthetic data.
Audit observations are in `scratch/final-system-audit-evidence-20261029.json`;
HTTP output is in `scratch/final-system-audit-http-20261029.log`.

No migration is required for these audit-only tools/reports. Future phases need
reviewed additive migrations. No real SMTP send, external model response quality,
production backup, Hostinger schema inspection, production restore, exhaustive
device walk-through or permission to publish/merge/deploy is claimed.
