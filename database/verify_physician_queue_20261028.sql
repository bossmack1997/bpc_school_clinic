-- Read-only post-migration checks in the explicitly selected application database.
SELECT DATABASE() AS selected_database, VERSION() AS server_version;
SHOW CREATE TABLE physician_queue;
SELECT status,priority,COUNT(*) AS handoffs FROM physician_queue GROUP BY status,priority;
SELECT p.id FROM physician_queue p
LEFT JOIN queue_numbers q ON q.id=p.queue_id
LEFT JOIN users u ON u.id=p.patient_user_id
LEFT JOIN users n ON n.id=p.referred_by
LEFT JOIN consultations c ON c.id=p.consultation_id
WHERE q.id IS NULL OR u.id IS NULL OR n.id IS NULL
 OR (p.status='completed' AND (c.id IS NULL OR p.completed_at IS NULL))
 OR (p.status='in_progress' AND (p.assigned_doctor_id IS NULL OR p.started_at IS NULL));
SELECT queue_id,COUNT(*) FROM physician_queue GROUP BY queue_id HAVING COUNT(*)>1;
