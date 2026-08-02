-- Migration: Add columns for nurse registration and student section
-- Run this file in phpMyAdmin or MySQL CLI

USE bpc_clinic_system;

-- Add middle_name, gender, date_of_birth to users table
ALTER TABLE users
    ADD COLUMN middle_name VARCHAR(80) DEFAULT NULL AFTER first_name,
    ADD COLUMN gender ENUM('Male','Female','Other') DEFAULT NULL AFTER middle_name,
    ADD COLUMN date_of_birth DATE DEFAULT NULL AFTER gender;

-- Add employee_id to nurses table
ALTER TABLE nurses
    ADD COLUMN employee_id VARCHAR(30) DEFAULT NULL AFTER user_id,
    ADD COLUMN gender ENUM('Male','Female','Other') DEFAULT NULL AFTER license_number,
    ADD COLUMN date_of_birth DATE DEFAULT NULL AFTER gender,
    ADD COLUMN contact_number VARCHAR(30) DEFAULT NULL AFTER date_of_birth,
    ADD COLUMN valid_id_path VARCHAR(255) DEFAULT NULL AFTER contact_number,
    ADD COLUMN profile_picture VARCHAR(255) DEFAULT 'assets/images/default-avatar.png' AFTER valid_id_path;

-- Add section to students table (already exists in schema, skip if duplicate)
-- ALTER TABLE students ADD COLUMN section VARCHAR(30) DEFAULT NULL AFTER year_level;

-- Update seed admin password hash to match 'Admin@123'
-- This is the correct hash for 'Admin@123'
UPDATE users SET password_hash = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi' WHERE username = 'admin';