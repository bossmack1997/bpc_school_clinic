-- READ-ONLY preflight for migration_nurse_credential_correction_20261024.sql (SELECT only).
-- Run with the production application database selected.
SELECT DATABASE() AS selected_database;

SELECT t.name AS required_table,
       CASE WHEN i.TABLE_NAME IS NULL THEN 'ADD (expected before migration)' ELSE 'EXISTS - review columns before continuing' END AS result
FROM (SELECT 'nurse_credential_drafts' AS name UNION ALL SELECT 'nurse_credential_submissions') t
LEFT JOIN information_schema.TABLES i ON i.TABLE_SCHEMA = DATABASE() AND i.TABLE_NAME = t.name;

-- Prerequisites that must already exist (each should return OK).
SELECT 'nurses.license_number/employee_id/position/valid_id_path' AS prerequisite,
       IF(COUNT(*) = 4, 'OK', 'BLOCK - missing columns') AS result
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nurses'
  AND COLUMN_NAME IN ('license_number','employee_id','position','valid_id_path');

SELECT 'nurses.clinical_authorization_status' AS prerequisite, IF(COUNT(*) = 1, 'OK', 'BLOCK') AS result
FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nurses' AND COLUMN_NAME = 'clinical_authorization_status';

SELECT 'nurse_clinical_reviews' AS prerequisite, IF(COUNT(*) = 1, 'OK', 'BLOCK') AS result
FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nurse_clinical_reviews';

-- Data that the new duplicate checks will reject on future edits (informational; nothing is changed).
SELECT 'duplicate PRC numbers' AS check_name, COUNT(*) AS groups_found FROM (
  SELECT LOWER(TRIM(license_number)) k FROM nurses WHERE license_number IS NOT NULL AND TRIM(license_number) <> '' GROUP BY k HAVING COUNT(*) > 1) d;
SELECT 'duplicate Employee IDs' AS check_name, COUNT(*) AS groups_found FROM (
  SELECT LOWER(TRIM(employee_id)) k FROM nurses WHERE employee_id IS NOT NULL AND TRIM(employee_id) <> '' GROUP BY k HAVING COUNT(*) > 1) d;

