# Implementation Plan — Remaining Gaps

## Goal

Complete the Notification System, QR Management, and Medicine Inventory enhancements that were only partially implemented.

## Status

All pending gaps have been implemented and the database migration has been applied successfully.

## Steps

- [x] 1. Update `api/qr_codes.php` — allow students to fetch their own QR payload (data + self list).
- [x] 2. Create `pages/student/my_qr.php` — student-facing QR page (view/download/print own QR).
- [x] 3. Add student sidebar link to `components/sidebar.php` (My QR Code).
- [x] 4. Auto-generate QR on account approval in `api/users.php` (approve action).
- [x] 5. Add Import Students CSV (with auto-QR) to `pages/admin/students.php` + `api/students_api.php` import action.
- [x] 6. Fix notification bell in `assets/js/main.js` — always show "View All", mark-read-on-click before navigating.
- [x] 7. Update `TODO_ENHANCE.md` to reflect completion.
- [x] 8. Provide migration runner to apply `migration_qr_inventory_notifications.sql`.

## Verification

The `_verify_bulk.php` script confirmed the migration succeeded:
- `notifications` now has `priority`, `related_id`, `read_at`
- `student_qrs` table created (2 student QRs backfilled)
- `qr_checkins` table present
- `medicines` now has `generic_name`, `brand`, `dosage`, `form`, `manufacturer`, `storage_location`, `description`, `medicine_image`, `is_archived`
- `consultations.visit_type` extended to support `dispensing`

The migration can be re-applied idempotently using `php _run_bulk_migration.php`.
