-- Additive migration for expiring, single-use Doctor account setup invitations.
-- Safe to run repeatedly. Existing users and clinical records are not modified.
CREATE TABLE IF NOT EXISTS admin_invitations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT NOT NULL,
    token_hash CHAR(64) NOT NULL,
    created_by INT NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    delivery_status ENUM('pending','sent','failed','manual') NOT NULL DEFAULT 'pending',
    delivery_error VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY admin_invitation_token (token_hash),
    KEY admin_invitation_user (user_id, created_at),
    KEY admin_invitation_expiry (expires_at, used_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Upgrade older invitation tables created by earlier iterations.
ALTER TABLE admin_invitations
    ADD COLUMN IF NOT EXISTS token_hash CHAR(64) NULL,
    ADD COLUMN IF NOT EXISTS created_by INT NULL,
    ADD COLUMN IF NOT EXISTS expires_at DATETIME NULL,
    ADD COLUMN IF NOT EXISTS used_at DATETIME NULL,
    ADD COLUMN IF NOT EXISTS delivery_status ENUM('pending','sent','failed','manual') NOT NULL DEFAULT 'pending',
    ADD COLUMN IF NOT EXISTS delivery_error VARCHAR(255) NULL,
    ADD COLUMN IF NOT EXISTS created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP;

CREATE INDEX IF NOT EXISTS admin_invitation_user ON admin_invitations (user_id, created_at);
CREATE INDEX IF NOT EXISTS admin_invitation_expiry ON admin_invitations (expires_at, used_at);
CREATE UNIQUE INDEX IF NOT EXISTS admin_invitation_token ON admin_invitations (token_hash);

ALTER TABLE admin_profiles
    ADD COLUMN IF NOT EXISTS official_designation VARCHAR(150) NULL AFTER profile;
