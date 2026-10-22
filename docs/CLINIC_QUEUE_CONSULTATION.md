# Integrated nurse queue and consultation workspace

`pages/nurse/queue.php` contains the existing queue controls and an inline consultation panel. Selecting **Open** previews a visit; **Start Consultation** starts it. **Save Draft** persists clinical fields in `walk_in_visits`. The original detailed walk-in form is reused in `includes/queue_walkin_ui.php`; the existing quick check-in and QR scanner remain available.

The panel includes original consultation fields, respiratory rate, BMI, follow-up, digital signature, editable AI summary, recent history, immediate dispensing, medication selection for completion, and certificates. `walkin.php` remains a compatible entry point and renders the integrated queue. The nurse sidebar has one Clinic Queue entry.

`includes/queue_consultation.php` provides ticket locks, existing-visit reuse, clinical draft persistence and revision checks. Completion retains the existing atomic queue API workflow and links all dispensed medicines to consultation history. Failed saves retain entered fields; polling updates queue data without replacing the form. Closed visits cannot be reopened through draft/start/completion actions. Clinical drafts persist in the database; pending medication and certificate selections are stored in the authenticated session, so those selections require review after the session expires.

No database migration or new dependency is required.

## Verification

- `C:\xampp\php\php.exe scratch/test_queue_consultation.php`: real MySQL SQL and API-action tests within a rollback-only connection. Covers nurse authorization, CSRF, walk-in creation, duplicate active check-ins, patient context, start/resume, clinical draft persistence, BMI, concurrent-edit rejection, rollback on failed dispensing, successful completion, medicine/history linkage, and duplicate-completion protection. All fixture records and stock changes are rolled back.
- `node scratch/test_queue_consultation_js.js`: DOM/API fixtures for selection/start separation, demographics, saved draft payload/resume, unsaved patient switching, polling retention, failed-save retention and closed-visit controls.
- PHP lint, JavaScript syntax, form-field inventory, balanced containers and unique element IDs checked.
- Responsive CSS uses two columns on desktop and stacks below 1200px, with contained table scrolling. No connected browser was available; actual visual checks at 1920/1440/1024/768/375px and authenticated browser interaction remain unverified.
