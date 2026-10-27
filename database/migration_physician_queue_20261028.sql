-- Additive physician handoff. Run preflight first in the selected application DB.
-- No existing clinical rows or permissions are changed. Never drop populated tables.
CREATE TABLE IF NOT EXISTS physician_queue (
 id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
 queue_id INT NOT NULL,
 patient_user_id INT NOT NULL,
 referred_by INT NOT NULL,
 assigned_doctor_id INT NULL,
 consultation_id INT NULL,
 priority ENUM('normal','urgent','emergency') NOT NULL DEFAULT 'normal',
 reason VARCHAR(1000) NOT NULL,
 status ENUM('awaiting','in_progress','completed') NOT NULL DEFAULT 'awaiting',
 record_version INT NOT NULL DEFAULT 0,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 started_at DATETIME NULL,
 completed_at DATETIME NULL,
 UNIQUE KEY uq_physician_queue_ticket (queue_id),
 UNIQUE KEY uq_physician_queue_consultation (consultation_id),
 KEY idx_physician_queue_status (status,priority,created_at),
 FOREIGN KEY (queue_id) REFERENCES queue_numbers(id) ON DELETE RESTRICT,
 FOREIGN KEY (patient_user_id) REFERENCES users(id) ON DELETE RESTRICT,
 FOREIGN KEY (referred_by) REFERENCES users(id) ON DELETE RESTRICT,
 FOREIGN KEY (assigned_doctor_id) REFERENCES users(id) ON DELETE RESTRICT,
 FOREIGN KEY (consultation_id) REFERENCES consultations(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
