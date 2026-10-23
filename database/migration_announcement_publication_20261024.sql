-- Additive MariaDB migration. Back up and verify in staging before deployment.
-- Existing announcements retain their previously published behavior.
ALTER TABLE announcements
 ADD COLUMN IF NOT EXISTS category VARCHAR(50) NOT NULL DEFAULT 'general',
 ADD COLUMN IF NOT EXISTS publication_status VARCHAR(20) NOT NULL DEFAULT 'published',
 ADD COLUMN IF NOT EXISTS starts_at DATETIME NULL,
 ADD COLUMN IF NOT EXISTS expires_at DATETIME NULL,
 ADD COLUMN IF NOT EXISTS image_path VARCHAR(255) NULL;
ALTER TABLE announcements MODIFY COLUMN audience ENUM('all','students','teachers','nurses','admin') NOT NULL DEFAULT 'all';
