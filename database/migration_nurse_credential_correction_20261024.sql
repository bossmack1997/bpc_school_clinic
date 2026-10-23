-- Nurse credential correction & resubmission: ADDITIVE migration (idempotent).
-- Creates two NEW tables only. No existing table, row, document or audit record is altered.
--
-- BEFORE RUNNING
--   1. Take a full backup:  mysqldump --single-transaction --routines <db> > backup_before_nurse_credentials.sql
--   2. Run preflight_nurse_credential_correction.sql (read-only) and confirm every row says OK / ADD.
--   3. Select the production application database explicitly in phpMyAdmin.
--
-- ROLLBACK (only if nothing needs to be kept; submissions are audit history, export them first):
--   DROP TABLE IF EXISTS nurse_credential_drafts;
--   DROP TABLE IF EXISTS nurse_credential_submissions;
-- Dropping either table loses only drafts / the resubmission timeline; nurses, users,
-- documents and nurse_clinical_reviews are unaffected. The application degrades safely
-- (the credential page reports the migration as pending) when the tables are absent.

CREATE TABLE IF NOT EXISTS `nurse_credential_drafts` (
  `nurse_user_id` INT(11) NOT NULL,
  `license_number` VARCHAR(100) NULL DEFAULT NULL,
  `employee_id` VARCHAR(100) NULL DEFAULT NULL,
  `position` VARCHAR(120) NULL DEFAULT NULL,
  `pending_document_path` VARCHAR(255) NULL DEFAULT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`nurse_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `nurse_credential_submissions` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `nurse_user_id` INT(11) NOT NULL,
  `license_number` VARCHAR(100) NOT NULL,
  `employee_id` VARCHAR(100) NOT NULL,
  `position` VARCHAR(120) NOT NULL,
  `document_path` VARCHAR(255) NOT NULL,
  `material_change` TINYINT(1) NOT NULL DEFAULT 0,
  `previous_status` VARCHAR(30) NOT NULL,
  `resulting_status` VARCHAR(30) NOT NULL DEFAULT 'pending',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ncs_nurse` (`nurse_user_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

