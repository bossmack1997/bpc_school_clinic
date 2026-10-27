# Clinical workflow release — October 28, 2026

Target milestone: November 3, 2026. Feature branch:
`feat/final-clinical-workflows-20261028`. Starting commit:
`e41b9f95fea1ff21cbeda9bfcec107e726270bed`. The existing application, four
database roles and Doctor admin-profile mapping are retained.

## Audit and implementation

The repository began clean. Existing Doctor history, document downloads,
authorship/version checks, staff review, messaging and MICA were reused. Concrete
gaps were the absence of an explicit physician handoff, nursing APIs accepting
physician fields, public registration, missing MIS routine provisioning, queue
policy enforced only by informational UI, and ordinary log responses exposing IPs.

Source backup: ignored `scratch/final-clinical-20261028/source-before.zip`, with
the base commit and initial diff beside it. This is a source snapshot, not a
verified disaster-recovery backup. No application/Hostinger data migration was
run. Integration tests create schema-only disposable databases and synthetic
accounts, documents and records; they never copy school records.

Implemented behavior:

- **Doctor:** explicit Nurse-to-Doctor queue for Student and Teacher profiles;
  saved nursing assessment required for referral; actual intake/vitals shown;
  Nurse-confirmed urgency; claim ownership; optimistic handoff versions; atomic
  physician findings, diagnosis, treatment, prescription, follow-up and completion.
  The physician encounter reuses `consultations`; the handoff links its stable
  patient account, operational ticket and author. Nursing records stay separate.
  Dashboard cards count unique physician patients today, awaiting physician
  handoffs, unresolved urgent handoffs and pending Nurse reviews. Failed sources
  remain unavailable. Existing clinical, medicine, emergency and message oversight
  remains available; MIS administration stays denied.
- **Nurse:** observations, vital signs, triage, priority confirmation, nursing
  interventions, medicine dispensing, coordination and referral remain. Four
  clinical write APIs reject physician diagnosis/prescription/treatment/signature
  payloads. Nursing intervention text uses the existing treatment storage field
  on nursing records, with explicit UI labels. Historical diagnosis/signature
  values are retained. Physician medical/fitness certificates are blocked for
  Nurses; clinic attendance confirmation remains. Historical certificates cannot
  be deleted through the former delete endpoint. Restricted Nurses can reach
  their credential correction and own-profile APIs, but not early queue check-in
  or clinical QR paths. Physician consultation permission is Doctor-only.
- **Nurse verification:** existing Doctor approval, information requests,
  rejection, revocation, review history and credential-version session invalidation
  are retained and exercised. Fixed the restricted-account route guard that had
  made Professional Credentials inaccessible. Updated old fixtures to supply an
  actual synthetic document and valid PRC format instead of weakening eligibility.
- **Provisioning:** MIS validates a single account or previews CSV/native Excel
  imports (100 rows / 1 MB). Templates exist in both formats. Role, row, identity
  and duplicate checks run again under a serialized transaction before creation.
  A rejected batch writes nothing and never overwrites accounts. Each recipient
  receives an individual 24-hour single-use activation link; only its hash is
  stored. Recipients privately choose their initial password. Delivery failure is
  reported truthfully and leaves the account pending; resend invalidates prior
  links and throttles recent successful sends. Nurse activation grants no clinical
  scope. Doctor invitations retain their separate privileged appointment process.
  Public registration GET/POST routes return 410; existing recipient activation
  routes remain available. Email availability checks are MIS-only, debounced and
  protected against stale UI replies. Profile writes cannot transfer a verified
  email flag onto a different address; official-email changes require a separate
  MIS verification process.
- **Queue:** all normal ticket generators share a database-scoped admission lock,
  backend schedule/closure/capacity checks, current-read duplicate checks and dated
  ticket numbers. Nurse operations configure Manila hours, weekdays, closures,
  current closed status and capacity. Patient urgency is stored as a preliminary
  intake warning; it cannot set confirmed emergency priority. Patient identity is
  resolved from the session. Polling updates availability and disables closed
  admission; SOS remains available independently. Anonymous monitors use the
  privacy-safe `public_display` rather than authenticated `get_status`.
- **Profiles:** existing Student/Teacher account-to-profile relationships and
  data-fetch paths are verified. No fabricated replacement data was introduced.
  Doctor patient records additionally show recorded vital signs with their date.
- **Exports/security:** server-authorized UTF-8 CSV and real OOXML Excel reports
  for clinical consultations, aggregate queue activity, current medicine stock and
  MIS account inventory. Dates, role boundaries, field selection, download errors
  and 5,000-row limits are enforced. CSV formulas are neutralized; Excel values are
  literal cells. Clinical/technical audit events remain stored; ordinary Activity
  Logs no longer returns IP fields or clinical-module events. Existing protected
  Security Center authentication/incident controls and database recovery remain.
- **MICA:** explicit physician-queue summaries use real backend handoff counts;
  existing authorized drafts, Nurse-review summaries, inventory and patient-scoped
  lookups remain. AI cannot claim/finalize care, prescribe or approve credentials.

## Schema and Hostinger deployment

New migrations (all rerunnable; existing rows/columns are not removed):

1. `database/migration_physician_queue_20261028.sql`: creates the handoff table,
   unique ticket/encounter links and restrictive foreign keys.
2. `database/migration_account_provisioning_20261028.sql`: creates hashed
   activation invitations and a transaction-serialization row.
3. `database/migration_queue_policy_20261028.sql`: inserts missing policy defaults
   without replacing an existing value.

Deployment order:

1. Put staging/production into a controlled maintenance window. Back up current
   files, `.env`, private documents/uploads and database, and verify a restore into
   an isolated database. Keep the backup outside the web root.
2. Run `preflight_final_clinical_20261028_hostinger.sql`. Its fixed intended schema
   is `u962995739_bpc_clinic`; selected context is reported separately. Resolve every
   missing prerequisite and inspect existing-table definitions, engines, signed
   INT keys and full-column unique email/username indexes. Target identity alone
   is not deployment approval. The CLI alternative is
   `php -d session.save_path=<writable-private-directory> tools/preflight_physician_queue.php`.
3. Verify the already-reviewed Teacher consultation/document, Doctor invitation,
   basic email activation, Nurse clinical authorization and Nurse credential
   correction migrations are installed. Follow their existing preflight and
   verification documents for missing prerequisites. **Do not blindly replay old
   role-consolidation or unrelated migration files.**
4. Explicitly select the application database before applying the three new
   migrations in the order above. The preflight does not select it for you.
   Execute `verify_physician_queue_20261028.sql` and
   `verify_account_provisioning_20261028.sql`; orphan/inconsistent and duplicate-link
   checks must return no rows. Review definitions against the migration SQL.
5. Upload the reviewed application files together, preserving production `.env`,
   private uploads and runtime storage. Keep tests, scratch, backups and tools
   inaccessible over HTTP. PHP needs PDO MySQL, mbstring, Fileinfo, SimpleXML,
   libxml and ZIP. Set PHP/application and DB connections to Manila / `+08:00`.
   Confirm `GET_LOCK` / `RELEASE_LOCK` support and writable private temp/storage.
6. Configure and verify SMTP/TLS using real controlled test recipients. New links
   carry tokens in URL fragments; verify the JavaScript-to-POST bridge, HTTPS,
   no-store headers and recipient delivery. Never publish test tokens or passwords.
7. Smoke-test MIS single/bulk preview, CSV/XLSX uploads, duplicate/import rejection,
   pending-account login rejection, activation/password setup, expiration/resend,
   restricted Nurse credentials, Doctor authorization and session invalidation.
8. Smoke-test Student/Teacher profiles, closed/holiday/capacity admission, duplicate
   and concurrent requests, preliminary urgency, QR entry, SOS, nursing draft and
   completion, physician referral/claim/completion, author ownership and audit.
   Verify all four Doctor cards against SQL and exercise unavailable-schema states.
9. Download and inspect CSV/XLSX reports under each role; verify MIS/clinical
   separation and technical backup restrictions. Check modified pages on mobile,
   tablet and desktop, including modals, sidebar, MICA and notifications. Clear
   opcode/browser caches as appropriate before opening the system.

Rollback preserves data: return to maintenance, preserve/export the new handoff
and activation audit tables, restore the reviewed prior code only after checking
its access rules, and keep all additive tables/columns. Old code can reintroduce
the previous nursing/registration behavior, so do not reopen access blindly.
Prefer a forward fix. Do not drop populated tables or restore a stale database
over records created after backup. Record policy values before changing them;
the defaults migration intentionally does not overwrite them.

## Validation and limits

Reproducible integrated command:

```text
C:/xampp/php/php.exe -d session.save_path=scratch tests/mis_admin_http.php --doctor-clinical --staff-verification --doctor-three-pages --global-mica --student-ui --reauthentication --provisioning --exports --final-browser
```

`--doctor-browser` additionally runs the eight-width Doctor and credential-modal
suite. Browser flags require a separate loopback-only headless Chrome instance on
port 9337. Tests discard their databases and synthetic files. See the final
validation results recorded below and ignored logs under
`scratch/final-clinical-20261028/`.

Production readiness remains conditional: live Hostinger MariaDB/PHP extensions,
SMTP receipt, real-device behavior, production-scale concurrency/performance and
backup restoration must be verified on staging/hosting. Local MariaDB is 10.4.32;
the reported Hostinger version is 11.8.9. Excel import accepts one plain-value
worksheet; formulas, macros, external links and oversized expanded archives are
rejected. No production deployment, merge or schema application is claimed.
