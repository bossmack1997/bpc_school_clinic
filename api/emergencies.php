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

header('Content-Type: application/json');
$db = getDB();
$u = current_user();
$role = $u['role'] ?? '';
$action = (string)($_REQUEST['action'] ?? '');

// ---------------------------------------------------------------------
// 1. ADVISER REPORT EMERGENCY (Manual Location)
// ---------------------------------------------------------------------
if ($action === 'report') {
    if ($role !== 'teacher') {
        json_response(false, [], 'Only teachers can submit teacher emergency reports.');
    }
    csrf_verify_or_die();

    $studentId = (int)($_POST['student_id'] ?? 0);
    $type = trim((string)($_POST['emergency_type'] ?? 'Emergency'));
    $location = trim((string)($_POST['location'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));
    $notes = trim((string)($_POST['notes'] ?? ''));
    $priority = trim((string)($_POST['priority'] ?? 'urgent'));
    if (!in_array($priority, ['routine', 'soon', 'urgent'], true)) $priority = 'urgent';

    if ($studentId <= 0) json_response(false, [], 'A valid student is required.');
    if (!adviser_can_access_student($db, $u['id'], $studentId)) {
        json_response(false, [], 'You can only report emergencies for students in your current advisory section.');
    }
    if ($location === '') {
        json_response(false, [], 'Location is required (e.g. Room 204, Main Building).');
    }

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
        VALUES (?, ?, ?, ?, 'manual', ?, 'new', ?, 'adviser', 'adviser', ?, ?, ?)
    ");
    $stmt->execute([
        $u['id'], $studentId, $type, $location, $fullDesc, $priority, $sectionId,
        $_SERVER['REMOTE_ADDR'] ?? null, $_SERVER['HTTP_USER_AGENT'] ?? null
    ]);

    $emergencyId = (int)$db->lastInsertId();
    log_activity($u['id'], 'Reported emergency', 'emergency', "Adviser emergency #$emergencyId for student #$studentId at $location");

    // Student name for notification
    $stuStmt = $db->prepare("SELECT u.first_name, u.last_name FROM students s JOIN users u ON u.id = s.user_id WHERE s.id = ?");
    $stuStmt->execute([$studentId]);
    $stu = $stuStmt->fetch();
    $stuName = $stu ? ($stu['first_name'] . ' ' . $stu['last_name']) : 'Student';

    $title = '🚨 Emergency Reported by Adviser';
    $msg = "{$u['first_name']} {$u['last_name']} reported an emergency for {$stuName} at {$location}. Type: {$type}";

    // Notify nurses
    $nurses = $db->query("SELECT user_id FROM nurses")->fetchAll();
    foreach ($nurses as $n) {
        push_notification((int)$n['user_id'], 'emergency', $title, $msg, APP_URL . '/pages/nurse/dashboard.php', $emergencyId, 'emergency');
    }
    // Notify admins
    $admins = $db->query("SELECT id FROM users WHERE role = 'admin'")->fetchAll();
    foreach ($admins as $a) {
        push_notification((int)$a['id'], 'emergency', $title, $msg, APP_URL . '/pages/admin/dashboard.php', $emergencyId, 'emergency');
    }
    // FCM push
    try {
        send_emergency_fcm($db, '🚨 ADVISER EMERGENCY', $msg, [
            'emergency_id' => (string)$emergencyId,
            'type' => 'emergency',
            'location' => $location,
        ]);
    } catch (Throwable $e) {
        error_log('Emergency FCM failed: ' . $e->getMessage());
    }

    json_response(true, ['emergency_id' => $emergencyId], 'Emergency reported. The clinic has been notified.');
}

// ---------------------------------------------------------------------
// 2. STUDENT DIRECT SOS (GPS Location)
// ---------------------------------------------------------------------
if ($action === 'sos') {
    csrf_verify_or_die();

    $emergencyType = trim((string)($_POST['emergency_type'] ?? 'Emergency Assistance'));
    $description = trim((string)($_POST['description'] ?? 'Student initiated SOS emergency'));
    $latitude = $_POST['latitude'] ?? null;
    $longitude = $_POST['longitude'] ?? null;
    $accuracy = (isset($_POST['accuracy']) && $_POST['accuracy'] !== '') ? trim((string)$_POST['accuracy']) : null;

    // Validate GPS coordinates server-side
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

    // Resolve student_id
    $studentId = null;
    $sStmt = $db->prepare("SELECT id FROM students WHERE user_id = ? LIMIT 1");
    $sStmt->execute([$u['id']]);
    $studentId = $sStmt->fetchColumn() ?: null;

    $locationUpdatedAt = ($validLat !== null && $validLng !== null) ? date('Y-m-d H:i:s') : null;

    $stmt = $db->prepare("
        INSERT INTO sos_emergencies
            (user_id, student_id, emergency_type, description, location_source, latitude, longitude, location_accuracy, location_updated_at, status, priority, source, reporter_role, ip_address, user_agent)
        VALUES (?, ?, ?, ?, 'gps', ?, ?, ?, ?, 'active', 'urgent', 'sos', 'student', ?, ?)
    ");
    $stmt->execute([
        $u['id'], $studentId, $emergencyType, $description,
        $validLat, $validLng, $accuracy, $locationUpdatedAt,
        $_SERVER['REMOTE_ADDR'] ?? null, $_SERVER['HTTP_USER_AGENT'] ?? null
    ]);
    $emergencyId = (int)$db->lastInsertId();

    // Log initial GPS position to location history table
    if ($validLat !== null && $validLng !== null) {
        try {
            $db->prepare("
                INSERT INTO emergency_location_updates (emergency_id, latitude, longitude, accuracy, recorded_at)
                VALUES (?, ?, ?, ?, NOW())
            ")->execute([$emergencyId, $validLat, $validLng, $accuracy]);
        } catch (Throwable $e) {
            error_log('Emergency location history insert failed: ' . $e->getMessage());
        }
    }

    log_activity($u['id'], 'SOS Emergency Alert', 'emergency', "$emergencyType - #$emergencyId");

    $senderName = trim((string)($u['first_name'] . ' ' . $u['last_name']));
    $locMsg = ($validLat !== null && $validLng !== null) ? " (GPS: $validLat, $validLng)" : " (GPS unavailable)";
    $title = '🚨 STUDENT SOS EMERGENCY';
    $msg = "{$senderName} triggered an emergency SOS: {$emergencyType}{$locMsg}.";

    // Notify clinic staff
    $nurses = $db->query("SELECT user_id FROM nurses")->fetchAll();
    foreach ($nurses as $n) {
        push_notification((int)$n['user_id'], 'emergency', $title, $msg, APP_URL . '/pages/nurse/dashboard.php', $emergencyId, 'emergency');
    }
    $admins = $db->query("SELECT id FROM users WHERE role = 'admin'")->fetchAll();
    foreach ($admins as $a) {
        push_notification((int)$a['id'], 'emergency', $title, $msg, APP_URL . '/pages/admin/dashboard.php', $emergencyId, 'emergency');
    }
    try {
        send_emergency_fcm($db, '🚨 STUDENT SOS', $msg, [
            'emergency_id' => (string)$emergencyId,
            'type' => 'emergency',
            'latitude' => (string)$validLat,
            'longitude' => (string)$validLng,
        ]);
    } catch (Throwable $e) {
        error_log('Emergency FCM failed: ' . $e->getMessage());
    }

    json_response(true, [
        'emergency_id'    => $emergencyId,
        'tracking_active' => true,
        'latitude'        => $validLat,
        'longitude'       => $validLng,
    ], 'Your emergency request has been sent to the School Clinic. Please remain where you are while waiting for assistance.');
}

// ---------------------------------------------------------------------
// 3. REAL-TIME LOCATION UPDATE (Student GPS watchPosition)
// ---------------------------------------------------------------------
if ($action === 'update_location') {
    csrf_verify_or_die();

    $emergencyId = (int)($_POST['emergency_id'] ?? 0);
    $latitude = $_POST['latitude'] ?? null;
    $longitude = $_POST['longitude'] ?? null;
    $accuracy = (isset($_POST['accuracy']) && $_POST['accuracy'] !== '') ? trim((string)$_POST['accuracy']) : null;

    if ($emergencyId <= 0) {
        json_response(false, [], 'Invalid emergency ID.');
    }

    // Verify emergency exists and belongs to current user
    $chk = $db->prepare("SELECT id, status, user_id FROM sos_emergencies WHERE id = ? LIMIT 1");
    $chk->execute([$emergencyId]);
    $em = $chk->fetch();
    if (!$em || (int)$em['user_id'] !== (int)$u['id']) {
        json_response(false, [], 'Emergency record not found or access denied.');
    }

    // If emergency is resolved or cancelled, signal frontend to STOP tracking
    if (in_array($em['status'], ['resolved', 'cancelled', 'false_alarm'], true)) {
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
        json_response(false, [], 'Invalid GPS coordinates.');
    }

    // Update main emergency record
    $db->prepare("
        UPDATE sos_emergencies
        SET latitude = ?, longitude = ?, location_accuracy = ?, location_updated_at = NOW(), updated_at = NOW()
        WHERE id = ?
    ")->execute([$validLat, $validLng, $accuracy, $emergencyId]);

    // Insert into location updates history trail
    try {
        $db->prepare("
            INSERT INTO emergency_location_updates (emergency_id, latitude, longitude, accuracy, recorded_at)
            VALUES (?, ?, ?, ?, NOW())
        ")->execute([$emergencyId, $validLat, $validLng, $accuracy]);
    } catch (Throwable $e) {
        error_log('Failed to log location update history: ' . $e->getMessage());
    }

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
    csrf_verify_or_die();

    $emergencyId = (int)($_POST['emergency_id'] ?? 0);
    if ($emergencyId <= 0) json_response(false, [], 'Invalid emergency ID.');

    $stmt = $db->prepare("SELECT * FROM sos_emergencies WHERE id = ? AND user_id = ? LIMIT 1");
    $stmt->execute([$emergencyId, $u['id']]);
    $em = $stmt->fetch();
    if (!$em) json_response(false, [], 'Emergency record not found.');

    if (in_array($em['status'], ['resolved', 'cancelled', 'false_alarm'], true)) {
        json_response(true, ['status' => $em['status']], 'Emergency is already closed.');
    }

    $db->prepare("
        UPDATE sos_emergencies
        SET status = 'cancelled', cancelled_by = ?, cancelled_at = NOW(), updated_at = NOW()
        WHERE id = ?
    ")->execute([$u['id'], $emergencyId]);

    log_activity($u['id'], 'Cancelled SOS Emergency', 'emergency', "Student cancelled SOS #$emergencyId");

    // Notify nurses
    $nurses = $db->query("SELECT user_id FROM nurses")->fetchAll();
    foreach ($nurses as $n) {
        push_notification((int)$n['user_id'], 'emergency', 'SOS Cancelled', "Student {$u['first_name']} {$u['last_name']} cancelled SOS #$emergencyId.", APP_URL . '/pages/nurse/dashboard.php', $emergencyId);
    }

    json_response(true, [
        'emergency_id'    => $emergencyId,
        'tracking_active' => false,
        'status'          => 'cancelled'
    ], 'Your emergency request has been cancelled. Location sharing has stopped.');
}

// ---------------------------------------------------------------------
// 6. LIVE TRACKING / GET LOCATION (Nurse/Admin map view)
// ---------------------------------------------------------------------
if ($action === 'live_tracking' || $action === 'get_location') {
    if (!in_array($role, ['nurse', 'admin'], true)) {
        json_response(false, [], 'Access denied.');
    }

    $emergencyId = (int)($_GET['emergency_id'] ?? 0);
    if ($emergencyId <= 0) json_response(false, [], 'Invalid emergency ID.');

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
    if (!$em) json_response(false, [], 'Emergency not found.');

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
    if (!in_array($role, ['nurse', 'admin', 'teacher'], true)) {
        json_response(false, [], 'Access denied.');
    }
    $where = '';
    $params = [];
    if ($role === 'teacher') {
        $where = "WHERE se.source = 'adviser' AND se.user_id = ?";
        $params[] = $u['id'];
    } else {
        $where = "WHERE se.status IN ('new','active','acknowledged','responding','responded')";
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
// 8. UPDATE STATUS (Nurse / Admin)
// ---------------------------------------------------------------------
if ($action === 'update_status') {
    if (!in_array($role, ['nurse', 'admin'], true)) {
        json_response(false, [], 'Access denied.');
    }
    csrf_verify_or_die();

    $id = (int)($_POST['emergency_id'] ?? 0);
    $op = trim((string)($_POST['op'] ?? ''));

    $stmt = $db->prepare("SELECT * FROM sos_emergencies WHERE id = ?");
    $stmt->execute([$id]);
    $em = $stmt->fetch();
    if (!$em) json_response(false, [], 'Emergency not found.');

    if ($op === 'acknowledge') {
        if (in_array($em['status'], ['resolved', 'cancelled', 'false_alarm'], true)) {
            json_response(false, [], 'This emergency is already closed.');
        }
        $db->prepare("UPDATE sos_emergencies SET status = 'acknowledged', acknowledged_by = ?, acknowledged_at = NOW(), updated_at = NOW() WHERE id = ?")
            ->execute([$u['id'], $id]);
        $log = 'Acknowledged';
    } elseif ($op === 'respond') {
        $db->prepare("UPDATE sos_emergencies SET status = 'responding', assigned_to = ?, updated_at = NOW() WHERE id = ?")
            ->execute([$u['id'], $id]);
        $log = 'Responding';
    } elseif ($op === 'resolve') {
        $resolution = trim((string)($_POST['resolution_notes'] ?? ''));
        $db->prepare("UPDATE sos_emergencies SET status = 'resolved', assigned_to = COALESCE(assigned_to,?), resolved_at = NOW(), resolution_notes = ?, updated_at = NOW() WHERE id = ?")
            ->execute([$u['id'], $resolution, $id]);
        $log = 'Resolved';
    } elseif ($op === 'cancel') {
        $db->prepare("UPDATE sos_emergencies SET status = 'cancelled', cancelled_by = ?, cancelled_at = NOW(), updated_at = NOW() WHERE id = ?")
            ->execute([$u['id'], $id]);
        $log = 'Cancelled';
    } else {
        json_response(false, [], 'Invalid operation.');
    }

    log_activity($u['id'], "Emergency $log", 'emergency', "Emergency #$id $log");

    // Notify the reporter if applicable
    if ($em['source'] === 'adviser' && $em['user_id']) {
        $labels = [
            'acknowledge' => ['✅ Emergency Acknowledged', 'The clinic has acknowledged the emergency you reported.'],
            'respond'     => ['🚑 Clinic Responding', 'A clinic staff member is now responding to the emergency you reported.'],
            'resolve'     => ['✅ Emergency Resolved', 'The emergency you reported has been resolved by the clinic.'],
            'cancel'      => ['Emergency Update', 'The emergency you reported has been updated by the clinic.'],
        ];
        $l = $labels[$op] ?? ['Emergency Update', 'Your reported emergency has been updated.'];
        push_notification((int)$em['user_id'], 'emergency', $l[0], $l[1], APP_URL . '/pages/adviser/emergencies.php', (int)$id);
    } elseif ($em['source'] === 'sos' && $em['user_id']) {
        if ($op === 'resolve') {
            push_notification((int)$em['user_id'], 'emergency', '✅ Emergency Resolved', 'Clinic staff has marked your emergency as resolved. Location sharing has ended.', APP_URL . '/pages/student/dashboard.php', (int)$id);
        }
    }

    json_response(true, [], "Emergency $log.");
}

json_response(false, [], 'Unknown action.');
