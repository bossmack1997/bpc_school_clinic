<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Operator CLI only.'); }
ini_set('session.save_path', __DIR__ . '/../scratch');
require_once __DIR__ . '/../config/config.php';

if(!in_array('--apply',$argv,true)){fwrite(STDERR,"Review Phase 1 preflight first. Usage: php setup/migrate_doctor_invitations.php --apply\n");exit(1);}
$db = getDB();
$sql = file_get_contents(__DIR__ . '/../database/migration_doctor_invitations_hostinger.sql');
if ($sql === false) throw new RuntimeException('Migration file is unavailable.');
foreach (array_filter(array_map('trim', preg_split('/;\s*(?:\r?\n|$)/', $sql))) as $statement) {
    $lines = preg_split('/\R/', $statement);
    $statement = trim(implode("\n", array_filter($lines, static fn($line) => !str_starts_with(ltrim($line), '--'))));
    if ($statement !== '') {$q=$db->query($statement);$q->closeCursor();}
}
if((int)$db->query('SELECT @doctor_complete')->fetchColumn()!==1)throw new RuntimeException('Doctor migration blocked or incomplete. Run preflight and inspect the errors.');
echo "Doctor invitation schema complete. Run separate post-verification.\n";
