# Medicine Inventory and MICA integration — 22 October 2026

## Implementation

Extended the existing inventory, permission modal, MICA services and requisition generator. No tables were rebuilt and no schema migration is required. Existing UI components, records and dispensing relationships are retained.

- Nurses manage medicines, separate batches, stock and dispensing. Co-admins require the new **Inventory Oversight** permission for monitoring, alerts and reviewed restock drafts; physical stock mutations remain nurse-only. Super Admin receives technical service health rather than medicine management. Inventory report exports also enforce inventory access.
- Shared database calculations drive list filters, stock alerts, MICA facts and draft suggestions. Search covers medicine, generic name, brand, supplier, category, batch and barcode. Server pagination supports results beyond the former 200-row cap. Read-only table columns align correctly; loading, empty and failure states are included.
- Summary cards use a scoped responsive grid. Existing search remains, with the redundant DataTables search removed. Existing medicine actions are retained; removal archives records to preserve history.
- Stock deductions lock the batch inside a transaction, reject expired/unusable or insufficient stock, and preserve the ledger with the deduction. Failed ledger writes roll back stock. Concurrent full-record edits check the original quantity. Adding stock only merges records with the same batch identity and expiry.
- MICA notifications are limited to nurses and explicitly authorized co-admins, with repeat-alert suppression. Inventory chat and context access use backend authorization. Reorder drafts ignore model-supplied quantities and require human review. No supplier order is sent.
- PDF/Word drafts retain the existing renderer and add institution names, generic/brand details, supplier when recorded, current/minimum stock, separate suggested/requested quantities, reference/date, prepared/reviewed/approved names and signature fields. Downloads require owner, review confirmation and a current inventory fingerprint.

Default suggestion: target is the greater of minimum stock × 2 and the ceiling of recorded dispensing over 30 days / 30 × 37 days; suggested quantity is target minus usable stock, bounded at zero. Nurses can configure lookback, coverage, safety days and minimum-stock multiplier. Missing facts remain unspecified. Existing medicine IDs represent separate batches. Expiry on today's date retains the existing usable-through-date convention.

## Actual validation

Executed CLI PHP endpoints with authenticated sessions and isolated MySQL temporary tables; these exercise the application APIs without changing live medicines or sending staff notifications.

- Nurse list/add/edit/stock adjustment/dispense passed; authorized co-admin list/MICA/PDF passed; unauthorized co-admin and Super Admin inventory requests returned 403; Super Admin technical health passed. Co-admin mutations and approval were denied.
- Shared-service SQL tests passed for usage-based suggestions, thresholds, low/expired/near-expiry membership, combined search and filters, notification authorization/deduplication, row-locked deductions, insufficient stock, expired batches, history linkage and rollback after ledger failure.
- MICA tests passed for deterministic requests, ignoring invented quantities, human-review state, stale drafts and role restrictions.
- JavaScript DOM fixtures passed for nurse/co-admin column mapping, escaping, one search control, paging beyond 200 records, combined filters and denied-request recovery.
- Real PDF and DOCX generation passed, including authenticated nurse/co-admin PDF bytes, validation, text fields and a multi-page 85-row document. PDF text bounds were checked, and single-page and multi-page renders were visually inspected.
- Consultation SQL/JavaScript tests passed with all fixture writes rolled back, including exact dispensing/history linkage and failed completion rollback. Messaging, patient activity, nurse dashboard, SOS frontend/global overlay and PWA cache/offline fixtures passed. Student and teacher QR pages rendered HTTP 200. The SOS SQL fixture printed its state-transition assertions as passed, then its final deliberately denied operation reached the application's generic exception handler; this is not claimed as a clean end-to-end SOS run.
- Live preservation comparison passed: **10 medicine records and 14 medicine-log rows are identical to the pre-change backup**; permission rows also unchanged. Syntax checks passed for changed PHP/JavaScript sources.

Test scripts: `scratch/test_inventory_upgrade.php`, `scratch/test_inventory_upgrade_endpoint.php`, `scratch/test_inventory_upgrade_ui.js`, `scratch/test_mica_inventory.php`, `scratch/test_requisition_downloads.php`, `scratch/verify_inventory_pdf.py`, `scratch/verify_inventory_preservation.php`, and the existing regression fixtures. QA artifacts are under `scratch/inventory-upgrade-qa/`.

## Limits and recovery

Browser layout, an actual browser download, live AI-provider responses, concurrent multi-connection load and physical-device/PWA behavior were not verified. PDF generation and download authorization were tested through PHP execution; this does not substitute for a browser acceptance pass. The wider workspace already contains other changes, including files edited concurrently during this task; those were preserved.

Recoverable source backup: `scratch/backups/inventory-mica-20261022/code-before.zip`, with SHA-256 manifest and `data-before.json` containing the medicine/history/permission before-image. Backups are denied HTTP access by `scratch/backups/.htaccess`. No database restore is necessary: production medicine records and dispensing history were not altered. For a code rollback, compare and restore only inventory-related entries from the backup after retaining any subsequent user edits; do not overwrite the entire working tree.
