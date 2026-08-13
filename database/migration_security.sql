-- =====================================================================
-- BPC Clinic System — Security Migration
-- Adds tables and columns needed for login rate limiting and
-- enhanced security tracking.
-- =====================================================================

-- ---------------------------------------------------------------------
-- LOGIN ATTEMPTS — tracks failed login attempts for rate limiting
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS login_attempts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ip_address VARCHAR(45) NOT NULL,
    username VARCHAR(120) DEFAULT NULL,
    attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_login_attempts_ip_time (ip_address, attempted_at)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- USERS — add last_failed_login and failed_login_count for rate limiting
-- ---------------------------------------------------------------------
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS last_failed_login DATETIME DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS failed_login_count INT DEFAULT 0;
