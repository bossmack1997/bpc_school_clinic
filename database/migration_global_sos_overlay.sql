-- Screen notification receipts are separate from emergency response status.
CREATE TABLE IF NOT EXISTS sos_alert_acknowledgments (
    user_id INT NOT NULL,
    emergency_id INT NOT NULL,
    acknowledged_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, emergency_id),
    KEY idx_sos_alert_emergency (emergency_id),
    CONSTRAINT fk_sos_alert_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_sos_alert_emergency FOREIGN KEY (emergency_id) REFERENCES sos_emergencies(id) ON DELETE CASCADE
) ENGINE=InnoDB;
