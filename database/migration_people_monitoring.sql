CREATE TABLE IF NOT EXISTS account_security_events (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 event_type VARCHAR(40) NOT NULL, subject_key CHAR(64) NOT NULL, user_id INT NULL,
 details VARCHAR(255) NOT NULL, occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX account_event_time (event_type,occurred_at), INDEX account_event_subject (subject_key)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS account_security_exceptions (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL,
 reason TEXT NOT NULL, evidence_reference VARCHAR(255) NOT NULL,
 opened_by INT NOT NULL, opened_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 status VARCHAR(20) NOT NULL DEFAULT 'open', disposition VARCHAR(30) NULL,
 resolved_by INT NULL, resolved_at DATETIME NULL, resolution_notes TEXT NULL,
 before_status VARCHAR(20) NULL, after_status VARCHAR(20) NULL,
 INDEX account_exception_status (status,user_id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS institutional_roster (
 id INT AUTO_INCREMENT PRIMARY KEY, identifier VARCHAR(100) NOT NULL,
 role ENUM('student','teacher','nurse','doctor','admin') NOT NULL,
 first_name VARCHAR(100) NOT NULL, last_name VARCHAR(100) NOT NULL,
 institutional_email VARCHAR(191) NOT NULL, department_or_course VARCHAR(150) NULL,
 license_number VARCHAR(100) NULL, status ENUM('eligible','registered','revoked') NOT NULL DEFAULT 'eligible',
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY roster_identity (identifier)
) ENGINE=InnoDB;
