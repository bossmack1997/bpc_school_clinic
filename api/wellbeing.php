<?php
/**
 * api/wellbeing.php
 * Student Health & Well-being profile endpoints.
 * Server-side authorization enforces ownership / role-based access.
 */
require_once __DIR__ . '/../config/config.php';
require_login();

header('Content-Type: application/json');
$db = getDB();
$u = current_user();
$role = $u['role'];
$action = $_REQUEST['action'] ?? '';

// Map current user -> own student id (if student)
function current_student_id($db, $userId) {
    $stmt = $db->prepare("SELECT id FROM students WHERE user_id = ?");
    $stmt->execute([(int)$userId]);
    $id = $stmt->fetchColumn();
    return $id === false ? null : (int)$id;
}

// ---------------------------------------------------------------------
// GET — fetch a well-being profile
//   - student: only own
//   - nurse/guidance: any student (detailed) + audit log
//   - adviser/admin: NOT allowed detailed -> 403
// ---------------------------------------------------------------------
if ($action === 'get') {
    $studentId = (int)($_GET['student_id'] ?? 0);

    if ($role === 'student') {
        $own = current_student_id($db, $u['id']);
        if (!$own || $own !== $studentId) {
            json_response(false, [], 'You can only view your own well-being information.');
        }
    } elseif (in_array($role, ['nurse', 'guidance'], true)) {
        // authorized to view details
        $stmt = $db->prepare("SELECT id FROM students WHERE id = ?");
        $stmt->execute([$studentId]);
        if (!$stmt->fetch()) {
            json_response(false, [], 'Student not found.');
        }
        log_activity($u['id'], 'Viewed sensitive well-being information', 'wellbeing', "Student #$studentId");
    } else {
        json_response(false, [], 'Access denied.');
    }

    if ($studentId <= 0) {
        json_response(false, [], 'Invalid student.');
    }

    $stmt = $db->prepare("SELECT * FROM wellbeing_profiles WHERE student_id = ?");
    $stmt->execute([$studentId]);
    $row = $stmt->fetch();
    if (!$row) {
        json_response(true, ['profile' => null, 'exists' => false]);
    }
    json_response(true, ['profile' => $row, 'exists' => true]);
}

// ---------------------------------------------------------------------
// SAVE — student updates own well-being profile
// ---------------------------------------------------------------------
if ($action === 'save') {
    if ($role !== 'student') {
        json_response(false, [], 'Only students can update their own well-being profile.');
    }
    csrf_verify_or_die();

    $studentId = current_student_id($db, $u['id']);
    if (!$studentId) {
        json_response(false, [], 'Student record not found.');
    }

    $consent = trim($_POST['consent_status'] ?? '');
    if ($consent !== 'granted') {
        json_response(false, [], 'You must acknowledge the privacy notice before saving.');
    }

    $text = function ($k) {
        return trim(substr((string)($_POST[$k] ?? ''), 0, 8000));
    };

    $prefs = $_POST['support_preferences'] ?? [];
    if (is_array($prefs)) {
        $prefs = array_map('trim', array_filter($prefs, fn($p) => trim((string)$p) !== ''));
    } else {
        $prefs = [];
    }
    $prefsJson = json_encode($prefs);

    $fields = [
        'known_medical_conditions' => $text('known_medical_conditions'),
        'allergies'                => $text('allergies'),
        'previous_illnesses'       => $text('previous_illnesses'),
        'current_medications'      => $text('current_medications'),
        'other_medical_info'       => $text('other_medical_info'),
        'concerns'                 => $text('concerns'),
        'previous_support'         => $text('previous_support'),
        'diagnosis_disclosed'      => $text('diagnosis_disclosed'),
        'current_support'          => $text('current_support'),
        'other_wellbeing'          => $text('other_wellbeing'),
        'support_preferences'      => $prefsJson,
        'student_notes'            => $text('student_notes'),
        'consent_status'           => 'granted',
        'consent_at'               => date('Y-m-d H:i:s'),
    ];

    // Upsert (preserve existing created_at on update)
    $stmt = $db->prepare("SELECT id FROM wellbeing_profiles WHERE student_id = ?");
    $stmt->execute([$studentId]);
    $existing = $stmt->fetchColumn();

    if ($existing) {
        $cols = array_keys($fields);
        $sql = "UPDATE wellbeing_profiles SET " . implode(', ', array_map(fn($c) => "$c = ?", $cols)) . ", updated_at = NOW() WHERE student_id = ?";
        $params = array_merge(array_values($fields), [$studentId]);
        $db->prepare($sql)->execute($params);
    } else {
        $cols = array_keys($fields);
        $sql = "INSERT INTO wellbeing_profiles (student_id, " . implode(', ', $cols) . ") VALUES (?, " . implode(', ', array_fill(0, count($cols), '?')) . ")";
        $params = array_merge([$studentId], array_values($fields));
        $db->prepare($sql)->execute($params);
    }

    log_activity($u['id'], 'Updated well-being profile', 'wellbeing', "Student #$studentId");
    json_response(true, [], 'Your health and well-being information has been saved securely.');
}

json_response(false, [], 'Unknown action.');
