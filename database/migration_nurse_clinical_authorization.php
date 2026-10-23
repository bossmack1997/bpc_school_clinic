<?php
/**
 * Local migration runner for Nurse Clinical Authorization schema.
 * Usage: php database/migration_nurse_clinical_authorization.php --apply
 */
require_once __DIR__ . '/../config/config.php';

if (!in_array('--apply', $argv, true)) {
    fwrite(STDERR, "Usage: php database/migration_nurse_clinical_authorization.php --apply\n");
    exit(1);
}

$db = getDB();
echo "Applying Nurse Clinical Authorization schema migration...\n";

// 1. Add columns to nurses table
$cols = [
    'clinical_authorization_status' => "ALTER TABLE `nurses` ADD COLUMN `clinical_authorization_status` VARCHAR(30) NOT NULL DEFAULT 'pending' AFTER `shift_schedule`",
    'clinical_reviewed_by' => "ALTER TABLE `nurses` ADD COLUMN `clinical_reviewed_by` INT(11) NULL DEFAULT NULL AFTER `clinical_authorization_status`",
    'clinical_reviewed_at' => "ALTER TABLE `nurses` ADD COLUMN `clinical_reviewed_at` DATETIME NULL DEFAULT NULL AFTER `clinical_reviewed_by`",
    'clinical_review_notes' => "ALTER TABLE `nurses` ADD COLUMN `clinical_review_notes` TEXT NULL DEFAULT NULL AFTER `clinical_reviewed_at`",
];

$existingCols = [];
$q = $db->query("SHOW COLUMNS FROM `nurses`");
while ($row = $q->fetch(PDO::FETCH_ASSOC)) {
    $existingCols[] = $row['Field'];
}

foreach ($cols as $col => $sql) {
    if (!in_array($col, $existingCols, true)) {
        $db->exec($sql);
        echo "  + Added column `nurses`.`$col`\n";
    } else {
        echo "  = Column `nurses`.`$col` already exists\n";
    }
}

// 2. Create nurse_clinical_reviews table
$db->exec("CREATE TABLE IF NOT EXISTS `nurse_clinical_reviews` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `nurse_user_id` INT(11) NOT NULL,
  `doctor_user_id` INT(11) NOT NULL,
  `action` ENUM('authorize','request_info','reject','revoke') NOT NULL,
  `previous_status` VARCHAR(30) NOT NULL,
  `new_status` VARCHAR(30) NOT NULL,
  `notes` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ncr_nurse` (`nurse_user_id`),
  KEY `idx_ncr_doctor` (`doctor_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
echo "  + Ensured table `nurse_clinical_reviews` exists\n";

// 3. Sync existing nurses
$db->exec("UPDATE `nurses` n
JOIN `users` u ON u.id = n.user_id
SET n.clinical_authorization_status = IF(COALESCE(u.self_service_only, 0) = 0, 'authorized', 'pending')
WHERE n.clinical_authorization_status IS NULL OR n.clinical_authorization_status = 'pending'");
echo "  + Synchronized initial authorization status for existing nurse records\n";

echo "Migration complete.\n";
