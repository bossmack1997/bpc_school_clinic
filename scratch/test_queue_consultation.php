<?php
/** Integration checks use real SQL and always roll back every test record. */
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../includes/queue_consultation.php';
define('APP_URL', 'http://localhost/bpc_clinic_system');
class QueueTestPDO extends PDO {
    public function beginTransaction(): bool { return $this->inTransaction() || parent::beginTransaction(); }
    public function commit(): bool { return true; } // Never persist fixture records.
    public function rollBack(): bool { $this->exec('ROLLBACK TO SAVEPOINT queue_action'); return true; }
    public function finishRollback(): bool { return parent::rollBack(); }
}
class QueueTestResponse extends Exception {
    public function __construct(public bool $success, public array $data, public string $notice) { parent::__construct($notice); }
}
function q_json($success, $data = [], $message = '') { throw new QueueTestResponse($success, $data, $message); }
function json_response($success, $data = [], $message = '') { q_json($success, $data, $message); }
function generateVisitNumber($db) { return 'QA-' . bin2hex(random_bytes(6)); }
function generate_queue_number($db) { return 'QA-' . bin2hex(random_bytes(4)); }
function calculate_queue_wait_time(...$args) { return 10; }
function logVisitAction($db, $visitId, $action, $details, $userId) {
    $db->prepare('INSERT INTO visit_logs (walk_in_id, action, details, performed_by) VALUES (?,?,?,?)')->execute([$visitId, $action, $details, $userId]);
}
function csrf_verify_or_die() { if (($_POST['csrf_token'] ?? '') !== 'test-token') throw new DomainException('Invalid CSRF token.'); }
function log_activity(...$args) {}
function push_notification(...$args) {}
function update_medicine_status(...$args) {}
function safe_api_error($error, ...$args) { return $error->getMessage(); }
function check($condition, $message) { if (!$condition) throw new RuntimeException($message); }
$db = new QueueTestPDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
$source = file_get_contents(__DIR__ . '/../api/queue.php');
$walkinSource = file_get_contents(__DIR__ . '/../api/walkin.php');
$user = $db->query("SELECT u.id, u.role FROM users u JOIN nurses n ON n.user_id=u.id WHERE u.role='nurse' LIMIT 1")->fetch();
$student = $db->query('SELECT id FROM students LIMIT 1')->fetchColumn();
check($user && $student, 'Nurse and student fixtures require existing references.');
$_SESSION = [];
$db->beginTransaction();
try {
    $db->prepare("INSERT INTO queue_numbers (queue_number, student_id, visit_type, priority, status, patient_type, created_at) VALUES (?, ?, 'walk-in', 'normal', 'waiting', 'student', NOW())")
        ->execute(['QA-' . bin2hex(random_bytes(4)), $student]);
    $ticketId = (int)$db->lastInsertId();
    $run = function ($action, $post = []) use (&$db, &$user, $source, $walkinSource, $ticketId) {
        if (in_array($action, ['save_visit', 'search_students', 'get_visit', 'generate_ai_summary', 'dispense_medicine'], true)) $source = $walkinSource;
        $_POST = array_merge(['csrf_token' => 'test-token', 'ticket_id' => $ticketId], $post);
        $_GET = ['ticket_id' => $ticketId, 'id' => $post['id'] ?? 0, 'q' => 'QA-no-match'];
        $start = strpos($source, "if (\$action === '$action')");
        check($start !== false, 'Action exists: ' . $action);
        $end = strpos($source, "\n// ===", $start);
        if ($end === false) $end = strpos($source, '// Fallthrough', $start);
        $code = substr($source, $start, $end - $start);
        $code = preg_replace('/catch \((Throwable|Exception) \$e\) \{/', '$0 if ($e instanceof QueueTestResponse) throw $e;', $code);
        $db->exec('SAVEPOINT queue_action');
        try { eval($code); } catch (QueueTestResponse $result) { return $result; }
        throw new RuntimeException('No API response: ' . $action);
    };
    $originalUser = $user;
    $guardStart = strpos($source, "if (in_array(\$action, ['start_serving'");
    $guardEnd = strpos($source, "\n// ===", $guardStart);
    foreach (['student', 'admin', 'adviser'] as $role) {
        $user['role'] = $role; $action = 'save_consultation_draft';
        try { eval(substr($source, $guardStart, $guardEnd - $guardStart)); throw new RuntimeException('Role guard failed'); }
        catch (QueueTestResponse $response) { check(!$response->success, 'Nurse-only consultation guard'); }
    }
    $user = $originalUser;
    try { $run('start_serving', ['csrf_token' => 'invalid']); throw new RuntimeException('CSRF guard failed'); }
    catch (DomainException $error) { check(str_contains($error->getMessage(), 'CSRF'), 'Invalid CSRF rejected'); }
    $guest = $run('save_visit', ['temp_full_name' => 'QA rollback-only visitor', 'temp_contact' => 'QA', 'temp_course' => 'QA course',
        'temp_year_level' => '1st Year', 'chief_complaint' => 'QA triage', 'respiratory_rate' => '16', 'assessment' => 'QA initial assessment']);
    check($guest->success, 'Original detailed walk-in creation: ' . $guest->notice);
    check((int)$db->query('SELECT is_temp_visitor FROM walk_in_visits WHERE id=' . (int)$guest->data['walk_in_id'])->fetchColumn() === 1, 'Visitor category retained in original visitor field');
    $duplicate = $run('save_visit', ['student_id' => $student, 'chief_complaint' => 'QA duplicate']);
    check(!$duplicate->success, 'Existing active patient cannot receive duplicate walk-in ticket');
    $summary = $run('get_patient_medical_summary');
    check($summary->success && (int)$summary->data['patient']['id'] === $ticketId, 'Correct patient summary and schema joins');
    $started = $run('start_serving');
    check($started->success, 'Start succeeds: ' . $started->notice);
    $startedAgain = $run('start_serving');
    check($startedAgain->success && $startedAgain->data['walk_in_id'] === $started->data['walk_in_id'], 'Repeated start reuses same visit');
    $summary = $run('get_patient_medical_summary');
    $version = $summary->data['draft_version'];
    $fields = ['chief_complaint' => 'QA rollback-only complaint', 'symptoms' => 'QA symptoms', 'assessment' => 'QA assessment',
        'diagnosis' => 'QA diagnosis', 'treatment' => 'QA treatment', 'recommendation' => 'QA recommendation',
        'temperature' => '36.7', 'blood_pressure_systolic' => '120', 'blood_pressure_diastolic' => '80',
        'heart_rate' => '72', 'respiratory_rate' => '16', 'oxygen_saturation' => '98', 'height_cm' => '170', 'weight_kg' => '65',
        'need_follow_up' => '1', 'follow_up_date' => date('Y-m-d', strtotime('+1 day')), 'digital_signature' => 'QA nurse signature',
        'ai_summary' => 'QA edited summary', 'medicines' => '[]', 'draft_version' => $version];
    $saved = $run('save_consultation_draft', $fields);
    check($saved->success, 'Save draft succeeds: ' . $saved->notice);
    $resumed = $run('get_patient_medical_summary');
    foreach (['chief_complaint','symptoms','assessment','diagnosis','treatment','recommendation','digital_signature'] as $field) {
        check($resumed->data['visit'][$field] === $fields[$field], 'Resume retains ' . $field);
    }
    check($resumed->data['visit']['ai_summary_edited'] === $fields['ai_summary'], 'Edited summary retained');
    check(abs((float)$resumed->data['visit']['bmi'] - 22.49) < .01, 'BMI saved');
    $search = $run('search_students');
    check($search->success, 'Student search endpoint available');
    $_GET = [];
    $stale = $run('save_consultation_draft', $fields);
    check(!$stale->success && str_contains($stale->notice, 'another window'), 'Stale revision rejected');
    $originalVisit = $run('get_visit', ['id' => $started->data['walk_in_id']]);
    check($originalVisit->success && (int)$originalVisit->data['data']['id'] === (int)$started->data['walk_in_id'], 'Original visit lookup uses correct schema');
    $generated = $run('generate_ai_summary', ['id' => $started->data['walk_in_id'], 'draft_version' => $resumed->data['draft_version']]);
    check($generated->success && str_contains($generated->data['summary'], 'QA diagnosis'), 'Original AI summary generation retained');
    $fields['draft_version'] = $generated->data['draft_version'];
    $invalid = $run('save_consultation_draft', array_merge($fields, ['temperature' => '-1']));
    check(!$invalid->success, 'Invalid vitals rejected');
    $badStock = $run('complete_consultation_full', array_merge($fields, ['medicines' => json_encode([['medicine_id' => 2147483647, 'quantity' => 1]])]));
    check(!$badStock->success, 'Unavailable medicine rejects completion');
    check($db->query('SELECT status FROM queue_numbers WHERE id=' . $ticketId)->fetchColumn() === 'serving', 'Failed completion retains serving status');
    $medicine = $db->query("SELECT id, quantity FROM medicines WHERE is_archived=0 AND quantity>0 AND (expiration_date IS NULL OR expiration_date>=CURDATE()) LIMIT 1")->fetch();
    $expectedDispenses = 1;
    if ($medicine && (int)$medicine['quantity'] >= 2) {
        $pending = json_encode([['medicine_id' => (int)$medicine['id'], 'quantity' => 1, 'dosage_instructions' => 'QA immediate dose']]);
        $pendingDraft = $run('save_consultation_draft', array_merge($fields, ['medicines' => $pending]));
        check($pendingDraft->success, 'Medication draft saved without deducting stock');
        $fields['draft_version'] = $pendingDraft->data['draft_version'];
        $dispensed = $run('dispense_medicine', ['walk_in_id' => $started->data['walk_in_id'], 'medicine_id' => $medicine['id'], 'quantity' => 1, 'draft_row_index' => 0]);
        check($dispensed->success, 'Original immediate dispensing API succeeds: ' . $dispensed->notice);
        check(empty($_SESSION['queue_consultation_drafts'][$ticketId]['medicines']), 'Immediate dispensing removed from pending draft');
        $expectedDispenses = 2;
    }
    if ($medicine) $fields['medicines'] = json_encode([['medicine_id' => (int)$medicine['id'], 'quantity' => 1, 'dosage_instructions' => 'QA rollback-only dosage']]);
    $done = $run('complete_consultation_full', $fields);
    check($done->success, 'Complete succeeds: ' . $done->notice);
    check($db->query('SELECT status FROM queue_numbers WHERE id=' . $ticketId)->fetchColumn() === 'completed', 'Queue completed');
    $record = $db->query('SELECT * FROM consultations WHERE id=' . (int)$done->data['consultation_id'])->fetch();
    check((int)$record['student_id'] === (int)$student && $record['diagnosis'] === $fields['diagnosis'], 'Authoritative medical history saved');
    if ($medicine) {
        check((int)$db->query('SELECT quantity FROM medicines WHERE id=' . (int)$medicine['id'])->fetchColumn() === (int)$medicine['quantity'] - $expectedDispenses, 'Exact medicine quantity deducted');
        check((int)$db->query('SELECT COUNT(*) FROM consultation_medicines WHERE consultation_id=' . (int)$done->data['consultation_id'])->fetchColumn() === $expectedDispenses, 'Dispensing linked to medical history');
    }
    $repeat = $run('complete_consultation_full', $fields);
    check(!$repeat->success && str_contains($repeat->notice, 'already closed'), 'Duplicate completion rejected');
    $closedDraft = $run('save_consultation_draft', $fields);
    check(!$closedDraft->success, 'Closed visit cannot be reopened by draft save');
    $db->finishRollback();
    check(!$db->query('SELECT id FROM queue_numbers WHERE id=' . $ticketId)->fetchColumn(), 'Fixture records rolled back');
    echo "PASS: nurse authorization, CSRF, detailed walk-in creation, visitor category, duplicate check-in guard, real SQL patient summary, idempotent start, draft/resume fields and BMI, original lookup/AI generation, stale-edit protection, vital validation, failed completion rollback, authoritative history, exact dispensing/history linkage, duplicate completion guard, closed draft guard; all fixture data rolled back.\n";
} finally {
    if ($db->inTransaction()) $db->finishRollback();
}
