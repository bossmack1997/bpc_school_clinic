# Evaluator critique audit — 6 November 2026

## Scope and safeguards

Reviewed the PHP/PDO architecture, session refresh and route enforcement, functional permission profiles, MIS provisioning and activation, Doctor-led Nurse verification, queue admission/triage, physician referrals, consultation persistence, patient documents, vaccination history and MICA authorization/grounding. The working tree was clean before this work. No school records were copied into fixtures. No production configuration, deployment or data migration was changed.

The application uses `users.id` as the account identity, `students.id` and `teachers.id` as patient-profile identities, `queue_numbers.id` as the operational ticket, `walk_in_visits.queue_id` as nursing intake linkage and `physician_queue.patient_user_id` as the physician patient linkage. `consultations` is the shared completed encounter history; nursing intake is retained separately as source documentation. These identifiers are not interchangeable.

## Findings and implemented changes

| Finding | Change | Regression evidence |
| --- | --- | --- |
| Missing Nurse authorization metadata inherited legacy clinical access; review labels could also imply authorization from `self_service_only=0`. | Missing metadata now denies clinical permission and keeps the account in personal services. Missing explicit review status displays pending. Doctor activation remains independent of Nurse metadata. | Missing-column HTTP denial, permission-helper tests, pending/revoked access tests, existing verified Doctor activation suite. |
| Nursing revision checks accepted an omitted/empty token and excluded triage priority. | Require the revision token; include priority in its hash. Existing row locks protect the check and write. Malformed tokens are rejected. | Missing token rejection, stale editor rejection, priority-only hash test and Doctor reassessment invalidation. |
| Triage changes lacked a required reason and a dedicated change event. | Require a reason for changed priority. Insert actor, old/new values, ticket, reason and database timestamp in the same transaction. After referral, the Doctor controls reassessment. | Saved actor/time/reason assertion; rejected changes leave priority unchanged. |
| Referrals changed operational priority without changing nursing intake. Doctor had no explicit priority reassessment action. | Synchronize referral priority to intake. Add versioned Doctor reassessment, with required reason, authorized ownership and atomic updates to all three queue sources. | Three-source consistency, stale Doctor conflict, role denial and existing concurrent claim workers. |
| Automatic ordering was emergency-first, but explicit patient selection could bypass waiting emergencies. | Guard manual Nurse call/start and Doctor claim against waiting emergency cases. Ongoing care is not interrupted. | Explicit routine-selection denials and automatic next-emergency assertion. |
| Legacy walk-in update/completion endpoints remained reachable behind the consolidated desk UI. | Return a clear conflict directing callers to Clinic Queue. The queue remains the single nursing save/completion workflow. Existing records are retained. | Both old writer endpoints return 409; unified nursing completion and physician history tests remain active. |
| Teacher emergency reporting exposed a clinical priority selector and accepted response downgrades. | Remove the selector. Treat reports as urgent requests for human assessment, irrespective of submitted priority. | Real Teacher report posting `routine` persists urgent; browser check verifies the selector is absent. |
| Medical attachment upload accepted independently supplied patient and consultation IDs. | Check the relationship before accepting a file, recheck under a transaction lock and commit the file metadata with its audit event. On failure, roll back and remove the new file. | Mismatched ownership denial, successful upload/download, cross-patient download denial and forced audit-write failure rollback. |
| Legacy encounter detail referenced nonexistent `users.birthdate`, then read vitals/medicines from the patient's latest visit rather than the selected encounter. | Use the existing date-of-birth columns, selected consultation's stored vitals and `consultation_medicines.consultation_id`. | HTTP detail succeeds with an unrelated newer visit present and returns only selected encounter data. |
| Teachers could list unrelated vaccination records. Vaccination delete erased history. | Require clinical-record permission for non-patient listing; Student listing always uses the session's own profile. Reject history deletion with correction guidance. | Teacher/MIS denials, Student scope assertion and preserved vaccination row after delete attempt. |
| MICA shared context invented fixed weekday hours, a clinic room location and 24/7 SOS monitoring. | Read the shared clinic availability policy, refresh it on each request, remove the unverified location and explicitly avoid staffing guarantees. | Four isolated tests cover configured days/hours, manual closure, updates in the same session and unavailable settings storage. |
| Operational queue endpoint retained a primary-administrator comparison inconsistent with clinical permissions. | Replace it with clinical permission checks and restrict operational mutations to Nurses. Central MIS route protection already blocked this path; this is additional endpoint enforcement, not a newly demonstrated MIS bypass. | MIS queue operation denials and existing role matrix tests. |

## Preserved account and AI behavior

MIS still provisions Student and Teacher accounts using private email activation. Doctor invitations activate the verified Doctor profile without obsolete per-page permission grants. Doctors invite Nurses; email activation alone does not grant nursing clinical authority. Revocation is read from MySQL on subsequent requests, so signing out is unnecessary.

MICA continues to use role-scoped database read models. Existing regressions verify patient isolation, restricted Nurse denials, MIS/Doctor alert separation, rejection of unauthorized write/automation actions, database-outage handling and drafts that do not create diagnoses or consultations. No new autonomous clinical decision action was added.

## Database requirements and integrity

No new schema migration is introduced. Deployments must already have the reviewed Nurse authorization, physician handoff and consultation-version schema required by these workflows. In particular, review `database/preflight_nurse_credential_correction.sql`, `database/preflight_physician_queue_hostinger_20261030.sql`, `database/migration_nurse_clinical_authorization_hostinger.sql` and `database/migration_physician_queue_hostinger_20261030.sql` against the intended target before any separately authorized migration. Do not blindly rerun historical migrations or infer approval for existing Nurses.

`tools/preflight_evaluator.php` is CLI-only, requires local/loopback configuration, runs a read-only transaction and prints aggregate integrity counts and column names, never patient rows. On the configured local database, it found zero duplicate patient-account mappings, duplicate institutional IDs, conflicting Student/Teacher mappings, orphaned consultation patient links, ambiguous consultation patient links, duplicate queue intakes, queue/intake priority mismatches or document/patient mismatches. The physician priority comparison was unavailable because the local handoff table is missing. This is an incomplete-schema result, not a clean physician-queue result. No data was repaired automatically.

## Modified files

- `includes/auth.php`, `includes/permission_profiles.php`, `includes/nurse_verification_service.php`: explicit Nurse authority and truthful pending labels.
- `includes/queue_consultation.php`, `api/queue.php`, `api/walkin.php`: revisions, audited nursing triage, emergency guard and consolidated writers.
- `includes/physician_queue_service.php`, `api/physician_queue.php`: synchronized referrals, Doctor reassessment, ownership and emergency-first claims.
- `api/medical_records.php`: attachment linkage/transaction and encounter-specific history.
- `api/vaccinations.php`, `api/emergencies.php`: patient privacy, history preservation and reporter-independent urgency.
- `includes/ai/MICAContextService.php`, `tests/mica_schedule_context.php`: current clinic policy without invented staffing or location claims.
- `pages/nurse/queue.php`, `pages/doctor/queue.php`, `pages/adviser/emergencies.php`: reason controls and understandable responsibility labels.
- `tests/evaluator_workflow_checks.php`, `tests/nursing_draft_worker.php`, `tests/doctor_clinical_checks.php`, `tests/physician_queue_checks.php`, `tests/nurse_authorization_safety_audit.php`, `tests/doctor_browser.cjs`: synthetic workflow regressions, separate-process nursing saves and responsive controls.
- `tests/mis_admin_http.php`: renew the real five-minute MIS password confirmation through its CSRF-protected form after long browser phases. An earlier combined run passed both browser phases but stopped when the proof expired; authentication was not relaxed to bypass this test issue.
- `tools/preflight_evaluator.php`, `tests/README.md`, this report: read-only integrity audit and verification instructions.

## Validation

Baseline: 398 HTTP checks passed before modifications. Final combined account/clinical/MICA/evaluator run: **487 HTTP checks passed** (PHP exit code explicitly captured as 0). Focused clinical/browser run: **279 checks passed**, including simultaneous nursing writers and browser checks at 320 and 1440 px. Separate Doctor/workflow and staff-review browser phases passed at 320, 360, 375, 390, 430, 768, 1024 and 1440 px. Their earlier combined invocation stopped later at the expired MIS password-proof assumption; the harness repair was validated in the final 487-check run. The entire eight-width invocation was not repeated after that test-only repair. The focused screenshots were also inspected with the synthetic emergency overlay closed.

All HTTP fixtures create schema-only `bpc_mis_test_*` databases containing synthetic records, block real mail and remove their databases afterward. Nurse authorization tests use a separate guarded synthetic database. Browser sessions use an isolated headless Chrome profile under ignored `scratch/`, not a personal browser profile. The headless browser was closed after verification.

Commands:

```text
C:/xampp/php/php.exe -d session.save_path=scratch tests/mis_admin_http.php --provisioning --doctor-access --doctor-clinical --staff-verification --global-mica --evaluator
C:/xampp/php/php.exe -d session.save_path=scratch tests/mis_admin_http.php --doctor-clinical --evaluator --doctor-browser
C:/xampp/php/php.exe -d session.save_path=scratch tests/nurse_authorization_safety_audit.php
C:/xampp/php/php.exe -d session.save_path=scratch tests/mica_history_scope.php
C:/xampp/php/php.exe -d session.save_path=scratch tests/mica_schedule_context.php
C:/xampp/php/php.exe -d session.save_path=scratch tools/preflight_evaluator.php
```

The focused browser command used `DOCTOR_UI_WIDTHS=320,1440`. Add `--doctor-browser` to the combined command without that environment override to reproduce the eight-width phases. Local logs are ignored artifacts: `scratch/evaluator-verified.log`, `scratch/evaluator-focused-browser.log`, `scratch/evaluator-final.log`, `scratch/evaluator-nurse-safety.log` and `scratch/evaluator-integrity.json`.

The Nurse suite passed 51 checks; MICA history isolation passed seven; MICA schedule context passed four. PHP syntax checks passed for all modified PHP files and new PHP tools/tests, and Node syntax validation passed for the changed browser suite. `git diff --check` passed. The integrity command intentionally exits 2 for nonzero findings or unavailable probes; the observed local result was 2 because physician handoff comparison was unavailable.

## Remaining risks and limits

- Production schema, data, email delivery, AI-provider transport and deployment have not been exercised. No production-ready claim follows from local fixture results.
- The local physician handoff schema needs separate operator review; missing sources continue to return an unavailable state. Missing Nurse authorization schema now denies clinical access, which can expose an incomplete older installation immediately.
- Historical encounters without stored vitals or linked medicines now show missing data rather than borrowing another visit's measurements. Backfilling requires reviewed provenance; no inference or silent repair is performed.
- Old integrations calling retired nursing writers must use the unified queue. Vaccination correction/retention policy needs an approved correction workflow; this change preserves history rather than deleting it.
- Revision tests exercise competing saved versions and two simultaneous nursing draft writers; the existing two-process test exercises simultaneous physician claims. They do not prove all possible interleavings or every legacy edit endpoint throughout the application. InnoDB deadlocks remain retryable failures, not successful saves.
- Queue polling/manual refresh reads database state without a new login; physician pages still require their Refresh queue action to show changed referrals. There is no new push-notification synchronization layer.
- This audit covers the named clinical/account paths and sampled adjacent record endpoints. It is not a penetration-test certification or proof that every unrelated endpoint and old schema variant is defect-free.

Rollback is a code revert of this commit. No reverse data migration is required. Do not revert authorization safeguards without reviewing the resulting clinical-access exposure.
