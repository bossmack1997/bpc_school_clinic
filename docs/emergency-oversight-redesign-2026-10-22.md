# Emergency Oversight Center redesign — 22 October 2026

Implemented directly in `pages/admin/emergencies.php`, using real clinic data and the existing emergency API and mutation workflow.

## Changes

- Four equal summary cards using Bootstrap's 1 / 2 / 4-column responsive grid. Counts are queried across all records, independently of table pagination and filters. Total reports and resolved reports are explicitly **all-time**.
- Active Emergencies contains `new`, `active`, and `acknowledged` reports (awaiting clinic response). Responding contains `responding` and legacy `responded`. History contains `resolved`, `cancelled`, and legacy `false_alarm`. The cards and tab badges use those same definitions; cancelled and resolved records never count as active.
- Correct six-column mapping: Patient / Reporter, Emergency Type, Location, Status, Reported At, Actions. Patient identity and reporting-user identity remain distinct. Teacher patients use employee IDs.
- Server-side search, tab/status/reporter-role/date filters, stable newest-first ordering, and 10/25/50-row pagination. Historical search is no longer limited to the former 200-row list. Dates include both selected calendar days. Clear Filters preserves the selected tab.
- Desktop/tablet table with horizontal overflow; compact report cards below 768px. Both render from the same response and pagination state. Tabs support arrow keys, Home and End.
- Organized details modal with patient/reporter facts, emergency description, validated map link, accuracy warning, assigned responder, notes, and response timeline.
- Action availability comes from the backend's existing ownership and status rules. Closed records and records assigned to another responder are read-only. Accept/respond/resolve use the repaired shared SOS action handler. Cancellation requires a reason. All mutations still use the existing `update_status` API, CSRF validation, transactional audit logging and notification workflow.
- Automatic refresh every 15 seconds; stale requests cannot overwrite newer filters. Loading requests do not overlap polling. Errors distinguish server/session messages, unreadable JSON, connection failures and timeouts. Existing records remain visible with a stale-data warning when refresh fails.
- No schema migration, historical record deletion, new notification widget, or change to nurse/student/teacher permissions.

## Files modified

| File | Purpose |
|---|---|
| `pages/admin/emergencies.php` | Replace broken administration page markup with summary, tabs, filters, reports, pagination and details modal. |
| `assets/css/emergency-oversight.css` | Scoped visual styles, mobile cards, tablet grid, focus visibility and dark-theme support. |
| `assets/js/emergency-oversight.js` | Read queries, shared table/card rendering, filters, navigation, modal timeline and existing status actions. |
| `includes/emergency_oversight.php` | Read-only SQL query model, database aggregates, identity/responder mappings, validation and authorized action metadata. |
| `api/emergencies.php` | Add admin-only GET actions `oversight` and `oversight_detail` inside the existing endpoint. List/count/summary queries share a read transaction. Existing actions remain intact. |

Added regression tests: `scratch/test_emergency_oversight.php`, `scratch/test_emergency_oversight_js.js`, `scratch/test_emergency_oversight_endpoint.php`.

The repository already contained other modifications. This redesign does not replace those unrelated changes, including `assets/css/dashboard-unified.css`.

## Verification executed

- PHP syntax checks passed for all three affected PHP files; Node syntax check passed for the new script.
- MySQL integration tests passed using connection-local temporary tables with 213 synthetic reports. Verified database counts beyond 200 records, all three tab/status groups, reporter/responder mappings, student/employee/name/type searches, combined date/role/status filters, SQL wildcard safety, 10/25/50-row pagination, deterministic ordering, page clamping, invalid filters, and read-only actions for assigned/closed records. No permanent test records or notifications were created.
- DOM fixture tests passed for header/value mapping, escaping untrusted content, shared mobile data, tabs and keyboard navigation, filters, pagination, details/timeline, action routing, closed/assigned permissions, and offline/invalid-JSON recovery.
- Authenticated endpoint execution against the installed application passed: admin list/detail HTTP 200; missing detail 404; invalid date 422; wrong method 405; nurse/student/teacher access to this administration read model 403. All responses were valid JSON and no read transaction was left open.
- Authenticated PHP rendering of the administration emergency page completed; extracted inline JavaScript passed Node syntax validation.
- Existing SOS MySQL workflow, frontend actions, screen-notification service and polling DOM regression suites passed. Those checks cover creation, teacher/no-GPS submission, duplicates, responder ownership, response/resolution/cancellation, audit rollback and notification-failure resilience.
- Targeted Git whitespace checks passed.

## Verification limits

No browser sessions were connected. An attempt to open the local page in the in-app browser returned `Browser is not available: iab`. Therefore screenshots, visual responsive checks at desktop/tablet/mobile sizes, native focus/scroll behavior and installed-PWA interactions were not executed. The responsive rules and DOM behavior are implemented and automatically checked, but this is not a claim of visual browser verification. Real emergency mutations were not triggered against staff accounts; action persistence was covered by the existing isolated MySQL workflow suite.
