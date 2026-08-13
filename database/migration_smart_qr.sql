-- =====================================================================
-- Smart Single QR System — Focused Enhancement Migration
-- Bulacan Polytechnic College (BPC) - AI-Powered School Clinic
-- =====================================================================
-- IDEMPOTENT / ADDITIVE migration — safe to run multiple times.
-- Preserves existing tables, data, and relationships (backward compatible).
-- For MariaDB 10.4+ / MySQL 8.0+ (IF NOT EXISTS column syntax).

USE bpc_clinic_system;

-- ---------------------------------------------------------------------
-- 1) STUDENT_QRS — widen status to support the full QR lifecycle.
--    Statuses: active | inactive | revoked | regenerated | deactivated
--    (kept 'deactivated' for backward compatibility with existing rows).
--    Add qr_image (optional stored image path), revoked_at, revoke_reason.
-- ---------------------------------------------------------------------
ALTER TABLE student_qrs
  MODIFY status ENUM('active','inactive','revoked','regenerated','deactivated')
  NOT NULL DEFAULT 'active';

ALTER TABLE student_qrs
  ADD COLUMN IF NOT EXISTS qr_image VARCHAR(255) DEFAULT NULL AFTER qr_data,
  ADD COLUMN IF NOT EXISTS revoked_at DATETIME DEFAULT NULL AFTER last_printed_at,
  ADD COLUMN IF NOT EXISTS revoke_reason VARCHAR(255) DEFAULT NULL AFTER revoked_at;

-- ---------------------------------------------------------------------
-- 2) QR_SCAN_LOGS — add scan_intent for the Smart QR workflow.
--    Purpose remains the general category; scan_intent captures the
--    smart-scanner detected scenario (appointment | walk_in | emergency |
--    medicine | certificate | check_in).
-- ---------------------------------------------------------------------
ALTER TABLE qr_scan_logs
  ADD COLUMN IF NOT EXISTS scan_intent VARCHAR(50) DEFAULT NULL AFTER purpose;

CREATE INDEX IF NOT EXISTS idx_qr_scan_logs_intent ON qr_scan_logs(scan_intent);

-- ---------------------------------------------------------------------
-- 3) QR_SETTINGS — add smart-workflow + queue-announcement settings
-- ---------------------------------------------------------------------
INSERT IGNORE INTO qr_settings (setting_key, setting_value, data_type, description) VALUES
    ('smart_scan_enabled',        '1',    'boolean', 'Enable the Smart QR auto-detection workflow'),
    ('auto_mark_arrived',         '1',    'boolean', 'Automatically mark a student as Arrived when scanning with a same-day approved appointment'),
    ('appointment_mark_status',   'arrived', 'string', 'Status applied to an appointment when the student arrives via QR scan'),
    ('mica_scan_greeting_enabled','1',    'boolean', 'Have MICA greet the student after a successful smart check-in');

-- ---------------------------------------------------------------------
-- 4) APPOINTMENTS — support "arrived" lifecycle state for check-in flow
-- ---------------------------------------------------------------------
ALTER TABLE appointments
  ADD COLUMN IF NOT EXISTS arrived_at DATETIME DEFAULT NULL AFTER status,
  ADD COLUMN IF NOT EXISTS qr_token VARCHAR(255) DEFAULT NULL AFTER arrived_at;

ALTER TABLE appointments
  MODIFY COLUMN status ENUM('pending','approved','rejected','completed','cancelled','rescheduled','arrived')
  NOT NULL DEFAULT 'pending';
