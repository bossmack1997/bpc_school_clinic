-- Hostinger phpMyAdmin: select/confirm the intended production database first.
-- Additive, idempotent and rerunnable on MySQL 8+ and MariaDB.
-- Adds clinical authorization columns to nurses table and creates nurse_clinical_reviews audit table.
USE `u962995739_bpc_clinic`;

-- 1. Add clinical_authorization_status to nurses
SET @bpc_col_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nurses' AND COLUMN_NAME = 'clinical_authorization_status'),
    'SELECT ''clinical_authorization_status already exists'' AS migration_note',
    'ALTER TABLE `nurses` ADD COLUMN `clinical_authorization_status` VARCHAR(30) NOT NULL DEFAULT ''pending'' AFTER `shift_schedule`'
);
PREPARE bpc_stmt FROM @bpc_col_sql;
EXECUTE bpc_stmt;
DEALLOCATE PREPARE bpc_stmt;

-- 2. Add clinical_reviewed_by to nurses
SET @bpc_col_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nurses' AND COLUMN_NAME = 'clinical_reviewed_by'),
    'SELECT ''clinical_reviewed_by already exists'' AS migration_note',
    'ALTER TABLE `nurses` ADD COLUMN `clinical_reviewed_by` INT(11) NULL DEFAULT NULL AFTER `clinical_authorization_status`'
);
PREPARE bpc_stmt FROM @bpc_col_sql;
EXECUTE bpc_stmt;
DEALLOCATE PREPARE bpc_stmt;

-- 3. Add clinical_reviewed_at to nurses
SET @bpc_col_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nurses' AND COLUMN_NAME = 'clinical_reviewed_at'),
    'SELECT ''clinical_reviewed_at already exists'' AS migration_note',
    'ALTER TABLE `nurses` ADD COLUMN `clinical_reviewed_at` DATETIME NULL DEFAULT NULL AFTER `clinical_reviewed_by`'
);
PREPARE bpc_stmt FROM @bpc_col_sql;
EXECUTE bpc_stmt;
DEALLOCATE PREPARE bpc_stmt;

-- 4. Add clinical_review_notes to nurses
SET @bpc_col_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nurses' AND COLUMN_NAME = 'clinical_review_notes'),
    'SELECT ''clinical_review_notes already exists'' AS migration_note',
    'ALTER TABLE `nurses` ADD COLUMN `clinical_review_notes` TEXT NULL DEFAULT NULL AFTER `clinical_reviewed_at`'
);
PREPARE bpc_stmt FROM @bpc_col_sql;
EXECUTE bpc_stmt;
DEALLOCATE PREPARE bpc_stmt;

-- 5. Create nurse_clinical_reviews audit table
CREATE TABLE IF NOT EXISTS `nurse_clinical_reviews` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `nurse_user_id` INT(11) NOT NULL,
  `doctor_user_id` INT(11) NOT NULL,
  `action` ENUM('authorize','request_info','reject','revoke') NOT NULL,
  `previous_status` VARCHAR(30) NOT NULL,
  `new_status` VARCHAR(30) NOT NULL,
  `notes` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ncr_nurse` (`nurse_user_id`),
  KEY `idx_ncr_doctor` (`doctor_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Synchronize initial status for existing nurse accounts
-- If a nurse already had self_service_only = 0, mark as authorized; otherwise pending.
UPDATE `nurses` n
JOIN `users` u ON u.id = n.user_id
SET n.clinical_authorization_status = IF(COALESCE(u.self_service_only, 0) = 0, 'authorized', 'pending')
WHERE n.clinical_authorization_status IS NULL OR n.clinical_authorization_status = 'pending';
