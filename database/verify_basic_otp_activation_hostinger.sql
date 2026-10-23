-- Read-only verification: run in the selected production database.
-- No USE, DDL, UPDATE, account identifiers or patient information.
SELECT DATABASE() AS selected_database, VERSION() AS database_version;

SELECT expected.column_name,
       c.COLUMN_TYPE AS actual_type, c.IS_NULLABLE, c.COLUMN_DEFAULT,
       CASE
         WHEN c.COLUMN_NAME IS NULL THEN 'MISSING'
         WHEN expected.column_name = 'email_verified_at'
              AND c.DATA_TYPE = 'datetime' AND c.IS_NULLABLE = 'YES'
              AND (c.COLUMN_DEFAULT IS NULL OR UPPER(c.COLUMN_DEFAULT) = 'NULL') THEN 'PASS'
         WHEN expected.column_name = 'self_service_only'
              AND c.DATA_TYPE = 'tinyint' AND c.IS_NULLABLE = 'NO'
              AND REPLACE(COALESCE(c.COLUMN_DEFAULT, ''), CHAR(39), '') = '0' THEN 'PASS'
         ELSE 'INCOMPATIBLE - review before proceeding'
       END AS verification
FROM (SELECT 'email_verified_at' AS column_name
      UNION ALL SELECT 'self_service_only') AS expected
LEFT JOIN information_schema.COLUMNS c
  ON c.TABLE_SCHEMA = DATABASE() AND c.TABLE_NAME = 'users'
 AND c.COLUMN_NAME = expected.column_name
ORDER BY expected.column_name;
