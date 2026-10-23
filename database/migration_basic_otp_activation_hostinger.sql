-- Hostinger phpMyAdmin: select/confirm the intended production database first.
-- Additive and rerunnable on MySQL and MariaDB. No account updates or new indexes.
USE `u962995739_bpc_clinic`;

SET @bpc_otp_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'email_verified_at'),
    'SELECT ''email_verified_at already exists'' AS migration_note',
    'ALTER TABLE `users` ADD COLUMN `email_verified_at` DATETIME NULL DEFAULT NULL'
);
PREPARE bpc_otp_stmt FROM @bpc_otp_sql;
EXECUTE bpc_otp_stmt;
DEALLOCATE PREPARE bpc_otp_stmt;

SET @bpc_otp_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'self_service_only'),
    'SELECT ''self_service_only already exists'' AS migration_note',
    'ALTER TABLE `users` ADD COLUMN `self_service_only` TINYINT(1) NOT NULL DEFAULT 0'
);
PREPARE bpc_otp_stmt FROM @bpc_otp_sql;
EXECUTE bpc_otp_stmt;
DEALLOCATE PREPARE bpc_otp_stmt;
-- Run verify_basic_otp_activation_hostinger.sql afterward and require two PASS rows.
-- Existing staff retain their scope. Registration explicitly inserts self_service_only=1.
