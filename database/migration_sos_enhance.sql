-- =====================================================================
-- SOS Emergency System — Enhancement Migration
-- Bulacan Polytechnic College (BPC) - AI-Powered School Clinic
-- =====================================================================
-- Adds a link between a notification and its SOS emergency record so
-- the notification bell can open full SOS details for the nurse/admin.
-- Requires MariaDB 10.4+ / MySQL 8.0+ (IF NOT EXISTS column syntax).

ALTER TABLE notifications
  ADD COLUMN IF NOT EXISTS sos_emergency_id INT DEFAULT NULL AFTER link;

-- Index for fast SOS notification lookups (idempotent in MariaDB)
CREATE INDEX IF NOT EXISTS idx_notifications_sos ON notifications(sos_emergency_id);

-- ---------------------------------------------------------------------
-- BACKFILL: Link existing emergency notifications to their SOS records.
-- The notification and the sos_emergency row are created within the same
-- request, so a ±2 second creation-time window is a safe heuristic to
-- reconnect pre-migration SOS alerts to their emergency records.
-- ---------------------------------------------------------------------
UPDATE notifications n
JOIN sos_emergencies se
  ON se.created_at >= (n.created_at - INTERVAL 2 SECOND)
 AND se.created_at <= (n.created_at + INTERVAL 2 SECOND)
SET n.sos_emergency_id = se.id
WHERE n.type = 'emergency'
  AND n.sos_emergency_id IS NULL;

