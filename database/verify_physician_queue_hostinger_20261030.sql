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

-- Expect InnoDB, the two unique links, priority/status index and five restrictive FKs.
SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='physician_queue';
SHOW INDEX FROM physician_queue;
SELECT k.COLUMN_NAME,k.REFERENCED_TABLE_NAME,k.REFERENCED_COLUMN_NAME,r.DELETE_RULE
FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r
 ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME AND r.TABLE_NAME=k.TABLE_NAME
WHERE k.TABLE_SCHEMA=DATABASE() AND k.TABLE_NAME='physician_queue' AND k.REFERENCED_TABLE_NAME IS NOT NULL;
-- Expect no rows: missing assigned Doctor or invalid referral/encounter lifecycle.
SELECT p.id FROM physician_queue p LEFT JOIN users d ON d.id=p.assigned_doctor_id
WHERE (p.assigned_doctor_id IS NOT NULL AND d.id IS NULL)
 OR (p.status='awaiting' AND (p.assigned_doctor_id IS NOT NULL OR p.consultation_id IS NOT NULL))
 OR (p.status='completed' AND p.assigned_doctor_id IS NULL);
SELECT consultation_id,COUNT(*) FROM physician_queue WHERE consultation_id IS NOT NULL GROUP BY consultation_id HAVING COUNT(*)>1;
