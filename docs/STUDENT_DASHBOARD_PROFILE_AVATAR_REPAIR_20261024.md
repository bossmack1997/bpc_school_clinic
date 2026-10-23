# Student dashboard, profile and messaging avatar repair — 24 October 2026

Branch: `fix/student-dashboard-profile-avatars`, based on `e516677`.
The worktree was clean at the start. Existing clinical verification, permissions,
patient records, QR workflows and message history were retained.

## Findings and fixes

**Announcements.** The Student dashboard repeated clinic availability already
shown in its welcome banner. The existing announcement table and Nurse editor
provided title/content/audience/pinning, but lacked publication windows, category,
status and images. The API also permitted Primary MIS to publish routine content.

The redundant card now contains **Clinic Announcements & Updates**, populated from
actual audience-authorized announcement records. Operating hours remain in the
welcome banner. Rotation is six seconds, with previous/next buttons, numbered
indicators, arrow-key navigation and horizontal pointer/touch gestures. Interaction
pauses rotation; Play explicitly resumes it. Reduced motion disables automatic
rotation by default. Updates refresh from the API every 60 seconds and on focus.
Empty and unavailable states are distinct. No sample announcements were published
to the school database.

The existing Nurse route now supports drafts, publication, archiving, category,
start/expiry in Asia/Manila time, audience, original author, pinning and an optional
validated image. Management requires an authorized Nurse, CSRF and transaction-time
authority checks. MIS, Doctors, Students and Teachers cannot publish routine content.
Archive preserves the existing row. Immediate publication creates in-app audience
notifications; a future scheduled item becomes visible through publication filtering
and refresh, without a new scheduled push-notification worker. The page reminds
staff to follow institutional physician review procedures for sensitive advisories;
no institutional approval policy or automated physician sign-off was invented.

Teacher dashboard and both MICA announcement context readers now reuse the same
publication filter. Draft, future, expired and other-audience records are therefore
excluded from those previously independent queries as well.

**QR/Profile layout.** The health pass used two intrinsic `1fr` grid columns and
unbounded flex children with right-aligned values. Long email/allergy/emergency
text could increase their minimum width. The QR identity block also lacked word
wrapping. Shared patient styling supplies zero minimum widths, bounded grid tracks,
a single-column mobile layout, stacked label/value rows at 320px, wrapping identity
and badge text, and square bounded QR rendering. Student Profile summary text and
badges also wrap. No global horizontal-overflow suppression was added. Download
and Print retain their existing handlers; a blocked print popup now reports a
controlled message. Print testing exercised the handler with a captured print
window, rather than sending output to a physical printer.

**Avatars.** Uploads correctly used `users.profile_picture`, but consumers assembled
URLs independently. Prefixing a stored absolute/application-root URL with `APP_URL`
produced malformed URLs. Message/contact image elements lacked a reliable dynamic
error fallback. Replacing a photo also deleted its previous file while existing
views could still reference that older URL.

The local read-only audit found all 18 current stored image paths present; it did
not establish a missing production file or a hosting-specific access failure.
The repaired defects were verified using uploads and synthetic URL/error cases.
One shared resolver now normalizes relative and same-origin legacy paths, verifies
an allowlisted public image file and canonical directory boundary, rejects traversal,
external URLs and private paths, and returns a content-versioned public URL. Only
unusable images receive the existing SVG fallback. Profile uploads retain their
previous files, validate real image MIME/type/size and keep random new filenames.
Messages no longer return the raw sender image-storage field. QR responses return
normalized public paths plus the shared avatar URL.

Navbar, Student/Teacher/Admin/Nurse profiles, patient contacts, message contacts,
conversation lists, message senders, health passes and physician message bubbles
use that resolver. A shared captured image-error listener handles dynamically
rendered avatars. A successful profile upload updates the header immediately and
signals other tabs; existing messaging polling retrieves the latest stored avatar.
No existing uploaded photo was replaced with placeholder data.

**Shared header.** Competing flex sizes and mobile button defaults left little
space for titles. The shared mobile rules reserve 40px for the hamburger, size
right-side controls consistently, remove secondary clock/status text on narrow
screens and allow a compact two-line title. Patient medical information is never
clamped. Sidebar behavior and Teacher/Nurse shared surfaces were checked.

## Database work and safety

`database/migration_announcement_publication_20261024.sql` adds five columns to
`announcements`: `category`, `publication_status`, `starts_at`, `expires_at`, and
`image_path`. It widens the audience enum to include Teachers. Existing rows retain
their published behavior. It does not alter patient or messaging tables.

The local-only CLI runner, `setup/migrate_announcement_publication.php --apply-local`,
was executed against local XAMPP. It wrote a private single-table SQL backup,
restored that exact backup into a disposable database, compared all restored rows,
then applied the additive migration and verified the seven original fields remained
unchanged. **One existing school announcement was preserved.** Backup:
`storage/backups/announcements-before-publication-20261023-195617-38e7055b.sql`.
The filename reflects the execution host timestamp; this report uses the client's
24 October date. The runner now explicitly uses Manila timestamps for future backups.

No school account, patient record, consultation, QR transaction, message or uploaded
photo was deleted. Fixtures use schema-only disposable databases, synthetic records
and temporary test images, which are removed afterward. No production deployment
or production migration was performed. For hosting, review the SQL and target enum,
back up and verify restore, then apply and smoke-test in staging first. The local
runner deliberately rejects production/non-loopback targets and custom audience enums.

## Executed validation

- **174 HTTP/integration checks passed**: `php tests/mis_admin_http.php
  --doctor-clinical --staff-verification --student-ui`. Includes prior clinical and
  Nurse/Doctor messaging boundaries plus publication permissions, CSRF, legacy reads,
  migration reruns, publication windows/audiences, MICA filtering, real profile and
  announcement image uploads, retained old photos, canonical/fallback avatar cases,
  message sender/contact images, health-pass avatar consistency and archive retention.
- **176 browser assertions passed**, using the same command plus
  `--student-ui-browser`, at **320, 360, 375, 390, 430, 768, 1024 and 1440px**.
  Student Dashboard, Profile, QR and Messages; Teacher Dashboard; Nurse publishing,
  patient messaging and physician messaging. Covers actual carousel timing/pause,
  keyboard and touch-handler navigation, reduced motion, long identity/medical
  fields, square QR, actual download/print handlers, live profile/header photo
  updates, dynamic fallback, compact headers, sidebar operation, browser publishing
  followed by Student visibility, and no uncaught JavaScript exceptions.
- **108 registration/OTP HTTP checks passed**, preserving Nurse authorization,
  Student/Teacher dashboards, SOS and private document workflows.
- Full application syntax scan: **295 PHP/JavaScript files passed**, recorded in
  ignored `scratch/student-syntax-delivery.log`; subsequently edited PHP fixtures
  and the local migration runner also passed execution/syntax checks.
- Messaging mobile source checks: **6 passed**; consent source checks: **9 passed**.
  These are separate from the real browser layout assertions. `git diff --check`
  passed. Screenshots at 320px and 1440px were inspected, including the long medical
  detail card after scrolling and the previously clipped QR identity block.

Browser evidence is headless Chrome emulation. Physical Android/iOS browsers,
camera hardware, printer output, live hosting file permissions/CDN behavior and
external push delivery were not exercised. The exact original production avatar
failure cannot be established from the local filesystem audit alone. A staging
smoke test remains necessary before production deployment.

## Files modified

- APIs: `announcements.php`, `messages.php`, `patients.php`, `profile.php`, `qr_codes.php`.
- Shared UI: `components/announcement_carousel.php`, `components/navbar.php`,
  `components/sidebar.php`, `includes/head.php`, `assets/css/patient-mobile.css`,
  `assets/css/ui-overrides.css`, `assets/js/announcement-carousel.js`,
  `assets/js/announcement-manager.js`, `assets/js/avatars.js`.
- Services/config: `config/config.php`, `includes/avatar.php`,
  `includes/announcement_service.php`, `includes/doctor_messaging_service.php`,
  `includes/ai/DatabaseContext.php`, `includes/ai/MICAContextService.php`.
- Pages: Student dashboard/profile/my_qr; Adviser dashboard/profile; Admin profile;
  Nurse announcements/profile; shared physician messages.
- Database/setup: the announcement migration SQL and local migration runner above.
- Tests/docs: `tests/student_ui_checks.php`, `tests/student_ui_browser.cjs`,
  `tests/mis_admin_http.php`, `tests/README.md`, `README.md`, and this report.
