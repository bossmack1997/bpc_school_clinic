-- Additive MIS-provisioned Student, Teacher and restricted Nurse accounts.
-- Existing Doctor invitations remain a separate privileged workflow.
CREATE TABLE IF NOT EXISTS account_activation_invitations (
 id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
 user_id INT NOT NULL,
 token_hash CHAR(64) NOT NULL,
 created_by INT NOT NULL,
 expires_at DATETIME NOT NULL,
 used_at DATETIME NULL,
 delivery_status ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_account_activation_hash(token_hash),
 KEY idx_account_activation_user(user_id,created_at),
 FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE RESTRICT,
 FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS account_provisioning_lock (
 id INT NOT NULL PRIMARY KEY
) ENGINE=InnoDB;
INSERT IGNORE INTO account_provisioning_lock(id) VALUES (1);
