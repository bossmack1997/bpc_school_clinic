CREATE TABLE IF NOT EXISTS admin_profiles (
 user_id INT PRIMARY KEY, profile ENUM('mis','doctor','legacy') NOT NULL,
 appointment_reference VARCHAR(255) NOT NULL, clinical_registration VARCHAR(150) NULL,
 verified_by INT NOT NULL, verified_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS admin_profile_history (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, actor_id INT NOT NULL,
 previous_profile VARCHAR(20) NOT NULL, new_profile VARCHAR(20) NOT NULL,
 reference VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX profile_history_user (user_id,id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS admin_departures (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, authorized_by INT NOT NULL,
 effective_at DATETIME NOT NULL, authority_reference VARCHAR(255) NOT NULL,
 status ENUM('scheduled','applied','cancelled') NOT NULL DEFAULT 'scheduled', applied_at DATETIME NULL,
 INDEX departure_due (status,effective_at)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS system_backups (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, requested_by INT NOT NULL,
 status VARCHAR(20) NOT NULL, filename VARCHAR(255) NULL, sha256 CHAR(64) NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, completed_at DATETIME NULL,
 error_message VARCHAR(255) NULL
) ENGINE=InnoDB;
ALTER TABLE consultations ADD COLUMN IF NOT EXISTS clinician_user_id INT NULL;
ALTER TABLE consultations ADD COLUMN IF NOT EXISTS record_version INT NOT NULL DEFAULT 0;
CREATE INDEX IF NOT EXISTS consultation_clinician ON consultations (clinician_user_id,consultation_date);
