<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Operator CLI only.'); }
ini_set('session.save_path', __DIR__ . '/../scratch');
require_once __DIR__ . '/../config/config.php';

$db = getDB();
$sql = file_get_contents(__DIR__ . '/../database/migration_doctor_invitations.sql');
if ($sql === false) throw new RuntimeException('Migration file is unavailable.');
foreach (array_filter(array_map('trim', preg_split('/;\s*(?:\r?\n|$)/', $sql))) as $statement) {
    $lines = preg_split('/\R/', $statement);
    $statement = trim(implode("\n", array_filter($lines, static fn($line) => !str_starts_with(ltrim($line), '--'))));
    if ($statement !== '') $db->exec($statement);
}
echo "Doctor invitation migration applied successfully.\n";
