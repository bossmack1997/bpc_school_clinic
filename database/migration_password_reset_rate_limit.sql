-- =====================================================================
-- Password Reset Rate Limiting
-- =====================================================================
-- Adds lightweight rate limiting for password reset requests to prevent
-- abuse/flooding without revealing whether an email exists.

CREATE TABLE IF NOT EXISTS password_reset_attempts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ip_address VARCHAR(45) NOT NULL,
    attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_reset_ip_time (ip_address, attempted_at)
) ENGINE=InnoDB;
