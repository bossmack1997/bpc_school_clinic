<?php
/** Interaction metadata is separate so historical messages remain intact. */
function messaging_interactions_ready(PDO $db): bool {
    static $ready;
    if ($ready !== null) return $ready;
    try { $db->query('SELECT message_id FROM message_interactions LIMIT 0');
        $db->query('SELECT message_id FROM message_reactions LIMIT 0'); return $ready = true;
    } catch (PDOException $e) { return $ready = false; }
}

if ($action === 'message_action') {
    $id = (int)($_POST['message_id'] ?? 0);
    $stmt = $db->prepare('SELECT id, conversation_id, sender_id FROM messages WHERE id = ?');
    $stmt->execute([$id]); $message = $stmt->fetch();
    if (!$message || !is_authorized_in_conversation($db, (int)$message['conversation_id'], $userId, $userRole))
        json_response(false, [], 'Message unavailable.');
    if (!messaging_interactions_ready($db)) json_response(false, [], 'Message interactions require the messaging migration.');
    $operation = $_POST['operation'] ?? '';
    if ($operation === 'delete') {
        if ((int)$message['sender_id'] !== $userId) json_response(false, [], 'Only your own messages can be removed.');
        $db->prepare('INSERT INTO message_interactions (message_id, deleted_at) VALUES (?, NOW()) ON DUPLICATE KEY UPDATE deleted_at = NOW()')->execute([$id]);
        $db->prepare('DELETE FROM message_reactions WHERE message_id = ?')->execute([$id]);
    } elseif ($operation === 'react') {
        $emoji = $_POST['emoji'] ?? '';
        if (!in_array($emoji, ['👍', '❤️', '😊'], true)) json_response(false, [], 'Invalid reaction.');
        $check = $db->prepare('SELECT deleted_at FROM message_interactions WHERE message_id = ?');
        $check->execute([$id]); if ($check->fetchColumn()) json_response(false, [], 'Message removed.');
        $check = $db->prepare('SELECT emoji FROM message_reactions WHERE message_id = ? AND user_id = ?');
        $check->execute([$id, $userId]);
        if ($check->fetchColumn() === $emoji) $db->prepare('DELETE FROM message_reactions WHERE message_id = ? AND user_id = ?')->execute([$id, $userId]);
        else $db->prepare('INSERT INTO message_reactions VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE emoji = VALUES(emoji)')->execute([$id, $userId, $emoji]);
    } else json_response(false, [], 'Invalid operation.');
    json_response(true);
}

function enrich_message_interactions(PDO $db, array &$messages): void {
    if (!$messages || !messaging_interactions_ready($db)) return;
    $ids = array_column($messages, 'id'); $slots = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare("SELECT i.*, r.message_body AS reply_body, ri.deleted_at AS reply_deleted FROM message_interactions i LEFT JOIN messages r ON r.id = i.reply_to_id LEFT JOIN message_interactions ri ON ri.message_id = r.id WHERE i.message_id IN ($slots)");
    $stmt->execute($ids); $metadata = [];
    foreach ($stmt as $row) $metadata[$row['message_id']] = $row;
    $stmt = $db->prepare("SELECT message_id, emoji, COUNT(*) AS count FROM message_reactions WHERE message_id IN ($slots) GROUP BY message_id, emoji");
    $stmt->execute($ids); $reactions = [];
    foreach ($stmt as $row) $reactions[$row['message_id']][] = $row;
    foreach ($messages as &$m) {
        $info = $metadata[$m['id']] ?? [];
        $m['deleted'] = !empty($info['deleted_at']);
        if ($m['deleted']) { $m['message_body'] = 'Message removed'; $m['attachment_url'] = null; $m['attachment_name'] = null; }
        $m['reply_to_id'] = $info['reply_to_id'] ?? null;
        $m['reply_preview'] = !empty($info['reply_deleted']) ? 'Message removed' : mb_substr($info['reply_body'] ?? '', 0, 160);
        $m['reactions'] = $m['deleted'] ? [] : ($reactions[$m['id']] ?? []);
    }
}
