-- =====================================================================
-- Walk-in Patient Management Module
-- Bulacan Polytechnic College (BPC) School Clinic
-- =====================================================================

-- QUEUE NUMBERS
CREATE TABLE IF NOT EXISTS queue_numbers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    queue_number VARCHAR(30) NOT NULL UNIQUE,
    student_id INT DEFAULT NULL,
    full_name VARCHAR(160) DEFAULT NULL,
    visit_type ENUM('walk-in','appointment','emergency') NOT NULL DEFAULT 'walk-in',
    priority ENUM('normal','urgent','emergency') NOT NULL DEFAULT 'normal',
    status ENUM('waiting','serving','completed','cancelled','no_show') NOT NULL DEFAULT 'waiting',
    estimated_wait_minutes INT DEFAULT NULL,
    called_at DATETIME DEFAULT NULL,
    served_at DATETIME DEFAULT NULL,
    completed_at DATETIME DEFAULT NULL,
    source ENUM('walkin_module','appointment_module','sos_emergency') DEFAULT 'walkin_module',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE INDEX idx_queue_status ON queue_numbers(status);
CREATE INDEX idx_queue_date ON queue_numbers(created_at);
CREATE INDEX idx_queue_priority ON queue_numbers(priority);

-- WALK-IN VISITS
CREATE TABLE IF NOT EXISTS walk_in_visits (
    id INT AUTO_INCREMENT PRIMARY KEY,
    queue_id INT DEFAULT NULL,
    student_id INT DEFAULT NULL,
    is_temp_visitor TINYINT(1) NOT NULL DEFAULT 0,
    temp_full_name VARCHAR(160) DEFAULT NULL,
    temp_course VARCHAR(100) DEFAULT NULL,
    temp_year_level VARCHAR(20) DEFAULT NULL,
    temp_contact VARCHAR(30) DEFAULT NULL,
    visit_number VARCHAR(30) NOT NULL UNIQUE,
    visit_date DATE NOT NULL,
    arrival_time TIME NOT NULL,
    visit_type ENUM('walk-in','emergency') NOT NULL DEFAULT 'walk-in',
    chief_complaint VARCHAR(255) NOT NULL,
    priority_level ENUM('normal','urgent','emergency') NOT NULL DEFAULT 'normal',
    
    -- Vitals
    temperature DECIMAL(4,1) DEFAULT NULL,
    blood_pressure_systolic INT DEFAULT NULL,
    blood_pressure_diastolic INT DEFAULT NULL,
    heart_rate INT DEFAULT NULL,
    respiratory_rate INT DEFAULT NULL,
    oxygen_saturation DECIMAL(4,1) DEFAULT NULL,
    height_cm DECIMAL(5,2) DEFAULT NULL,
    weight_kg DECIMAL(5,2) DEFAULT NULL,
    bmi DECIMAL(5,2) DEFAULT NULL,
    bmi_status VARCHAR(20) DEFAULT NULL,
    
    -- Consultation
    symptoms TEXT DEFAULT NULL,
    assessment TEXT DEFAULT NULL,
    diagnosis VARCHAR(255) DEFAULT NULL,
    treatment TEXT DEFAULT NULL,
    recommendation TEXT DEFAULT NULL,
    need_follow_up TINYINT(1) NOT NULL DEFAULT 0,
    follow_up_date DATE DEFAULT NULL,
    
    -- AI Summary
    ai_summary TEXT DEFAULT NULL,
    ai_summary_edited TEXT DEFAULT NULL,
    
    -- Nurse
    attended_by INT DEFAULT NULL,
    digital_signature TEXT DEFAULT NULL,
    
    -- Status
    status ENUM('waiting','in_consultation','completed','cancelled') NOT NULL DEFAULT 'waiting',
    
    -- Timestamps
    consultation_started_at DATETIME DEFAULT NULL,
    consultation_ended_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (queue_id) REFERENCES queue_numbers(id) ON DELETE SET NULL,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE SET NULL,
    FOREIGN KEY (attended_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE INDEX idx_walkin_date ON walk_in_visits(visit_date);
CREATE INDEX idx_walkin_status ON walk_in_visits(status);
CREATE INDEX idx_walkin_student ON walk_in_visits(student_id);

-- MEDICINE DISPENSING
CREATE TABLE IF NOT EXISTS medicine_dispensing (
    id INT AUTO_INCREMENT PRIMARY KEY,
    walk_in_id INT NOT NULL,
    medicine_id INT NOT NULL,
    quantity INT NOT NULL,
    dosage_instructions VARCHAR(255) DEFAULT NULL,
    dispensed_by INT DEFAULT NULL,
    dispensed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (walk_in_id) REFERENCES walk_in_visits(id) ON DELETE CASCADE,
    FOREIGN KEY (medicine_id) REFERENCES medicines(id) ON DELETE CASCADE,
    FOREIGN KEY (dispensed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- VISIT LOGS
CREATE TABLE IF NOT EXISTS visit_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    walk_in_id INT DEFAULT NULL,
    action VARCHAR(100) NOT NULL,
    details TEXT DEFAULT NULL,
    performed_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (walk_in_id) REFERENCES walk_in_visits(id) ON DELETE SET NULL,
    FOREIGN KEY (performed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- QR CODE CHECK-IN
CREATE TABLE IF NOT EXISTS qr_checkins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    qr_data VARCHAR(255) DEFAULT NULL,
    checked_in_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    walk_in_id INT DEFAULT NULL,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    FOREIGN KEY (walk_in_id) REFERENCES walk_in_visits(id) ON DELETE SET NULL
) ENGINE=InnoDB;

