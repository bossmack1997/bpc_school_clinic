-- =====================================================================
-- BPC Clinic — Teacher Self-Registration + Multi-Section + Guidance
-- Extends the existing enhancement migration. Preserves all data.
-- =====================================================================

-- 1. Extend users.status with rejected / suspended (disabled => inactive)
ALTER TABLE users
  MODIFY COLUMN status ENUM('active','inactive','pending','rejected','suspended')
  NOT NULL DEFAULT 'pending';

-- 2. Teacher registration requests (self-service, pending approval)
CREATE TABLE IF NOT EXISTS teacher_registration_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    teacher_id_number VARCHAR(50) DEFAULT NULL,
    requested_sections JSON DEFAULT NULL COMMENT 'array of section IDs, max 3',
    status ENUM('pending','approved','rejected','correction') NOT NULL DEFAULT 'pending',
    reviewed_by INT DEFAULT NULL,
    reviewed_at DATETIME DEFAULT NULL,
    rejection_reason TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_req_status (status)
) ENGINE=InnoDB;

-- 3. advisory_assignments: who approved + when
ALTER TABLE advisory_assignments
  ADD COLUMN IF NOT EXISTS approved_by INT DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS approved_at DATETIME DEFAULT NULL;

-- 4. Support referrals: align statuses to Guidance workflow
--    Map existing values first so no data is lost/invalidated.
UPDATE support_referrals SET status = 'pending' WHERE status IN ('pending_assessment','referral_recommended');
UPDATE support_referrals SET status = 'accepted' WHERE status = 'referred';
ALTER TABLE support_referrals
  MODIFY COLUMN status ENUM('pending','accepted','in_support','follow_up_required','closed')
  NOT NULL DEFAULT 'pending';
