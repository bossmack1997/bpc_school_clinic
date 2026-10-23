-- Read-only verification: run in the selected production database.
-- No USE, DDL, UPDATE, account identifiers or patient information.
SELECT DATABASE() AS selected_database, VERSION() AS database_version;

-- Check nurse table columns
SELECT expected.column_name,
       c.COLUMN_TYPE AS actual_type, c.IS_NULLABLE, c.COLUMN_DEFAULT,
       CASE
         WHEN c.COLUMN_NAME IS NULL THEN 'MISSING'
         WHEN expected.column_name = 'clinical_authorization_status'
              AND c.DATA_TYPE = 'varchar' AND c.IS_NULLABLE = 'NO'
              AND REPLACE(COALESCE(c.COLUMN_DEFAULT, ''), CHAR(39), '') = 'pending' THEN 'PASS'
         WHEN expected.column_name = 'clinical_reviewed_by'
              AND c.DATA_TYPE = 'int' AND c.IS_NULLABLE = 'YES' THEN 'PASS'
         WHEN expected.column_name = 'clinical_reviewed_at'
              AND c.DATA_TYPE = 'datetime' AND c.IS_NULLABLE = 'YES' THEN 'PASS'
         WHEN expected.column_name = 'clinical_review_notes'
              AND c.DATA_TYPE = 'text' AND c.IS_NULLABLE = 'YES' THEN 'PASS'
         ELSE 'INCOMPATIBLE - review before proceeding'
       END AS verification
FROM (SELECT 'clinical_authorization_status' AS column_name
      UNION ALL SELECT 'clinical_reviewed_by'
      UNION ALL SELECT 'clinical_reviewed_at'
      UNION ALL SELECT 'clinical_review_notes') AS expected
LEFT JOIN information_schema.COLUMNS c
  ON c.TABLE_SCHEMA = DATABASE() AND c.TABLE_NAME = 'nurses'
 AND c.COLUMN_NAME = expected.column_name
ORDER BY expected.column_name;

-- Check nurse_clinical_reviews table
SELECT 'nurse_clinical_reviews' AS table_name,
       CASE WHEN COUNT(*) > 0 THEN 'PASS' ELSE 'MISSING' END AS table_verification
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nurse_clinical_reviews';
