-- =====================================================================
-- Hybrid Voice Verification System — Migration
-- Bulacan Polytechnic College (BPC) - AI-Powered School Clinic
-- =====================================================================
-- Stores ONLY the generated voice profile reference (template/embedding).
-- Raw audio recordings are never stored in the database.
--
-- One active voice profile per user (enforced by UNIQUE + is_active).

CREATE TABLE IF NOT EXISTS voice_profiles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL UNIQUE,
    voice_reference TEXT NOT NULL COMMENT 'Voice template / embedding reference (never raw audio)',
    provider VARCHAR(50) NOT NULL DEFAULT 'hybrid' COMMENT 'hybrid | azure | resemblyzer | nemo | ...',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Index for fast lookups by user
CREATE INDEX idx_voice_profiles_user ON voice_profiles(user_id);

