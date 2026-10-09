-- Migration: Add admin_type column for Primary Admin vs Co-Admin architecture
-- Project: AI-Enhanced School Clinic Management System for Bulacan Polytechnic College Healthcare Service Improvement

ALTER TABLE users 
ADD COLUMN admin_type ENUM('primary_admin', 'co_admin') NULL DEFAULT NULL AFTER role;

-- Seed existing active admin (id = 1 or first active admin) as primary_admin
UPDATE users 
SET admin_type = 'primary_admin' 
WHERE role = 'admin' AND status = 'active' AND id = 1;

-- Seed any other existing admin accounts as co_admin
UPDATE users 
SET admin_type = 'co_admin' 
WHERE role = 'admin' AND (id != 1 OR status != 'active');

