<?php
/**
 * Nurse credential correction/resubmission workflow tests.
 *
 * SAFETY: runs only against an explicitly supplied disposable database and never getDB():
 *   NURSE_TEST_DSN="mysql:host=127.0.0.1;port=3399;charset=utf8mb4" NURSE_TEST_USER=root NURSE_TEST_PASS= php tests/nurse_credentials_workflow.php
 * A throw-away database is created and dropped. Files it stores in storage/registration_documents are removed.
 */
$dsn = getenv('NURSE_TEST_DSN');
if (!$dsn) { fwrite(STDERR, "SKIP: set NURSE_TEST_DSN to a disposable server (never production).\n"); exit(2); }
define('NURSE_CREDENTIAL_TEST_MODE', true);
// config.php is deliberately NOT loaded: it would open the application's configured database.
define('BASE_PATH', dirname(__DIR__));
if (!function_exists('app_url')) { function app_url($p = '') { return '/' . ltrim($p, '/'); } }
require_once __DIR__ . '/../includes/nurse_credentials_service.php';

$root = new PDO($dsn, getenv('NURSE_TEST_USER') ?: 'root', getenv('NURSE_TEST_PASS') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$name = 'bpc_nurse_cred_test_' . bin2hex(random_bytes(4));
$root->exec("CREATE DATABASE `$name`"); $root->exec("USE `$name`");
$db = $root;
$failures = 0; $files = [];
function check(string $n, bool $ok): void { global $failures; echo ($ok ? 'PASS ' : 'FAIL ') . $n . PHP_EOL; if (!$ok) $failures++; }
function throws(callable $f, string $needle = ''): bool { try { $f(); return false; } catch (Throwable $e) { return $needle === '' || stripos($e->getMessage(), $needle) !== false; } }

$db->exec("CREATE TABLE users (id INT AUTO_INCREMENT PRIMARY KEY, role VARCHAR(20), status VARCHAR(20) DEFAULT 'active', email_verified TINYINT DEFAULT 1, self_service_only TINYINT DEFAULT 1, credential_version INT DEFAULT 0)");
$db->exec("CREATE TABLE nurses (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT, employee_id VARCHAR(100), license_number VARCHAR(100), position VARCHAR(120), valid_id_path VARCHAR(255), clinical_authorization_status VARCHAR(30) DEFAULT 'pending', clinical_review_notes TEXT, clinical_reviewed_at DATETIME NULL)");
$db->exec("CREATE TABLE activity_logs (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT, action VARCHAR(100), module VARCHAR(100), details TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
$db->exec("CREATE TABLE notifications (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT, type VARCHAR(50), title VARCHAR(255), message TEXT, link VARCHAR(255))");
$db->exec("CREATE TABLE admin_profiles (user_id INT, profile VARCHAR(30))");
foreach (array_filter(array_map('trim', explode(';', preg_replace('/^--.*$/m', '', file_get_contents(__DIR__ . '/../database/migration_nurse_credential_correction_20261024.sql'))))) as $stmt) $db->exec($stmt);

function mk(PDO $db, array $u = [], array $n = []): array {
    $u += ['role' => 'nurse', 'status' => 'active', 'email_verified' => 1, 'self_service_only' => 1];
    $db->prepare('INSERT INTO users(role,status,email_verified,self_service_only) VALUES (?,?,?,?)')->execute([$u['role'], $u['status'], $u['email_verified'], $u['self_service_only']]);
    $id = (int)$db->lastInsertId();
    $n += ['employee_id' => 'E' . $id, 'license_number' => null, 'position' => null, 'valid_id_path' => null, 'clinical_authorization_status' => 'pending'];
    $db->prepare('INSERT INTO nurses(user_id,employee_id,license_number,position,valid_id_path,clinical_authorization_status) VALUES (?,?,?,?,?,?)')->execute([$id, $n['employee_id'], $n['license_number'], $n['position'], $n['valid_id_path'], $n['clinical_authorization_status']]);
    return ['id' => $id, 'role' => $u['role']];
}
function row(PDO $db, int $id): array { return $db->query("SELECT u.self_service_only,u.credential_version,n.* FROM users u JOIN nurses n ON n.user_id=u.id WHERE u.id=$id")->fetch(); }
function upload(string $bytes, string $name = 'x.pdf', ?int $size = null): array {
    $t = tempnam(sys_get_temp_dir(), 'ncr'); file_put_contents($t, $bytes);
    return ['name' => $name, 'tmp_name' => $t, 'error' => UPLOAD_ERR_OK, 'size' => $size ?? strlen($bytes)];
}
$pdf = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF";
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
$good = ['license_number' => '0123456', 'employee_id' => 'EMP-100', 'position' => 'School Nurse I'];

// ---- upload security
check('valid PDF accepted', NurseCredentialsService::inspectUpload(upload($pdf))['ext'] === 'pdf');
check('valid PNG accepted', NurseCredentialsService::inspectUpload(upload($png, 'a.png'))['ext'] === 'png');
check('text renamed .pdf rejected', throws(fn() => NurseCredentialsService::inspectUpload(upload('plain text', 'evil.pdf')), 'Only PDF'));
check('PHP script renamed .jpg rejected', throws(fn() => NurseCredentialsService::inspectUpload(upload('<?php echo 1;', 'shell.jpg')), 'Only PDF'));
check('HTML/SVG rejected', throws(fn() => NurseCredentialsService::inspectUpload(upload('<svg xmlns="http://www.w3.org/2000/svg"/>', 'a.png')), 'Only PDF'));
check('WebP-like/unsupported rejected', throws(fn() => NurseCredentialsService::inspectUpload(upload("RIFF\0\0\0\0WEBPVP8 ", 'a.webp')), 'Only PDF'));
check('oversize rejected', throws(fn() => NurseCredentialsService::inspectUpload(upload($pdf, 'big.pdf', 5242881)), '5 MB'));
check('empty file rejected', throws(fn() => NurseCredentialsService::inspectUpload(upload('', 'e.pdf')), '5 MB'));
check('upload error rejected', throws(fn() => NurseCredentialsService::inspectUpload(['error' => UPLOAD_ERR_INI_SIZE, 'size' => 1, 'tmp_name' => ''])));
check('fake PNG header (not an image) rejected', throws(fn() => NurseCredentialsService::inspectUpload(upload("\x89PNG\r\n\x1a\nGARBAGE", 'a.png'))));
$stored = NurseCredentialsService::storeUpload(upload($pdf, '../../evil name.php')); $files[] = $stored;
check('stored name is server-generated, in protected storage, with safe extension', (bool)preg_match('#^storage/registration_documents/credential_[a-f0-9]{32}\.pdf$#', $stored) && is_file(BASE_PATH . '/' . $stored));
check('client filename never used', !str_contains($stored, 'evil'));

// ---- validation rules
check('PRC: no fixed length (short)', NurseCredentialsService::validateLicense('7') === null);
check('PRC: no fixed length (long, hyphenated)', NurseCredentialsService::validateLicense('RN-0001234567890') === null);
check('PRC: rejects empty', NurseCredentialsService::validateLicense('  ') !== null);
check('PRC: rejects injection characters', NurseCredentialsService::validateLicense("123'; DROP") !== null);
check('PRC: rejects no digit', NurseCredentialsService::validateLicense('ABCDEF') !== null);
check('Employee ID: no pattern configured accepts safe value', NurseCredentialsService::validateEmployeeId('X-9') === null);
putenv('NURSE_EMPLOYEE_ID_PATTERN=BPC-\d{4}');
check('Employee ID: configured pattern enforced', NurseCredentialsService::validateEmployeeId('X-9') !== null && NurseCredentialsService::validateEmployeeId('BPC-1234') === null);
check('Employee ID: unchanged legacy value not retroactively rejected', NurseCredentialsService::validateEmployeeId('LEGACY7', 'LEGACY7') === null);
putenv('NURSE_EMPLOYEE_ID_PATTERN');

// ---- draft
$authz = mk($db, ['self_service_only' => 0], ['license_number' => 'OLD123', 'position' => 'Old Pos', 'employee_id' => 'EMPOLD', 'valid_id_path' => 'storage/registration_documents/old_doc.pdf', 'clinical_authorization_status' => 'authorized']);
file_put_contents(BASE_PATH . '/storage/registration_documents/old_doc.pdf', $pdf); $files[] = 'storage/registration_documents/old_doc.pdf';
$svc = new NurseCredentialsService($db, $authz);
check('schema ready', $svc->ready());
$svc->saveDraft(['license_number' => 'NEW999', 'employee_id' => '', 'position' => ''], null);
$r = row($db, $authz['id']);
check('draft does not alter live credentials', $r['license_number'] === 'OLD123' && $r['position'] === 'Old Pos');
check('draft does not alter status or access', $r['clinical_authorization_status'] === 'authorized' && (int)$r['self_service_only'] === 0);
check('incomplete draft allowed and creates no submission/notification', (int)$db->query('SELECT COUNT(*) FROM nurse_credential_submissions')->fetchColumn() === 0 && (int)$db->query('SELECT COUNT(*) FROM notifications')->fetchColumn() === 0);
check('draft prefill preserved in load()', $svc->load()['draft']['license_number'] === 'NEW999');
check('draft with invalid field rejected', throws(fn() => $svc->saveDraft(['license_number' => '<<', 'employee_id' => '', 'position' => ''])));

// ---- submit validation / eligibility
$doc = mk($db, [], []); $db->exec("INSERT INTO admin_profiles VALUES (999,'doctor')"); $db->exec("INSERT INTO users(id,role,status) VALUES (999,'admin','active') ON DUPLICATE KEY UPDATE status='active'");
$svc2 = new NurseCredentialsService($db, $doc);
check('submit without document rejected', throws(fn() => $svc2->submit($good, null), 'Upload'));
check('submit with incomplete fields rejected', throws(fn() => $svc2->submit(['license_number' => '', 'employee_id' => '', 'position' => ''], null)));
check('failed submit changed nothing', row($db, $doc['id'])['license_number'] === null && (int)$db->query('SELECT COUNT(*) FROM nurse_credential_submissions')->fetchColumn() === 0);
$unv = mk($db, ['email_verified' => 0]); check('unverified email cannot submit (OTP not bypassed)', throws(fn() => (new NurseCredentialsService($db, $unv))->submit($good, $GLOBALS['files'][] = NurseCredentialsService::storeUpload(upload($pdf))), 'Verify your email'));
$ina = mk($db, ['status' => 'inactive']); check('inactive account cannot submit', throws(fn() => (new NurseCredentialsService($db, $ina))->submit($good, 'x'), 'inactive'));
$rev = mk($db, [], ['clinical_authorization_status' => 'revoked']); check('revoked nurse cannot resubmit', throws(fn() => (new NurseCredentialsService($db, $rev))->submit($good, 'x'), 'revoked'));
check('non-nurse actor rejected', throws(fn() => (new NurseCredentialsService($db, ['id' => $doc['id'], 'role' => 'admin']))->submit($good, null), 'Only the Nurse'));

// ---- successful submit
$p1 = NurseCredentialsService::storeUpload(upload($pdf)); $files[] = $p1;
$res = $svc2->submit($good, $p1);
$r = row($db, $doc['id']);
check('submit stores values and stays pending (never authorized)', $r['clinical_authorization_status'] === 'pending' && $r['license_number'] === '0123456' && $r['valid_id_path'] === $p1 && (int)$r['self_service_only'] === 1);
check('submission audit row written', (int)$db->query("SELECT COUNT(*) FROM nurse_credential_submissions WHERE nurse_user_id={$doc['id']} AND material_change=1")->fetchColumn() === 1);
check('credential_version bumped (stale review forms invalid)', (int)$r['credential_version'] === 1);
check('doctor notified', (int)$db->query("SELECT COUNT(*) FROM notifications WHERE user_id=999 AND type='clinical_review'")->fetchColumn() === 1);
check('activity log written', (int)$db->query("SELECT COUNT(*) FROM activity_logs WHERE user_id={$doc['id']} AND module='nurse_clinical_authorization'")->fetchColumn() === 1);
check('duplicate submission rejected and not double-logged', throws(fn() => $svc2->submit($good, null), 'already submitted') && (int)$db->query('SELECT COUNT(*) FROM nurse_credential_submissions WHERE nurse_user_id=' . $doc['id'])->fetchColumn() === 1);

// ---- needs_information correction + replacement preserves old file
$db->exec("UPDATE nurses SET clinical_authorization_status='needs_information', clinical_review_notes='Upload a clearer PRC ID' WHERE user_id={$doc['id']}");
$p2 = NurseCredentialsService::storeUpload(upload($png, 'b.png')); $files[] = $p2;
$svc2->submit(['license_number' => '0123456', 'employee_id' => 'EMP-100', 'position' => 'School Nurse I'], $p2);
$r = row($db, $doc['id']);
check('needs_information -> pending after correction', $r['clinical_authorization_status'] === 'pending' && $r['valid_id_path'] === $p2);
check('previous document preserved after replacement', is_file(BASE_PATH . '/' . $p1));

// ---- duplicate identifiers
$other = mk($db); $so = new NurseCredentialsService($db, $other);
check('duplicate PRC across accounts rejected', throws(fn() => $so->submit(['license_number' => '0123456', 'employee_id' => 'EMP-OTHER', 'position' => 'P'], $p2), 'already registered'));
check('duplicate Employee ID across accounts rejected', throws(fn() => $so->submit(['license_number' => '777', 'employee_id' => 'EMP-100', 'position' => 'P'], $p2), 'already registered'));

// ---- authorized nurse re-verification
check('authorized: unchanged resubmission rejected', throws(fn() => $svc->submit(['license_number' => 'OLD123', 'employee_id' => 'EMPOLD', 'position' => 'Old Pos'], null), 'No professional details'));
$p3 = NurseCredentialsService::storeUpload(upload($pdf)); $files[] = $p3;
$out = $svc->submit(['license_number' => 'OLD123', 'employee_id' => 'EMPOLD', 'position' => 'Charge Nurse'], $p3);
$r = row($db, $authz['id']);
check('authorized: material change demotes to pending + restricted access', $out['demoted'] && $r['clinical_authorization_status'] === 'pending' && (int)$r['self_service_only'] === 1);
check('authorized: previous document file not deleted', is_file(BASE_PATH . '/storage/registration_documents/old_doc.pdf'));
check('authorized: resubmission audited with previous status', $db->query("SELECT previous_status FROM nurse_credential_submissions WHERE nurse_user_id={$authz['id']}")->fetchColumn() === 'authorized');
check('draft cleared after submission', $svc->load()['draft'] === null);
check('no automatic authorization anywhere', (int)$db->query("SELECT COUNT(*) FROM nurses WHERE clinical_authorization_status='authorized'")->fetchColumn() === 0);

foreach ($files as $f) { $p = BASE_PATH . '/' . $f; if (is_file($p)) @unlink($p); }
$root->exec("DROP DATABASE `$name`");
echo $failures ? "$failures FAILED" . PHP_EOL : 'All checks passed' . PHP_EOL;
exit($failures ? 1 : 0);
