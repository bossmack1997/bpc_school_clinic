-- =====================================================================
-- Migration: Messaging & Presence System Enhancements
-- BPC AI-Enhanced School Clinic Management System
-- =====================================================================

ALTER TABLE users 
ADD COLUMN IF NOT EXISTS last_activity DATETIME NULL DEFAULT NULL AFTER last_login,
ADD COLUMN IF NOT EXISTS clinic_status ENUM('available', 'busy', 'away', 'offline') NOT NULL DEFAULT 'available' AFTER last_activity;

CREATE INDEX IF NOT EXISTS idx_users_last_activity ON users(last_activity, role);

