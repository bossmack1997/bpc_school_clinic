-- Additive incident workflow. Review and back up the deployment before applying.
CREATE TABLE IF NOT EXISTS security_incidents (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 event_id BIGINT UNSIGNED NOT NULL UNIQUE,
 severity VARCHAR(15) NOT NULL,
 stage VARCHAR(20) NOT NULL DEFAULT 'detected',
 reviewer_id INT NOT NULL,
 outcome VARCHAR(255) NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 resolved_at DATETIME NULL,
 INDEX incident_stage (stage,updated_at)
) ENGINE=InnoDB;
