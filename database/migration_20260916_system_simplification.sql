-- ====================================================================
-- Migration: System Simplification, Conversations, Multi-Admin Permissions & Cleanup
-- File: database/migration_20260916_system_simplification.sql
-- ====================================================================

-- 1. Drop Foreign Key on consultations referencing appointments if it exists
SET @fk_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
    WHERE TABLE_SCHEMA = DATABASE() 
      AND TABLE_NAME = 'consultations' 
      AND CONSTRAINT_NAME = 'consultations_ibfk_3'
);
SET @sql = IF(@fk_exists > 0, 'ALTER TABLE consultations DROP FOREIGN KEY consultations_ibfk_3', 'SELECT "No FK to drop"');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2. Ensure appointment_id is nullable or can be safely left decoupled
-- We do not drop data columns if existing historical records reference them, but we make it decoupled.

-- 3. Create Admin Permissions Table for Multi-Admin Architecture
CREATE TABLE IF NOT EXISTS admin_permissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    permission_key VARCHAR(64) NOT NULL,
    granted_by INT NULL,
    granted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uk_user_permission UNIQUE (user_id, permission_key),
    INDEX idx_admin_perm_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Create Structured Admin Customizations Table
CREATE TABLE IF NOT EXISTS admin_customizations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    config_key VARCHAR(64) NOT NULL,
    config_value JSON NOT NULL,
    updated_by INT NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT uk_user_config UNIQUE (user_id, config_key),
    INDEX idx_customization_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Create Clinic Conversations Table
CREATE TABLE IF NOT EXISTS conversations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    conversation_type ENUM('student_nurse', 'teacher_nurse', 'doctor_consultation') NOT NULL DEFAULT 'student_nurse',
    title VARCHAR(255) NULL,
    created_by INT NOT NULL,
    status ENUM('active', 'closed', 'archived') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_conv_type_status (conversation_type, status),
    INDEX idx_conv_created_by (created_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Create Conversation Participants Table
CREATE TABLE IF NOT EXISTS conversation_participants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    conversation_id INT NOT NULL,
    user_id INT NOT NULL,
    joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_read_at DATETIME NULL,
    CONSTRAINT uk_conv_user UNIQUE (conversation_id, user_id),
    INDEX idx_part_user (user_id),
    INDEX idx_part_conv (conversation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Create Clinic Messages Table
CREATE TABLE IF NOT EXISTS messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    conversation_id INT NOT NULL,
    sender_id INT NOT NULL,
    message_body TEXT NOT NULL,
    message_type ENUM('text', 'system_notice', 'ai_summary') NOT NULL DEFAULT 'text',
    read_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_msg_conv_created (conversation_id, created_at),
    INDEX idx_msg_sender (sender_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

