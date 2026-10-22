-- Additive: attach future teacher scans to the actual teacher record.
ALTER TABLE qr_scan_logs ADD COLUMN IF NOT EXISTS teacher_id INT NULL;
CREATE INDEX IF NOT EXISTS idx_qr_history_teacher ON qr_scan_logs (teacher_id, scan_time, id);
CREATE INDEX IF NOT EXISTS idx_qr_history_student ON qr_scan_logs (student_id, scan_time, id);
-- Rollback (does not delete logs):
-- DROP INDEX idx_qr_history_teacher ON qr_scan_logs;
-- DROP INDEX idx_qr_history_student ON qr_scan_logs;
-- ALTER TABLE qr_scan_logs DROP COLUMN teacher_id;
