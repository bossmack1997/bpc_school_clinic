# Patient records, MIS language and student import improvements

## Behavior

- Doctor Patient Records and Consultations share one patient workspace, with
  patient information, history, documents, treatments, follow-ups and an editor
  restricted to the Doctor's own encounters. The old consultations URL still
  works. Clinic Queue remains separate.
- Nurses use the same Student/Teacher directory and history, with the existing
  nursing consultation modal and queue actions. QR scanning and the extended
  student vaccination/health timeline remain available. Doctor APIs stay closed
  to Nurses; the read-only clinical service cannot save physician encounters.
- MIS account, security, directory and monitoring wording is simpler. Permission
  checks and audit records remain intact. Setup failures show a safe message.
- Student CSV/XLSX uploads retain server-side validation and preview. Confirmation
  imports valid students, reports invalid/skipped records, never updates existing
  accounts, and offers a CSV correction report. Reports contain no passwords or
  activation tokens and escape spreadsheet formula prefixes.
- Each imported account uses the existing transaction, shared creation lock,
  unique identity constraints, private password selection and activation email.
  One database row failure rolls back that account and lets other valid rows run.
  Email failure leaves the created account pending and is shown for resend.

## Files

- `includes/doctor_patient_workspace.php`, `includes/doctor_clinical_service.php`,
  `includes/head.php`, `components/sidebar.php`: unified records and scoped UI.
- `pages/doctor/consultations.php`, `pages/nurse/patient_history.php`,
  `pages/nurse/patient_timeline.php`: compatible clinical entry points and timeline.
- `includes/account_provisioning_service.php`, `pages/admin/provision_accounts.php`,
  `assets/js/account-provisioning.js`: partial imports, summaries and safe reports.
- `pages/admin/admin_accounts.php`, `dashboard.php`, `security.php`, `users.php`,
  `system_monitoring.php`: clearer MIS wording.
- `tests/account_provisioning_checks.php`, `doctor_workspace_checks.php`,
  `doctor_browser.cjs`, `student_import_worker.php`, `README.md`: regressions.

## Verification

All database writes in verification use a disposable schema-only `bpc_mis_test_*`
fixture with synthetic accounts. School records are not copied or modified.
Mail delivery is stubbed or blocked against loopback, so no real invitations send.
Browser screenshots remain under ignored `scratch/doctor-browser/`.

The import suite requires `--staff-verification` to build Nurse authorization
fixture columns used by the existing account invitation checks. Run:

```
php tests/mis_admin_http.php --provisioning --doctor-clinical --staff-verification
```

Add `--doctor-browser` with isolated headless Chrome on port 9337 for responsive
checks at 320, 360, 375, 390, 430, 768, 1024 and 1440 pixels. Browser checks cover
Doctor consultations/MICA, Nurse history and MIS upload/template availability.

PHP lint, Node syntax and `git diff --check` are also required before commit.
Real email inbox delivery and production deployment are outside these synthetic
checks. Existing account-setup database migrations must already be installed.

## Executed results

- Final HTTP/SQL suite above: **343 checks passed**, exit 0.
- Browser portion of the earlier `--provisioning --doctor-clinical --doctor-browser`
  run: all eight widths passed for Doctor, Nurse and MIS, including MICA drafts,
  with no uncaught browser exceptions. That combined run later stopped at the
  import fixture's missing Nurse schema prerequisite; the complete HTTP/import
  suite was then rerun successfully with `--staff-verification` as shown above.
- PHP syntax: all 17 changed/new PHP files passed.
- Node syntax: account provisioning JavaScript and Doctor browser suite passed.
- `git diff --check`: passed. Reviewed mobile MIS and desktop Nurse screenshots.

No production database migration or production patient data mutation was run.
