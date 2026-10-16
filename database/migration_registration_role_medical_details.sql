-- Run once on the existing BPC clinic database before deploying this registration update.
-- Adds profile fields required for teacher emergency/medical details and nurse position/emergency contact.
ALTER TABLE teachers
    ADD COLUMN emergency_contact_name VARCHAR(120) DEFAULT NULL AFTER phone,
    ADD COLUMN emergency_contact_number VARCHAR(30) DEFAULT NULL AFTER emergency_contact_name,
    ADD COLUMN blood_type VARCHAR(5) DEFAULT NULL AFTER emergency_contact_number,
    ADD COLUMN allergies TEXT DEFAULT NULL AFTER blood_type;

ALTER TABLE nurses
    ADD COLUMN position VARCHAR(120) DEFAULT NULL AFTER license_number,
    ADD COLUMN emergency_contact_name VARCHAR(120) DEFAULT NULL AFTER position,
    ADD COLUMN emergency_contact_number VARCHAR(30) DEFAULT NULL AFTER emergency_contact_name;
