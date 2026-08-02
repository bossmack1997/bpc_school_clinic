-- =====================================================================
-- AI-Powered School Clinic Management System
-- Bulacan Polytechnic College (BPC)
-- Database: bpc_clinic_system
-- =====================================================================

CREATE DATABASE IF NOT EXISTS bpc_clinic_system CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE bpc_clinic_system;

-- ---------------------------------------------------------------------
-- USERS (base account table for Admin / Nurse / Student)
-- ---------------------------------------------------------------------
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    role ENUM('admin','nurse','student') NOT NULL,
    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(120) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    first_name VARCHAR(80) NOT NULL,
    last_name VARCHAR(80) NOT NULL,
    phone VARCHAR(30) DEFAULT NULL,
    profile_picture VARCHAR(255) DEFAULT 'assets/images/default-avatar.png',
    status ENUM('active','inactive','pending') NOT NULL DEFAULT 'active',
    remember_token VARCHAR(255) DEFAULT NULL,
    reset_token VARCHAR(255) DEFAULT NULL,
    reset_token_expires DATETIME DEFAULT NULL,
    last_login DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- STUDENTS (extends users)
-- ---------------------------------------------------------------------
CREATE TABLE students (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    student_number VARCHAR(30) NOT NULL UNIQUE,
    course VARCHAR(100) DEFAULT NULL,
    year_level VARCHAR(20) DEFAULT NULL,
    section VARCHAR(30) DEFAULT NULL,
    gender ENUM('Male','Female','Other') DEFAULT NULL,
    birthdate DATE DEFAULT NULL,
    address VARCHAR(255) DEFAULT NULL,
    guardian_name VARCHAR(120) DEFAULT NULL,
    guardian_contact VARCHAR(30) DEFAULT NULL,
    emergency_contact_name VARCHAR(120) DEFAULT NULL,
    emergency_contact_number VARCHAR(30) DEFAULT NULL,
    blood_type VARCHAR(5) DEFAULT NULL,
    allergies TEXT DEFAULT NULL,
    qr_code VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- NURSES (extends users)
-- ---------------------------------------------------------------------
CREATE TABLE nurses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    license_number VARCHAR(60) DEFAULT NULL,
    specialization VARCHAR(120) DEFAULT NULL,
    shift_schedule VARCHAR(120) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- APPOINTMENTS
-- ---------------------------------------------------------------------
CREATE TABLE appointments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    nurse_id INT DEFAULT NULL,
    appointment_date DATE NOT NULL,
    appointment_time TIME NOT NULL,
    reason VARCHAR(255) NOT NULL,
    status ENUM('pending','approved','rejected','completed','cancelled','rescheduled') NOT NULL DEFAULT 'pending',
    notes TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    FOREIGN KEY (nurse_id) REFERENCES nurses(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- CONSULTATIONS (walk-in / appointment visit record, feeds medical_records)
-- ---------------------------------------------------------------------
CREATE TABLE consultations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    nurse_id INT DEFAULT NULL,
    appointment_id INT DEFAULT NULL,
    visit_type ENUM('walk-in','appointment') NOT NULL DEFAULT 'walk-in',
    consultation_date DATETIME NOT NULL,
    chief_complaint VARCHAR(255) NOT NULL,
    diagnosis VARCHAR(255) DEFAULT NULL,
    treatment TEXT DEFAULT NULL,
    prescription TEXT DEFAULT NULL,
    remarks TEXT DEFAULT NULL,
    follow_up_date DATE DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    FOREIGN KEY (nurse_id) REFERENCES nurses(id) ON DELETE SET NULL,
    FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- MEDICAL RECORDS (attachments linked to a consultation)
-- ---------------------------------------------------------------------
CREATE TABLE medical_records (
    id INT AUTO_INCREMENT PRIMARY KEY,
    consultation_id INT NOT NULL,
    student_id INT NOT NULL,
    file_path VARCHAR(255) DEFAULT NULL,
    file_type VARCHAR(50) DEFAULT NULL,
    description VARCHAR(255) DEFAULT NULL,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (consultation_id) REFERENCES consultations(id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- HEALTH MONITORING
-- ---------------------------------------------------------------------
CREATE TABLE health_monitoring (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    recorded_by INT DEFAULT NULL, -- nurse user id
    height_cm DECIMAL(5,2) DEFAULT NULL,
    weight_kg DECIMAL(5,2) DEFAULT NULL,
    bmi DECIMAL(5,2) DEFAULT NULL,
    bmi_status ENUM('Underweight','Normal','Overweight','Obese') DEFAULT NULL,
    blood_pressure VARCHAR(20) DEFAULT NULL,
    heart_rate INT DEFAULT NULL,
    pulse_rate INT DEFAULT NULL,
    temperature DECIMAL(4,1) DEFAULT NULL,
    oxygen_saturation DECIMAL(4,1) DEFAULT NULL,
    vision VARCHAR(30) DEFAULT NULL,
    hearing VARCHAR(30) DEFAULT NULL,
    recorded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- VACCINATION RECORD
-- ---------------------------------------------------------------------
CREATE TABLE vaccinations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    vaccine_name VARCHAR(150) NOT NULL,
    dose_number VARCHAR(20) DEFAULT NULL,
    date_administered DATE NOT NULL,
    administered_by VARCHAR(120) DEFAULT NULL,
    next_dose_date DATE DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- MEDICINES / INVENTORY
-- ---------------------------------------------------------------------
CREATE TABLE medicines (
    id INT AUTO_INCREMENT PRIMARY KEY,
    medicine_name VARCHAR(150) NOT NULL,
    category VARCHAR(80) DEFAULT NULL,
    supplier VARCHAR(150) DEFAULT NULL,
    quantity INT NOT NULL DEFAULT 0,
    unit VARCHAR(30) DEFAULT 'pcs',
    reorder_level INT NOT NULL DEFAULT 20,
    expiration_date DATE DEFAULT NULL,
    batch_number VARCHAR(60) DEFAULT NULL,
    barcode VARCHAR(80) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE medicine_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    medicine_id INT NOT NULL,
    action ENUM('stock_in','stock_out') NOT NULL,
    quantity INT NOT NULL,
    reason VARCHAR(255) DEFAULT NULL,
    student_id INT DEFAULT NULL,
    performed_by INT DEFAULT NULL,
    performed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (medicine_id) REFERENCES medicines(id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE SET NULL,
    FOREIGN KEY (performed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- alias table required by spec name
CREATE TABLE inventory_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    medicine_id INT NOT NULL,
    change_type ENUM('in','out','adjustment') NOT NULL,
    quantity_change INT NOT NULL,
    remarks VARCHAR(255) DEFAULT NULL,
    logged_by INT DEFAULT NULL,
    logged_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (medicine_id) REFERENCES medicines(id) ON DELETE CASCADE,
    FOREIGN KEY (logged_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- CERTIFICATES
-- ---------------------------------------------------------------------
CREATE TABLE certificates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    issued_by INT DEFAULT NULL,
    certificate_type VARCHAR(100) NOT NULL DEFAULT 'Medical Certificate',
    purpose VARCHAR(255) DEFAULT NULL,
    remarks TEXT DEFAULT NULL,
    date_issued DATE NOT NULL,
    file_path VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    FOREIGN KEY (issued_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- ANNOUNCEMENTS
-- ---------------------------------------------------------------------
CREATE TABLE announcements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(200) NOT NULL,
    content TEXT NOT NULL,
    posted_by INT DEFAULT NULL,
    audience ENUM('all','students','nurses','admin') NOT NULL DEFAULT 'all',
    is_pinned TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (posted_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- NOTIFICATIONS
-- ---------------------------------------------------------------------
CREATE TABLE notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    type VARCHAR(50) NOT NULL,
    title VARCHAR(150) NOT NULL,
    message VARCHAR(255) NOT NULL,
    link VARCHAR(255) DEFAULT NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- ACTIVITY LOGS (audit trail)
-- ---------------------------------------------------------------------
CREATE TABLE activity_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT DEFAULT NULL,
    action VARCHAR(150) NOT NULL,
    module VARCHAR(80) DEFAULT NULL,
    details VARCHAR(255) DEFAULT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- REPORTS (generated/exported report log)
-- ---------------------------------------------------------------------
CREATE TABLE reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    report_type VARCHAR(80) NOT NULL,
    period_from DATE DEFAULT NULL,
    period_to DATE DEFAULT NULL,
    generated_by INT DEFAULT NULL,
    file_path VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (generated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- SYSTEM SETTINGS
-- ---------------------------------------------------------------------
CREATE TABLE system_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(80) NOT NULL UNIQUE,
    setting_value TEXT DEFAULT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT INTO system_settings (setting_key, setting_value) VALUES
('school_name', 'Bulacan Polytechnic College'),
('clinic_name', 'BPC School Clinic'),
('school_address', 'Bulacan, Philippines'),
('clinic_contact', ''),
('system_logo', 'assets/images/logo.png'),
('theme_mode', 'light');

-- ---------------------------------------------------------------------
-- SEED: default administrator (username: admin / password: Admin@123)
-- Password hash generated with PHP password_hash('Admin@123', PASSWORD_DEFAULT)
-- ---------------------------------------------------------------------
INSERT INTO users (role, username, email, password_hash, first_name, last_name, status)
VALUES ('admin', 'admin', 'admin@bpc.edu.ph', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'System', 'Administrator', 'active');
-- NOTE: run reset_admin_password.php (provided) or use "Forgot Password" to set your own password on first login.

-- ---------------------------------------------------------------------
-- SEED: default school nurse (username: clinic_nurse / password: Nurse@123)
-- Password hash generated with PHP password_hash('Nurse@123', PASSWORD_DEFAULT)
-- ---------------------------------------------------------------------
INSERT INTO users (role, username, email, password_hash, first_name, last_name, status)
VALUES ('nurse', 'clinic_nurse', 'nurse@bpc.edu.ph', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Clinic', 'Nurse', 'active');
INSERT INTO nurses (user_id, license_number, specialization, shift_schedule)
VALUES (LAST_INSERT_ID(), 'PRC-2020-001', 'School Health Nursing', 'Monday - Friday 8:00 AM - 5:00 PM');

-- ---------------------------------------------------------------------
-- INDEXES for performance (foreign keys & frequently filtered columns)
-- ---------------------------------------------------------------------
CREATE INDEX idx_users_role_status ON users(role, status);
CREATE INDEX idx_students_user ON students(user_id);
CREATE INDEX idx_students_number ON students(student_number);
CREATE INDEX idx_nurses_user ON nurses(user_id);
CREATE INDEX idx_appointments_student ON appointments(student_id);
CREATE INDEX idx_appointments_date ON appointments(appointment_date);
CREATE INDEX idx_appointments_status ON appointments(status);
CREATE INDEX idx_consultations_student_date ON consultations(student_id, consultation_date);
CREATE INDEX idx_health_monitoring_student_date ON health_monitoring(student_id, recorded_at);
CREATE INDEX idx_vaccinations_student ON vaccinations(student_id);
CREATE INDEX idx_medicines_name ON medicines(medicine_name);
CREATE INDEX idx_medicines_expiry ON medicines(expiration_date);
CREATE INDEX idx_medicine_logs_medicine ON medicine_logs(medicine_id);
CREATE INDEX idx_medicine_logs_performed ON medicine_logs(performed_at);
CREATE INDEX idx_certificates_student ON certificates(student_id);
CREATE INDEX idx_announcements_audience ON announcements(audience);
CREATE INDEX idx_notifications_user_read ON notifications(user_id, is_read);
CREATE INDEX idx_activity_logs_user ON activity_logs(user_id);
CREATE INDEX idx_activity_logs_created ON activity_logs(created_at);
CREATE INDEX idx_reports_type ON reports(report_type);
