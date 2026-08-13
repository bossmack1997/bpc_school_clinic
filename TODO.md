# Smart Single QR System — Implementation TODO

1. [x] Database migration (`database/migration_smart_qr.sql`)
2. [x] Add QR helper functions in `includes/functions.php` (smart scan, export, MICA greeting)
3. [x] Add API actions in `api/qr_codes.php` (smart_scan, mark_arrived, qr_export, scan_intent helpers)
4. [x] Update Nurse Scanner UI (`includes/qr_scanner_ui.php`) — Smart QR Workflow panel
5. [x] Update QR Management UI (`includes/qr_management_ui.php`) — Export Logs + new status filters + analytics strip
6. [x] Add QR report types + PDF/Excel/CSV export (`api/reports.php`, `pages/nurse/reports.php`, `pages/admin/reports.php`, `api/qr_codes.php` QR export)
7. [x] MICA integration — post-scan greeting with queue info (via `build_mica_scan_greeting`)
8. [ ] Update Student Health Pass (`pages/student/my_qr.php`) — new QR status display
9. [ ] Test workflows (registration, check-in, walk-in, medicine, certificate, scanning)
