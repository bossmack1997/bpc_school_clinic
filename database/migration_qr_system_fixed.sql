-- =====================================================================
-- QR System — Extended Schema Migration
-- Bulacan Polytechnic College (BPC) - AI-Powered School Clinic
-- =====================================================================
-- IDEMPOTENT / ADDITIVE migration — safe to run multiple times.
-- Never drops or renames existing tables/columns. Existing data preserved.
-- For MariaDB 10.4+ / MySQL 8.0+ (IF NOT EXISTS column syntax).

USE bpc_clinic_system;

-- ---------------------------------------------------------------------
-- 1) QR_SCAN_LOGS — Detailed scan log with device/location/IP
--    Replaces the minimal qr_checkins enrichment with a richer log
--    that captures scanner, device, scan_location, ip_address, purpose.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS qr_scan_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    qr_token VARCHAR(255) NOT NULL,
    purpose VARCHAR(80) NOT NULL DEFAULT 'check-in'
        COMMENT 'check-in, appointment, medical_record, dispensing, certificate_verify, visitor_pass',
    scanner_id INT DEFAULT NULL,
    scanner_name VARCHAR(160) DEFAULT NULL,
    scan_location VARCHAR(160) DEFAULT NULL COMMENT 'e.g. Main Entrance, Nurse Station',
    device VARCHAR(120) DEFAULT NULL COMMENT 'browser/device user-agent summary',
    ip_address VARCHAR(45) DEFAULT NULL,
    status ENUM('success','invalid','duplicate','expired','not_found','disabled')
        NOT NULL DEFAULT 'success',
    metadata JSON DEFAULT NULL,
    scan_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    FOREIGN KEY (scanner_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE INDEX IF NOT EXISTS idx_qr_scan_logs_student   ON qr_scan_logs(student_id);
CREATE INDEX IF NOT EXISTS idx_qr_scan_logs_token     ON qr_scan_logs(qr_token);
CREATE INDEX IF NOT EXISTS idx_qr_scan_logs_status    ON qr_scan_logs(status);
CREATE INDEX IF NOT EXISTS idx_qr_scan_logs_time      ON qr_scan_logs(scan_time);
CREATE INDEX IF NOT EXISTS idx_qr_scan_logs_purpose   ON qr_scan_logs(purpose);
CREATE INDEX IF NOT EXISTS idx_qr_scan_logs_scanner   ON qr_scan_logs(scanner_id);

-- ---------------------------------------------------------------------
-- 2) QR_SETTINGS — Configurable QR system settings
--    Stored in system_settings for backward compatibility, but this
--    table provides typed fields for the QR module.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS qr_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(80) NOT NULL UNIQUE,
    setting_value TEXT DEFAULT NULL,
    data_type ENUM('string','integer','boolean') NOT NULL DEFAULT 'string',
    description VARCHAR(255) DEFAULT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT IGNORE INTO qr_settings (setting_key, setting_value, data_type, description) VALUES
    ('token_length',        '40',     'integer', 'Length of the random HMAC token embedded in QR payload'),
    ('token_expiration',    '0',      'integer', 'Dynamic QR expiration time in seconds (0 = never expires)'),
    ('enable_dynamic_qr',   '1',      'boolean', 'Enable dynamic QR (appointment/visitor pass) generation'),
    ('allow_duplicate_scan', '0',     'boolean', 'Allow the same QR to be scanned multiple times within the duplicate window'),
    ('duplicate_scan_window', '30',  'integer', 'Seconds before the same QR can be scanned again (anti-duplicate)'),
    ('queue_prefix',        'A',      'string',  'Prefix for generated queue numbers (e.g. A-001)'),
    ('scan_location_default', 'Clinic Entrance', 'string', 'Default scan location label');

-- ---------------------------------------------------------------------
-- 3) DYNAMIC_QRS — Temporary/dynamic QR codes (appointment verification,
--    medical certificate verification, visitor passes, one-time auth)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS dynamic_qrs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    purpose VARCHAR(80) NOT NULL
        COMMENT 'appointment, certificate, visitor_pass, one_time_auth',
    reference_id INT DEFAULT NULL
        COMMENT 'appointment_id, certificate_id, etc. (FK-style, nullable)',
    reference_type VARCHAR(50) DEFAULT NULL
        COMMENT 'appointments, certificates, walk_in_visits, etc.',
    subject_student_id INT DEFAULT NULL
        COMMENT 'Student this dynamic QR is for (if applicable)',
    qr_token VARCHAR(255) NOT NULL UNIQUE,
    qr_data VARCHAR(512) NOT NULL,
    status ENUM('active','expired','used','revoked') NOT NULL DEFAULT 'active',
    issued_by INT DEFAULT NULL,
    issued_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME DEFAULT NULL,
    used_at DATETIME DEFAULT NULL,
    FOREIGN KEY (subject_student_id) REFERENCES students(id) ON DELETE SET NULL,
    FOREIGN KEY (issued_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE INDEX IF NOT EXISTS idx_dynamic_qrs_token   ON dynamic_qrs(qr_token);
CREATE INDEX IF NOT EXISTS idx_dynamic_qrs_status  ON dynamic_qrs(status);
CREATE INDEX IF NOT EXISTS idx_dynamic_qrs_expires ON dynamic_qrs(expires_at);
CREATE INDEX IF NOT EXISTS idx_dynamic_qrs_purpose ON dynamic_qrs(purpose);

-- ---------------------------------------------------------------------
-- 4) ENRICH qr_checkins — add device/scanner columns if not present
--    (already has purpose, scanner_id, status from migration_qr_*)
-- ---------------------------------------------------------------------
ALTER TABLE qr_checkins
  ADD COLUMN IF NOT EXISTS scan_location VARCHAR(160) DEFAULT NULL AFTER purpose,
  ADD COLUMN IF NOT EXISTS ip_address VARCHAR(45) DEFAULT NULL AFTER scan_location,
  ADD COLUMN IF NOT EXISTS device VARCHAR(120) DEFAULT NULL AFTER ip_address;

-- ---------------------------------------------------------------------
-- 5) BACKFILL — migrate existing qr_checkins data into qr_scan_logs
--    so all historical scans are preserved in the richer log.
-- ---------------------------------------------------------------------
INSERT INTO qr_scan_logs (student_id, qr_token, purpose, scanner_id, scan_time, status)
SELECT
    qc.student_id,
    COALESCE(sq.qr_token, qc.qr_data),
    COALESCE(qc.purpose, 'check-in'),
    qc.scanner_id,
    qc.checked_in_at,
    COALESCE(qc.status, 'success')
FROM qr_checkins qc
LEFT JOIN student_qrs sq ON sq.student_id = qc.student_id
WHERE qc.id NOT IN (
    SELECT qc2.id FROM qr_checkins qc2
    JOIN qr_scan_logs qsl ON qsl.student_id = qc2.student_id
      AND qsl.scan_time = qc2.checked_in_at
);

-- ---------------------------------------------------------------------
-- 6) QUEUE_NUMBERS — add columns to support QR-based check-in
-- ---------------------------------------------------------------------
ALTER TABLE queue_numbers
  ADD COLUMN IF NOT EXISTS qr_token VARCHAR(255) DEFAULT NULL AFTER visit_type,
  ADD COLUMN IF NOT EXISTS checked_in_at TIMESTAMP NULL DEFAULT NULL AFTER qr_token;

ALTER TABLE queue_numbers
  MODIFY COLUMN source ENUM('walkin_module','appointment_module','sos_emergency','qr_checkin')
  DEFAULT 'walkin_module';

-- ---------------------------------------------------------------------
-- 7) STUDENTS — add last_checkin_at for quick check-in status lookup
-- ---------------------------------------------------------------------
ALTER TABLE students
  ADD COLUMN IF NOT EXISTS last_checkin_at DATETIME NULL DEFAULT NULL;
