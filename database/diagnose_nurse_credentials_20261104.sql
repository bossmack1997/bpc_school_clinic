-- READ ONLY: select the intended application database in phpMyAdmin first.
-- Do not run migrations based on table counts alone. Review exact definitions.
SELECT DATABASE() AS selected_database, VERSION() AS server_version;
SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES
WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN
('users','nurses','nurse_credential_drafts','nurse_credential_submissions','nurse_clinical_reviews','activity_logs','notifications');
SELECT TABLE_NAME,COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA
FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN
('nurse_credential_drafts','nurse_credential_submissions','nurse_clinical_reviews')
ORDER BY TABLE_NAME,ORDINAL_POSITION;
-- Run individually; missing objects will fail explicitly without exposing patient data.
SHOW CREATE TABLE nurse_credential_drafts;
SHOW CREATE TABLE nurse_credential_submissions;
SHOW CREATE TABLE nurse_clinical_reviews;
SELECT u.status,u.email_verified,u.self_service_only,u.credential_version,
 n.employee_id,n.license_number,n.position,n.valid_id_path,
 n.clinical_authorization_status,n.clinical_review_notes,n.clinical_reviewed_at
FROM users u JOIN nurses n ON n.user_id=u.id WHERE u.id=0 AND u.role='nurse';
SELECT nurse_user_id,license_number,employee_id,position,pending_document_path,updated_at
FROM nurse_credential_drafts LIMIT 0;
SELECT id,nurse_user_id,license_number,employee_id,position,document_path,
 material_change,previous_status,resulting_status,created_at
FROM nurse_credential_submissions LIMIT 0;
