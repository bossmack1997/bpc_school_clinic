<?php
/** One delivery per announcement/recipient; read state does not reset delivery identity. */
function push_announcement_notification(PDO $db, int $userId, int $announcementId, string $title, string $message, string $link): int
{
    $link .= (str_contains($link,'?') ? '&' : '?') . 'announcement_id=' . $announcementId;
    $lockName='bpc_announcement_'.$userId.'_'.$announcementId;
    $lock=$db->prepare('SELECT GET_LOCK(?, 3)');$lock->execute([$lockName]);
    if ((int)$lock->fetchColumn()!==1) throw new RuntimeException('Announcement notification delivery is busy.');
    try {
        $check=$db->prepare("SELECT id FROM notifications WHERE user_id = ? AND type = 'announcement' AND (related_id = ? OR link = ?) LIMIT 1");
        $check->execute([$userId,$announcementId,$link]);
        $existing=$check->fetchColumn();if($existing)return (int)$existing;
        $insert=$db->prepare("INSERT INTO notifications (user_id,type,title,message,link,related_id) VALUES (?, 'announcement', ?, ?, ?, ?)");
        $insert->execute([$userId,$title,$message,$link,$announcementId]);
        return (int)$db->lastInsertId();
    } finally {$release=$db->prepare('SELECT RELEASE_LOCK(?)');$release->execute([$lockName]);}
}
