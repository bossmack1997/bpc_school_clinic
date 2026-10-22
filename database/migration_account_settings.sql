ALTER TABLE teachers ADD COLUMN IF NOT EXISTS gender VARCHAR(16) NULL;
ALTER TABLE teachers ADD COLUMN IF NOT EXISTS date_of_birth DATE NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS credential_version INT UNSIGNED NOT NULL DEFAULT 0;
-- Rollback only after restoring the previous authentication/settings code:
-- ALTER TABLE teachers DROP COLUMN gender, DROP COLUMN date_of_birth;
-- ALTER TABLE users DROP COLUMN credential_version;
