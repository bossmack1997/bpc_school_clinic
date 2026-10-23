# Mobile workflow repair ? 23 October 2026

## Confirmed causes and changes

SOS and Doctor invitation footers inherited mobile `.btn { flex: 1 1 auto }` rules. In column layouts that lets buttons consume the vertical main axis. Scoped final styles in `assets/css/workflow-mobile.css` fix action heights at 44?52px, keep compact headers/footers, constrain the modal to the visual viewport and give only the body scrolling. SOS uses a light red header and clear confirmation text. A room/landmark remains available when GPS cannot be obtained. Both dialogs preserve their fields and existing Bootstrap focus behavior. Doctor fields now have associated labels and personal/professional headings. The submit label is **Send Invitation**; actual backend delivery status still determines the success/failure notice.

Server-rendered username/email errors had been inserted inside `.input-group`, unlike JavaScript errors placed after the group. They now share the external error layout and IDs. Editing clears stale errors while preserving entered values and placeholders. The frontend still trims and validates the same 3?50 ASCII letter/number/underscore rule as the backend. Duplicate messages are produced by backend duplicate checks, not guessed availability. There is no asynchronous availability request to race; the existing submit workflow remains intact. All registration input/select/textarea controls and their attributes were compared against the preceding Git revision and match exactly, including uploads and required fields.

The People Directory was a wide desktop table under shared `overflow-wrap:anywhere` rules. Below 768px the same authorized server rows now render as cards, with clear names/roles/status, complete applicable identifiers, expandable email/registration/QR details and a compact detail action. Desktop keeps its table. A whitelisted Sort by control and header links support sorting while retaining filters and pagination. Empty identifiers are omitted on mobile. MICA's launcher stays in document flow on this page so it cannot cover filtering or row actions. The install banner no longer covers registration inputs or these open dialogs. Other pages retain their existing assistant/install behavior.

## Workflow and authorization

- Opening SOS does not submit or request location. Activate SOS is explicit. Duplicate activation clicks are blocked. Cancel/close invalidates a pending GPS-to-send operation. GPS denial, timeout and unsupported browsers keep manual details and never imply GPS was sent. Latitude zero is handled correctly. Confirmation uses coordinates acknowledged by the server. Submission failures restore the button and show an error. Existing server-side deduplication, emergency routing, in-app notifications, auditing, tracking and cancellation remain in place.
- **My SOS history** now explicitly requests the reporter-only scope for every role. MIS can read its own reports through that GET-only scope but still cannot enumerate other reporters' histories or access clinical oversight. This narrow permission exception is tested; it does not grant clinical access. History retrieval closes the Bootstrap dialog before opening its viewer, avoiding competing focus traps.
- Student/Teacher/Nurse basic registration remains OTP-activated without routine MIS approval. Nurse clinical access still requires trusted authorization. No account is approved by these UI changes. Existing security exceptions, suspension controls, audit logs and account records are preserved. MICA notices now distinguish pending email/invitation setup and security signals from obsolete routine administrative registration approval.
- Doctor creation still requires authorized MIS access, CSRF, server validation and unique identity. Expiring single-use invitation tokens remain hashed, Doctor chooses its own password, and inactive/unverified appointments cannot activate. No public Co-Admin registration or password exposure is introduced.

## Executed verification

All database tests use disposable schema copies and synthetic accounts. No real patient records or production emergency reports were used.

- Real HTTP registration/OTP/login suite: 108 checks, including Student/Teacher/Nurse SOS creation, deduplication, reporter history and cancellation; nurse clinical restrictions; private attachments; recovery; and Doctor activation/login.
- MIS HTTP suite: 26 backend checks (both browser harnesses also passed), including own-history versus clinical denial, search/role filter/sort/pagination, malformed filters, older schema rendering, invitation CSRF/duplicates/delivery failure, password setup, replay and expiration.
- Real Chrome layout tests at 320, 360, 375, 390, 430, 768, 1024 and 1440px. Invitation fields/footers and card/table breakpoints are measured, with simulated reduced-height viewports. Browser form submission verifies duplicate username placement and input recovery. Password toggles remain functional. MICA/install obstruction is checked.
- SOS browser transport/location tests use controlled geolocation and jQuery transport stubs: open/cancel without delivery, pending-GPS cancellation, double submit, denied/timeout/unsupported location, latitude zero and transport failure. Actual API persistence is tested separately in the HTTP suite.
- 70 activation/security checks, 18 Doctor invitation service checks and People Management rendering checks passed. PHP lint, inline/external JavaScript syntax and Git whitespace review passed.

No actual SMTP inbox, FCM/device delivery, physical mobile keyboard or hardware GPS test is claimed. Reduced viewport tests simulate keyboard/browser-space loss. No schema migration or data update is required for this change. Production must already have the previously supplied basic-OTP schema migration to register accounts; these UI repairs do not bypass that requirement.

## Files modified

- `assets/css/workflow-mobile.css`: scoped modal, directory and obstruction styles.
- `auth/register.php`: external server error placement and stale-error clearing.
- `components/mica.php`: compact SOS copy, location/confirmation/cancellation and personal-history behavior.
- `api/emergencies.php`, `includes/permission_profiles.php`: reporter-only history authorization.
- `includes/head.php`: load scoped repair stylesheet after shared styles.
- `pages/admin/users.php`, `includes/account_monitoring.php`: responsive directory metadata, safe sorting and filter preservation.
- `pages/admin/admin_accounts.php`: Send Invitation label, grouping and label associations.
- `includes/functions.php`, `includes/ai/MICAHubService.php`: remove misleading routine-approval wording while retaining security monitoring.
- `tests/basic_registration_http.php`, `tests/mis_admin_http.php`, `tests/mis_admin_browser.cjs`, `tests/registration_browser.cjs`, `tests/sos_mobile_browser.cjs`: functional, authorization and browser regression coverage.
- This report.

## Deployment limits

The commit/push status is reported in the accompanying response. A Git push is not proof of Hostinger deployment. Authenticated production directory/modals, real inbox delivery and live end-to-end role workflows remain unverified unless explicitly reported after deployment. The change contains no credentials, production data or database reset.
