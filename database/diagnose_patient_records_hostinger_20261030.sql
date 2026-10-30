-- READ ONLY. Run statements separately: retain failed probe name and SQLSTATE/driver.
-- LIMIT 0 resolves column dependencies without returning patient rows.
-- No migration, temporary table, clinical write, token or patient output.
SELECT DATABASE() AS selected_database, VERSION() AS server_version;
-- patient_directory (both joins are required even for a Student search)
SELECT u.id,u.first_name,u.last_name,u.role,s.student_number,t.employee_id
FROM u962995739_bpc_clinic.users u
LEFT JOIN u962995739_bpc_clinic.students s ON s.user_id=u.id AND u.role='student'
LEFT JOIN u962995739_bpc_clinic.teachers t ON t.user_id=u.id AND u.role='teacher'
WHERE u.role IN ('student','teacher')
 AND (CONCAT(u.first_name,' ',u.last_name) LIKE '%__readiness_probe__%'
 OR COALESCE(s.student_number,t.employee_id) LIKE '%__readiness_probe__%')
 AND COALESCE(s.student_number,t.employee_id,'') LIKE '%'
ORDER BY u.last_name,u.id LIMIT 0;
-- patient_context
SELECT u.id,u.role,u.first_name,u.last_name,s.id AS student_id,t.id AS teacher_id,
 COALESCE(s.student_number,t.employee_id) AS identifier,
 COALESCE(s.blood_type,t.blood_type) AS blood_type,COALESCE(s.allergies,t.allergies) AS allergies
FROM u962995739_bpc_clinic.users u
LEFT JOIN u962995739_bpc_clinic.students s ON s.user_id=u.id AND u.role='student'
LEFT JOIN u962995739_bpc_clinic.teachers t ON t.user_id=u.id AND u.role='teacher'
WHERE u.id=0 AND u.role IN ('student','teacher') LIMIT 0;
-- consultation_history (both patient foreign-key columns)
SELECT id,student_id,teacher_id,consultation_date,chief_complaint,diagnosis,treatment,
 prescription,remarks,vital_signs,follow_up_date,clinician_user_id,nurse_id,record_version
FROM u962995739_bpc_clinic.consultations ORDER BY consultation_date DESC,id DESC LIMIT 0;
-- clinical_documents
SELECT id,student_id,teacher_id,consultation_id,file_type,description,uploaded_at,file_path
FROM u962995739_bpc_clinic.medical_records ORDER BY uploaded_at DESC,id DESC LIMIT 0;
