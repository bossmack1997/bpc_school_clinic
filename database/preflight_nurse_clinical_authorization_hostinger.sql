-- READ ONLY: SELECT/information_schema only. No session changes or prepared SQL.
-- Safe when the audit table and new columns do not exist.
-- PASS = compatible, ADD = supported missing structure, REPAIR = recognized unsafe FK rule.
-- BLOCK/custom-review rows = NO-GO. Inspect every result set, not just the summary.
SELECT DATABASE() AS selected_database, VERSION() AS server_version,
 IF(@@FOREIGN_KEY_CHECKS=1,'PASS','BLOCK') AS foreign_key_checks;
SELECT 'users' AS table_name, 'id' AS column_name, c.COLUMN_TYPE AS actual_type, c.IS_NULLABLE, c.COLUMN_DEFAULT,
 CASE WHEN c.COLUMN_NAME IS NULL THEN 'BLOCK'
 WHEN (c.DATA_TYPE = 'int' AND c.IS_NULLABLE = 'NO' AND EXISTS (SELECT 1 FROM information_schema.STATISTICS s WHERE s.TABLE_SCHEMA=DATABASE() AND s.TABLE_NAME='users' AND s.INDEX_NAME='PRIMARY' GROUP BY s.INDEX_NAME HAVING COUNT(*)=1 AND MAX(s.COLUMN_NAME)='id')) AND c.EXTRA NOT LIKE '%GENERATED%' THEN 'PASS' ELSE 'BLOCK' END AS result
 FROM (SELECT 1 AS seed) seed LEFT JOIN information_schema.COLUMNS c
 ON c.TABLE_SCHEMA=DATABASE() AND c.TABLE_NAME='users' AND c.COLUMN_NAME='id'
UNION ALL
SELECT 'nurses' AS table_name, 'user_id' AS column_name, c.COLUMN_TYPE AS actual_type, c.IS_NULLABLE, c.COLUMN_DEFAULT,
 CASE WHEN c.COLUMN_NAME IS NULL THEN 'BLOCK'
 WHEN (c.COLUMN_TYPE = (SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='id') AND c.IS_NULLABLE='NO') AND c.EXTRA NOT LIKE '%GENERATED%' THEN 'PASS' ELSE 'BLOCK' END AS result
 FROM (SELECT 1 AS seed) seed LEFT JOIN information_schema.COLUMNS c
 ON c.TABLE_SCHEMA=DATABASE() AND c.TABLE_NAME='nurses' AND c.COLUMN_NAME='user_id'
UNION ALL
SELECT 'users' AS table_name, 'self_service_only' AS column_name, c.COLUMN_TYPE AS actual_type, c.IS_NULLABLE, c.COLUMN_DEFAULT,
 CASE WHEN c.COLUMN_NAME IS NULL THEN 'BLOCK'
 WHEN (c.DATA_TYPE='tinyint' AND c.IS_NULLABLE='NO' AND REPLACE(COALESCE(c.COLUMN_DEFAULT,''),CHAR(39),'')='0') AND c.EXTRA NOT LIKE '%GENERATED%' THEN 'PASS' ELSE 'BLOCK' END AS result
 FROM (SELECT 1 AS seed) seed LEFT JOIN information_schema.COLUMNS c
 ON c.TABLE_SCHEMA=DATABASE() AND c.TABLE_NAME='users' AND c.COLUMN_NAME='self_service_only'
UNION ALL
SELECT 'users' AS table_name, 'credential_version' AS column_name, c.COLUMN_TYPE AS actual_type, c.IS_NULLABLE, c.COLUMN_DEFAULT,
 CASE WHEN c.COLUMN_NAME IS NULL THEN 'ADD'
 WHEN (c.DATA_TYPE='int' AND c.COLUMN_TYPE LIKE '%unsigned%' AND c.IS_NULLABLE='NO' AND REPLACE(COALESCE(c.COLUMN_DEFAULT,''),CHAR(39),'')='0') AND c.EXTRA NOT LIKE '%GENERATED%' THEN 'PASS' ELSE 'BLOCK' END AS result
 FROM (SELECT 1 AS seed) seed LEFT JOIN information_schema.COLUMNS c
 ON c.TABLE_SCHEMA=DATABASE() AND c.TABLE_NAME='users' AND c.COLUMN_NAME='credential_version'
UNION ALL
SELECT 'nurses' AS table_name, 'clinical_authorization_status' AS column_name, c.COLUMN_TYPE AS actual_type, c.IS_NULLABLE, c.COLUMN_DEFAULT,
 CASE WHEN c.COLUMN_NAME IS NULL THEN 'ADD'
 WHEN (c.DATA_TYPE='varchar' AND c.CHARACTER_MAXIMUM_LENGTH=30 AND c.IS_NULLABLE='NO' AND REPLACE(COALESCE(c.COLUMN_DEFAULT,''),CHAR(39),'')='pending') AND c.EXTRA NOT LIKE '%GENERATED%' THEN 'PASS' ELSE 'BLOCK' END AS result
 FROM (SELECT 1 AS seed) seed LEFT JOIN information_schema.COLUMNS c
 ON c.TABLE_SCHEMA=DATABASE() AND c.TABLE_NAME='nurses' AND c.COLUMN_NAME='clinical_authorization_status'
UNION ALL
SELECT 'nurses' AS table_name, 'clinical_reviewed_by' AS column_name, c.COLUMN_TYPE AS actual_type, c.IS_NULLABLE, c.COLUMN_DEFAULT,
 CASE WHEN c.COLUMN_NAME IS NULL THEN 'ADD'
 WHEN (c.COLUMN_TYPE=(SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='id') AND c.IS_NULLABLE='YES' AND (c.COLUMN_DEFAULT IS NULL OR UPPER(c.COLUMN_DEFAULT)='NULL')) AND c.EXTRA NOT LIKE '%GENERATED%' THEN 'PASS' ELSE 'BLOCK' END AS result
 FROM (SELECT 1 AS seed) seed LEFT JOIN information_schema.COLUMNS c
 ON c.TABLE_SCHEMA=DATABASE() AND c.TABLE_NAME='nurses' AND c.COLUMN_NAME='clinical_reviewed_by'
UNION ALL
SELECT 'nurses' AS table_name, 'clinical_reviewed_at' AS column_name, c.COLUMN_TYPE AS actual_type, c.IS_NULLABLE, c.COLUMN_DEFAULT,
 CASE WHEN c.COLUMN_NAME IS NULL THEN 'ADD'
 WHEN (c.DATA_TYPE='datetime' AND c.IS_NULLABLE='YES' AND (c.COLUMN_DEFAULT IS NULL OR UPPER(c.COLUMN_DEFAULT)='NULL')) AND c.EXTRA NOT LIKE '%GENERATED%' THEN 'PASS' ELSE 'BLOCK' END AS result
 FROM (SELECT 1 AS seed) seed LEFT JOIN information_schema.COLUMNS c
 ON c.TABLE_SCHEMA=DATABASE() AND c.TABLE_NAME='nurses' AND c.COLUMN_NAME='clinical_reviewed_at'
UNION ALL
SELECT 'nurses' AS table_name, 'clinical_review_notes' AS column_name, c.COLUMN_TYPE AS actual_type, c.IS_NULLABLE, c.COLUMN_DEFAULT,
 CASE WHEN c.COLUMN_NAME IS NULL THEN 'ADD'
 WHEN (c.DATA_TYPE='text' AND c.IS_NULLABLE='YES' AND (c.COLUMN_DEFAULT IS NULL OR UPPER(c.COLUMN_DEFAULT)='NULL')) AND c.EXTRA NOT LIKE '%GENERATED%' THEN 'PASS' ELSE 'BLOCK' END AS result
 FROM (SELECT 1 AS seed) seed LEFT JOIN information_schema.COLUMNS c
 ON c.TABLE_SCHEMA=DATABASE() AND c.TABLE_NAME='nurses' AND c.COLUMN_NAME='clinical_review_notes'
UNION ALL
SELECT 'nurse_clinical_reviews' AS table_name, 'id' AS column_name, c.COLUMN_TYPE AS actual_type, c.IS_NULLABLE, c.COLUMN_DEFAULT,
 CASE WHEN c.COLUMN_NAME IS NULL THEN IF(EXISTS(SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='nurse_clinical_reviews'),'BLOCK','ADD')
 WHEN (c.DATA_TYPE='int' AND c.IS_NULLABLE='NO' AND c.EXTRA LIKE '%auto_increment%' AND EXISTS (SELECT 1 FROM information_schema.STATISTICS s WHERE s.TABLE_SCHEMA=DATABASE() AND s.TABLE_NAME='nurse_clinical_reviews' AND s.INDEX_NAME='PRIMARY' GROUP BY s.INDEX_NAME HAVING COUNT(*)=1 AND MAX(s.COLUMN_NAME)='id')) AND c.EXTRA NOT LIKE '%GENERATED%' THEN 'PASS' ELSE 'BLOCK' END AS result
 FROM (SELECT 1 AS seed) seed LEFT JOIN information_schema.COLUMNS c
 ON c.TABLE_SCHEMA=DATABASE() AND c.TABLE_NAME='nurse_clinical_reviews' AND c.COLUMN_NAME='id'
UNION ALL
SELECT 'nurse_clinical_reviews' AS table_name, 'nurse_user_id' AS column_name, c.COLUMN_TYPE AS actual_type, c.IS_NULLABLE, c.COLUMN_DEFAULT,
 CASE WHEN c.COLUMN_NAME IS NULL THEN IF(EXISTS(SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='nurse_clinical_reviews'),'BLOCK','ADD')
 WHEN (c.COLUMN_TYPE=(SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='id') AND c.IS_NULLABLE='NO') AND c.EXTRA NOT LIKE '%GENERATED%' THEN 'PASS' ELSE 'BLOCK' END AS result
 FROM (SELECT 1 AS seed) seed LEFT JOIN information_schema.COLUMNS c
 ON c.TABLE_SCHEMA=DATABASE() AND c.TABLE_NAME='nurse_clinical_reviews' AND c.COLUMN_NAME='nurse_user_id'
UNION ALL
SELECT 'nurse_clinical_reviews' AS table_name, 'doctor_user_id' AS column_name, c.COLUMN_TYPE AS actual_type, c.IS_NULLABLE, c.COLUMN_DEFAULT,
 CASE WHEN c.COLUMN_NAME IS NULL THEN IF(EXISTS(SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='nurse_clinical_reviews'),'BLOCK','ADD')
 WHEN (c.COLUMN_TYPE=(SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='id') AND c.IS_NULLABLE='NO') AND c.EXTRA NOT LIKE '%GENERATED%' THEN 'PASS' ELSE 'BLOCK' END AS result
 FROM (SELECT 1 AS seed) seed LEFT JOIN information_schema.COLUMNS c
 ON c.TABLE_SCHEMA=DATABASE() AND c.TABLE_NAME='nurse_clinical_reviews' AND c.COLUMN_NAME='doctor_user_id'
UNION ALL
SELECT 'nurse_clinical_reviews' AS table_name, 'action' AS column_name, c.COLUMN_TYPE AS actual_type, c.IS_NULLABLE, c.COLUMN_DEFAULT,
 CASE WHEN c.COLUMN_NAME IS NULL THEN IF(EXISTS(SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='nurse_clinical_reviews'),'BLOCK','ADD')
 WHEN (c.COLUMN_TYPE='enum(''authorize'',''request_info'',''reject'',''revoke'')' AND c.IS_NULLABLE='NO') AND c.EXTRA NOT LIKE '%GENERATED%' THEN 'PASS' ELSE 'BLOCK' END AS result
 FROM (SELECT 1 AS seed) seed LEFT JOIN information_schema.COLUMNS c
 ON c.TABLE_SCHEMA=DATABASE() AND c.TABLE_NAME='nurse_clinical_reviews' AND c.COLUMN_NAME='action'
UNION ALL
SELECT 'nurse_clinical_reviews' AS table_name, 'previous_status' AS column_name, c.COLUMN_TYPE AS actual_type, c.IS_NULLABLE, c.COLUMN_DEFAULT,
 CASE WHEN c.COLUMN_NAME IS NULL THEN IF(EXISTS(SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='nurse_clinical_reviews'),'BLOCK','ADD')
 WHEN (c.DATA_TYPE='varchar' AND c.CHARACTER_MAXIMUM_LENGTH=30 AND c.IS_NULLABLE='NO') AND c.EXTRA NOT LIKE '%GENERATED%' THEN 'PASS' ELSE 'BLOCK' END AS result
 FROM (SELECT 1 AS seed) seed LEFT JOIN information_schema.COLUMNS c
 ON c.TABLE_SCHEMA=DATABASE() AND c.TABLE_NAME='nurse_clinical_reviews' AND c.COLUMN_NAME='previous_status'
UNION ALL
SELECT 'nurse_clinical_reviews' AS table_name, 'new_status' AS column_name, c.COLUMN_TYPE AS actual_type, c.IS_NULLABLE, c.COLUMN_DEFAULT,
 CASE WHEN c.COLUMN_NAME IS NULL THEN IF(EXISTS(SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='nurse_clinical_reviews'),'BLOCK','ADD')
 WHEN (c.DATA_TYPE='varchar' AND c.CHARACTER_MAXIMUM_LENGTH=30 AND c.IS_NULLABLE='NO') AND c.EXTRA NOT LIKE '%GENERATED%' THEN 'PASS' ELSE 'BLOCK' END AS result
 FROM (SELECT 1 AS seed) seed LEFT JOIN information_schema.COLUMNS c
 ON c.TABLE_SCHEMA=DATABASE() AND c.TABLE_NAME='nurse_clinical_reviews' AND c.COLUMN_NAME='new_status'
UNION ALL
SELECT 'nurse_clinical_reviews' AS table_name, 'notes' AS column_name, c.COLUMN_TYPE AS actual_type, c.IS_NULLABLE, c.COLUMN_DEFAULT,
 CASE WHEN c.COLUMN_NAME IS NULL THEN IF(EXISTS(SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='nurse_clinical_reviews'),'BLOCK','ADD')
 WHEN (c.DATA_TYPE='text' AND c.IS_NULLABLE='YES') AND c.EXTRA NOT LIKE '%GENERATED%' THEN 'PASS' ELSE 'BLOCK' END AS result
 FROM (SELECT 1 AS seed) seed LEFT JOIN information_schema.COLUMNS c
 ON c.TABLE_SCHEMA=DATABASE() AND c.TABLE_NAME='nurse_clinical_reviews' AND c.COLUMN_NAME='notes'
UNION ALL
SELECT 'nurse_clinical_reviews' AS table_name, 'created_at' AS column_name, c.COLUMN_TYPE AS actual_type, c.IS_NULLABLE, c.COLUMN_DEFAULT,
 CASE WHEN c.COLUMN_NAME IS NULL THEN IF(EXISTS(SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='nurse_clinical_reviews'),'BLOCK','ADD')
 WHEN (c.DATA_TYPE='datetime' AND c.IS_NULLABLE='NO' AND LOWER(c.COLUMN_DEFAULT) IN ('current_timestamp','current_timestamp()')) AND c.EXTRA NOT LIKE '%GENERATED%' THEN 'PASS' ELSE 'BLOCK' END AS result
 FROM (SELECT 1 AS seed) seed LEFT JOIN information_schema.COLUMNS c
 ON c.TABLE_SCHEMA=DATABASE() AND c.TABLE_NAME='nurse_clinical_reviews' AND c.COLUMN_NAME='created_at';
SELECT e.table_name, t.ENGINE, t.TABLE_TYPE,
 CASE WHEN t.ENGINE='InnoDB' AND t.TABLE_TYPE='BASE TABLE' THEN 'PASS'
 WHEN t.TABLE_NAME IS NULL AND e.table_name='nurse_clinical_reviews' THEN 'ADD' ELSE 'BLOCK' END AS result
 FROM (SELECT 'users' table_name UNION ALL SELECT 'nurses' UNION ALL SELECT 'nurse_clinical_reviews') e
 LEFT JOIN information_schema.TABLES t ON t.TABLE_SCHEMA=DATABASE() AND t.TABLE_NAME=e.table_name;
SELECT k.CONSTRAINT_NAME, COUNT(*) AS column_count, MAX(k.COLUMN_NAME) AS column_name,
 MAX(k.REFERENCED_TABLE_SCHEMA) AS target_schema, MAX(k.REFERENCED_TABLE_NAME) AS target_table,
 MAX(k.REFERENCED_COLUMN_NAME) AS target_column, MAX(r.DELETE_RULE) AS delete_rule, MAX(r.UPDATE_RULE) AS update_rule,
 CASE WHEN COUNT(*)<>1 OR MAX(k.ORDINAL_POSITION)<>1 OR MAX(k.POSITION_IN_UNIQUE_CONSTRAINT)<>1
 OR MAX(k.COLUMN_NAME) NOT IN ('nurse_user_id','doctor_user_id')
 OR BINARY MAX(k.REFERENCED_TABLE_SCHEMA)<>BINARY DATABASE() OR BINARY MAX(k.REFERENCED_TABLE_NAME)<>BINARY 'users'
 OR MAX(k.REFERENCED_COLUMN_NAME)<>'id' THEN 'BLOCK'
 WHEN MAX(r.DELETE_RULE) IN ('RESTRICT','NO ACTION') AND MAX(r.UPDATE_RULE) IN ('RESTRICT','NO ACTION') THEN 'PASS'
 WHEN MAX(r.DELETE_RULE) IN ('CASCADE','SET NULL','RESTRICT','NO ACTION')
 AND MAX(r.UPDATE_RULE) IN ('CASCADE','SET NULL','RESTRICT','NO ACTION') THEN 'REPAIR'
 ELSE 'BLOCK' END AS result
 FROM information_schema.KEY_COLUMN_USAGE k
 JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA
 AND r.TABLE_NAME=k.TABLE_NAME AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME
 WHERE k.TABLE_SCHEMA=DATABASE() AND k.TABLE_NAME='nurse_clinical_reviews'
 AND k.REFERENCED_TABLE_NAME IS NOT NULL GROUP BY k.CONSTRAINT_NAME;
SELECT e.column_name, COUNT(k.CONSTRAINT_NAME) AS fk_count,
 CASE WHEN COUNT(k.CONSTRAINT_NAME)=0 THEN 'ADD' WHEN COUNT(k.CONSTRAINT_NAME)=1 THEN 'PASS' ELSE 'BLOCK' END AS result
 FROM (SELECT 'nurse_user_id' column_name UNION ALL SELECT 'doctor_user_id') e
 LEFT JOIN information_schema.KEY_COLUMN_USAGE k ON k.TABLE_SCHEMA=DATABASE()
 AND k.TABLE_NAME='nurse_clinical_reviews' AND k.COLUMN_NAME=e.column_name
 AND k.REFERENCED_TABLE_NAME IS NOT NULL GROUP BY e.column_name;
SELECT 'trigger requires review' AS reason, TRIGGER_NAME AS object_name FROM information_schema.TRIGGERS
 WHERE TRIGGER_SCHEMA=DATABASE() AND EVENT_OBJECT_TABLE IN ('users','nurses','nurse_clinical_reviews')
 UNION ALL
 SELECT 'audit CHECK constraint requires review', CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
 WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='nurse_clinical_reviews' AND CONSTRAINT_TYPE='CHECK'
 UNION ALL
 SELECT 'audit extra unique index requires review', INDEX_NAME FROM information_schema.STATISTICS
 WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='nurse_clinical_reviews' AND NON_UNIQUE=0 AND INDEX_NAME<>'PRIMARY'
 UNION ALL
 SELECT 'unexpected audit column requires review', COLUMN_NAME FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='nurse_clinical_reviews'
 AND COLUMN_NAME NOT IN ('id','nurse_user_id','doctor_user_id','action','previous_status','new_status','notes','created_at')
 UNION ALL
 SELECT 'incoming audit FK requires review', CONCAT(TABLE_NAME,'.',CONSTRAINT_NAME) FROM information_schema.KEY_COLUMN_USAGE
 WHERE REFERENCED_TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME='nurse_clinical_reviews';
SELECT IF(DATABASE() IS NOT NULL AND @@FOREIGN_KEY_CHECKS=1 AND NOT EXISTS (SELECT 1 FROM (
SELECT 'users' AS table_name, 'id' AS column_name, c.COLUMN_TYPE AS actual_type, c.IS_NULLABLE, c.COLUMN_DEFAULT,
 CASE WHEN c.COLUMN_NAME IS NULL THEN 'BLOCK'
 WHEN (c.DATA_TYPE = 'int' AND c.IS_NULLABLE = 'NO' AND EXISTS (SELECT 1 FROM information_schema.STATISTICS s WHERE s.TABLE_SCHEMA=DATABASE() AND s.TABLE_NAME='users' AND s.INDEX_NAME='PRIMARY' GROUP BY s.INDEX_NAME HAVING COUNT(*)=1 AND MAX(s.COLUMN_NAME)='id')) AND c.EXTRA NOT LIKE '%GENERATED%' THEN 'PASS' ELSE 'BLOCK' END AS result
 FROM (SELECT 1 AS seed) seed LEFT JOIN information_schema.COLUMNS c
 ON c.TABLE_SCHEMA=DATABASE() AND c.TABLE_NAME='users' AND c.COLUMN_NAME='id'
UNION ALL
SELECT 'nurses' AS table_name, 'user_id' AS column_name, c.COLUMN_TYPE AS actual_type, c.IS_NULLABLE, c.COLUMN_DEFAULT,
 CASE WHEN c.COLUMN_NAME IS NULL THEN 'BLOCK'
 WHEN (c.COLUMN_TYPE = (SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='id') AND c.IS_NULLABLE='NO') AND c.EXTRA NOT LIKE '%GENERATED%' THEN 'PASS' ELSE 'BLOCK' END AS result
 FROM (SELECT 1 AS seed) seed LEFT JOIN information_schema.COLUMNS c
 ON c.TABLE_SCHEMA=DATABASE() AND c.TABLE_NAME='nurses' AND c.COLUMN_NAME='user_id'
UNION ALL
SELECT 'users' AS table_name, 'self_service_only' AS column_name, c.COLUMN_TYPE AS actual_type, c.IS_NULLABLE, c.COLUMN_DEFAULT,
 CASE WHEN c.COLUMN_NAME IS NULL THEN 'BLOCK'
 WHEN (c.DATA_TYPE='tinyint' AND c.IS_NULLABLE='NO' AND REPLACE(COALESCE(c.COLUMN_DEFAULT,''),CHAR(39),'')='0') AND c.EXTRA NOT LIKE '%GENERATED%' THEN 'PASS' ELSE 'BLOCK' END AS result
 FROM (SELECT 1 AS seed) seed LEFT JOIN information_schema.COLUMNS c
 ON c.TABLE_SCHEMA=DATABASE() AND c.TABLE_NAME='users' AND c.COLUMN_NAME='self_service_only'
UNION ALL
SELECT 'users' AS table_name, 'credential_version' AS column_name, c.COLUMN_TYPE AS actual_type, c.IS_NULLABLE, c.COLUMN_DEFAULT,
 CASE WHEN c.COLUMN_NAME IS NULL THEN 'ADD'
 WHEN (c.DATA_TYPE='int' AND c.COLUMN_TYPE LIKE '%unsigned%' AND c.IS_NULLABLE='NO' AND REPLACE(COALESCE(c.COLUMN_DEFAULT,''),CHAR(39),'')='0') AND c.EXTRA NOT LIKE '%GENERATED%' THEN 'PASS' ELSE 'BLOCK' END AS result
 FROM (SELECT 1 AS seed) seed LEFT JOIN information_schema.COLUMNS c
 ON c.TABLE_SCHEMA=DATABASE() AND c.TABLE_NAME='users' AND c.COLUMN_NAME='credential_version'
UNION ALL
SELECT 'nurses' AS table_name, 'clinical_authorization_status' AS column_name, c.COLUMN_TYPE AS actual_type, c.IS_NULLABLE, c.COLUMN_DEFAULT,
 CASE WHEN c.COLUMN_NAME IS NULL THEN 'ADD'
 WHEN (c.DATA_TYPE='varchar' AND c.CHARACTER_MAXIMUM_LENGTH=30 AND c.IS_NULLABLE='NO' AND REPLACE(COALESCE(c.COLUMN_DEFAULT,''),CHAR(39),'')='pending') AND c.EXTRA NOT LIKE '%GENERATED%' THEN 'PASS' ELSE 'BLOCK' END AS result
 FROM (SELECT 1 AS seed) seed LEFT JOIN information_schema.COLUMNS c
 ON c.TABLE_SCHEMA=DATABASE() AND c.TABLE_NAME='nurses' AND c.COLUMN_NAME='clinical_authorization_status'
UNION ALL
SELECT 'nurses' AS table_name, 'clinical_reviewed_by' AS column_name, c.COLUMN_TYPE AS actual_type, c.IS_NULLABLE, c.COLUMN_DEFAULT,
 CASE WHEN c.COLUMN_NAME IS NULL THEN 'ADD'
 WHEN (c.COLUMN_TYPE=(SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='id') AND c.IS_NULLABLE='YES' AND (c.COLUMN_DEFAULT IS NULL OR UPPER(c.COLUMN_DEFAULT)='NULL')) AND c.EXTRA NOT LIKE '%GENERATED%' THEN 'PASS' ELSE 'BLOCK' END AS result
 FROM (SELECT 1 AS seed) seed LEFT JOIN information_schema.COLUMNS c
 ON c.TABLE_SCHEMA=DATABASE() AND c.TABLE_NAME='nurses' AND c.COLUMN_NAME='clinical_reviewed_by'
UNION ALL
SELECT 'nurses' AS table_name, 'clinical_reviewed_at' AS column_name, c.COLUMN_TYPE AS actual_type, c.IS_NULLABLE, c.COLUMN_DEFAULT,
 CASE WHEN c.COLUMN_NAME IS NULL THEN 'ADD'
 WHEN (c.DATA_TYPE='datetime' AND c.IS_NULLABLE='YES' AND (c.COLUMN_DEFAULT IS NULL OR UPPER(c.COLUMN_DEFAULT)='NULL')) AND c.EXTRA NOT LIKE '%GENERATED%' THEN 'PASS' ELSE 'BLOCK' END AS result
 FROM (SELECT 1 AS seed) seed LEFT JOIN information_schema.COLUMNS c
 ON c.TABLE_SCHEMA=DATABASE() AND c.TABLE_NAME='nurses' AND c.COLUMN_NAME='clinical_reviewed_at'
UNION ALL
SELECT 'nurses' AS table_name, 'clinical_review_notes' AS column_name, c.COLUMN_TYPE AS actual_type, c.IS_NULLABLE, c.COLUMN_DEFAULT,
 CASE WHEN c.COLUMN_NAME IS NULL THEN 'ADD'
 WHEN (c.DATA_TYPE='text' AND c.IS_NULLABLE='YES' AND (c.COLUMN_DEFAULT IS NULL OR UPPER(c.COLUMN_DEFAULT)='NULL')) AND c.EXTRA NOT LIKE '%GENERATED%' THEN 'PASS' ELSE 'BLOCK' END AS result
 FROM (SELECT 1 AS seed) seed LEFT JOIN information_schema.COLUMNS c
 ON c.TABLE_SCHEMA=DATABASE() AND c.TABLE_NAME='nurses' AND c.COLUMN_NAME='clinical_review_notes'
UNION ALL
SELECT 'nurse_clinical_reviews' AS table_name, 'id' AS column_name, c.COLUMN_TYPE AS actual_type, c.IS_NULLABLE, c.COLUMN_DEFAULT,
 CASE WHEN c.COLUMN_NAME IS NULL THEN IF(EXISTS(SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='nurse_clinical_reviews'),'BLOCK','ADD')
 WHEN (c.DATA_TYPE='int' AND c.IS_NULLABLE='NO' AND c.EXTRA LIKE '%auto_increment%' AND EXISTS (SELECT 1 FROM information_schema.STATISTICS s WHERE s.TABLE_SCHEMA=DATABASE() AND s.TABLE_NAME='nurse_clinical_reviews' AND s.INDEX_NAME='PRIMARY' GROUP BY s.INDEX_NAME HAVING COUNT(*)=1 AND MAX(s.COLUMN_NAME)='id')) AND c.EXTRA NOT LIKE '%GENERATED%' THEN 'PASS' ELSE 'BLOCK' END AS result
 FROM (SELECT 1 AS seed) seed LEFT JOIN information_schema.COLUMNS c
 ON c.TABLE_SCHEMA=DATABASE() AND c.TABLE_NAME='nurse_clinical_reviews' AND c.COLUMN_NAME='id'
UNION ALL
SELECT 'nurse_clinical_reviews' AS table_name, 'nurse_user_id' AS column_name, c.COLUMN_TYPE AS actual_type, c.IS_NULLABLE, c.COLUMN_DEFAULT,
 CASE WHEN c.COLUMN_NAME IS NULL THEN IF(EXISTS(SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='nurse_clinical_reviews'),'BLOCK','ADD')
 WHEN (c.COLUMN_TYPE=(SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='id') AND c.IS_NULLABLE='NO') AND c.EXTRA NOT LIKE '%GENERATED%' THEN 'PASS' ELSE 'BLOCK' END AS result
 FROM (SELECT 1 AS seed) seed LEFT JOIN information_schema.COLUMNS c
 ON c.TABLE_SCHEMA=DATABASE() AND c.TABLE_NAME='nurse_clinical_reviews' AND c.COLUMN_NAME='nurse_user_id'
UNION ALL
SELECT 'nurse_clinical_reviews' AS table_name, 'doctor_user_id' AS column_name, c.COLUMN_TYPE AS actual_type, c.IS_NULLABLE, c.COLUMN_DEFAULT,
 CASE WHEN c.COLUMN_NAME IS NULL THEN IF(EXISTS(SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='nurse_clinical_reviews'),'BLOCK','ADD')
 WHEN (c.COLUMN_TYPE=(SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='id') AND c.IS_NULLABLE='NO') AND c.EXTRA NOT LIKE '%GENERATED%' THEN 'PASS' ELSE 'BLOCK' END AS result
 FROM (SELECT 1 AS seed) seed LEFT JOIN information_schema.COLUMNS c
 ON c.TABLE_SCHEMA=DATABASE() AND c.TABLE_NAME='nurse_clinical_reviews' AND c.COLUMN_NAME='doctor_user_id'
UNION ALL
SELECT 'nurse_clinical_reviews' AS table_name, 'action' AS column_name, c.COLUMN_TYPE AS actual_type, c.IS_NULLABLE, c.COLUMN_DEFAULT,
 CASE WHEN c.COLUMN_NAME IS NULL THEN IF(EXISTS(SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='nurse_clinical_reviews'),'BLOCK','ADD')
 WHEN (c.COLUMN_TYPE='enum(''authorize'',''request_info'',''reject'',''revoke'')' AND c.IS_NULLABLE='NO') AND c.EXTRA NOT LIKE '%GENERATED%' THEN 'PASS' ELSE 'BLOCK' END AS result
 FROM (SELECT 1 AS seed) seed LEFT JOIN information_schema.COLUMNS c
 ON c.TABLE_SCHEMA=DATABASE() AND c.TABLE_NAME='nurse_clinical_reviews' AND c.COLUMN_NAME='action'
UNION ALL
SELECT 'nurse_clinical_reviews' AS table_name, 'previous_status' AS column_name, c.COLUMN_TYPE AS actual_type, c.IS_NULLABLE, c.COLUMN_DEFAULT,
 CASE WHEN c.COLUMN_NAME IS NULL THEN IF(EXISTS(SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='nurse_clinical_reviews'),'BLOCK','ADD')
 WHEN (c.DATA_TYPE='varchar' AND c.CHARACTER_MAXIMUM_LENGTH=30 AND c.IS_NULLABLE='NO') AND c.EXTRA NOT LIKE '%GENERATED%' THEN 'PASS' ELSE 'BLOCK' END AS result
 FROM (SELECT 1 AS seed) seed LEFT JOIN information_schema.COLUMNS c
 ON c.TABLE_SCHEMA=DATABASE() AND c.TABLE_NAME='nurse_clinical_reviews' AND c.COLUMN_NAME='previous_status'
UNION ALL
SELECT 'nurse_clinical_reviews' AS table_name, 'new_status' AS column_name, c.COLUMN_TYPE AS actual_type, c.IS_NULLABLE, c.COLUMN_DEFAULT,
 CASE WHEN c.COLUMN_NAME IS NULL THEN IF(EXISTS(SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='nurse_clinical_reviews'),'BLOCK','ADD')
 WHEN (c.DATA_TYPE='varchar' AND c.CHARACTER_MAXIMUM_LENGTH=30 AND c.IS_NULLABLE='NO') AND c.EXTRA NOT LIKE '%GENERATED%' THEN 'PASS' ELSE 'BLOCK' END AS result
 FROM (SELECT 1 AS seed) seed LEFT JOIN information_schema.COLUMNS c
 ON c.TABLE_SCHEMA=DATABASE() AND c.TABLE_NAME='nurse_clinical_reviews' AND c.COLUMN_NAME='new_status'
UNION ALL
SELECT 'nurse_clinical_reviews' AS table_name, 'notes' AS column_name, c.COLUMN_TYPE AS actual_type, c.IS_NULLABLE, c.COLUMN_DEFAULT,
 CASE WHEN c.COLUMN_NAME IS NULL THEN IF(EXISTS(SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='nurse_clinical_reviews'),'BLOCK','ADD')
 WHEN (c.DATA_TYPE='text' AND c.IS_NULLABLE='YES') AND c.EXTRA NOT LIKE '%GENERATED%' THEN 'PASS' ELSE 'BLOCK' END AS result
 FROM (SELECT 1 AS seed) seed LEFT JOIN information_schema.COLUMNS c
 ON c.TABLE_SCHEMA=DATABASE() AND c.TABLE_NAME='nurse_clinical_reviews' AND c.COLUMN_NAME='notes'
UNION ALL
SELECT 'nurse_clinical_reviews' AS table_name, 'created_at' AS column_name, c.COLUMN_TYPE AS actual_type, c.IS_NULLABLE, c.COLUMN_DEFAULT,
 CASE WHEN c.COLUMN_NAME IS NULL THEN IF(EXISTS(SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='nurse_clinical_reviews'),'BLOCK','ADD')
 WHEN (c.DATA_TYPE='datetime' AND c.IS_NULLABLE='NO' AND LOWER(c.COLUMN_DEFAULT) IN ('current_timestamp','current_timestamp()')) AND c.EXTRA NOT LIKE '%GENERATED%' THEN 'PASS' ELSE 'BLOCK' END AS result
 FROM (SELECT 1 AS seed) seed LEFT JOIN information_schema.COLUMNS c
 ON c.TABLE_SCHEMA=DATABASE() AND c.TABLE_NAME='nurse_clinical_reviews' AND c.COLUMN_NAME='created_at'
) bpc_check WHERE result='BLOCK') AND NOT EXISTS (SELECT 1 FROM (
SELECT e.table_name, t.ENGINE, t.TABLE_TYPE,
 CASE WHEN t.ENGINE='InnoDB' AND t.TABLE_TYPE='BASE TABLE' THEN 'PASS'
 WHEN t.TABLE_NAME IS NULL AND e.table_name='nurse_clinical_reviews' THEN 'ADD' ELSE 'BLOCK' END AS result
 FROM (SELECT 'users' table_name UNION ALL SELECT 'nurses' UNION ALL SELECT 'nurse_clinical_reviews') e
 LEFT JOIN information_schema.TABLES t ON t.TABLE_SCHEMA=DATABASE() AND t.TABLE_NAME=e.table_name
) bpc_check WHERE result='BLOCK') AND NOT EXISTS (SELECT 1 FROM (
SELECT k.CONSTRAINT_NAME, COUNT(*) AS column_count, MAX(k.COLUMN_NAME) AS column_name,
 MAX(k.REFERENCED_TABLE_SCHEMA) AS target_schema, MAX(k.REFERENCED_TABLE_NAME) AS target_table,
 MAX(k.REFERENCED_COLUMN_NAME) AS target_column, MAX(r.DELETE_RULE) AS delete_rule, MAX(r.UPDATE_RULE) AS update_rule,
 CASE WHEN COUNT(*)<>1 OR MAX(k.ORDINAL_POSITION)<>1 OR MAX(k.POSITION_IN_UNIQUE_CONSTRAINT)<>1
 OR MAX(k.COLUMN_NAME) NOT IN ('nurse_user_id','doctor_user_id')
 OR BINARY MAX(k.REFERENCED_TABLE_SCHEMA)<>BINARY DATABASE() OR BINARY MAX(k.REFERENCED_TABLE_NAME)<>BINARY 'users'
 OR MAX(k.REFERENCED_COLUMN_NAME)<>'id' THEN 'BLOCK'
 WHEN MAX(r.DELETE_RULE) IN ('RESTRICT','NO ACTION') AND MAX(r.UPDATE_RULE) IN ('RESTRICT','NO ACTION') THEN 'PASS'
 WHEN MAX(r.DELETE_RULE) IN ('CASCADE','SET NULL','RESTRICT','NO ACTION')
 AND MAX(r.UPDATE_RULE) IN ('CASCADE','SET NULL','RESTRICT','NO ACTION') THEN 'REPAIR'
 ELSE 'BLOCK' END AS result
 FROM information_schema.KEY_COLUMN_USAGE k
 JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA
 AND r.TABLE_NAME=k.TABLE_NAME AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME
 WHERE k.TABLE_SCHEMA=DATABASE() AND k.TABLE_NAME='nurse_clinical_reviews'
 AND k.REFERENCED_TABLE_NAME IS NOT NULL GROUP BY k.CONSTRAINT_NAME
) bpc_check WHERE result='BLOCK') AND NOT EXISTS (SELECT 1 FROM (
SELECT e.column_name, COUNT(k.CONSTRAINT_NAME) AS fk_count,
 CASE WHEN COUNT(k.CONSTRAINT_NAME)=0 THEN 'ADD' WHEN COUNT(k.CONSTRAINT_NAME)=1 THEN 'PASS' ELSE 'BLOCK' END AS result
 FROM (SELECT 'nurse_user_id' column_name UNION ALL SELECT 'doctor_user_id') e
 LEFT JOIN information_schema.KEY_COLUMN_USAGE k ON k.TABLE_SCHEMA=DATABASE()
 AND k.TABLE_NAME='nurse_clinical_reviews' AND k.COLUMN_NAME=e.column_name
 AND k.REFERENCED_TABLE_NAME IS NOT NULL GROUP BY e.column_name
) bpc_check WHERE result='BLOCK') AND NOT EXISTS (SELECT 1 FROM (
SELECT 'trigger requires review' AS reason, TRIGGER_NAME AS object_name FROM information_schema.TRIGGERS
 WHERE TRIGGER_SCHEMA=DATABASE() AND EVENT_OBJECT_TABLE IN ('users','nurses','nurse_clinical_reviews')
 UNION ALL
 SELECT 'audit CHECK constraint requires review', CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
 WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='nurse_clinical_reviews' AND CONSTRAINT_TYPE='CHECK'
 UNION ALL
 SELECT 'audit extra unique index requires review', INDEX_NAME FROM information_schema.STATISTICS
 WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='nurse_clinical_reviews' AND NON_UNIQUE=0 AND INDEX_NAME<>'PRIMARY'
 UNION ALL
 SELECT 'unexpected audit column requires review', COLUMN_NAME FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='nurse_clinical_reviews'
 AND COLUMN_NAME NOT IN ('id','nurse_user_id','doctor_user_id','action','previous_status','new_status','notes','created_at')
 UNION ALL
 SELECT 'incoming audit FK requires review', CONCAT(TABLE_NAME,'.',CONSTRAINT_NAME) FROM information_schema.KEY_COLUMN_USAGE
 WHERE REFERENCED_TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME='nurse_clinical_reviews'
) bpc_custom),'METADATA_READY_ONLY - complete generated data checks and policy checklist','BLOCK') AS preflight_result;
-- Execute the returned SQL when present. Counts must all be zero BEFORE importing DDL.
SELECT CASE WHEN
 (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='nurse_clinical_reviews' AND COLUMN_NAME IN ('nurse_user_id','doctor_user_id'))=2
 AND EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='id')
 THEN 'SELECT COUNT(*) AS audit_orphans_must_be_zero FROM nurse_clinical_reviews r LEFT JOIN users n ON n.id=r.nurse_user_id LEFT JOIN users d ON d.id=r.doctor_user_id WHERE n.id IS NULL OR d.id IS NULL'
 ELSE 'Audit absent or malformed - see metadata results. Migration also checks orphans before DDL.' END AS audit_data_check_sql;
SELECT TABLE_NAME, CONSTRAINT_NAME, CONSTRAINT_TYPE FROM information_schema.TABLE_CONSTRAINTS
 WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('users','nurses','nurse_clinical_reviews');
SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME FROM information_schema.STATISTICS
 WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('users','nurses','nurse_clinical_reviews') ORDER BY TABLE_NAME,INDEX_NAME,SEQ_IN_INDEX;
-- Run only if users/nurses prerequisite metadata PASS.
SELECT 'SELECT COUNT(*) AS missing_users FROM nurses n LEFT JOIN users u ON u.id=n.user_id WHERE u.id IS NULL' AS nurse_orphan_check_sql;
SELECT 'SELECT user_id, COUNT(*) AS profile_count FROM nurses GROUP BY user_id HAVING COUNT(*)>1' AS nurse_duplicate_check_sql;
-- Application dependencies for clinical review (not a schema migration grant).
SELECT e.column_name, IF(c.COLUMN_NAME IS NULL,'MISSING_WORKFLOW_DEPENDENCY','PRESENT_REVIEW_DEFINITION') AS workflow_result,
 c.COLUMN_TYPE,c.IS_NULLABLE,c.COLUMN_DEFAULT FROM (
 SELECT 'email_verified' column_name UNION ALL SELECT 'email_verified_at' UNION ALL SELECT 'role'
 UNION ALL SELECT 'status' UNION ALL SELECT 'reviewed_by' UNION ALL SELECT 'reviewed_at'
 UNION ALL SELECT 'validation_reason') e LEFT JOIN information_schema.COLUMNS c
 ON c.TABLE_SCHEMA=DATABASE() AND c.TABLE_NAME='users' AND c.COLUMN_NAME=e.column_name;
SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('admin_profiles','activity_logs');

-- Existing foreign-key inventory. Inspect users/nurses custom relationships without rewriting them.
SELECT k.TABLE_NAME,k.CONSTRAINT_NAME,k.ORDINAL_POSITION,k.COLUMN_NAME,
 k.REFERENCED_TABLE_SCHEMA,k.REFERENCED_TABLE_NAME,k.REFERENCED_COLUMN_NAME,
 r.DELETE_RULE,r.UPDATE_RULE FROM information_schema.KEY_COLUMN_USAGE k
 JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA
 AND r.TABLE_NAME=k.TABLE_NAME AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME
 WHERE k.TABLE_SCHEMA=DATABASE() AND k.TABLE_NAME IN ('users','nurses','nurse_clinical_reviews')
 ORDER BY k.TABLE_NAME,k.CONSTRAINT_NAME,k.ORDINAL_POSITION;
