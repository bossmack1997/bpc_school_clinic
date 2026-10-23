-- HOSTINGER MANUAL MIGRATION. No production execution is authorized by this file.
-- Run preflight_nurse_clinical_authorization_hostinger.sql first, export backup, quiesce writes.
-- DDL auto-commits. Stop on ANY SQL error, rerun preflight before resuming the WHOLE file.
-- No account/patient DML. Existing permissions/statuses and audit rows are preserved.
-- Missing FKs are ADD, recognized unsafe rules REPAIR, duplicates/wrong targets BLOCK.
-- This is schema readiness only. Authorization policy blockers are documented separately.
SET @bpc_id_type = (SELECT COLUMN_TYPE FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='id');
SET @bpc_orphans = NULL;
SET @bpc_ready = DATABASE() IS NOT NULL AND @@FOREIGN_KEY_CHECKS=1 AND NOT EXISTS (SELECT 1 FROM (
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
) bpc_custom);
SET @bpc_sql = IF(@bpc_ready, IF(EXISTS(SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='nurse_clinical_reviews'), 'SELECT COUNT(*) INTO @bpc_orphans FROM nurse_clinical_reviews r LEFT JOIN users n ON n.id=r.nurse_user_id LEFT JOIN users d ON d.id=r.doctor_user_id WHERE n.id IS NULL OR d.id IS NULL', 'SELECT 0 INTO @bpc_orphans'), 'SELECT ''BLOCKED - no DDL executed'' AS migration_note');
PREPARE bpc_stmt FROM @bpc_sql;
EXECUTE bpc_stmt;
DEALLOCATE PREPARE bpc_stmt;
SET @bpc_ready = @bpc_ready AND COALESCE(@bpc_orphans,1)=0;
SET @bpc_profile_issues = NULL;
SET @bpc_sql = IF(@bpc_ready, 'SELECT ((SELECT COUNT(*) FROM nurses n LEFT JOIN users u ON u.id=n.user_id WHERE u.id IS NULL) + (SELECT COUNT(*) FROM (SELECT user_id FROM nurses GROUP BY user_id HAVING COUNT(*)>1) duplicates)) INTO @bpc_profile_issues', 'SELECT ''BLOCKED - no DDL executed'' AS migration_note');
PREPARE bpc_stmt FROM @bpc_sql;
EXECUTE bpc_stmt;
DEALLOCATE PREPARE bpc_stmt;
SET @bpc_ready = @bpc_ready AND COALESCE(@bpc_profile_issues,1)=0;
SELECT IF(@bpc_ready, 'READY_SCHEMA_ONLY', 'BLOCKED - no DDL permitted') AS migration_preflight;

SET @bpc_sql = IF(@bpc_ready, IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='credential_version'), 'SELECT ''users.credential_version exists'' AS migration_note', 'ALTER TABLE `users` ADD COLUMN `credential_version` INT UNSIGNED NOT NULL DEFAULT 0'), 'SELECT ''BLOCKED - no DDL executed'' AS migration_note');
PREPARE bpc_stmt FROM @bpc_sql;
EXECUTE bpc_stmt;
DEALLOCATE PREPARE bpc_stmt;
SET @bpc_ready = @bpc_ready AND EXISTS (SELECT 1 FROM (
SELECT 'users' AS table_name, 'credential_version' AS column_name, c.COLUMN_TYPE AS actual_type, c.IS_NULLABLE, c.COLUMN_DEFAULT,
 CASE WHEN c.COLUMN_NAME IS NULL THEN 'ADD'
 WHEN (c.DATA_TYPE='int' AND c.COLUMN_TYPE LIKE '%unsigned%' AND c.IS_NULLABLE='NO' AND REPLACE(COALESCE(c.COLUMN_DEFAULT,''),CHAR(39),'')='0') AND c.EXTRA NOT LIKE '%GENERATED%' THEN 'PASS' ELSE 'BLOCK' END AS result
 FROM (SELECT 1 AS seed) seed LEFT JOIN information_schema.COLUMNS c
 ON c.TABLE_SCHEMA=DATABASE() AND c.TABLE_NAME='users' AND c.COLUMN_NAME='credential_version'
) step_check WHERE table_name='users' AND column_name='credential_version' AND result='PASS');

SET @bpc_sql = IF(@bpc_ready, IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='nurses' AND COLUMN_NAME='clinical_authorization_status'), 'SELECT ''nurses.clinical_authorization_status exists'' AS migration_note', 'ALTER TABLE `nurses` ADD COLUMN `clinical_authorization_status` VARCHAR(30) NOT NULL DEFAULT ''pending'''), 'SELECT ''BLOCKED - no DDL executed'' AS migration_note');
PREPARE bpc_stmt FROM @bpc_sql;
EXECUTE bpc_stmt;
DEALLOCATE PREPARE bpc_stmt;
SET @bpc_ready = @bpc_ready AND EXISTS (SELECT 1 FROM (
SELECT 'nurses' AS table_name, 'clinical_authorization_status' AS column_name, c.COLUMN_TYPE AS actual_type, c.IS_NULLABLE, c.COLUMN_DEFAULT,
 CASE WHEN c.COLUMN_NAME IS NULL THEN 'ADD'
 WHEN (c.DATA_TYPE='varchar' AND c.CHARACTER_MAXIMUM_LENGTH=30 AND c.IS_NULLABLE='NO' AND REPLACE(COALESCE(c.COLUMN_DEFAULT,''),CHAR(39),'')='pending') AND c.EXTRA NOT LIKE '%GENERATED%' THEN 'PASS' ELSE 'BLOCK' END AS result
 FROM (SELECT 1 AS seed) seed LEFT JOIN information_schema.COLUMNS c
 ON c.TABLE_SCHEMA=DATABASE() AND c.TABLE_NAME='nurses' AND c.COLUMN_NAME='clinical_authorization_status'
) step_check WHERE table_name='nurses' AND column_name='clinical_authorization_status' AND result='PASS');

SET @bpc_sql = IF(@bpc_ready, IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='nurses' AND COLUMN_NAME='clinical_reviewed_by'), 'SELECT ''nurses.clinical_reviewed_by exists'' AS migration_note', CONCAT('ALTER TABLE `nurses` ADD COLUMN `clinical_reviewed_by` ', @bpc_id_type, ' NULL DEFAULT NULL')), 'SELECT ''BLOCKED - no DDL executed'' AS migration_note');
PREPARE bpc_stmt FROM @bpc_sql;
EXECUTE bpc_stmt;
DEALLOCATE PREPARE bpc_stmt;
SET @bpc_ready = @bpc_ready AND EXISTS (SELECT 1 FROM (
SELECT 'nurses' AS table_name, 'clinical_reviewed_by' AS column_name, c.COLUMN_TYPE AS actual_type, c.IS_NULLABLE, c.COLUMN_DEFAULT,
 CASE WHEN c.COLUMN_NAME IS NULL THEN 'ADD'
 WHEN (c.COLUMN_TYPE=(SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='id') AND c.IS_NULLABLE='YES' AND (c.COLUMN_DEFAULT IS NULL OR UPPER(c.COLUMN_DEFAULT)='NULL')) AND c.EXTRA NOT LIKE '%GENERATED%' THEN 'PASS' ELSE 'BLOCK' END AS result
 FROM (SELECT 1 AS seed) seed LEFT JOIN information_schema.COLUMNS c
 ON c.TABLE_SCHEMA=DATABASE() AND c.TABLE_NAME='nurses' AND c.COLUMN_NAME='clinical_reviewed_by'
) step_check WHERE table_name='nurses' AND column_name='clinical_reviewed_by' AND result='PASS');

SET @bpc_sql = IF(@bpc_ready, IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='nurses' AND COLUMN_NAME='clinical_reviewed_at'), 'SELECT ''nurses.clinical_reviewed_at exists'' AS migration_note', 'ALTER TABLE `nurses` ADD COLUMN `clinical_reviewed_at` DATETIME NULL DEFAULT NULL'), 'SELECT ''BLOCKED - no DDL executed'' AS migration_note');
PREPARE bpc_stmt FROM @bpc_sql;
EXECUTE bpc_stmt;
DEALLOCATE PREPARE bpc_stmt;
SET @bpc_ready = @bpc_ready AND EXISTS (SELECT 1 FROM (
SELECT 'nurses' AS table_name, 'clinical_reviewed_at' AS column_name, c.COLUMN_TYPE AS actual_type, c.IS_NULLABLE, c.COLUMN_DEFAULT,
 CASE WHEN c.COLUMN_NAME IS NULL THEN 'ADD'
 WHEN (c.DATA_TYPE='datetime' AND c.IS_NULLABLE='YES' AND (c.COLUMN_DEFAULT IS NULL OR UPPER(c.COLUMN_DEFAULT)='NULL')) AND c.EXTRA NOT LIKE '%GENERATED%' THEN 'PASS' ELSE 'BLOCK' END AS result
 FROM (SELECT 1 AS seed) seed LEFT JOIN information_schema.COLUMNS c
 ON c.TABLE_SCHEMA=DATABASE() AND c.TABLE_NAME='nurses' AND c.COLUMN_NAME='clinical_reviewed_at'
) step_check WHERE table_name='nurses' AND column_name='clinical_reviewed_at' AND result='PASS');

SET @bpc_sql = IF(@bpc_ready, IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='nurses' AND COLUMN_NAME='clinical_review_notes'), 'SELECT ''nurses.clinical_review_notes exists'' AS migration_note', 'ALTER TABLE `nurses` ADD COLUMN `clinical_review_notes` TEXT NULL'), 'SELECT ''BLOCKED - no DDL executed'' AS migration_note');
PREPARE bpc_stmt FROM @bpc_sql;
EXECUTE bpc_stmt;
DEALLOCATE PREPARE bpc_stmt;
SET @bpc_ready = @bpc_ready AND EXISTS (SELECT 1 FROM (
SELECT 'nurses' AS table_name, 'clinical_review_notes' AS column_name, c.COLUMN_TYPE AS actual_type, c.IS_NULLABLE, c.COLUMN_DEFAULT,
 CASE WHEN c.COLUMN_NAME IS NULL THEN 'ADD'
 WHEN (c.DATA_TYPE='text' AND c.IS_NULLABLE='YES' AND (c.COLUMN_DEFAULT IS NULL OR UPPER(c.COLUMN_DEFAULT)='NULL')) AND c.EXTRA NOT LIKE '%GENERATED%' THEN 'PASS' ELSE 'BLOCK' END AS result
 FROM (SELECT 1 AS seed) seed LEFT JOIN information_schema.COLUMNS c
 ON c.TABLE_SCHEMA=DATABASE() AND c.TABLE_NAME='nurses' AND c.COLUMN_NAME='clinical_review_notes'
) step_check WHERE table_name='nurses' AND column_name='clinical_review_notes' AND result='PASS');

SET @bpc_sql = IF(@bpc_ready, CONCAT('CREATE TABLE IF NOT EXISTS nurse_clinical_reviews (
 id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
 nurse_user_id ', @bpc_id_type, ' NOT NULL, doctor_user_id ', @bpc_id_type, ' NOT NULL,
 action ENUM(''authorize'',''request_info'',''reject'',''revoke'') NOT NULL,
 previous_status VARCHAR(30) NOT NULL, new_status VARCHAR(30) NOT NULL,
 notes TEXT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY idx_ncr_nurse (nurse_user_id), KEY idx_ncr_doctor (doctor_user_id),
 FOREIGN KEY (nurse_user_id) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
 FOREIGN KEY (doctor_user_id) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'), 'SELECT ''BLOCKED - no DDL executed'' AS migration_note');
PREPARE bpc_stmt FROM @bpc_sql;
EXECUTE bpc_stmt;
DEALLOCATE PREPARE bpc_stmt;
SET @bpc_ready = @bpc_ready AND NOT EXISTS (SELECT 1 FROM (
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
) step_check WHERE result<>'PASS');

SET @bpc_drop = (SELECT CONCAT('DROP FOREIGN KEY `',REPLACE(k.CONSTRAINT_NAME,'`','``'),'`, ')
 FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r
 ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.TABLE_NAME=k.TABLE_NAME AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME
 WHERE k.TABLE_SCHEMA=DATABASE() AND k.TABLE_NAME='nurse_clinical_reviews' AND k.COLUMN_NAME='nurse_user_id'
 AND (r.DELETE_RULE NOT IN ('RESTRICT','NO ACTION') OR r.UPDATE_RULE NOT IN ('RESTRICT','NO ACTION')) LIMIT 1);
SET @bpc_sql = IF(@bpc_ready, IF(EXISTS(SELECT 1 FROM (SELECT k.CONSTRAINT_NAME, COUNT(*) AS column_count, MAX(k.COLUMN_NAME) AS column_name,
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
 AND k.REFERENCED_TABLE_NAME IS NOT NULL GROUP BY k.CONSTRAINT_NAME) fk_check WHERE column_name='nurse_user_id' AND result='PASS'), 'SELECT ''nurse_user_id FK PASS'' AS migration_note', CONCAT('ALTER TABLE nurse_clinical_reviews ',COALESCE(@bpc_drop,''),'ADD FOREIGN KEY (`nurse_user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT')), 'SELECT ''BLOCKED - no DDL executed'' AS migration_note');
PREPARE bpc_stmt FROM @bpc_sql;
EXECUTE bpc_stmt;
DEALLOCATE PREPARE bpc_stmt;
SET @bpc_ready = @bpc_ready AND EXISTS(SELECT 1 FROM (SELECT k.CONSTRAINT_NAME, COUNT(*) AS column_count, MAX(k.COLUMN_NAME) AS column_name,
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
 AND k.REFERENCED_TABLE_NAME IS NOT NULL GROUP BY k.CONSTRAINT_NAME) fk_check WHERE column_name='nurse_user_id' AND result='PASS');

SET @bpc_drop = (SELECT CONCAT('DROP FOREIGN KEY `',REPLACE(k.CONSTRAINT_NAME,'`','``'),'`, ')
 FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r
 ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.TABLE_NAME=k.TABLE_NAME AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME
 WHERE k.TABLE_SCHEMA=DATABASE() AND k.TABLE_NAME='nurse_clinical_reviews' AND k.COLUMN_NAME='doctor_user_id'
 AND (r.DELETE_RULE NOT IN ('RESTRICT','NO ACTION') OR r.UPDATE_RULE NOT IN ('RESTRICT','NO ACTION')) LIMIT 1);
SET @bpc_sql = IF(@bpc_ready, IF(EXISTS(SELECT 1 FROM (SELECT k.CONSTRAINT_NAME, COUNT(*) AS column_count, MAX(k.COLUMN_NAME) AS column_name,
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
 AND k.REFERENCED_TABLE_NAME IS NOT NULL GROUP BY k.CONSTRAINT_NAME) fk_check WHERE column_name='doctor_user_id' AND result='PASS'), 'SELECT ''doctor_user_id FK PASS'' AS migration_note', CONCAT('ALTER TABLE nurse_clinical_reviews ',COALESCE(@bpc_drop,''),'ADD FOREIGN KEY (`doctor_user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT')), 'SELECT ''BLOCKED - no DDL executed'' AS migration_note');
PREPARE bpc_stmt FROM @bpc_sql;
EXECUTE bpc_stmt;
DEALLOCATE PREPARE bpc_stmt;
SET @bpc_ready = @bpc_ready AND EXISTS(SELECT 1 FROM (SELECT k.CONSTRAINT_NAME, COUNT(*) AS column_count, MAX(k.COLUMN_NAME) AS column_name,
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
 AND k.REFERENCED_TABLE_NAME IS NOT NULL GROUP BY k.CONSTRAINT_NAME) fk_check WHERE column_name='doctor_user_id' AND result='PASS');
SET @bpc_complete = @bpc_ready AND (DATABASE() IS NOT NULL AND @@FOREIGN_KEY_CHECKS=1 AND NOT EXISTS (SELECT 1 FROM (
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
) bpc_check WHERE result<>'PASS') AND NOT EXISTS (SELECT 1 FROM (
SELECT e.table_name, t.ENGINE, t.TABLE_TYPE,
 CASE WHEN t.ENGINE='InnoDB' AND t.TABLE_TYPE='BASE TABLE' THEN 'PASS'
 WHEN t.TABLE_NAME IS NULL AND e.table_name='nurse_clinical_reviews' THEN 'ADD' ELSE 'BLOCK' END AS result
 FROM (SELECT 'users' table_name UNION ALL SELECT 'nurses' UNION ALL SELECT 'nurse_clinical_reviews') e
 LEFT JOIN information_schema.TABLES t ON t.TABLE_SCHEMA=DATABASE() AND t.TABLE_NAME=e.table_name
) bpc_check WHERE result<>'PASS') AND NOT EXISTS (SELECT 1 FROM (
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
) bpc_check WHERE result<>'PASS') AND NOT EXISTS (SELECT 1 FROM (
SELECT e.column_name, COUNT(k.CONSTRAINT_NAME) AS fk_count,
 CASE WHEN COUNT(k.CONSTRAINT_NAME)=0 THEN 'ADD' WHEN COUNT(k.CONSTRAINT_NAME)=1 THEN 'PASS' ELSE 'BLOCK' END AS result
 FROM (SELECT 'nurse_user_id' column_name UNION ALL SELECT 'doctor_user_id') e
 LEFT JOIN information_schema.KEY_COLUMN_USAGE k ON k.TABLE_SCHEMA=DATABASE()
 AND k.TABLE_NAME='nurse_clinical_reviews' AND k.COLUMN_NAME=e.column_name
 AND k.REFERENCED_TABLE_NAME IS NOT NULL GROUP BY e.column_name
) bpc_check WHERE result<>'PASS') AND NOT EXISTS (SELECT 1 FROM (
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
) bpc_custom));
SELECT IF(@bpc_complete, 'SCHEMA_COMPLETE - run post-verification. Authorization release NOT approved', 'BLOCKED_OR_INCOMPLETE - stop and inspect errors/preflight') AS migration_result;
