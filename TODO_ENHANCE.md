# TODO — Notification System, QR Management & Medicine Inventory Enhancement

## Phase 1 — Database Migration

- [x] Create `database/migration_qr_inventory_notifications.sql` (idempotent, additive)
  - notifications: +priority, +related_id, +read_at
  - medicines: +generic_name, +brand, +dosage, +form, +manufacturer, +storage_location, +description, +medicine_image, +is_archived
  - consultations: visit_type ENUM + 'dispensing'
  - qr_checkins: +purpose, +scanner_id, +status
  - new table student_qrs (one permanent QR per student) + backfill

## Phase 2 — Notification Center

- [ ] Extend `includes/functions.php` (push_notification priority/related_id, QR & medicine helpers)
- [ ] Enhance `api/notifications.php` (list filters/search/sort/pagination, details, delete, delete_selected, refresh/latest)
- [ ] Create `includes/notification_ui.php` shared partial
- [ ] Create `pages/admin/notifications.php`
- [ ] Rebuild `pages/nurse/notifications.php` & `pages/student/notifications.php`
- [ ] Update notification bell in `assets/js/main.js` (latest 5 + always View All + mark read on click)
- [ ] Add qrcodejs + SheetJS CDN to `includes/head.php`

## Phase 3 — QR Management Module

- [ ] Create `api/qr_codes.php` (list, ensure, regenerate, deactivate, details, logs, backfill)
- [ ] Add QR Management links to `components/sidebar.php` (admin + nurse)
- [ ] Create `pages/admin/qr_management.php`
- [ ] Create `pages/nurse/qr_management.php`
- [ ] Create `pages/student/my_qr.php`
- [ ] Auto-generate QR on registration (`auth/register.php`) and approval (`api/users.php`)
- [ ] Update `api/walkin.php` qr_checkin (decode payload, log purpose/scanner/status)

## Phase 4 — Medicine Inventory Enhancement

- [ ] Enhance `api/inventory.php` (status, add/edit/delete/archive, bulk, import, template, stats)
- [ ] Redesign `pages/admin/inventory.php`
- [ ] Redesign `pages/nurse/inventory.php`
- [ ] Auto-alerts (low stock / near expiry / expired / out of stock → notifications)

## Phase 5 — Medicine Dispensing Module

- [ ] Enhance `api/dispensing.php` (search w/ expiry, validation, consultations record, history)
- [ ] Redesign `pages/nurse/dispensing.php`

## Phase 6 — UI/UX & Sidebar

- [ ] Add Import Students CSV to `pages/admin/students.php` (auto-QR)
- [ ] Add new styles to `assets/css/style.css`
- [ ] Final review & test

## Follow-up

- Run migration SQL (phpMyAdmin / MySQL / PHP runner)
- Test admin/nurse/student flows
