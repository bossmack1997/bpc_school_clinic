# MICA Intelligence Hub audit and repair — 22 October 2026

## Outcome and verification boundary

Implemented deterministic scan reconciliation, durable event/read/scan history, scoped detail and archive APIs, safer rendering, current summaries, and repaired grounded queries. The additive migration was applied to the local database. Real clinic records were not scanned or rewritten during QA; integration fixtures shadow permanent tables with connection-local temporary tables.

This is an implementation and automated integration report, not a claim of complete visual or live-provider certification. Browser inventory was empty and opening the in-app browser returned `Browser is not available: iab`. Live OpenRouter returned HTTP 0 / network_error. Mobile screenshots, keyboard navigation, dark-mode contrast, actual push delivery, and successful provider generation remain unverified.

## Findings and root causes

| Finding | Root cause and repair |
| --- | --- |
| Pending counts stayed stale | Existing active action returned without refreshing evidence. Creation now refreshes title, description, severity and suggested data. |
| Duplicate alerts under concurrent writers | SELECT/INSERT had no serialization. Named writer lock now protects creation; scans hold it through transaction commit. Scan lock rejects overlapping scans. |
| Registration duplicates | Registration validator wrote separate per-account review cards while monitoring wrote grouped totals. It now uses the same grouped pending condition; legacy review cards are archived as invalidated without deleting records. |
| Nurse saw administrative alerts | Role query included every admin-targeted action. Removed that unconditional exception; detail and archive enforce the same audience. |
| Read/click implied workflow progress | Detail was generic client text and manual status could close arbitrary action IDs. Read receipts are per user; manual closure is rejected; issue closure follows successful source verification. |
| Fake progress | Buttons displayed boilerplate. Detail now offers recorded events only; no backfilled historical detection or delivery timestamps. |
| Missing persistence and failure metadata | No scan ledger or event/read tables. Added three compatible companion tables. Source/persistence failure rolls back alerts and saves a failed scan. |
| Incorrect empty/zero states | Null inventory counts displayed as zero; zero escaping also produced blank text. Null now says unavailable; zero renders as zero. |
| Unsupported predictions | Grounded handler hardcoded peak days/hours and student waiting-time advice. Removed those claims and unsupported 24/7 hotline wording. |
| Missed natural-language wording | Registrations-before-pending and plural emergencies did not match. Added patterns plus direct today-consultation and issues-requiring-attention handlers. |
| Unsafe UI interpolation | Alert/reorder strings entered JavaScript attributes; raw Markdown renderer accepted HTML. Controls now pass numeric IDs, cached reorder records stay out of handlers, output uses escaped formatting, and modal titles use titleText. |
| Cron/session bypass | Logged-in admin could trigger mutating cron via GET. Interactive scans now use POST/CSRF API; web cron requires its configured secret. |
| Timezone ambiguity | MICA now explicitly uses Asia/Manila in PHP and +08:00 in the database session. |

## Sources, endpoints and dependencies

| Feature | Verified source or entry point |
| --- | --- |
| Pending registrations | users.status='pending'; same count used by registration alert and account query. This is pending account status, not merely an unverified email. |
| Consultation totals | consultations.consultation_date, reporting calendar date; counts encounters rather than unique people. |
| Queue activity | queue_numbers.created_at/status; waiting and serving counts. |
| Compatibility appointments | appointments.appointment_date/status retained in summary API; redesigned headline metrics focus on consultations. |
| Inventory | MedicineInventoryService::snapshot; unarchived medicine IDs represent separate batches; medicine_logs stock_out usage; inventory_rule_* configuration in system_settings. |
| SOS | sos_emergencies nonterminal statuses active/new/acknowledged/responding. Primary creation, immediate notification attempts and nurse response remain in api/emergencies.php, includes/sos_workflow.php and global SOS APIs/UI. MICA never updates SOS rows. |
| Account security | users.failed_login_count and last_failed_login; per-account key rather than null-target grouping. |
| Graduation | students.lifecycle_status explicitly graduating plus active linked account; year level alone does not establish graduation eligibility. |
| Action/read/history | ai_actions plus mica_alert_reads, mica_alert_events, mica_scans; notifications for responsible roles. |
| Assistant | AIService → MICAWorkflowService allowlisted read queries, MICAAuthorization, MICAContextService, PromptBuilder and OpenRouter; chat history remains separate. No AI SQL execution introduced. |
| Interactive API | api/ai_assistant.php: daily_summary, recommended_actions, archived_actions, alert_details (read-only), read_alert (POST/CSRF), run_automation (POST/CSRF). Existing reorder APIs remain nurse-reviewed. |
| Scheduled runner | api/cron_automation.php → MICAAutomationEngine::runAllTasks → MICAHubService::scan. OS scheduler installation was not changed or verified. |
| Documents | Existing medicine requisition service and download endpoints retained; source revision/owner/confirmation checks and generated PDF/DOCX tested. |

## Business rules

Critical: active SOS or positive expired medicine stock. High: pending account review, zero usable stock, expiry within seven days, or at least five failed logins with a failure in the last 24 hours. Medium: positive usable stock at/below its configured minimum, expiry in 8–30 days, or recorded stock-out volume of at least 40 units in 48 hours. Low (stored as existing `informational` enum): explicit graduating lifecycle review.

Stock thresholds and requisition quantities reuse inventory configuration. The 40-unit review threshold is a fixed rule, explicitly described as such rather than a learned statistical baseline. Expiration is a calendar-date rule; medicines expiring today retain the existing inventory convention. No purchases, account deactivations or emergency closures are performed by scans.

## Lifecycle and compatibility

Detect → validate complete sources in a transaction → match action_type/target_type/target_role/nullable target_id → refresh one active card → persist role notification once → record changed validation evidence → verify absence on a later complete scan → archive.

Legacy duplicate/obsolete alert rows remain in ai_actions. Compatible action statuses remain new/in_progress/resolved; the API displays Active/Monitoring/Resolved, and a terminal invalidated event distinguishes invalidation from resolution in details. Per-user read_at is independent. Notifications link to an authorized alert ID; opening details marks the corresponding user notification read without closing the issue. The history selector lists archived records under the same role restrictions. No events are invented for time before this implementation. An existing card first monitored here gets an honest “monitoring history begins” event.

Scan locking is MySQL/MariaDB connection-level advisory locking. All application alert inserts now use MICAActionService. Direct external SQL writers do not participate in those locks. Manual resolution is prohibited; reading neither approves registrations nor handles emergencies. Unchanged validation evidence does not append repetitive progress events. Notification-created means persisted, not device-delivered.

## UI changes

Content-height cards replace forced equal-height cards. Cards expose severity, issue state, separate read state, related module/ID, source reason and detection time. Details show source evidence and timestamp, responsible-module navigation and expandable recorded history. Active/archive selection preserves history. Summary uses responsive metric columns and timestamps, with explicit unavailable values. Student/Teacher sessions open assistance without requesting staff-only summary endpoints. Scan buttons prevent double submission; API lock conflicts return 409. Metrics and visible alerts refresh every minute while the page is visible; alerts remain explicitly tied to successful scan evidence.

## Files changed

- includes/ai/MICAHubService.php (new reconciliation, history, detail and scan service)
- includes/ai/MICAActionService.php (serialized creation/refresh, audience scope, read state/archive retrieval, restricted manual transitions)
- includes/ai/MICARegistrationValidator.php (shared grouped registration action)
- includes/ai/MICAAutomationEngine.php (shared scheduled scan path)
- includes/ai/MICAContextService.php (registration counts scoped to administration)
- includes/ai/MICAWorkflowService.php (grounded question coverage, unsupported claims removed)
- includes/ai/MICAAuthorization.php (detail/read/archive actions)
- includes/ai/AIService.php (honest monitoring greeting)
- api/ai_assistant.php (new API actions, failure handling, scan metadata)
- api/cron_automation.php (shared scan result contract and authentication)
- pages/ai_assistant.php (compact cards, details/history, safer rendering, summary and scan states)
- database/migration_mica_hub.sql (new companion tables only; applied locally)
- scratch/test_mica_hub.php, scratch/test_mica_hub_endpoint.php, scratch/test_mica_hub_ui.js, scratch/test_mica_provider.php (new QA)
- scratch/test_sos_workflow_db.php (corrected obsolete admin-response expectation to existing clinical authorization policy)

The workspace contained extensive pre-existing modifications. Those changes were preserved; this list describes this task's edits, not the entire working-tree diff.

## Tests and actual results

- New hub MySQL fixture suite: 19 checks passed, including zero/one/multiple pending, updated counts, repeated scans, single notification, nurse/admin isolation, read without resolve, approval resolution, event history, manual closure rejection, low-stock/replenishment, SOS/handled SOS, preserved source status, two-connection lock rejection, natural-language pending count, and source failure persistence.
- Assistant endpoint fixtures: admin/nurse daily_summary, admin run_automation and archived_actions returned HTTP 200 with success. Student scan, Teacher actions, and invalid-CSRF nurse scan returned HTTP 403.
- JavaScript: page script parses; numeric-only record handlers, scan guard/reset, escaped detail URLs, content-height cards, zero preservation, hostile HTML escaping, text modal titles passed.
- Existing test_inventory_upgrade.php passed all 26 MySQL inventory checks, including nurse/delegated oversight policy, usage-based quantities, stock locking/rollback, expiry rules, human review and scoped daily summary.
- Existing test_mica_inventory.php passed with config/config.php auto-prepended (standalone test otherwise lacked canonical_role); classification, thresholds, batch separation, notification deduplication/recovery and role denials passed.
- Existing SOS workflow fixture tests passed nurse transitions, duplicate reports, cancellation, audit metadata, outage resilience, admin clinical-response denial and rollback after audit SQL failure. Its obsolete final admin-acknowledgment assertion initially failed; corrected the fixture expectation to current authorization rather than granting clinical access.
- Existing test_requisition_downloads.php passed real PDF/DOCX generation, editable Word tables, escaping, layout metadata, owner/revision/confirmation rejection.
- PHP syntax checks passed for changed PHP sources.
- Provider disabled test passed. Live OpenRouter test returned network_error / HTTP 0, no content; live generation is NOT verified.

## Remaining limits and follow-up verification

Browser was unavailable, so no desktop/mobile screenshot, focus, screen-reader or dark-mode certification is claimed. Push-device delivery and external cron scheduling were not exercised. Alerts update after explicit/scheduled scans; the original SOS interface remains responsible for immediate emergency notifications. No production scan was run during QA, so existing stale alerts will be reconciled on the next authorized successful scan. The archive currently loads at most 50 results per API request; full historical pagination is not implemented. Invalidation is preserved as an event alongside legacy resolved status, not a new enum value. No unrestricted AI operations were added. A configured reachable OpenRouter service is required to verify live conversational generation.
