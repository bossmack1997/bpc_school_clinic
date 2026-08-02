-- =====================================================================
-- SOS Emergency System Migration
-- Bulacan Polytechnic College (BPC) - AI-Powered School Clinic
-- =====================================================================

-- SOS EMERGENCIES TABLE
CREATE TABLE IF NOT EXISTS sos_emergencies (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    student_id INT DEFAULT NULL,
    emergency_type VARCHAR(100) NOT NULL COMMENT 'Chest Pain, Difficulty Breathing, Severe Bleeding, Unconscious, Seizure, etc.',
    description TEXT DEFAULT NULL,
    status ENUM('active', 'responded', 'resolved', 'false_alarm') NOT NULL DEFAULT 'active',
    latitude DECIMAL(10, 7) DEFAULT NULL,
    longitude DECIMAL(10, 7) DEFAULT NULL,
    location_accuracy VARCHAR(50) DEFAULT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    user_agent TEXT DEFAULT NULL,
    responded_by INT DEFAULT NULL,
    responded_at DATETIME DEFAULT NULL,
    resolved_at DATETIME DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE SET NULL,
    FOREIGN KEY (responded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- INDEX for faster lookups
CREATE INDEX idx_sos_status ON sos_emergencies(status);
CREATE INDEX idx_sos_user ON sos_emergencies(user_id);
CREATE INDEX idx_sos_created ON sos_emergencies(created_at);

