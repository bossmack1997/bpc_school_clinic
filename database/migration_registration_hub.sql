-- =====================================================================
-- BPC Clinic — Registration Hub + Teacher Self-Managed Advisory
-- Preserves all existing data. Additive changes only.
--
-- 1) teacher_registration_requests.requested_role
--    Lets ONE request table serve both Teacher (adviser) and Guidance
--    Counselor self-registrations without a duplicate table.
--    Existing rows default to 'adviser' so history stays valid.
-- 2) teachers.middle_name — mirrors users.middle_name for staff records.
-- 3) advisory_assignments.self_managed — audit trail distinguishing
--    assignments the TEACHER set themselves vs admin-approved requests.
-- =====================================================================

-- 1. Request table: which role was requested (adviser = Teacher)
ALTER TABLE teacher_registration_requests
  ADD COLUMN IF NOT EXISTS requested_role ENUM('adviser','guidance') NOT NULL DEFAULT 'adviser'
  AFTER user_id;

-- Existing rows were all teacher registrations.
UPDATE teacher_registration_requests SET requested_role = 'adviser' WHERE requested_role IS NULL;

-- 2. Teachers patient record: middle name (mirrors users.middle_name)
ALTER TABLE teachers
  ADD COLUMN IF NOT EXISTS middle_name VARCHAR(80) DEFAULT NULL AFTER first_name;

-- 3. Advisory assignments: mark self-managed assignments (teacher-set)
ALTER TABLE advisory_assignments
  ADD COLUMN IF NOT EXISTS self_managed TINYINT(1) NOT NULL DEFAULT 0;

-- Backfill: existing rows came from either admin assignment or approval;
-- they are NOT teacher self-managed, so default 0 is correct.

-- 4. Helpful index for the admin pending-requests list
-- (safe to run repeatedly; ignore duplicate-key errors if already present)
-- CREATE INDEX IF NOT EXISTS is not supported on older MariaDB for
-- secondary indexes via ALTER, so this is intentionally omitted.
