# Clinic Messenger redesign

The student, teacher (`pages/adviser`), and nurse routes are authorization-only entry points into `includes/messaging_page.php`. This single template is based on the existing Student page. `assets/js/messenger-client.js` owns shared contact/list/navigation behavior; `assets/js/messenger.js` owns message rendering and interactions; `assets/css/messenger.css` owns the common presentation. No screenshot file accompanied the follow-up requirements, so pixel matching against an external screenshot has not been verified.

Students and teachers render identical patient layouts with Clinic Staff and Recent Conversations sections and All/Unread/Staff filters. Nurses use the same template with All/Unread/Students/Teachers filters, clinic availability, quick replies, patient-record access and MICA summaries. Their New Message button uses an authorized, minimal patient contact directory; the Clinic Staff section is omitted. Student SOS retains its existing modal, and Teacher SOS retains its existing emergency route. Shared navigation components remain unchanged. The compact medical notice is dismissible. The attachment button, textarea and Send button share one horizontal row, with attachment names and reply/error notices outside the row.

Administration has no Messages sidebar item and cannot enter the shared template, any of the three page routes, or the messaging API. The new patient directory is available only to nurses. Pair creation is serialized with a database advisory lock and creates conversations/participants in one transaction to avoid simultaneous duplicate threads. Switching away from a mobile chat stops read-marking it. Mobile viewport resize handling keeps the panel within the available keyboard viewport.

## Database

No additional schema changes are required for the shared-layout consolidation. The earlier `database/migration_messenger_interactions.sql` remains the dependency for reactions, replies and removal metadata. It adds two tables without changing existing message rows. It has been applied to the local development database; the existing 14 messages and three conversations were preserved. No duplicate participant pairs were present. Rollback statements are included as comments; rolling back removes replies, removal flags and reactions, and restores the original message bodies to display.

Replies reference messages in the same authorized conversation. Removal is restricted to the sender and leaves the original record intact for history preservation; removed attachments are unavailable through the download endpoint. Reactions are limited to one per user per message, and clicking the same reaction removes it. API endpoints continue to authorize conversation access and private attachment downloads. Administration has no messaging access by default. Mutating endpoints require POST and a valid CSRF token. Notifications no longer contain a message-body preview.

## Behavior

Conversation lists use real existing records, search and role/unread filters. Message polling reuses existing timers, avoids overlapping shared requests, ignores responses after switching conversations, and pauses while the page is hidden. It fetches the latest 80 messages (including read and interaction state). Earlier history loads on request. Active history remains scrollable, with scroll position preserved when reading older messages. Presence uses the existing heartbeat, and Sent/Seen derive from persisted read state. Delivered and typing states are not invented.

The composer keeps text after errors, blocks duplicate submissions, supports Enter and Shift+Enter, and keeps per-conversation text drafts in memory. Drafts do not persist after closing/reloading the page. Attachments keep the existing 10 MB and MIME validation rules. Mobile uses separate list/chat views and an in-header Back button.

## Validation

- PHP lint: API, interaction helper, and all three portal pages passed.
- Node syntax check: shared messaging script passed.
- Real database: migration preserved 14 messages and three conversations; no duplicate participant pairs.
- Transactional endpoint checks: three role-specific lists load; nurse history loads; administrator list access is denied; student and teacher cross-patient history access is denied; reactions and sender-owned removal succeed; removal of another sender's message is denied. Test mutations and read marks were rolled back.
- Consolidation checks: rendered actual active Student, Teacher and Nurse account pages; each has one shared composer, multiline textarea and common client. Patient staff sections, nurse filters/tools, medical notices and authorized SOS controls are present. All three routes reject an Administrator account.
- Real-database sends: Student and Nurse messages with replies, and a Teacher message in the teacher's own conversation, were inserted and verified through the send endpoint inside rolled-back transactions. All original 14 messages and three conversations remain. Cross-patient and Administrator sends were rejected. Chat notifications are database-only and test notification writes were also rolled back.
- Node controller smoke tests for all three roles pass shared initialization/rendering, nurse role filtering, unread/search filtering, HTML/attribute escaping, failed-send text preservation, retry and duplicate-send prevention (`node scratch/test_messenger_ui.js`). These use DOM/API stubs and do not substitute for browser layout testing.
- Page/account harness: `php scratch/test_messaging_page.php teacher adviser` (and corresponding Student/Nurse/Admin combinations). Endpoint harness accepts `own` as the conversation selector, e.g. `php scratch/test_messenger_endpoint.php teacher send_message own`.
- `scratch/test_messenger_endpoint.php` is a CLI-only test harness intended for local execution; it loads an existing active user by role and summarizes endpoint results without printing patient content. Do not deploy scratch scripts to a public web directory.

## Remaining verification

Authenticated browser layout/accessibility checks at desktop and mobile widths, mobile keyboard behavior, and a two-session live send/receive/attachment flow remain to be performed. The browser-surface inventory was empty during this session; these checks have not been claimed as passing. This implementation retains the application's shared nurse-triage authorization policy and legacy conversation-level `read_at` semantics. Older pages of history are refreshed when explicitly loaded, while routine polling refreshes the newest 80 messages.

## One entry per patient in the nursing inbox

The nursing inbox now groups active, single-patient clinic threads by the actual patient user ID. The latest preview and timestamp are preserved, unread counts are summed, and history/pagination/read marks include every grouped thread. Message and attachment records stay in their original conversations. Existing conversation URLs resolve to the same combined nurse history. Nurse replies can quote a message from any thread in that patient group, and MICA summaries use the combined history. Patient account access remains restricted to its existing participant threads. Nurse New Message reuses an existing patient thread; creation is locked per patient to avoid simultaneous duplicate creation.

`includes/messaging_threads.php` implements grouping; API queries and shared-client alias handling use it. No migration is required. `php scratch/test_messaging_threads.php` verified that the actual three source threads produce two patient entries, with stable canonical IDs, aggregated unread counts, old-link resolution and unchanged patient authorization. All 14 existing messages remain. The nurse history endpoint now returns all 13 messages for the student group; the student's original thread still returns its own 11. Transactional nurse send/reply and role access regression checks passed.
