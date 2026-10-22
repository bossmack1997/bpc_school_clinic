<?php
/** Independent of MICA, AI services and general notification read status. */
final class GlobalSosService
{
    public const ACTIVE = ['new','active','acknowledged','responding','responded'];
    public function __construct(private PDO $db) {}
    public static function authorized(string $role): bool { return in_array($role, ['admin','nurse'], true); }
    public static function operationAllowed(string $status, string $operation): bool
    {
        if (!in_array($status, self::ACTIVE, true)) return false;
        return match ($operation) {
            'acknowledge' => in_array($status, ['new','active'], true),
            'respond' => in_array($status, ['new','active','acknowledged','responded'], true),
            'resolve','cancel' => true,
            default => false,
        };
    }
    public function snapshot(int $userId): array
    {
        $stmt = $this->db->prepare("SELECT se.id, se.emergency_type, se.description, se.status, se.location,
            se.latitude, se.longitude, se.location_accuracy, se.created_at, se.source,
            u.first_name, u.last_name, u.role AS reporter_role, u.phone,
            CASE WHEN ack.emergency_id IS NULL THEN 0 ELSE 1 END AS notification_acknowledged
            FROM sos_emergencies se
            LEFT JOIN students s ON s.id = se.student_id
            LEFT JOIN users u ON u.id = COALESCE(s.user_id, se.user_id)
            LEFT JOIN sos_alert_acknowledgments ack ON ack.emergency_id = se.id AND ack.user_id = ?
            WHERE se.status IN ('new','active','acknowledged','responding','responded') AND se.resolved_at IS NULL
            ORDER BY se.created_at DESC, se.id DESC");
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['id'] = (int)$row['id'];
            $row['notification_acknowledged'] = (bool)$row['notification_acknowledged'];
            $row['reference'] = 'SOS-' . $row['id'];
            $row['reported_by'] = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')) ?: null;
            unset($row['first_name'], $row['last_name']);
        }
        unset($row);
        return ['emergencies'=>$rows, 'active_count'=>count($rows), 'pending_ids'=>array_values(array_map(fn($r)=>$r['id'],array_filter($rows,fn($r)=>!$r['notification_acknowledged'])))];
    }
    public static function identifiers(mixed $values): array
    {
        if (!is_array($values) || !$values || count($values)>2000) throw new InvalidArgumentException('Select between 1 and 2000 notifications.');
        $ids=[];
        foreach($values as $value) {
            if (!is_scalar($value) || !preg_match('/^[1-9][0-9]{0,9}$/D',(string)$value) || (int)$value>2147483647) throw new InvalidArgumentException('Invalid emergency identifier.');
            $ids[(int)$value]=(int)$value;
        }
        return array_values($ids);
    }
    public function acknowledgeNotifications(int $userId, array $ids): void
    {
        $ids=self::identifiers($ids);
        $marks=implode(',',array_fill(0,count($ids),'?'));
        $this->db->beginTransaction();
        try {
            $stmt=$this->db->prepare("INSERT IGNORE INTO sos_alert_acknowledgments (user_id,emergency_id) SELECT ?, id FROM sos_emergencies WHERE id IN ($marks)");
            $stmt->execute(array_merge([$userId],$ids));
            if ($stmt->rowCount()>0) log_activity($userId,'Acknowledged SOS screen notifications','emergency','Emergency IDs: '.implode(',',$ids).'; no report status changed.');
            $this->db->commit();
        } catch(Throwable $e) { if($this->db->inTransaction())$this->db->rollBack();throw $e; }
    }
}
