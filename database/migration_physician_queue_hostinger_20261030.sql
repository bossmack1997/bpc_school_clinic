-- Guarded, additive, rerunnable. Select and confirm the intended database first.
-- Back up and verify restoration; run the separate preflight before this file.
-- No stored routines, DELIMITER, DEFINER or elevated routine privileges required.
SELECT DATABASE() AS selected_database;
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
SET @physician_ddl = IF(@physician_prerequisites_ready, 'CREATE TABLE IF NOT EXISTS physician_queue (
 id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
 queue_id INT NOT NULL,
 patient_user_id INT NOT NULL,
 referred_by INT NOT NULL,
 assigned_doctor_id INT NULL,
 consultation_id INT NULL,
 priority ENUM(''normal'',''urgent'',''emergency'') NOT NULL DEFAULT ''normal'',
 reason VARCHAR(1000) NOT NULL,
 status ENUM(''awaiting'',''in_progress'',''completed'') NOT NULL DEFAULT ''awaiting'',
 record_version INT NOT NULL DEFAULT 0,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 started_at DATETIME NULL,
 completed_at DATETIME NULL,
 UNIQUE KEY uq_physician_queue_ticket (queue_id),
 UNIQUE KEY uq_physician_queue_consultation (consultation_id),
 KEY idx_physician_queue_status (status,priority,created_at),
 FOREIGN KEY (queue_id) REFERENCES queue_numbers(id) ON DELETE RESTRICT,
 FOREIGN KEY (patient_user_id) REFERENCES users(id) ON DELETE RESTRICT,
 FOREIGN KEY (referred_by) REFERENCES users(id) ON DELETE RESTRICT,
 FOREIGN KEY (assigned_doctor_id) REFERENCES users(id) ON DELETE RESTRICT,
 FOREIGN KEY (consultation_id) REFERENCES consultations(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci', 'SELECT ''BLOCKED: physician queue prerequisites are incomplete, no schema changed'' AS migration_result');
PREPARE physician_queue_statement FROM @physician_ddl;
EXECUTE physician_queue_statement;
DEALLOCATE PREPARE physician_queue_statement;
SELECT @physician_prerequisites_ready AS prerequisites_ready;
-- IF NOT EXISTS preserves existing handoffs; it never repairs incompatible definitions.
-- Run the separate post-migration verification and inspect every result.
