<?php
/** Shared guards and draft persistence for the integrated nurse workspace. */
function queue_consultation_guard_emergency(PDO $db, array $ticket): void {
    if($ticket['priority']==='emergency')return;
    $q=$db->prepare("SELECT id FROM queue_numbers WHERE priority='emergency' AND status IN ('waiting','called') AND created_at>=CURDATE() AND created_at<CURDATE()+INTERVAL 1 DAY AND id<>? ORDER BY created_at,id LIMIT 1 FOR UPDATE");
    $q->execute([$ticket['id']]);
    if($q->fetchColumn())throw new DomainException('An emergency patient is waiting for assessment. Attend to the emergency before calling or starting a routine visit.');
}
function queue_consultation_lock(PDO $db, int $ticketId): array {
    $stmt = $db->prepare('SELECT * FROM queue_numbers WHERE id = ? FOR UPDATE');
    $stmt->execute([$ticketId]);
    $ticket = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$ticket) throw new DomainException('Queue ticket not found.');
    if (in_array($ticket['status'], ['completed', 'cancelled', 'no_show'], true)) {
        throw new DomainException('This visit is already closed. Refresh the queue to view its record.');
    }
    return $ticket;
}

function queue_consultation_visit(PDO $db, array $ticket, int $userId): array {
    $stmt = $db->prepare('SELECT * FROM walk_in_visits WHERE queue_id = ? LIMIT 1 FOR UPDATE');
    $stmt->execute([$ticket['id']]);
    $visit = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($visit) {
        if (in_array($visit['status'], ['completed', 'cancelled'], true)) throw new DomainException('This consultation is already closed.');
        return $visit;
    }
    $patientName=$ticket['full_name']??null;
    if(!$patientName&&!empty($ticket['teacher_id'])){$nameStmt=$db->prepare("SELECT CONCAT(u.first_name,' ',u.last_name) FROM teachers t JOIN users u ON u.id=t.user_id WHERE t.id=?");$nameStmt->execute([$ticket['teacher_id']]);$patientName=$nameStmt->fetchColumn()?:'Faculty Patient';}
    $stmt = $db->prepare("INSERT INTO walk_in_visits
        (queue_id, student_id, is_temp_visitor, temp_full_name, visit_number, visit_date,
         arrival_time, visit_type, chief_complaint, priority_level, attended_by, status)
        VALUES (?, ?, ?, ?, ?, CURDATE(), NOW(), 'walk-in', '', ?, ?, 'in_consultation')");
    $stmt->execute([$ticket['id'], $ticket['student_id'], empty($ticket['student_id']) ? 1 : 0,
        $patientName, 'BPC-CL-' . date('Ymd') . '-' . $ticket['id'], $ticket['priority'], $userId]);
    $stmt = $db->prepare('SELECT * FROM walk_in_visits WHERE id = ?');
    $stmt->execute([$db->lastInsertId()]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function queue_consultation_version(?array $visit): string {
    if (!$visit) return '';
    // Hash the actual clinical fields, avoiding timestamp precision races.
    $fields = ['id', 'status', 'priority_level', 'chief_complaint', 'symptoms', 'assessment', 'diagnosis', 'treatment',
        'recommendation', 'need_follow_up', 'follow_up_date', 'digital_signature', 'ai_summary',
        'ai_summary_edited', 'temperature', 'blood_pressure_systolic', 'blood_pressure_diastolic',
        'heart_rate', 'respiratory_rate', 'oxygen_saturation', 'height_cm', 'weight_kg'];
    return hash('sha256', json_encode(array_map(static fn($key) => $visit[$key] ?? null, $fields)));
}

function queue_consultation_check_version(array $visit): void {
    $expected = $_POST['draft_version'] ?? '';
    if (!is_string($expected) || $expected === '' || !hash_equals(queue_consultation_version($visit), $expected)) {
        throw new DomainException('This consultation was changed in another window. Your entries are retained; reopen the patient to review the saved record before retrying.');
    }
}

function queue_consultation_save(PDO $db, int $visitId, array $input): void {
    require_once __DIR__.'/nursing_scope.php';
    enforce_nursing_documentation($input);
    if(isset($input['clinical_priority'])){
        if(!in_array($input['clinical_priority'],['normal','urgent','emergency'],true))throw new DomainException('Select a valid Nurse-confirmed clinical priority.');
        $current=$db->prepare('SELECT priority_level,queue_id FROM walk_in_visits WHERE id=? FOR UPDATE');
        $current->execute([$visitId]);$previous=$current->fetch(PDO::FETCH_ASSOC);
        if(!$previous)throw new DomainException('Consultation not found.');
        if($previous['priority_level']!==$input['clinical_priority']) {
            $handoff=$db->prepare('SELECT id FROM physician_queue WHERE queue_id=? LIMIT 1');
            $handoff->execute([$previous['queue_id']]);
            if($handoff->fetchColumn())throw new DomainException('This patient has been referred. Ask the Doctor to reassess priority in the physician queue.');
            $reason=$input['priority_reason']??'';
            if(!is_string($reason)||mb_strlen(trim($reason))<3||mb_strlen($reason)>1000)throw new DomainException('Explain why the clinical priority changed (3–1000 characters).');
            $actor=current_user();
            $db->prepare("INSERT INTO activity_logs(user_id,action,module,details) VALUES (?,'Clinical priority changed','clinical',?)")
                ->execute([$actor['id'],'Queue #'.$previous['queue_id'].'; '.$previous['priority_level'].' -> '.$input['clinical_priority'].'; reason: '.trim($reason)]);
        }
        $db->prepare('UPDATE walk_in_visits SET priority_level=? WHERE id=?')->execute([$input['clinical_priority'],$visitId]);
        $db->prepare('UPDATE queue_numbers SET priority=? WHERE id=(SELECT queue_id FROM walk_in_visits WHERE id=?)')->execute([$input['clinical_priority'],$visitId]);
    }
    $values = [];
    $sets = [];
    foreach (['chief_complaint', 'symptoms', 'assessment', 'recommendation'] as $field) {
        $sets[] = "$field = ?";
        $values[] = trim((string)($input[$field] ?? ''));
    }
    // The legacy treatment column stores nursing interventions on nursing visits.
    // Existing diagnosis/signature fields are never erased by a nursing draft.
    $sets[] = 'treatment = ?';$values[] = trim($input['nursing_interventions']??'');
    foreach (['temperature', 'blood_pressure_systolic', 'blood_pressure_diastolic', 'heart_rate',
        'respiratory_rate', 'oxygen_saturation', 'height_cm', 'weight_kg'] as $field) {
        $value = $input[$field] ?? '';
        if ($value !== '' && (!is_numeric($value) || (float)$value < 0)) throw new DomainException('Vital signs must be valid non-negative numbers.');
        $sets[] = "$field = ?";
        $values[] = $value === '' ? null : $value;
    }
    $date = !empty($input['need_follow_up']) ? ($input['follow_up_date'] ?? '') : '';
    if ($date !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) || date('Y-m-d', strtotime($date)) !== $date)) throw new DomainException('Invalid follow-up date.');
    $sets[] = 'need_follow_up = ?'; $values[] = !empty($input['need_follow_up']) ? 1 : 0;
    $sets[] = 'follow_up_date = ?'; $values[] = $date ?: null;
    $sets[] = 'ai_summary_edited = ?'; $values[] = (string)($input['ai_summary'] ?? '');
    $height = (float)($input['height_cm'] ?? 0); $weight = (float)($input['weight_kg'] ?? 0);
    $bmi = $height > 0 && $weight > 0 ? round($weight / (($height / 100) ** 2), 2) : null;
    $sets[] = 'bmi = ?'; $values[] = $bmi;
    $sets[] = 'bmi_status = ?'; $values[] = $bmi === null ? null : ($bmi < 18.5 ? 'Underweight' : ($bmi < 25 ? 'Normal' : ($bmi < 30 ? 'Overweight' : 'Obese')));
    $values[] = $visitId;
    $db->prepare('UPDATE walk_in_visits SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($values);
}
