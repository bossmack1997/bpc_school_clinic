<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../config/config.php';
$db = getDB();
$before = $db->query('SELECT COUNT(*) FROM messages')->fetchColumn();
$db->exec(file_get_contents(__DIR__ . '/../database/migration_messenger_interactions.sql'));
$after = $db->query('SELECT COUNT(*) FROM messages')->fetchColumn();
echo json_encode(['messages_before'=>$before,'messages_after'=>$after,'conversations'=>$db->query('SELECT COUNT(*) FROM conversations')->fetchColumn(),'roles'=>$db->query('SELECT role, COUNT(*) AS total FROM users GROUP BY role')->fetchAll(),'duplicate_pairs'=>$db->query('SELECT COUNT(*) FROM (SELECT GROUP_CONCAT(user_id ORDER BY user_id) AS pair FROM conversation_participants GROUP BY conversation_id) x GROUP BY pair HAVING COUNT(*) > 1')->fetchAll()]);
