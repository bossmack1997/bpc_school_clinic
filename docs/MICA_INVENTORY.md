# MICA inventory monitoring and requisitions

Authorized users: existing admin and nurse roles. Nurse inventory editing permissions remain unchanged; admin inventory is view-only as before. Student/teacher requests cannot use the new inventory API or requisition page.

MICA checks the database whenever authorized staff open the widget or use its inventory controls. The panel's Check Inventory and View Low Stock controls use deterministic PHP data. Natural-language inventory requests open the same panel. Requisitions open in a separate review page. No language model receives or changes inventory records through this integration.

Existing medicines IDs are distinct inventory/batch records, not deduplicated by medicine name. Archived rows are excluded. Expired records (expiration before today's database date), quarantined/unavailable/recalled rows when tracked are unusable. Low stock includes usable quantity at or below its configured reorder level, plus zero usable stock. Missing thresholds are shown explicitly; nurses configure reorder levels in the existing inventory editor.

## Notifications and scheduling

No database migration is needed. Per-medicine notification state is stored in existing system_settings keys mica_stock_state_ID; notifications use the existing notifications table with type mica_inventory. A database advisory lock plus a transaction serializes checks. New low stock, lower usable quantity, changed severity or threshold sends staff notifications. Normal repeated checks do not. View Low Stock marks the current user's MICA inventory notifications read. Opening MICA always keeps current low-stock information accessible.

The existing api/cron_automation.php runner now uses the same deterministic inventory monitor rather than inventing order quantities. Other existing scheduled tasks are unchanged. Run the configured scheduler as:

    php C:/xampp/htdocs/bpc_clinic_system/api/cron_automation.php

A runner file exists; this change does not install an operating-system schedule. If not already scheduled, configure Windows Task Scheduler to run it at the desired interval. Web scheduling requires an explicitly configured CRON_SECRET_TOKEN (prefer the X-Cron-Auth header) or an authenticated admin. The former public default token is no longer accepted.

## Requisitions

pages/medicine_requisition.php is admin/nurse-only. Drafts and their unique references live in the authenticated session (up to ten drafts), not a new database table. Without a configured target_stock_level, quantities remain blank until entered by staff. Where that field exists, target minus usable stock is only a proposal. IDs cannot be duplicated in submitted quantities. Whole quantities 1..9999999 are validated server-side; users may remove rows.

Confirmation re-reads inventory and rejects changed snapshots, preserving entered values for another review. Confirmed selected IDs and facts are checked again by a CSRF-protected request before the Print button opens the print dialog. A changed list must be reviewed again. The document remains pending human approval; there is no supplier submission. Confirmation is audit logged, with identical repeated confirmations avoiding another audit entry. Separate deliberately created drafts receive separate references.

Print CSS uses A4 portrait, 15mm margins, repeated table headers and a signature section. The confirmed preview offers Download PDF (Dompdf), Download Word (.docx, PHPWord), and Print Requisition. Both downloadable files use the same validated quantities and selected IDs as the preview. A server check rejects wrong-owner, unconfirmed, stale-revision, healthy-stock and changed-stock requests. Downloads require a configured reorder threshold, including zero-stock rows. All exported documents retain Draft status pending human approval. Word tables, requested quantities and remarks are editable. Browser-native print commands cannot force another server check; use the supplied Verify stock and print button. Inventory can also change after verification, so the verification timestamp is shown.

## Validation

php scratch/test_mica_inventory.php runs deterministic classification/quantity tests without writing medicine records. php scratch/test_mica_inventory_db.php checks live snapshot/schema compatibility read-only. Run the latter with a writable PHP session directory in sandboxed environments. Browser UI, OS scheduling, printer pagination and real notification delivery require deployment verification.

## Download dependencies and setup

Composer now installs dompdf/dompdf ~3.1 (locked 3.1.6) and phpoffice/phpword ~1.4 (locked 1.4.0). GD and ZIP must be enabled in PHP. They were enabled in C:/xampp/php/php.ini with a backup in scratch/php.ini.before-requisition-downloads.bak; restart Apache in XAMPP to load changed extensions. Future deployments should run composer install and verify PHP GD/ZIP. No database migration is required.

api/medicine_requisition_download.php accepts authenticated, CSRF-protected POST requests only, regenerates files server-side using fresh inventory facts, escapes dynamic values and logs successful downloads. PDF external resources and embedded PHP/JavaScript are disabled. Generated Word temporary files are deleted after response bytes are built. Filenames include the actual generation date.

php scratch/test_requisition_downloads.php validates real PDF and DOCX bytes, OOXML tables/A4 layout/repeated headers, consistent quantities, and stale/unauthorized/unconfirmed/empty/healthy-stock rejection. It writes QA-only single/multipage fixtures to scratch/requisition-document-qa. PDF fixtures were rendered and visually inspected; Word package XML was validated but Word/LibreOffice visual rendering was unavailable. Browser download clicks and print dialogs were not exercised.
