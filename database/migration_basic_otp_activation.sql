-- Additive only: no existing account activation or permission updates.
ALTER TABLE users
  ADD COLUMN IF NOT EXISTS email_verified_at DATETIME NULL,
  ADD COLUMN IF NOT EXISTS self_service_only TINYINT(1) NOT NULL DEFAULT 0;
-- Legacy authorized roles retain their scope. Public registrations and individual
-- pending-account reconciliation explicitly set self_service_only=1.
