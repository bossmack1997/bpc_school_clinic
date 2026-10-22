# Visit timeline and QR scan history

The existing Student Health Pass and Teacher QR pages retain their headers, navigation, QR download/print controls, patient details and MICA assistant. Only the activity sections are changed.

`includes/patient_activity_sections.php` renders a shared, initially collapsed Visit Timeline with a real event total and Bootstrap collapse button semantics. The full-width native button supports Enter/Space, tracks `aria-expanded`, and rotates its chevron; the transition is 250 ms with reduced-motion support. Ten visit events load per page. QR Scan History is directly below it and initially shows five genuine QR log records, with View More and explicit empty/error/retry states. Scoped styling preserves the vertical timeline, compact rows and status badges. Bottom scroll space keeps the history controls clear of MICA's floating button.

## Files

- `pages/student/my_qr.php`, `pages/adviser/my_qr.php`: shared activity sections and assets; existing pass details and QR controls remain.
- `includes/patient_activity.php`: authenticated patient identity resolution, paginated visit events and QR history.
- `api/qr_codes.php`: `patient_visit_timeline` and `patient_scan_history` actions, with legacy `student_qr_timeline` routed to the same service; corrected canonical Teacher-role handling and teacher scan callers.
- `includes/functions.php`: optional teacher ID in `log_qr_scan`, retaining existing Student-call compatibility.
- `assets/js/patient-activity.js`, `assets/css/patient-activity.css`: loading, pagination, compact shared UI and accordion styling.

## Data and authorization

History comes from existing scan logs, queues, consultations, dispensing and certificates. Queue assignment does not create a scan record. Manual `walk_in` log entries remain visit events labeled Clinic Check-in but are excluded from QR Scan History. Medicine events use the actual `dispensed_at` column. Queue Called and Consultation Started use their recorded timestamps. Certificate issue times use `created_at` only when its date matches the issue date; otherwise only the actual issue date is shown.

Students and teachers resolve their own patient record from the authenticated user ID. Attempts to supply another patient identifier/type are rejected. Nurse/Admin requests require an existing selected patient and retain the existing clinical-record authorization boundary used by `students_api.php` and `teacher_qr`. Responses omit QR tokens, IP addresses and device data. SQL is prepared and UI strings are escaped. No example records are inserted as history.

## Migration

`database/migration_patient_scan_history.sql` adds a nullable `teacher_id` and history lookup indexes to the existing scan log table. It has been applied locally. All 17 original scan log records remain. The old Teacher scan callers incorrectly passed teacher identity as a device argument; future scans now store it in `teacher_id`. Real legacy scans can be matched only through exact owned QR tokens, not inferred from queue assignments. No historical records were fabricated or rewritten. Rollback SQL is included as comments; restore the previous logging code when rolling back the column.

## Verification

- PHP lint passed for the API, service, template, shared logger and both patient pages; Node syntax check passed for the activity script.
- `php scratch/test_patient_activity.php`: real Student/Teacher identity scopes, tampering rejection, descending timestamps, limits and full timeline pagination without lost/duplicate event IDs. Teacher logging and queue-reference retrieval pass inside a rolled-back transaction; manual walk-ins are excluded from QR history and the original 17 logs remain.
- `php scratch/test_patient_activity_endpoint.php <role> <action> [tamper]`: real account endpoint checks for Student, Teacher, Nurse and Admin. Patient ID tampering returns Access denied; authorized staff retrieval follows existing medical-record permissions.
- `node scratch/test_patient_activity_ui.js`: subdirectory API path, 10/5 initial limits, totals, Show/View More, escaping, empty/error/retry states, initially collapsed markup and section ordering.
- `php scratch/test_patient_activity_page.php student` and `teacher`: actual account page rendering passed, including collapsed markup, section order, shared script, QR print controls and the retained MICA assistant mount.

Browser animation, keyboard interaction, mobile visual layout and MICA overlap have not been visually verified in this session because the available browser-surface inventory was empty. Bootstrap collapse provides the accordion interaction; DOM/API-stub tests do not substitute for those browser checks. All scratch test files are CLI-only and must not be deployed publicly.
