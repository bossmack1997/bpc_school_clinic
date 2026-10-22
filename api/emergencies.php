<?php
/**
 * api/emergencies.php
 * ------------------------------------------------------------------
 * Dual Location Emergency Workflow:
 *   1. Student SOS: Automatic Real Device GPS + Real-Time Tracking + Location History
 *   2. Adviser Emergency: Exact Manual Location Input (e.g. "Room 204, Main Building")
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../config/config.php';
require_login();
require_once __DIR__ . '/../includes/sos_workflow.php';
set_exception_handler(function (Throwable $e) {
    $db = getDB();
    if ($db->inTransaction()) $db->rollBack();
    error_log('SOS API: ' . $e->getMessage());
    http_response_code(500);
    json_response(false, ['error_code'=>'SOS_SERVER_ERROR'], 'The emergency service could not complete this request. Check the current status before retrying.');
});

header('Content-Type: application/json');
$db = getDB();
$u = current_user();
$role = $u['role'] ?? '';
$action = (string)($_POST['action'] ?? $_GET['action'] ?? '');
if (in_array($action, ['report','sos','update_location','cancel_sos','update_status'], true)) {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); header('Allow: POST'); json_response(false, [], 'Use POST.'); }
    if (!csrf_verify($_POST['csrf_token'] ?? '')) { http_response_code(403); json_response(false, ['error_code'=>'CSRF_INVALID'], 'Your security token expired. Refresh the page and try again.'); }
}


if ($role==='admin') {
    if(in_array($action,['oversight','oversight_detail','list','live_tracking','get_location'],true))require_admin_permission('view_emergencies');
    if($action==='update_status'&&!admin_can_respond_sos($u))admin_access_denied('Co-administrators have emergency monitoring access only. A nurse must handle clinical response.');
}

// Administration read model uses the existing endpoint and existing role boundary.
if (in_array($action, ['oversight','oversight_detail'], true)) {
    if ($role !== 'admin') { http_response_code(403); json_response(false, [], 'Administration access is required.'); }
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') { http_response_code(405); header('Allow: GET'); json_response(false, [], 'Use GET.'); }
    require_once __DIR__ . '/../includes/emergency_oversight.php';
    $oversight = new EmergencyOversight($db,admin_can_respond_sos($u));
    try {
        if ($action === 'oversight_detail') {
            $id=filter_var($_GET['emergency_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            if(!$id){http_response_code(400);json_response(false, [], 'Invalid emergency reference.');}
            $report=$oversight->detail($id,(int)$u['id']);
            if(!$report){http_response_code(404);json_response(false, [], 'Emergency not found.');}
            json_response(true,['report'=>$report]);
        }
        $db->beginTransaction();
        $result=$oversight->listing($_GET,(int)$u['id']);
        $db->commit();
        json_response(true,$result);
    } catch(InvalidArgumentException $e) {if($db->inTransaction())$db->rollBack();http_response_code(422);json_response(false, [], $e->getMessage());}
}

// ---------------------------------------------------------------------
// 1. ADVISER REPORT EMERGENCY (Manual Location)
// ---------------------------------------------------------------------
if ($action === 'report') {
    if ($role !== 'teacher') {
        http_response_code(403); json_response(false, [], 'Only teachers can submit teacher emergency reports.');
    }


    $studentId = (int)($_POST['student_id'] ?? 0);
    $type = trim((string)($_POST['emergency_type'] ?? ''));
    $location = trim((string)($_POST['location'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));
    $notes = trim((string)($_POST['notes'] ?? ''));
    $priority = trim((string)($_POST['priority'] ?? 'urgent'));
    if (!in_array($priority, ['routine', 'soon', 'urgent'], true)) $priority = 'urgent';

    if ($studentId <= 0) json_response(false, [], 'A valid student is required.');
    if ($type === '' || mb_strlen($type) > 100) json_response(false, [], 'Select a valid emergency type.');
    if ($description === '' || mb_strlen($description) < 5 || mb_strlen($description) > 2000) json_response(false, [], 'Describe the incident in 5 to 2,000 characters.');
    if (mb_strlen($location) > 255 || mb_strlen($notes) > 1000) json_response(false, [], 'The location or notes are too long.');
    if (!adviser_can_access_student($db, $u['id'], $studentId)) {
        http_response_code(403); json_response(false, [], 'You can only report emergencies for students in your current advisory section.');
    }
    if ($location === '') {
        json_response(false, [], 'Location is required (e.g. Room 204, Main Building).');
    }

    $db->beginTransaction();
    $lock=$db->prepare('SELECT id FROM users WHERE id=? FOR UPDATE');$lock->execute([$u['id']]);
    $duplicate=$db->prepare("SELECT id FROM sos_emergencies WHERE user_id=? AND student_id=? AND emergency_type=? AND location=? AND status IN ('new','active','acknowledged','responding','responded') AND created_at>=DATE_SUB(NOW(),INTERVAL 2 MINUTE) LIMIT 1");
    $duplicate->execute([$u['id'],$studentId,$type,$location]);
    if($duplicateId=$duplicate->fetchColumn()){$db->commit();json_response(true,['emergency_id'=>(int)$duplicateId],'This emergency is already recorded.');}

    // Resolve section ID
    $sectionId = null;
    $myStudents = get_adviser_students($db, $u['id']);
    foreach ($myStudents as $st) {
        if ((int)$st['id'] === $studentId && !empty($st['assigned_section_id'])) {
            $sectionId = (int)$st['assigned_section_id'];
            break;
        }
    }

    $fullDesc = $description;
    if ($notes !== '') {
        $fullDesc .= "\n\nNotes: " . $notes;
    }

    $stmt = $db->prepare("
        INSERT INTO sos_emergencies
            (user_id, student_id, emergency_type, location, location_source, description, status, priority, source, reporter_role, section_id, ip_address, user_agent)
        VALUES (?, ?, ?, ?, 'manual', ?, 'new', ?, 'adviser', 'teacher', ?, ?, ?)
    ");
    $stmt->execute([
        $u['id'], $studentId, $type, $location, $fullDesc, $priority, $sectionId,
        $_SERVER['REMOTE_ADDR'] ?? null, $_SERVER['HTTP_USER_AGENT'] ?? null
    ]);

    $emergencyId = (int)$db->lastInsertId();
    sos_audit($db,$u,$emergencyId,'','new');
    $db->commit();
    sos_notify($db,$emergencyId,'new',(int)$u['id']);

    json_response(true, ['emergency_id' => $emergencyId], 'Emergency reported. The clinic has been notified.');
}

// ---------------------------------------------------------------------
// 2. STUDENT DIRECT SOS (GPS Location)
// ---------------------------------------------------------------------
if ($action === 'sos') {


    if (!in_array($role, ['student','teacher','nurse','admin'], true)) { http_response_code(403); json_response(false, [], 'Access denied.'); }
    $result = sos_create($db, $u, $_POST);
    json_response(true, $result, 'Emergency request saved. The clinic has been notified.');
}

// ---------------------------------------------------------------------
// 3. REAL-TIME LOCATION UPDATE (Student GPS watchPosition)
// ---------------------------------------------------------------------
if ($action === 'update_location') {


    $emergencyId = (int)($_POST['emergency_id'] ?? 0);
    $latitude = $_POST['latitude'] ?? null;
    $longitude = $_POST['longitude'] ?? null;
    $accuracy = sos_accuracy($_POST['accuracy'] ?? null);

    if ($emergencyId <= 0) {
        http_response_code(400); json_response(false, [], 'Invalid emergency ID.');
    }

    $db->beginTransaction();
    // Verify emergency exists and belongs to current user
    $chk = $db->prepare("SELECT id, status, user_id, location_accuracy FROM sos_emergencies WHERE id = ? LIMIT 1 FOR UPDATE");
    $chk->execute([$emergencyId]);
    $em = $chk->fetch();
    if (!$em || (int)$em['user_id'] !== (int)$u['id']) {
        sos_fail(403, 'Emergency record not found or access denied.', 'SOS_FORBIDDEN');
    }

    // If emergency is resolved or cancelled, signal frontend to STOP tracking
    if (in_array($em['status'], ['resolved', 'cancelled', 'false_alarm'], true)) {
        $db->commit();
        json_response(true, [
            'tracking_active' => false,
            'status'          => $em['status'],
            'message'         => 'Emergency has been closed. Location tracking stopped.'
        ]);
    }

    // Validate coordinates
    $validLat = null;
    $validLng = null;
    if ($latitude !== null && $latitude !== '' && is_numeric($latitude)) {
        $latF = (float)$latitude;
        if ($latF >= -90.0 && $latF <= 90.0) $validLat = $latF;
    }
    if ($longitude !== null && $longitude !== '' && is_numeric($longitude)) {
        $lngF = (float)$longitude;
        if ($lngF >= -180.0 && $lngF <= 180.0) $validLng = $lngF;
    }

    if ($validLat === null || $validLng === null) {
        sos_fail(422, 'Invalid GPS coordinates.', 'SOS_INVALID_LOCATION');
    }

    $previousAccuracy = sos_accuracy($em['location_accuracy']);
    if ($previousAccuracy !== null && ($accuracy === null || $accuracy > $previousAccuracy)) {
        $db->commit();
        json_response(true, ['tracking_active'=>true, 'status'=>$em['status']], 'Previous, more accurate location retained.');
    }
    // Update main emergency record
    $db->prepare("
        UPDATE sos_emergencies
        SET latitude = ?, longitude = ?, location_accuracy = ?, location_updated_at = NOW(), updated_at = NOW()
        WHERE id = ? AND status IN ('new','active','acknowledged','responding','responded')
    ")->execute([$validLat, $validLng, $accuracy, $emergencyId]);

    $db->prepare("INSERT INTO emergency_location_updates (emergency_id, latitude, longitude, accuracy, recorded_at) VALUES (?, ?, ?, ?, NOW())")
        ->execute([$emergencyId, $validLat, $validLng, $accuracy]);

    $db->commit();
    json_response(true, [
        'tracking_active'     => true,
        'emergency_id'        => $emergencyId,
        'status'              => $em['status'],
        'latitude'            => $validLat,
        'longitude'           => $validLng,
        'accuracy'            => $accuracy,
        'location_updated_at' => date('Y-m-d H:i:s'),
    ], 'Location updated.');
}

// ---------------------------------------------------------------------
// 4. ACTIVE SOS CHECK (Student UI & resume tracking)
// ---------------------------------------------------------------------
if ($action === 'active_sos') {
    $stmt = $db->prepare("
        SELECT id, emergency_type, description, status, location_source, latitude, longitude, location_accuracy, location_updated_at, created_at
        FROM sos_emergencies
        WHERE user_id = ? AND source = 'sos' AND status IN ('new','active','acknowledged','responding','responded')
        ORDER BY created_at DESC
        LIMIT 1
    ");
    $stmt->execute([$u['id']]);
    $active = $stmt->fetch();

    json_response(true, [
        'has_active_sos' => (bool)$active,
        'emergency'      => $active ?: null
    ]);
}

// ---------------------------------------------------------------------
// 5. CANCEL SOS (Student self-cancelling their emergency)
// ---------------------------------------------------------------------
if ($action === 'cancel_sos') {


    $result = sos_transition($db, $u, (int)($_POST['emergency_id'] ?? 0), 'cancel', $_POST, true);
    json_response(true, $result, 'Emergency cancelled. Location sharing has stopped.');
}

// ---------------------------------------------------------------------
// 6. LIVE TRACKING / GET LOCATION (Nurse/Admin map view)
// ---------------------------------------------------------------------
if ($action === 'live_tracking' || $action === 'get_location') {
    if (!in_array($role, ['nurse', 'admin'], true)) {
        http_response_code(403); json_response(false, [], 'Access denied.');
    }

    $emergencyId = (int)($_GET['emergency_id'] ?? 0);
    if ($emergencyId <= 0) { http_response_code(400); json_response(false, [], 'Invalid emergency ID.'); }

    $stmt = $db->prepare("
        SELECT se.*,
               s.student_number, s.course, s.year_level, s.section,
               u.first_name, u.last_name, u.phone, u.profile_picture,
               sec.name AS section_name
        FROM sos_emergencies se
        LEFT JOIN students s ON s.id = se.student_id
        LEFT JOIN users u ON u.id = COALESCE(s.user_id, se.user_id)
        LEFT JOIN sections sec ON sec.id = se.section_id
        WHERE se.id = ?
        LIMIT 1
    ");
    $stmt->execute([$emergencyId]);
    $em = $stmt->fetch();
    if (!$em) { http_response_code(404); json_response(false, [], 'Emergency not found.'); }

    // Fetch location updates trail (history)
    $histStmt = $db->prepare("
        SELECT latitude, longitude, accuracy, recorded_at
        FROM emergency_location_updates
        WHERE emergency_id = ?
        ORDER BY recorded_at ASC
        LIMIT 100
    ");
    $histStmt->execute([$emergencyId]);
    $history = $histStmt->fetchAll();

    json_response(true, [
        'emergency' => $em,
        'history'   => $history
    ]);
}

// ---------------------------------------------------------------------
// 7. LIST EMERGENCIES (Nurse / Admin / Adviser)
// ---------------------------------------------------------------------
if ($action === 'list') {
    if (!in_array($role, ['nurse', 'admin', 'teacher', 'student'], true)) {
        http_response_code(403); json_response(false, [], 'Access denied.');
    }
    $where = '';
    $params = [];
    if (in_array($role, ['teacher','student'], true)) {
        $where = "WHERE se.user_id = ?";
        $params[] = $u['id'];
    } else {
        $where = (($_GET['history'] ?? '') === '1') ? '' : "WHERE se.status IN ('new','active','acknowledged','responding','responded')";
    }

    $stmt = $db->prepare("
        SELECT se.*,
               s.student_number, s.course, s.year_level, s.section,
               u.first_name, u.last_name, u.phone,
               sec.name AS section_name
        FROM sos_emergencies se
        LEFT JOIN students s ON s.id = se.student_id
        LEFT JOIN users u ON u.id = COALESCE(s.user_id, se.user_id)
        LEFT JOIN sections sec ON sec.id = se.section_id
        $where
        ORDER BY se.created_at DESC
        LIMIT 200
    ");
    $stmt->execute($params);
    json_response(true, ['data' => $stmt->fetchAll()]);
}

// ---------------------------------------------------------------------
// 9. TEACHER STUDENTS (searchable roster for the emergency modal)
// ---------------------------------------------------------------------
if ($action === 'teacher_students') {
    if ($role !== 'teacher') {
        http_response_code(403); json_response(false, [], 'Access denied.');
    }
    $q = trim((string)($_GET['q'] ?? ''));
    $students = get_adviser_students($db, $u['id']);
    if ($q !== '') {
        $qLower = strtolower($q);
        $students = array_values(array_filter($students, function ($st) use ($qLower) {
            $haystack = strtolower(trim(($st['first_name'] ?? '') . ' ' . ($st['last_name'] ?? '') . ' ' . ($st['student_number'] ?? '') . ' ' . ($st['section'] ?? '')));
            return str_contains($haystack, $qLower);
        }));
    }
    $out = array_map(function ($st) {
        return [
            'id' => (int)$st['id'],
            'student_number' => $st['student_number'] ?? '',
            'name' => trim(($st['first_name'] ?? '') . ' ' . ($st['last_name'] ?? '')),
            'section' => $st['section'] ?? '',
            'course' => $st['course'] ?? '',
            'label' => trim(($st['first_name'] ?? '') . ' ' . ($st['last_name'] ?? '')) . ' • ' . ($st['student_number'] ?? ''),
        ];
    }, array_slice($students, 0, 20));
    json_response(true, ['data' => $out]);
}

// ---------------------------------------------------------------------
// 8. UPDATE STATUS (Nurse / Admin)
// ---------------------------------------------------------------------
if ($action === 'update_status') {
    $result = sos_transition($db, $u, (int)($_POST['emergency_id'] ?? 0), (string)($_POST['op'] ?? ''), $_POST);
    json_response(true, $result, 'Emergency status updated.');
}
http_response_code(400);
json_response(false, [], 'Unknown action.');
