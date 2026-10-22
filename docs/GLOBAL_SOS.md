# Global SOS responder alerts

## Integration

The component is loaded by the shared authenticated footer for admin and nurse pages, plus the two standalone authenticated print-preview pages (requisition and certificate). It has its own CSS, JavaScript and authenticated endpoint, without calling MICA, AI, inventory or Bootstrap. The topbar also retains an Emergency Management link when JavaScript is unavailable. Admin sidebar Emergencies/empty Clinic Operations were removed; nurse and teacher navigation were preserved.

The native dialog uses the browser top layer to cover the sidebar, topbar, chatbot and other components. It provides an SOS symbol, counts, grouped report list, reporter/type/description/location/time/status/contact details and verified-coordinate Google Maps links. Missing values say Not provided. Reports and text are inserted with textContent, not unescaped HTML. Printable documents suppress the entire alert component in print CSS.

## Detection and receipts

A same-origin fetch polls api/global_sos.php every 12 seconds while the page is visible, with no overlapping polls and a 9-second request timeout. Online/visibility events trigger recovery. Last known reports remain visible on transient failures with an explicit connection warning. Authentication failure clears private report details and requires sign-in/reload.

Active statuses are new, active, acknowledged, responding and responded, with resolved_at NULL. The feed follows the existing responder scope and includes both student SOS and adviser emergency reports. Only admin and nurse can access global report details; teachers retain their existing own-report interface.

The sos_alert_acknowledgments table stores one screen receipt per user/report. Its composite primary key makes dismissal idempotent across refreshes/tabs. It is separate from emergency response status and general notification-read flags. Clicking Acknowledge screen notification records only receipts and an audit entry; it never changes or closes a report. Opening management from an unseen overlay also explicitly acknowledges its screen notices. Persistent red SOS access remains after dismissal; new report IDs trigger a fresh overlay. Multiple reports share one dialog. Escape cannot silently discard unseen notices; visible details and response/management actions remain available.

Screen receipts are synchronized on subsequent polls (and cross-tab BroadcastChannel refresh where supported). Marking reports resolved/cancelled removes them from the active feed. Existing API update_status handles acknowledgment/respond/resolve actions with CSRF, validated IDs and checked status transitions under a row lock. Closed reports cannot be reopened by stale response requests. Report acknowledgment and screen acknowledgment have separate labels and endpoints.

## Deployment

Apply database/migration_global_sos_overlay.sql once using the project's database migration process. The additive CREATE TABLE IF NOT EXISTS migration was applied to this workspace database. It does not modify existing emergency rows. New deployments must apply it before serving the global alerts. No external service, worker, AI key or socket server is needed. The page must be open and connected for polling; this is not an operating-system background alert service.

## Verification

- php scratch/test_global_sos.php: PDO-fixture tests for role boundaries, identifiers, transitions, grouped/new/resolved reports, idempotent receipts, user isolation, refresh persistence and no status mutations during screen acknowledgment.
- node scratch/test_global_sos_js.js: DOM-fixture tests for polling, overlay/details behavior, multiple/new/resolved reports, screen-only acknowledgment, offline recovery and preservation of input values/focus.
- Read-only live schema/feed check succeeded with five active reports after migration; no emergency was created, acknowledged, responded to or resolved for testing.
- PHP lint/JavaScript syntax and scoped navigation checks passed. Live browser clicking, device responsiveness and real response mutations were not exercised.
