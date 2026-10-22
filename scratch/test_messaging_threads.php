<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('session.save_path', __DIR__);
require __DIR__ . '/../config/config.php';
require __DIR__ . '/../includes/messaging_threads.php';
$db = getDB();
$before = (int)$db->query('SELECT COUNT(*) FROM messages')->fetchColumn();
$ids = $db->query("SELECT id FROM conversations WHERE status='active' AND conversation_type IN ('student_nurse','teacher_nurse') ORDER BY id DESC")->fetchAll(PDO::FETCH_COLUMN);
$rows = [];
foreach ($ids as $id) $rows[] = ['id'=>(int)$id,'unread_count'=>1,'last_message'=>'preview-'.$id];
$groups = group_clinic_patient_threads($db, $rows);
$seen = [];
foreach ($groups as $group) {
    $threads = $group['conversation_ids'];
    if ((int)$group['unread_count'] !== count($threads)) throw new RuntimeException('Unread totals must include every grouped thread.');
    if ((int)$group['id'] !== min($threads)) throw new RuntimeException('Canonical thread must be stable.');
    foreach ($threads as $thread) {
        if (isset($seen[$thread])) throw new RuntimeException('Thread appears in multiple inbox entries.');
        $seen[$thread] = true;
        if (clinic_patient_thread_ids($db, $thread, 'student') !== [$thread]) throw new RuntimeException('Patient scope was widened.');
        $resolved = clinic_patient_thread_ids($db, $thread, 'nurse'); sort($resolved); $expected=$threads; sort($expected);
        if ($resolved !== $expected) throw new RuntimeException('Old links must resolve to the same combined history.');
    }
}
if (count($seen) !== count($ids)) throw new RuntimeException('A historical thread was lost.');
if ((int)$db->query('SELECT COUNT(*) FROM messages')->fetchColumn() !== $before) throw new RuntimeException('Message records changed.');
echo 'PASS: '.count($ids).' source threads become '.count($groups).' patient entries; unread totals, stable IDs, historical links and patient scope verified; '.$before.' messages preserved.'.PHP_EOL;
