-- BPC clinic workflow consolidation.  Run after migration_20260904_four_role_clinic.sql.
-- This preserves historical records; it deliberately does not drop legacy tables.

-- Four application roles and a non-destructive account lifecycle.
ALTER TABLE users
  MODIFY COLUMN role ENUM('student','nurse','teacher','admin') NOT NULL,
  MODIFY COLUMN status ENUM('pending','active','suspended','graduating','graduated','deactivated','inactive','rejected') NOT NULL DEFAULT 'pending';

-- A consultation may have multiple dispensed medicines.  Existing walk-in
-- medicine_dispensing records remain intact; this table is for the unified
-- consultation workflow and makes inventory movements traceable per visit.
CREATE TABLE IF NOT EXISTS consultation_medicines (
    id INT AUTO_INCREMENT PRIMARY KEY,
    consultation_id INT NOT NULL,
    medicine_id INT NOT NULL,
    quantity INT NOT NULL,
    dosage_instructions VARCHAR(255) DEFAULT NULL,
    dispensed_by INT DEFAULT NULL,
    dispensed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (consultation_id) REFERENCES consultations(id) ON DELETE CASCADE,
    FOREIGN KEY (medicine_id) REFERENCES medicines(id) ON DELETE RESTRICT,
    FOREIGN KEY (dispensed_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_consultation_medicines_consultation (consultation_id),
    INDEX idx_consultation_medicines_medicine (medicine_id)
) ENGINE=InnoDB;

-- Certificate generation is tied to the relevant clinic visit when one exists.
ALTER TABLE certificates
  ADD COLUMN IF NOT EXISTS consultation_id INT DEFAULT NULL,
  ADD INDEX IF NOT EXISTS idx_certificates_consultation (consultation_id);

-- Common operational lookups; no duplicate data is introduced.
CREATE INDEX IF NOT EXISTS idx_consultations_student_date ON consultations(student_id, consultation_date);
CREATE INDEX IF NOT EXISTS idx_medicines_stock_expiry ON medicines(quantity, reorder_level, expiration_date);
CREATE INDEX IF NOT EXISTS idx_sos_status_created ON sos_emergencies(status, created_at);
