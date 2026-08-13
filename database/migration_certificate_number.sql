-- Add certificate_number to certificates table
-- Safe migration: adds column with unique constraint

ALTER TABLE certificates
  ADD COLUMN IF NOT EXISTS certificate_number VARCHAR(30) DEFAULT NULL AFTER id,
  ADD UNIQUE KEY IF NOT EXISTS uq_certificate_number (certificate_number);

-- Backfill existing certificates with generated numbers
-- Format: MC-YYYY-NNNNN
SET @num = 0;
UPDATE certificates c
JOIN (
  SELECT id, @num := @num + 1 AS seq
  FROM certificates
  ORDER BY date_issued ASC, id ASC
) x ON x.id = c.id
SET c.certificate_number = CONCAT('MC-', YEAR(c.date_issued), '-', LPAD(x.seq, 5, '0'))
WHERE c.certificate_number IS NULL;
