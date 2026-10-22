<?php
/** Patient-scoped visit and genuine QR activity, with database pagination. */
function patient_activity_scope(PDO $db, array $user, array $request): array {
    $role = canonical_role($user['role'] ?? '');
    if (in_array($role, ['student', 'teacher'], true)) {
        $type = $role;
        $table = $type === 'student' ? 'students' : 'teachers';
        $stmt = $db->prepare("SELECT id FROM $table WHERE user_id = ? LIMIT 1");
        $stmt->execute([(int)$user['id']]); $id = (int)$stmt->fetchColumn();
        if (!$id) throw new RuntimeException('Patient record unavailable.', 404);
        if ((!empty($request['patient_type']) && $request['patient_type'] !== $type) ||
            (!empty($request['patient_id']) && (int)$request['patient_id'] !== $id) ||
            (!empty($request['student_id']) && ($type !== 'student' || (int)$request['student_id'] !== $id)) ||
            (!empty($request['teacher_id']) && ($type !== 'teacher' || (int)$request['teacher_id'] !== $id)))
            throw new RuntimeException('Access denied.', 403);
    } elseif (in_array($role, ['nurse', 'admin'], true)) {
        // Same clinical-record access boundary as students_api.php / teacher_qr.
        $type = $request['patient_type'] ?? (!empty($request['teacher_id']) ? 'teacher' : 'student');
        if (!in_array($type, ['student','teacher'], true)) throw new RuntimeException('Invalid patient type.', 400);
        $id = (int)($request['patient_id'] ?? $request[$type.'_id'] ?? 0);
        $table = $type === 'student' ? 'students' : 'teachers';
        $stmt = $db->prepare("SELECT id FROM $table WHERE id = ?"); $stmt->execute([$id]);
        if (!$id || !$stmt->fetchColumn()) throw new RuntimeException('Patient record unavailable.', 404);
    } else throw new RuntimeException('Access denied.', 403);
    return ['type'=>$type,'id'=>$id];
}

function patient_activity_scan_condition(array $scope, string $alias = 's'): array {
    if ($scope['type'] === 'student') return ["$alias.student_id = ?", [$scope['id']]];
    // Exact token ownership recovers real legacy teacher scans without inventing records.
    return ["($alias.teacher_id = ? OR ($alias.teacher_id IS NULL AND $alias.student_id IS NULL AND ($alias.qr_token IN (SELECT qr_token FROM teacher_qrs WHERE teacher_id = ?) OR $alias.qr_token IN (SELECT qr_token FROM teachers WHERE id = ? AND qr_token IS NOT NULL AND qr_token <> ''))))", array_fill(0,3,$scope['id'])];
}

function patient_activity_scans(PDO $db, array $scope, int $offset = 0, int $limit = 5): array {
    [$where,$params] = patient_activity_scan_condition($scope);
    // Manual walk-ins are not QR scans, even if legacy code logged them here.
    $where .= " AND COALESCE(s.scan_method, 'unknown') <> 'walk_in'";
    $count = $db->prepare("SELECT COUNT(*) FROM qr_scan_logs s WHERE $where"); $count->execute($params);
    $total = (int)$count->fetchColumn();
    $stmt = $db->prepare("SELECT s.id, s.scan_time, s.purpose, s.status, s.scan_location, s.scanner_name, s.metadata FROM qr_scan_logs s WHERE $where ORDER BY s.scan_time DESC, s.id DESC LIMIT ? OFFSET ?");
    $stmt->execute(array_merge($params, [max(1,min(50,$limit)),max(0,$offset)]));
    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $meta = json_decode($row['metadata'] ?? '', true) ?: [];
        $row['queue_number'] = $meta['queue_number'] ?? null;
        $row['queue_id'] = isset($meta['queue_id']) ? (int)$meta['queue_id'] : null;
        $row['visit_id'] = isset($meta['walk_in_id']) ? (int)$meta['walk_in_id'] : null;
        unset($row['metadata']);
    }
    return ['data'=>$rows,'total'=>$total,'has_more'=>max(0,$offset)+count($rows)<$total];
}

function patient_activity_timeline(PDO $db, array $scope, int $offset = 0, int $limit = 10): array {
    $field = $scope['type'] === 'student' ? 'student_id' : 'teacher_id';
    $id = $scope['id']; $queries = []; $params = [];
    $add = function(string $sql, array $values) use (&$queries,&$params) { $queries[]=$sql; $params=array_merge($params,$values); };
    [$scanWhere,$scanParams] = patient_activity_scan_condition($scope);
    $add("SELECT CONCAT('scan-',s.id) AS event_key, s.scan_time AS timestamp, CASE WHEN s.scan_method = 'walk_in' THEN 'visit' ELSE 'scan' END AS type, CASE WHEN s.scan_method = 'walk_in' THEN 'Clinic Check-in' ELSE 'QR Scanned' END AS title, CONCAT(REPLACE(s.purpose,'_',' '), IF(COALESCE(s.scan_location,'')='', '', CONCAT(' · ',s.scan_location))) AS detail, s.status, NULL AS reference, 'datetime' AS precision_kind FROM qr_scan_logs s WHERE $scanWhere", $scanParams);
    $add("SELECT CONCAT('queue-',id), created_at, 'queue', 'Queue Assigned', CONCAT(queue_number,' (',visit_type,')'), status, queue_number, 'datetime' FROM queue_numbers WHERE $field = ?",[$id]);
    $add("SELECT CONCAT('called-',id), called_at, 'queue', 'Queue Called', CONCAT('Queue ',queue_number), 'called', queue_number, 'datetime' FROM queue_numbers WHERE $field = ? AND called_at IS NOT NULL",[$id]);
    $add("SELECT CONCAT('started-',id), served_at, 'consultation', 'Consultation Started', CONCAT('Queue ',queue_number), 'serving', queue_number, 'datetime' FROM queue_numbers WHERE $field = ? AND served_at IS NOT NULL",[$id]);
    $add("SELECT CONCAT('completed-',id), completed_at, 'visit_completed', 'Visit Completed', CONCAT('Queue ',queue_number), 'completed', queue_number, 'datetime' FROM queue_numbers WHERE $field = ? AND completed_at IS NOT NULL",[$id]);
    $add("SELECT CONCAT('consultation-',id), consultation_date, 'consultation', 'Consultation Completed', CONCAT(COALESCE(chief_complaint,''),IF(COALESCE(diagnosis,'')='', '', CONCAT(' — ',diagnosis))), 'completed', CONCAT('Consultation #',id), 'datetime' FROM consultations WHERE $field = ?",[$id]);
    $walkScope = $scope['type'] === 'student' ? 'w.student_id = ?' : 'q.teacher_id = ?';
    $add("SELECT CONCAT('medicine-',md.id), md.dispensed_at, 'medicine', 'Medicine Dispensed', CONCAT(m.medicine_name,' (',md.quantity,')'), 'completed', q.queue_number, 'datetime' FROM medicine_dispensing md JOIN medicines m ON m.id = md.medicine_id JOIN walk_in_visits w ON w.id = md.walk_in_id LEFT JOIN queue_numbers q ON q.id = w.queue_id WHERE $walkScope",[$id]);
    $certScope = $scope['type'] === 'student' ? 'c.student_id = ?' : 'v.teacher_id = ?';
    $add("SELECT CONCAT('certificate-',c.id), CASE WHEN DATE(c.created_at)=c.date_issued THEN c.created_at ELSE CAST(c.date_issued AS DATETIME) END, 'certificate', 'Medical Certificate Issued', CONCAT(c.certificate_type,IF(COALESCE(c.purpose,'')='', '', CONCAT(' — ',c.purpose))), 'completed', c.certificate_number, CASE WHEN DATE(c.created_at)=c.date_issued THEN 'datetime' ELSE 'date' END FROM certificates c LEFT JOIN consultations v ON v.id = c.consultation_id WHERE $certScope",[$id]);
    $union = implode(' UNION ALL ', $queries);
    $count=$db->prepare("SELECT COUNT(*) FROM ($union) activity WHERE timestamp IS NOT NULL");$count->execute($params);$total=(int)$count->fetchColumn();
    $stmt=$db->prepare("SELECT * FROM ($union) activity WHERE timestamp IS NOT NULL ORDER BY timestamp DESC, event_key DESC LIMIT ? OFFSET ?");
    $stmt->execute(array_merge($params,[max(1,min(100,$limit)),max(0,$offset)]));$rows=$stmt->fetchAll();
    return ['timeline'=>$rows,'total'=>$total,'has_more'=>max(0,$offset)+count($rows)<$total];
}
