-- READ ONLY. Select the intended application database in phpMyAdmin first.
-- No account, clinical record or schema is changed.
SELECT DATABASE() AS selected_database, VERSION() AS server_version;
SET @physician_missing_columns = (SELECT COUNT(*) FROM (
 SELECT 'users' AS table_name, 'id' AS column_name
 UNION ALL
 SELECT 'users' AS table_name, 'role' AS column_name
 UNION ALL
 SELECT 'users' AS table_name, 'status' AS column_name
 UNION ALL
 SELECT 'users' AS table_name, 'email_verified' AS column_name
 UNION ALL
 SELECT 'users' AS table_name, 'registration_status' AS column_name
 UNION ALL
 SELECT 'users' AS table_name, 'credential_version' AS column_name
 UNION ALL
 SELECT 'users' AS table_name, 'first_name' AS column_name
 UNION ALL
 SELECT 'users' AS table_name, 'last_name' AS column_name
 UNION ALL
 SELECT 'admin_profiles' AS table_name, 'user_id' AS column_name
 UNION ALL
 SELECT 'admin_profiles' AS table_name, 'profile' AS column_name
 UNION ALL
 SELECT 'nurses' AS table_name, 'user_id' AS column_name
 UNION ALL
 SELECT 'students' AS table_name, 'id' AS column_name
 UNION ALL
 SELECT 'students' AS table_name, 'student_number' AS column_name
 UNION ALL
 SELECT 'students' AS table_name, 'blood_type' AS column_name
 UNION ALL
 SELECT 'students' AS table_name, 'allergies' AS column_name
 UNION ALL
 SELECT 'students' AS table_name, 'user_id' AS column_name
 UNION ALL
 SELECT 'teachers' AS table_name, 'id' AS column_name
 UNION ALL
 SELECT 'teachers' AS table_name, 'employee_id' AS column_name
 UNION ALL
 SELECT 'teachers' AS table_name, 'blood_type' AS column_name
 UNION ALL
 SELECT 'teachers' AS table_name, 'allergies' AS column_name
 UNION ALL
 SELECT 'teachers' AS table_name, 'user_id' AS column_name
 UNION ALL
 SELECT 'queue_numbers' AS table_name, 'id' AS column_name
 UNION ALL
 SELECT 'queue_numbers' AS table_name, 'queue_number' AS column_name
 UNION ALL
 SELECT 'queue_numbers' AS table_name, 'student_id' AS column_name
 UNION ALL
 SELECT 'queue_numbers' AS table_name, 'teacher_id' AS column_name
 UNION ALL
 SELECT 'queue_numbers' AS table_name, 'patient_type' AS column_name
 UNION ALL
 SELECT 'queue_numbers' AS table_name, 'priority' AS column_name
 UNION ALL
 SELECT 'queue_numbers' AS table_name, 'status' AS column_name
 UNION ALL
 SELECT 'walk_in_visits' AS table_name, 'id' AS column_name
 UNION ALL
 SELECT 'walk_in_visits' AS table_name, 'queue_id' AS column_name
 UNION ALL
 SELECT 'walk_in_visits' AS table_name, 'chief_complaint' AS column_name
 UNION ALL
 SELECT 'walk_in_visits' AS table_name, 'symptoms' AS column_name
 UNION ALL
 SELECT 'walk_in_visits' AS table_name, 'assessment' AS column_name
 UNION ALL
 SELECT 'walk_in_visits' AS table_name, 'recommendation' AS column_name
 UNION ALL
 SELECT 'walk_in_visits' AS table_name, 'temperature' AS column_name
 UNION ALL
 SELECT 'walk_in_visits' AS table_name, 'blood_pressure_systolic' AS column_name
 UNION ALL
 SELECT 'walk_in_visits' AS table_name, 'blood_pressure_diastolic' AS column_name
 UNION ALL
 SELECT 'walk_in_visits' AS table_name, 'heart_rate' AS column_name
 UNION ALL
 SELECT 'walk_in_visits' AS table_name, 'respiratory_rate' AS column_name
 UNION ALL
 SELECT 'walk_in_visits' AS table_name, 'oxygen_saturation' AS column_name
 UNION ALL
 SELECT 'walk_in_visits' AS table_name, 'height_cm' AS column_name
 UNION ALL
 SELECT 'walk_in_visits' AS table_name, 'weight_kg' AS column_name
 UNION ALL
 SELECT 'consultations' AS table_name, 'id' AS column_name
 UNION ALL
 SELECT 'consultations' AS table_name, 'nurse_id' AS column_name
 UNION ALL
 SELECT 'consultations' AS table_name, 'visit_type' AS column_name
 UNION ALL
 SELECT 'consultations' AS table_name, 'student_id' AS column_name
 UNION ALL
 SELECT 'consultations' AS table_name, 'teacher_id' AS column_name
 UNION ALL
 SELECT 'consultations' AS table_name, 'patient_type' AS column_name
 UNION ALL
 SELECT 'consultations' AS table_name, 'clinician_user_id' AS column_name
 UNION ALL
 SELECT 'consultations' AS table_name, 'record_version' AS column_name
 UNION ALL
 SELECT 'consultations' AS table_name, 'consultation_date' AS column_name
 UNION ALL
 SELECT 'consultations' AS table_name, 'chief_complaint' AS column_name
 UNION ALL
 SELECT 'consultations' AS table_name, 'diagnosis' AS column_name
 UNION ALL
 SELECT 'consultations' AS table_name, 'treatment' AS column_name
 UNION ALL
 SELECT 'consultations' AS table_name, 'prescription' AS column_name
 UNION ALL
 SELECT 'consultations' AS table_name, 'remarks' AS column_name
 UNION ALL
 SELECT 'consultations' AS table_name, 'follow_up_date' AS column_name
 UNION ALL
 SELECT 'consultations' AS table_name, 'vital_signs' AS column_name
 UNION ALL
 SELECT 'activity_logs' AS table_name, 'user_id' AS column_name
 UNION ALL
 SELECT 'activity_logs' AS table_name, 'action' AS column_name
 UNION ALL
 SELECT 'activity_logs' AS table_name, 'module' AS column_name
 UNION ALL
 SELECT 'activity_logs' AS table_name, 'details' AS column_name
) required_columns LEFT JOIN information_schema.COLUMNS actual ON actual.TABLE_SCHEMA=DATABASE() AND BINARY actual.TABLE_NAME=BINARY required_columns.table_name AND BINARY actual.COLUMN_NAME=BINARY required_columns.column_name WHERE actual.COLUMN_NAME IS NULL);
SET @physician_prerequisites_ready = DATABASE() IS NOT NULL AND @physician_missing_columns=0 AND (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('users','admin_profiles','nurses','students','teachers','queue_numbers','walk_in_visits','consultations','activity_logs') AND ENGINE='InnoDB' AND TABLE_TYPE='BASE TABLE')=9 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('users','queue_numbers','consultations') AND COLUMN_NAME='id' AND DATA_TYPE='int' AND COLUMN_TYPE NOT LIKE '%unsigned%' AND COLUMN_KEY='PRI')=3;
SELECT @physician_prerequisites_ready AS prerequisites_ready, @physician_missing_columns AS missing_column_count;
SELECT required_columns.* FROM (
 SELECT 'users' AS table_name, 'id' AS column_name
 UNION ALL
 SELECT 'users' AS table_name, 'role' AS column_name
 UNION ALL
 SELECT 'users' AS table_name, 'status' AS column_name
 UNION ALL
 SELECT 'users' AS table_name, 'email_verified' AS column_name
 UNION ALL
 SELECT 'users' AS table_name, 'registration_status' AS column_name
 UNION ALL
 SELECT 'users' AS table_name, 'credential_version' AS column_name
 UNION ALL
 SELECT 'users' AS table_name, 'first_name' AS column_name
 UNION ALL
 SELECT 'users' AS table_name, 'last_name' AS column_name
 UNION ALL
 SELECT 'admin_profiles' AS table_name, 'user_id' AS column_name
 UNION ALL
 SELECT 'admin_profiles' AS table_name, 'profile' AS column_name
 UNION ALL
 SELECT 'nurses' AS table_name, 'user_id' AS column_name
 UNION ALL
 SELECT 'students' AS table_name, 'id' AS column_name
 UNION ALL
 SELECT 'students' AS table_name, 'student_number' AS column_name
 UNION ALL
 SELECT 'students' AS table_name, 'blood_type' AS column_name
 UNION ALL
 SELECT 'students' AS table_name, 'allergies' AS column_name
 UNION ALL
 SELECT 'students' AS table_name, 'user_id' AS column_name
 UNION ALL
 SELECT 'teachers' AS table_name, 'id' AS column_name
 UNION ALL
 SELECT 'teachers' AS table_name, 'employee_id' AS column_name
 UNION ALL
 SELECT 'teachers' AS table_name, 'blood_type' AS column_name
 UNION ALL
 SELECT 'teachers' AS table_name, 'allergies' AS column_name
 UNION ALL
 SELECT 'teachers' AS table_name, 'user_id' AS column_name
 UNION ALL
 SELECT 'queue_numbers' AS table_name, 'id' AS column_name
 UNION ALL
 SELECT 'queue_numbers' AS table_name, 'queue_number' AS column_name
 UNION ALL
 SELECT 'queue_numbers' AS table_name, 'student_id' AS column_name
 UNION ALL
 SELECT 'queue_numbers' AS table_name, 'teacher_id' AS column_name
 UNION ALL
 SELECT 'queue_numbers' AS table_name, 'patient_type' AS column_name
 UNION ALL
 SELECT 'queue_numbers' AS table_name, 'priority' AS column_name
 UNION ALL
 SELECT 'queue_numbers' AS table_name, 'status' AS column_name
 UNION ALL
 SELECT 'walk_in_visits' AS table_name, 'id' AS column_name
 UNION ALL
 SELECT 'walk_in_visits' AS table_name, 'queue_id' AS column_name
 UNION ALL
 SELECT 'walk_in_visits' AS table_name, 'chief_complaint' AS column_name
 UNION ALL
 SELECT 'walk_in_visits' AS table_name, 'symptoms' AS column_name
 UNION ALL
 SELECT 'walk_in_visits' AS table_name, 'assessment' AS column_name
 UNION ALL
 SELECT 'walk_in_visits' AS table_name, 'recommendation' AS column_name
 UNION ALL
 SELECT 'walk_in_visits' AS table_name, 'temperature' AS column_name
 UNION ALL
 SELECT 'walk_in_visits' AS table_name, 'blood_pressure_systolic' AS column_name
 UNION ALL
 SELECT 'walk_in_visits' AS table_name, 'blood_pressure_diastolic' AS column_name
 UNION ALL
 SELECT 'walk_in_visits' AS table_name, 'heart_rate' AS column_name
 UNION ALL
 SELECT 'walk_in_visits' AS table_name, 'respiratory_rate' AS column_name
 UNION ALL
 SELECT 'walk_in_visits' AS table_name, 'oxygen_saturation' AS column_name
 UNION ALL
 SELECT 'walk_in_visits' AS table_name, 'height_cm' AS column_name
 UNION ALL
 SELECT 'walk_in_visits' AS table_name, 'weight_kg' AS column_name
 UNION ALL
 SELECT 'consultations' AS table_name, 'id' AS column_name
 UNION ALL
 SELECT 'consultations' AS table_name, 'nurse_id' AS column_name
 UNION ALL
 SELECT 'consultations' AS table_name, 'visit_type' AS column_name
 UNION ALL
 SELECT 'consultations' AS table_name, 'student_id' AS column_name
 UNION ALL
 SELECT 'consultations' AS table_name, 'teacher_id' AS column_name
 UNION ALL
 SELECT 'consultations' AS table_name, 'patient_type' AS column_name
 UNION ALL
 SELECT 'consultations' AS table_name, 'clinician_user_id' AS column_name
 UNION ALL
 SELECT 'consultations' AS table_name, 'record_version' AS column_name
 UNION ALL
 SELECT 'consultations' AS table_name, 'consultation_date' AS column_name
 UNION ALL
 SELECT 'consultations' AS table_name, 'chief_complaint' AS column_name
 UNION ALL
 SELECT 'consultations' AS table_name, 'diagnosis' AS column_name
 UNION ALL
 SELECT 'consultations' AS table_name, 'treatment' AS column_name
 UNION ALL
 SELECT 'consultations' AS table_name, 'prescription' AS column_name
 UNION ALL
 SELECT 'consultations' AS table_name, 'remarks' AS column_name
 UNION ALL
 SELECT 'consultations' AS table_name, 'follow_up_date' AS column_name
 UNION ALL
 SELECT 'consultations' AS table_name, 'vital_signs' AS column_name
 UNION ALL
 SELECT 'activity_logs' AS table_name, 'user_id' AS column_name
 UNION ALL
 SELECT 'activity_logs' AS table_name, 'action' AS column_name
 UNION ALL
 SELECT 'activity_logs' AS table_name, 'module' AS column_name
 UNION ALL
 SELECT 'activity_logs' AS table_name, 'details' AS column_name
) required_columns LEFT JOIN information_schema.COLUMNS actual ON actual.TABLE_SCHEMA=DATABASE() AND BINARY actual.TABLE_NAME=BINARY required_columns.table_name AND BINARY actual.COLUMN_NAME=BINARY required_columns.column_name WHERE actual.COLUMN_NAME IS NULL;
SELECT TABLE_NAME,ENGINE,TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('users','admin_profiles','nurses','students','teachers','queue_numbers','walk_in_visits','consultations','activity_logs');
SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='physician_queue' ORDER BY ORDINAL_POSITION;
-- No physician_queue columns means the additive migration is required.
-- prerequisites_ready=0: STOP. Resolve named prerequisites, engines and signed INT primary keys.
