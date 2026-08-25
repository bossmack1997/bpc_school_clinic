-- =====================================================================
-- BPC School Clinic — Controlled Feature Enhancement Migration
-- Adds: Adviser role, advisory assignments, student well-being profiles,
--       support referrals, and extended emergency workflow.
-- Preserves ALL existing tables/data (appointment, SOS, consultations, etc.)
-- =====================================================================

-- ---------------------------------------------------------------------
-- 1. Extend users.role ENUM to include adviser + guidance (idempotent)
-- ---------------------------------------------------------------------
ALTER TABLE users
  MODIFY COLUMN role ENUM('admin','nurse','student','adviser','guidance')
  NOT NULL DEFAULT 'student';

-- ---------------------------------------------------------------------
-- 2. SECTIONS (class/section master)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sections (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(60) NOT NULL COMMENT 'e.g. BSIS 4A',
    course VARCHAR(100) DEFAULT NULL,
    year_level VARCHAR(20) DEFAULT NULL,
    school_year VARCHAR(20) DEFAULT NULL,
    semester VARCHAR(20) DEFAULT NULL,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_section_name (name)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 3. ADVISORY ASSIGNMENTS (teacher -> section, time-bound & auditable)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS advisory_assignments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    teacher_id INT NOT NULL COMMENT 'users.id of the adviser',
    section_id INT NOT NULL,
    school_year VARCHAR(20) DEFAULT NULL,
    semester VARCHAR(20) DEFAULT NULL,
    start_date DATE DEFAULT NULL,
    end_date DATE DEFAULT NULL,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (section_id) REFERENCES sections(id) ON DELETE CASCADE,
    INDEX idx_adviser_active (teacher_id, status),
    INDEX idx_section_active (section_id, status)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 4. STUDENT HEALTH & WELL-BEING PROFILES (sensitive, role-gated)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS wellbeing_profiles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    -- Medical information (voluntary)
    known_medical_conditions TEXT DEFAULT NULL,
    allergies TEXT DEFAULT NULL,
    previous_illnesses TEXT DEFAULT NULL,
    current_medications TEXT DEFAULT NULL,
    other_medical_info TEXT DEFAULT NULL,
    -- Mental & emotional well-being (voluntary, no forced diagnosis)
    concerns TEXT DEFAULT NULL,
    previous_support TEXT DEFAULT NULL,
    diagnosis_disclosed TEXT DEFAULT NULL,
    current_support TEXT DEFAULT NULL,
    other_wellbeing TEXT DEFAULT NULL,
    -- Support preferences
    support_preferences TEXT DEFAULT NULL COMMENT 'JSON array of selected options + custom',
    student_notes TEXT DEFAULT NULL,
    -- Privacy consent/acknowledgment
    consent_status ENUM('pending','granted','revoked') NOT NULL DEFAULT 'pending',
    consent_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_student_wellbeing (student_id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 5. SUPPORT REFERRALS (nurse -> authorized support personnel)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS support_referrals (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    created_by INT DEFAULT NULL COMMENT 'users.id of nurse',
    referred_to VARCHAR(120) NOT NULL DEFAULT 'Guidance Office',
    reason TEXT DEFAULT NULL,
    priority ENUM('routine','soon','urgent') NOT NULL DEFAULT 'routine',
    notes TEXT DEFAULT NULL,
    student_informed TINYINT(1) NOT NULL DEFAULT 0,
    status ENUM('pending_assessment','referral_recommended','referred','follow_up_required','closed')
        NOT NULL DEFAULT 'pending_assessment',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_referral_status (status),
    INDEX idx_referral_student (student_id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 6. EXTEND sos_emergencies for unified emergency workflow
--    (preserves existing SOS columns & status values)
-- ---------------------------------------------------------------------
ALTER TABLE sos_emergencies
  MODIFY COLUMN status ENUM('new','active','acknowledged','responding','resolved','cancelled','responded','false_alarm')
  NOT NULL DEFAULT 'active';

ALTER TABLE sos_emergencies
  ADD COLUMN IF NOT EXISTS source ENUM('sos','adviser') NOT NULL DEFAULT 'sos',
  ADD COLUMN IF NOT EXISTS priority ENUM('routine','soon','urgent') NOT NULL DEFAULT 'urgent',
  ADD COLUMN IF NOT EXISTS reporter_role VARCHAR(30) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS section_id INT DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS acknowledged_by INT DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS acknowledged_at DATETIME DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS assigned_to INT DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS resolution_notes TEXT DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS cancelled_by INT DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS cancelled_at DATETIME DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

-- Seed a default section so advisory assignment has something to reference.
-- (Admins create real sections via the new Adviser/Section management UI.)
INSERT IGNORE INTO sections (name, status) VALUES ('Unassigned', 'inactive');
