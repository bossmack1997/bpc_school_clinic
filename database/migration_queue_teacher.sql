-- queue_numbers: support Teacher/Staff patients (additive)
ALTER TABLE queue_numbers
  MODIFY COLUMN student_id INT NULL,
  ADD COLUMN IF NOT EXISTS patient_type ENUM('student','teacher') NOT NULL DEFAULT 'student',
  ADD COLUMN IF NOT EXISTS teacher_id INT NULL,
  ADD CONSTRAINT fk_queue_teacher FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE SET NULL;
