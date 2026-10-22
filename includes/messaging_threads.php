<?php
/** Shared nursing inbox: one patient entry, without moving records or widening patient access. */
function clinic_patient_thread_ids(PDO $db, int $conversationId, string $role): array {
    if ($role !== 'nurse') return [$conversationId];
    $stmt = $db->prepare("SELECT cp.user_id FROM conversation_participants cp JOIN users u ON u.id = cp.user_id WHERE cp.conversation_id = ? AND u.role IN ('student', 'teacher', 'adviser')");
    $stmt->execute([$conversationId]);
    $patients = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (count($patients) !== 1) return [$conversationId];
    $stmt = $db->prepare("SELECT c.id FROM conversations c JOIN conversation_participants cp ON cp.conversation_id = c.id WHERE cp.user_id = ? AND c.status = 'active' AND c.conversation_type IN ('student_nurse', 'teacher_nurse') AND (SELECT COUNT(*) FROM conversation_participants p JOIN users u ON u.id = p.user_id WHERE p.conversation_id = c.id AND u.role IN ('student', 'teacher', 'adviser')) = 1 ORDER BY c.id");
    $stmt->execute([(int)$patients[0]]);
    $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    if (!in_array($conversationId, $ids, true)) $ids[] = $conversationId;
    return $ids;
}

function group_clinic_patient_threads(PDO $db, array $conversations): array {
    $groups = [];
    // Input is newest first, so the first row supplies the latest preview and timestamp.
    foreach ($conversations as $conversation) {
        $ids = clinic_patient_thread_ids($db, (int)$conversation['id'], 'nurse');
        $key = min($ids);
        if (!isset($groups[$key])) {
            $conversation['id'] = $key;
            $conversation['conversation_ids'] = $ids;
            $groups[$key] = $conversation;
        } else {
            $groups[$key]['unread_count'] = (int)$groups[$key]['unread_count'] + (int)$conversation['unread_count'];
        }
    }
    return array_values($groups);
}
