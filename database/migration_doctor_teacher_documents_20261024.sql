-- After migration_teacher_patient.sql. Allows Teacher-only clinical documents.
-- No records, keys or tables are removed. Preserve the existing integer type.
SET @doctor_student_id_type = (SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='medical_records' AND COLUMN_NAME='student_id');
SET @doctor_document_sql = IF(@doctor_student_id_type IS NULL, 'SELECT ''medical_records.student_id missing - apply base and Teacher migrations first'' AS migration_notice', CONCAT('ALTER TABLE medical_records MODIFY COLUMN student_id ', @doctor_student_id_type, ' NULL'));
PREPARE doctor_document_statement FROM @doctor_document_sql;
EXECUTE doctor_document_statement;
DEALLOCATE PREPARE doctor_document_statement;
