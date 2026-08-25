<?php
require 'config/database.php';
$pdo = getDB();

echo "=== SECTIONS TABLE ===\n";
$stmt = $pdo->query('DESCRIBE sections');
foreach($stmt->fetchAll() as $r) echo implode(' | ', $r) . "\n";

echo "\n=== SECTIONS DATA ===\n";
$stmt = $pdo->query('SELECT * FROM sections ORDER BY id');
foreach($stmt->fetchAll() as $r) echo json_encode($r) . "\n";

echo "\n=== ADVISORY ASSIGNMENTS ===\n";
$stmt = $pdo->query('SELECT * FROM advisory_assignments ORDER BY id');
foreach($stmt->fetchAll() as $r) echo json_encode($r) . "\n";

echo "\n=== BSIS STUDENTS ===\n";
$stmt = $pdo->query("SELECT s.id, s.course, s.year_level, s.section, u.first_name, u.last_name FROM students s JOIN users u ON u.id=s.user_id WHERE s.course LIKE '%BSIS%' ORDER BY s.year_level, s.section");
foreach($stmt->fetchAll() as $r) echo json_encode($r) . "\n";

echo "\n=== SECTION VALUE COUNTS ===\n";
$stmt = $pdo->query("SELECT s.section, s.year_level, s.course, COUNT(*) as cnt FROM students s GROUP BY s.section, s.year_level, s.course ORDER BY cnt DESC LIMIT 30");
foreach($stmt->fetchAll() as $r) echo json_encode($r) . "\n";

echo "\n=== TEACHERS ===\n";
$stmt = $pdo->query('SELECT t.*, u.first_name, u.last_name FROM teachers t JOIN users u ON u.id=t.user_id');
foreach($stmt->fetchAll() as $r) echo json_encode($r) . "\n";

echo "\n=== ADVISERS ===\n";
$stmt = $pdo->query('SELECT id, role, username, email, first_name, last_name FROM users WHERE role="adviser"');
foreach($stmt->fetchAll() as $r) echo json_encode($r) . "\n";

echo "\n=== COURSES TABLE ===\n";
$stmt = $pdo->query('SELECT * FROM courses ORDER BY id');
foreach($stmt->fetchAll() as $r) echo json_encode($r) . "\n";