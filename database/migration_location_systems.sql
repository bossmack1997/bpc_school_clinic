-- ================================================================
-- BPC CLINIC SYSTEM: DUAL LOCATION SYSTEMS MIGRATION
-- Supports Student GPS Location Tracking & Adviser Manual Location
-- ================================================================

ALTER TABLE sos_emergencies
    ADD COLUMN IF NOT EXISTS location VARCHAR(255) NULL AFTER emergency_type,
    ADD COLUMN IF NOT EXISTS location_source ENUM('gps','manual') DEFAULT 'gps' AFTER location,
    ADD COLUMN IF NOT EXISTS location_updated_at DATETIME NULL AFTER location_accuracy;

CREATE TABLE IF NOT EXISTS emergency_location_updates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    emergency_id INT NOT NULL,
    latitude DECIMAL(10, 7) NOT NULL,
    longitude DECIMAL(10, 7) NOT NULL,
    accuracy VARCHAR(50) NULL,
    recorded_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_emg_loc (emergency_id, recorded_at),
    CONSTRAINT fk_emg_loc_parent FOREIGN KEY (emergency_id) REFERENCES sos_emergencies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

