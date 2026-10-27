-- READ ONLY. Explicit target; does not switch the selected database or run DDL.
-- TARGET_VERIFIED confirms schema identity only. Inspect every result before migration.
SELECT DATABASE() AS selected_database,'u962995739_bpc_clinic' AS intended_database,VERSION() AS server_version,
 CASE WHEN EXISTS(SELECT 1 FROM information_schema.SCHEMATA WHERE BINARY SCHEMA_NAME=BINARY 'u962995739_bpc_clinic')
 AND (SELECT COUNT(*) FROM information_schema.TABLES WHERE BINARY TABLE_SCHEMA=BINARY 'u962995739_bpc_clinic' AND BINARY TABLE_NAME IN (BINARY 'users',BINARY 'consultations',BINARY 'queue_numbers') AND TABLE_TYPE='BASE TABLE' AND ENGINE='InnoDB')=3
 THEN 'TARGET_VERIFIED' ELSE 'BLOCK_TARGET_NOT_VERIFIED' END AS target_result;
SELECT expected.table_name,expected.column_name,
 CASE WHEN actual.COLUMN_NAME IS NULL THEN 'BLOCK_MISSING_PREREQUISITE' ELSE 'PRESENT_REVIEW_DEFINITION' END AS result,
 actual.COLUMN_TYPE,actual.IS_NULLABLE,actual.COLUMN_DEFAULT
FROM (
 SELECT 'users' table_name,'id' column_name UNION ALL SELECT 'users','email'
 UNION ALL SELECT 'users','username' UNION ALL SELECT 'users','email_verified_at'
 UNION ALL SELECT 'users','credential_version' UNION ALL SELECT 'users','self_service_only'
 UNION ALL SELECT 'users','registration_status' UNION ALL SELECT 'users','password_hash'
 UNION ALL SELECT 'nurses','clinical_authorization_status'
 UNION ALL SELECT 'queue_numbers','id' UNION ALL SELECT 'queue_numbers','teacher_id'
 UNION ALL SELECT 'queue_numbers','patient_type' UNION ALL SELECT 'queue_numbers','status'
 UNION ALL SELECT 'consultations','id' UNION ALL SELECT 'consultations','teacher_id'
 UNION ALL SELECT 'consultations','clinician_user_id' UNION ALL SELECT 'consultations','record_version'
 UNION ALL SELECT 'consultations','vital_signs' UNION ALL SELECT 'consultations','follow_up_date'
 UNION ALL SELECT 'walk_in_visits','assessment' UNION ALL SELECT 'walk_in_visits','respiratory_rate'
 UNION ALL SELECT 'admin_profiles','profile' UNION ALL SELECT 'activity_logs','details'
 UNION ALL SELECT 'system_settings','setting_key'
) expected LEFT JOIN information_schema.COLUMNS actual
 ON BINARY actual.TABLE_SCHEMA=BINARY 'u962995739_bpc_clinic'
 AND BINARY actual.TABLE_NAME=BINARY expected.table_name AND BINARY actual.COLUMN_NAME=BINARY expected.column_name
ORDER BY expected.table_name,expected.column_name;
SELECT TABLE_NAME,TABLE_TYPE,ENGINE FROM information_schema.TABLES
WHERE BINARY TABLE_SCHEMA=BINARY 'u962995739_bpc_clinic'
 AND TABLE_NAME IN ('users','students','teachers','nurses','queue_numbers','consultations','walk_in_visits','activity_logs','system_settings','admin_profiles');
-- Both email and username require a full-column, single-column unique index.
SELECT INDEX_NAME,GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS indexed_columns,
 NON_UNIQUE,MAX(COALESCE(SUB_PART,0)) AS prefix_length
FROM information_schema.STATISTICS WHERE BINARY TABLE_SCHEMA=BINARY 'u962995739_bpc_clinic' AND BINARY TABLE_NAME=BINARY 'users'
GROUP BY INDEX_NAME,NON_UNIQUE;
-- Absent new tables are ADD. Present tables require definition review; IF NOT EXISTS is not a repair.
SELECT expected.table_name,CASE WHEN actual.TABLE_NAME IS NULL THEN 'ADD' ELSE 'REVIEW_EXISTING_DEFINITION' END AS result,actual.ENGINE
FROM (SELECT 'physician_queue' table_name UNION ALL SELECT 'account_activation_invitations' UNION ALL SELECT 'account_provisioning_lock') expected
LEFT JOIN information_schema.TABLES actual ON BINARY actual.TABLE_SCHEMA=BINARY 'u962995739_bpc_clinic' AND BINARY actual.TABLE_NAME=BINARY expected.table_name;
SELECT TABLE_NAME,COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT FROM information_schema.COLUMNS
WHERE BINARY TABLE_SCHEMA=BINARY 'u962995739_bpc_clinic' AND TABLE_NAME IN ('physician_queue','account_activation_invitations','account_provisioning_lock') ORDER BY TABLE_NAME,ORDINAL_POSITION;
