<?php
require_once __DIR__ . '/../config/config.php';
$db = getDB();

echo "Step 1.1: Creating missing consultation_medicines table...\n";
$db->exec("
    CREATE TABLE IF NOT EXISTS consultation_medicines (
        id INT AUTO_INCREMENT PRIMARY KEY,
        consultation_id INT NOT NULL,
        medicine_id INT NOT NULL,
        quantity INT NOT NULL,
        dosage_instructions VARCHAR(255) DEFAULT NULL,
        dispensed_by INT DEFAULT NULL,
        dispensed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (consultation_id) REFERENCES consultations(id) ON DELETE CASCADE,
        FOREIGN KEY (medicine_id) REFERENCES medicines(id) ON DELETE RESTRICT,
        FOREIGN KEY (dispensed_by) REFERENCES users(id) ON DELETE SET NULL,
        INDEX idx_consultation_medicines_consultation (consultation_id),
        INDEX idx_consultation_medicines_medicine (medicine_id)
    ) ENGINE=InnoDB;
");
echo "✓ Table consultation_medicines created / verified.\n";

echo "Step 1.2: Adding missing composite indexes for queue and advisory performance...\n";
try {
    $db->exec("CREATE INDEX IF NOT EXISTS idx_queue_patient_status ON queue_numbers (student_id, status, created_at)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_queue_teacher_status ON queue_numbers (teacher_id, status, created_at)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_advisory_teacher_status ON advisory_assignments (teacher_id, status)");
    echo "✓ Indexes added / verified.\n";
} catch (Throwable $e) {
    echo "Notice on index creation: " . $e->getMessage() . "\n";
}

echo "Step 1.3: Fixing User 99 (Nazarette) role to 'teacher' and status to 'active'...\n";
$stmt = $db->prepare("UPDATE users SET role = 'teacher', status = 'active' WHERE id = 99");
$stmt->execute();
echo "✓ User 99 updated to role 'teacher' and status 'active'.\n";

$check = $db->query("SELECT id, username, role, status FROM users WHERE id = 99")->fetch();
echo "User 99: Role = {$check['role']}, Status = {$check['status']}\n";

