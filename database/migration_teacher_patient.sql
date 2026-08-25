-- =====================================================================
-- BPC Clinic — Teacher/Staff as Clinic Patients + QR + Consultation support
-- Preserves all existing data. Additive changes only.
-- =====================================================================

-- Teacher/Staff patient identity (mirrors `students` but for staff)
CREATE TABLE IF NOT EXISTS teachers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    employee_id VARCHAR(50) DEFAULT NULL,
    first_name VARCHAR(60) DEFAULT NULL,
    last_name VARCHAR(60) DEFAULT NULL,
    department VARCHAR(120) DEFAULT NULL,
    email VARCHAR(150) DEFAULT NULL,
    phone VARCHAR(40) DEFAULT NULL,
    qr_token VARCHAR(120) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_teacher_user (user_id)
) ENGINE=InnoDB;

-- Permanent QR for teachers/staff (mirrors student_qrs)
CREATE TABLE IF NOT EXISTS teacher_qrs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    teacher_id INT NOT NULL,
    qr_token VARCHAR(120) NOT NULL,
    qr_data TEXT NOT NULL,
    qr_image LONGTEXT DEFAULT NULL,
    status ENUM('active','regenerated','revoked') NOT NULL DEFAULT 'active',
    generated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    generated_by INT DEFAULT NULL,
    last_printed_at DATETIME DEFAULT NULL,
    revoked_at DATETIME DEFAULT NULL,
    revoke_reason TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_teacher_qr (teacher_id),
    UNIQUE KEY uniq_teacher_token (qr_token)
) ENGINE=InnoDB;

-- Allow consultations to reference a Teacher/Staff patient in addition to a Student.
-- student_id becomes nullable; patient_type distinguishes the patient.
ALTER TABLE consultations
  MODIFY COLUMN student_id INT NULL,
  ADD COLUMN IF NOT EXISTS patient_type ENUM('student','teacher') NOT NULL DEFAULT 'student',
  ADD COLUMN IF NOT EXISTS teacher_id INT NULL,
  ADD CONSTRAINT fk_consult_teacher FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE SET NULL;

-- medical_records: allow staff patient too (additive, both nullable-safe)
ALTER TABLE medical_records
  ADD COLUMN IF NOT EXISTS patient_type ENUM('student','teacher') NOT NULL DEFAULT 'student',
  ADD COLUMN IF NOT EXISTS teacher_id INT NULL;

-- qr_checkins: allow teacher check-ins
ALTER TABLE qr_checkins
  ADD COLUMN IF NOT EXISTS patient_type ENUM('student','teacher') NOT NULL DEFAULT 'student',
  ADD COLUMN IF NOT EXISTS teacher_id INT NULL;
