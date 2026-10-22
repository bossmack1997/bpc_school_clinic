-- BPC School Clinic: four visible roles and safe legacy-role consolidation.
-- Review and back up the database before running. This migration preserves
-- doctor/adviser rows; it does not drop tables or medical records.

-- Preserve legacy doctor employment metadata by creating an equivalent nurse
-- row where one does not already exist. This makes the required Nurse role the
-- single clinical staff role without deleting source data from `doctors`.
INSERT INTO nurses (user_id, employee_id, license_number, contact_number, profile_picture)
SELECT d.user_id, d.employee_id, d.license_number, d.contact_number, d.profile_picture
FROM doctors d
JOIN users u ON u.id = d.user_id
LEFT JOIN nurses n ON n.user_id = d.user_id
WHERE u.role = 'doctor' AND n.id IS NULL;

-- Adviser was the prior internal name for the teacher workflow.
UPDATE users SET role = 'teacher' WHERE role = 'adviser';
UPDATE users SET role = 'nurse' WHERE role = 'doctor';

-- The role enum is made explicit after all legacy values have been converted.
ALTER TABLE users
  MODIFY COLUMN role ENUM('student','nurse','teacher','admin') NOT NULL;

-- Do not add unique indexes blindly: deployments may contain legacy duplicate
-- employee IDs or appointment slots. Review these queries, resolve duplicates,
-- then add the indexes separately:
-- SELECT employee_id, COUNT(*) FROM teachers WHERE employee_id IS NOT NULL
-- GROUP BY employee_id HAVING COUNT(*) > 1;
-- SELECT student_id, appointment_date, appointment_time, COUNT(*) FROM appointments
-- GROUP BY student_id, appointment_date, appointment_time HAVING COUNT(*) > 1;
