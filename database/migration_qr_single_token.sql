-- =====================================================================
-- Single Smart QR Code System — Token-Only QR + Status Lifecycle Audit
-- Bulacan Polytechnic College (BPC) - AI-Powered School Clinic
-- =====================================================================
-- IDEMPOTENT / ADDITIVE migration — safe to run multiple times.
-- Never drops or renames existing tables/columns. Existing data preserved.
-- For MariaDB 10.4+ / MySQL 8.0+ (IF NOT EXISTS column syntax).

USE bpc_clinic_system;

-- ---------------------------------------------------------------------
-- 1) QR SETTINGS — new keys for the token-only QR format
--    qr_token_prefix : Prefix appended to the random token (e.g. "BPC-")
--    qr_token_length  : Length of the random hex token body
--    qr_pii_in_payload: 0 = encrypt NO PII in the QR (recommended/secure)
-- ---------------------------------------------------------------------
INSERT IGNORE INTO qr_settings (setting_key, setting_value, data_type, description) VALUES
    ('qr_token_prefix',  'BPC-', 'string',  'Prefix for the encrypted QR token (e.g. BPC-xxxxxxxx)'),
    ('qr_token_length',  '40',   'integer', 'Length of the random hex token body'),
    ('qr_pii_in_payload','0',    'boolean', '0 = QR encodes ONLY an encrypted token (no PII)');

-- ---------------------------------------------------------------------
-- 2) STUDENT_QR_HISTORY — audit trail for QR status lifecycle
--    Records every generate / regenerate / deactivate / reactivate /
--    revoke, including the previous token and a reason.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS student_qr_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    action VARCHAR(40) NOT NULL
        COMMENT 'generated, regenerated, deactivated, reactivated, revoked, activated',
    old_token VARCHAR(255) DEFAULT NULL,
    new_token VARCHAR(255) DEFAULT NULL,
    reason VARCHAR(255) DEFAULT NULL,
    performed_by INT DEFAULT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_sqrh_student (student_id),
    KEY idx_sqrh_token (new_token),
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    FOREIGN KEY (performed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

