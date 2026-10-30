-- READ ONLY: metadata only; explicit Hostinger schema, independent of selected DB.
-- This file never creates/repairs schema. Export results without patient data.
SELECT DATABASE() AS selected_database, VERSION() AS server_version,
 'u962995739_bpc_clinic' AS inspected_schema;
SELECT TABLE_NAME,TABLE_TYPE,ENGINE,TABLE_COLLATION
FROM information_schema.TABLES
WHERE BINARY TABLE_SCHEMA=BINARY 'u962995739_bpc_clinic'
 AND TABLE_NAME IN ('physician_queue','account_activation_invitations','account_provisioning_lock');
SELECT TABLE_NAME,ORDINAL_POSITION,COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,
 COLUMN_DEFAULT,EXTRA,CHARACTER_SET_NAME,COLLATION_NAME,GENERATION_EXPRESSION
FROM information_schema.COLUMNS
WHERE BINARY TABLE_SCHEMA=BINARY 'u962995739_bpc_clinic'
 AND TABLE_NAME IN ('physician_queue','account_activation_invitations','account_provisioning_lock')
ORDER BY TABLE_NAME,ORDINAL_POSITION;
-- Individual index components avoid GROUP_CONCAT truncation or lost prefix/order details.
SELECT TABLE_NAME,INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,SUB_PART,COLLATION,INDEX_TYPE
FROM information_schema.STATISTICS
WHERE BINARY TABLE_SCHEMA=BINARY 'u962995739_bpc_clinic'
 AND TABLE_NAME IN ('physician_queue','account_activation_invitations','account_provisioning_lock')
ORDER BY TABLE_NAME,INDEX_NAME,SEQ_IN_INDEX;
SELECT k.TABLE_NAME,k.CONSTRAINT_NAME,k.ORDINAL_POSITION,k.COLUMN_NAME,
 k.REFERENCED_TABLE_SCHEMA,k.REFERENCED_TABLE_NAME,k.REFERENCED_COLUMN_NAME,
 r.DELETE_RULE,r.UPDATE_RULE
FROM information_schema.KEY_COLUMN_USAGE k
JOIN information_schema.REFERENTIAL_CONSTRAINTS r
 ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.TABLE_NAME=k.TABLE_NAME
 AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME
WHERE BINARY k.TABLE_SCHEMA=BINARY 'u962995739_bpc_clinic'
 AND k.TABLE_NAME IN ('physician_queue','account_activation_invitations')
ORDER BY k.TABLE_NAME,k.CONSTRAINT_NAME,k.ORDINAL_POSITION;
-- Parent type compatibility and actual indexed parent key definitions also matter.
SELECT TABLE_NAME,COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_KEY,EXTRA
FROM information_schema.COLUMNS
WHERE BINARY TABLE_SCHEMA=BINARY 'u962995739_bpc_clinic'
 AND TABLE_NAME IN ('users','queue_numbers','consultations') AND COLUMN_NAME='id';
SHOW CREATE TABLE u962995739_bpc_clinic.physician_queue;
SHOW CREATE TABLE u962995739_bpc_clinic.account_activation_invitations;
SHOW CREATE TABLE u962995739_bpc_clinic.account_provisioning_lock;
-- Required sentinel exists independently of table structure. No invitation tokens are selected.
SELECT COUNT(*) AS provisioning_lock_row_1_count
FROM u962995739_bpc_clinic.account_provisioning_lock WHERE id=1;
