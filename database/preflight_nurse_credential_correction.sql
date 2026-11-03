
-- ============================================================
-- BPC SCHOOL CLINIC MANAGEMENT SYSTEM
-- NURSE CREDENTIAL CORRECTION - PRODUCTION PREFLIGHT
-- Database: u962995739_bpc_clinic
--
-- READ-ONLY: No tables or records will be modified.
-- ============================================================

-- 1. Verify the production database exists
SELECT
    SCHEMA_NAME AS database_name,
    'DATABASE FOUND' AS result
FROM information_schema.SCHEMATA
WHERE SCHEMA_NAME = 'u962995739_bpc_clinic';


-- 2. Check the two required credential tables
SELECT
    required.name AS required_table,
    CASE
        WHEN existing.TABLE_NAME IS NULL
            THEN 'ADD - Missing, migration required'
        ELSE 'EXISTS - Verify table structure'
    END AS result
FROM (
    SELECT 'nurse_credential_drafts' AS name
    UNION ALL
    SELECT 'nurse_credential_submissions'
) AS required
LEFT JOIN information_schema.TABLES AS existing
    ON existing.TABLE_SCHEMA = 'u962995739_bpc_clinic'
    AND existing.TABLE_NAME = required.name;


-- 3. Check required Nurse columns
SELECT
    'Nurse credential columns' AS prerequisite,
    COUNT(DISTINCT COLUMN_NAME) AS columns_found,
    CASE
        WHEN COUNT(DISTINCT COLUMN_NAME) = 4 THEN 'OK'
        ELSE 'BLOCK - Missing required columns'
    END AS result
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = 'u962995739_bpc_clinic'
  AND TABLE_NAME = 'nurses'
  AND COLUMN_NAME IN (
      'license_number',
      'employee_id',
      'position',
      'valid_id_path'
  );


-- 4. Check Nurse clinical authorization
SELECT
    'Nurse clinical authorization' AS prerequisite,
    CASE
        WHEN COUNT(*) = 1 THEN 'OK'
        ELSE 'BLOCK - Missing authorization column'
    END AS result
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = 'u962995739_bpc_clinic'
  AND TABLE_NAME = 'nurses'
  AND COLUMN_NAME = 'clinical_authorization_status';


-- 5. Check Doctor review history table
SELECT
    'Nurse clinical reviews' AS prerequisite,
    CASE
        WHEN COUNT(*) = 1 THEN 'OK'
        ELSE 'BLOCK - Missing review table'
    END AS result
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = 'u962995739_bpc_clinic'
  AND TABLE_NAME = 'nurse_clinical_reviews'
  AND TABLE_TYPE = 'BASE TABLE';


-- 6. Check duplicate PRC license numbers
SELECT
    'Duplicate PRC numbers' AS check_name,
    COUNT(*) AS groups_found,
    CASE
        WHEN COUNT(*) = 0 THEN 'OK'
        ELSE 'REVIEW - Duplicate PRC numbers found'
    END AS result
FROM (
    SELECT LOWER(TRIM(license_number)) AS license_key
    FROM `u962995739_bpc_clinic`.`nurses`
    WHERE license_number IS NOT NULL
      AND TRIM(license_number) <> ''
    GROUP BY LOWER(TRIM(license_number))
    HAVING COUNT(*) > 1
) AS duplicate_prc;


-- 7. Check duplicate Employee IDs
SELECT
    'Duplicate Employee IDs' AS check_name,
    COUNT(*) AS groups_found,
    CASE
        WHEN COUNT(*) = 0 THEN 'OK'
        ELSE 'REVIEW - Duplicate Employee IDs found'
    END AS result
FROM (
    SELECT LOWER(TRIM(employee_id)) AS employee_key
    FROM `u962995739_bpc_clinic`.`nurses`
    WHERE employee_id IS NOT NULL
      AND TRIM(employee_id) <> ''
    GROUP BY LOWER(TRIM(employee_id))
    HAVING COUNT(*) > 1
) AS duplicate_employees;

-- END OF READ-ONLY PREFLIGHT
