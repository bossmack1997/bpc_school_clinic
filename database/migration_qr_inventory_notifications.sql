-- =====================================================================
-- QR Management, Notification Center & Medicine Inventory Enhancement
-- Bulacan Polytechnic College (BPC) - AI-Powered School Clinic
-- =====================================================================
-- IDEMPOTENT / ADDITIVE migration — safe to run multiple times.
-- Never drops or renames existing tables/columns. Existing data preserved.
-- For MariaDB 10.4+ / MySQL 8.0+ (IF NOT EXISTS column syntax).

USE bpc_clinic_system;

-- ---------------------------------------------------------------------
-- 1) NOTIFICATIONS — enrichment for the Notification Center
--    priority    : low | medium | high | emergency
--    related_id  : generic FK-style id (appointment id, medicine id, etc.)
--    read_at     : when the notification was actually read (audit)
-- ---------------------------------------------------------------------
ALTER TABLE notifications
  ADD COLUMN IF NOT EXISTS priority ENUM('low','medium','high','emergency') NOT NULL DEFAULT 'medium' AFTER type,
  ADD COLUMN IF NOT EXISTS related_id INT DEFAULT NULL AFTER link,
  ADD COLUMN IF NOT EXISTS read_at DATETIME DEFAULT NULL AFTER is_read;

CREATE INDEX IF NOT EXISTS idx_notifications_type ON notifications(type);
CREATE INDEX IF NOT EXISTS idx_notifications_priority ON notifications(priority);
CREATE INDEX IF NOT EXISTS idx_notifications_related ON notifications(related_id);

-- ---------------------------------------------------------------------
-- 2) MEDICINES — professional inventory fields
-- ---------------------------------------------------------------------
ALTER TABLE medicines
  ADD COLUMN IF NOT EXISTS generic_name VARCHAR(150) DEFAULT NULL AFTER medicine_name,
  ADD COLUMN IF NOT EXISTS brand VARCHAR(120) DEFAULT NULL AFTER generic_name,
  ADD COLUMN IF NOT EXISTS dosage VARCHAR(80) DEFAULT NULL AFTER category,
  ADD COLUMN IF NOT EXISTS form ENUM('Tablet','Capsule','Syrup','Injection','Cream','Drops','Powder','Other') DEFAULT NULL AFTER dosage,
  ADD COLUMN IF NOT EXISTS manufacturer VARCHAR(150) DEFAULT NULL AFTER supplier,
  ADD COLUMN IF NOT EXISTS storage_location VARCHAR(120) DEFAULT NULL AFTER batch_number,
  ADD COLUMN IF NOT EXISTS description TEXT DEFAULT NULL AFTER storage_location,
  ADD COLUMN IF NOT EXISTS medicine_image VARCHAR(255) DEFAULT NULL AFTER barcode,
  ADD COLUMN IF NOT EXISTS is_archived TINYINT(1) NOT NULL DEFAULT 0 AFTER medicine_image;

ALTER TABLE medicines
  ADD COLUMN IF NOT EXISTS reorder_level INT NOT NULL DEFAULT 20 AFTER unit;

CREATE INDEX IF NOT EXISTS idx_medicines_category ON medicines(category);
CREATE INDEX IF NOT EXISTS idx_medicines_supplier ON medicines(supplier);
CREATE INDEX IF NOT EXISTS idx_medicines_batch ON medicines(batch_number);
CREATE INDEX IF NOT EXISTS idx_medicines_archived ON medicines(is_archived);

-- reorder_level already exists in fresh installs; ensure it's not NULL-safe.
-- (No-op if present.)

-- ---------------------------------------------------------------------
-- 3) CONSULTATIONS — allow dispensing records to be part of the
--    medical record / clinic visit history.
-- ---------------------------------------------------------------------
ALTER TABLE consultations
  MODIFY COLUMN visit_type ENUM('walk-in','appointment','dispensing') NOT NULL DEFAULT 'walk-in';

-- ---------------------------------------------------------------------
-- 4) QR_CHECKINS — richer scan logs
-- ---------------------------------------------------------------------
ALTER TABLE qr_checkins
  ADD COLUMN IF NOT EXISTS purpose VARCHAR(80) DEFAULT 'check-in' AFTER qr_data,
  ADD COLUMN IF NOT EXISTS scanner_id INT DEFAULT NULL AFTER purpose,
  ADD COLUMN IF NOT EXISTS status ENUM('success','invalid','duplicate') NOT NULL DEFAULT 'success' AFTER scanner_id;

-- ---------------------------------------------------------------------
-- 5) STUDENT_QRS — ONE permanent QR per student.
--    Avoids duplicating/regenerating many times; qr_token is the
--    encrypted/HMAC token embedded in the QR payload.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS student_qrs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    qr_token VARCHAR(255) NOT NULL,
    qr_data VARCHAR(512) NOT NULL,
    status ENUM('active','deactivated') NOT NULL DEFAULT 'active',
    generated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    generated_by INT DEFAULT NULL,
    last_printed_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_student_qr_student (student_id),
    UNIQUE KEY uq_student_qr_token (qr_token),
    KEY idx_student_qr_status (status),
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    FOREIGN KEY (generated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 6) BACKFILL — create a student_qrs row for every existing student
--    that does not yet have one. Payload format (JSON):
--    { v:1, sid:<student_id>, sno:<student_number>, t:<token> }
--    token = substr(sha256(<APP_KEY>|<student_id>|<student_number>),0,32)
-- ---------------------------------------------------------------------
INSERT INTO student_qrs (student_id, qr_token, qr_data, status, generated_at)
SELECT
    s.id,
    SUBSTRING(SHA2(CONCAT('bpc_qr_ph1_', s.id, '_', s.student_number), 256), 1, 40) AS qr_token,
    CONCAT(
        '{"v":1,"sid":', s.id,
        ',"sno":"', REPLACE(REPLACE(REPLACE(s.student_number, '\\', '\\\\'), '"', '\\"'), '\n', '\\n'),
        '","t":"', SUBSTRING(SHA2(CONCAT('bpc_qr_ph1_', s.id, '_', s.student_number), 256), 1, 40), '"}'
    ) AS qr_data,
    'active',
    NOW()
FROM students s
LEFT JOIN student_qrs q ON q.student_id = s.id
WHERE q.id IS NULL;

-- Backfill students.qr_code with the same token for backward compatibility
UPDATE students st
JOIN student_qrs q ON q.student_id = st.id
SET st.qr_code = q.qr_token
WHERE (st.qr_code IS NULL OR st.qr_code = '');

