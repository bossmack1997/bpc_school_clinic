<?php
/**
 * api/wellbeing.php
 * Student Medical Health Profile endpoints.
 * Provides medical information (conditions, allergies, previous illnesses, medications) for clinic care.
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
// GET — fetch medical health profile
// ---------------------------------------------------------------------
if ($action === 'get') {
    $studentId = (int)($_GET['student_id'] ?? 0);

    if ($studentId <= 0) {
        if ($role === 'student') {
            $studentId = current_student_id($db, $u['id']) ?? 0;
        }
    }

    if ($studentId <= 0) {
        json_response(false, [], 'Invalid student ID.');
    }

    // Verify student exists
    $stmt = $db->prepare("SELECT id, user_id, blood_type, allergies, known_medical_conditions, emergency_contact_name, emergency_contact_number FROM students WHERE id = ?");
    $stmt->execute([$studentId]);
    $studentRow = $stmt->fetch();
    if (!$studentRow) {
        json_response(false, [], 'Student not found.');
    }

    if ($role === 'student') {
        $own = current_student_id($db, $u['id']);
        if (!$own || $own !== $studentId) {
            json_response(false, [], 'Access denied. You can only view your own medical health information.');
        }
    } elseif ($role === 'nurse' || $role === 'admin') {
        log_activity($u['id'], 'Viewed student medical information', 'medical_records', "Student #$studentId");
    } else {
        json_response(false, [], 'Access denied.');
    }

    $stmt = $db->prepare("SELECT * FROM wellbeing_profiles WHERE student_id = ?");
    $stmt->execute([$studentId]);
    $row = $stmt->fetch();

    $medicalProfile = [
        'student_id'               => $studentId,
        'blood_type'               => $studentRow['blood_type'] ?? '',
        'allergies'                => !empty($studentRow['allergies']) ? $studentRow['allergies'] : ($row['allergies'] ?? ''),
        'known_medical_conditions' => !empty($studentRow['known_medical_conditions']) ? $studentRow['known_medical_conditions'] : ($row['known_medical_conditions'] ?? ''),
        'previous_illnesses'       => $row['previous_illnesses'] ?? '',
        'current_medications'      => $row['current_medications'] ?? '',
        'other_medical_info'       => $row['other_medical_info'] ?? '',
        'emergency_contact_name'   => $studentRow['emergency_contact_name'] ?? '',
        'emergency_contact_number' => $studentRow['emergency_contact_number'] ?? '',
        'updated_at'               => $row['updated_at'] ?? null,
    ];

    json_response(true, ['profile' => $medicalProfile, 'exists' => true, 'scope' => 'medical_only']);
}

// ---------------------------------------------------------------------
// SAVE — student updates own medical health profile
// ---------------------------------------------------------------------
if ($action === 'save') {
    if ($role !== 'student') {
        json_response(false, [], 'Only students can update their own medical profile.');
    }
    csrf_verify_or_die();

    $studentId = current_student_id($db, $u['id']);
    if (!$studentId) {
        json_response(false, [], 'Student record not found.');
    }

    $text = function ($k) {
        return trim(substr((string)($_POST[$k] ?? ''), 0, 4000));
    };

    $allergies = $text('allergies');
    $conditions = $text('known_medical_conditions');
    $prevIllness = $text('previous_illnesses');
    $medications = $text('current_medications');
    $otherInfo = $text('other_medical_info');
    $bloodType = trim((string)($_POST['blood_type'] ?? ''));

    // Update students table
    $updateFields = [];
    $updateParams = [];
    if ($allergies !== '') {
        $updateFields[] = "allergies = ?";
        $updateParams[] = $allergies;
    }
    if ($conditions !== '') {
        $updateFields[] = "known_medical_conditions = ?";
        $updateParams[] = $conditions;
    }
    if ($bloodType !== '') {
        $updateFields[] = "blood_type = ?";
        $updateParams[] = $bloodType;
    }
    if (!empty($updateFields)) {
        $updateParams[] = $studentId;
        $db->prepare("UPDATE students SET " . implode(', ', $updateFields) . " WHERE id = ?")->execute($updateParams);
    }

    // Upsert into wellbeing_profiles table for backwards compatibility
    $stmt = $db->prepare("SELECT id FROM wellbeing_profiles WHERE student_id = ?");
    $stmt->execute([$studentId]);
    $existing = $stmt->fetchColumn();

    if ($existing) {
        $db->prepare("UPDATE wellbeing_profiles SET allergies = ?, known_medical_conditions = ?, previous_illnesses = ?, current_medications = ?, other_medical_info = ?, updated_at = NOW() WHERE student_id = ?")
           ->execute([$allergies, $conditions, $prevIllness, $medications, $otherInfo, $studentId]);
    } else {
        $db->prepare("INSERT INTO wellbeing_profiles (student_id, allergies, known_medical_conditions, previous_illnesses, current_medications, other_medical_info, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())")
           ->execute([$studentId, $allergies, $conditions, $prevIllness, $medications, $otherInfo]);
    }

    log_activity($u['id'], 'Updated medical health profile', 'medical_records', "Student #$studentId");
    json_response(true, [], 'Your medical health profile has been saved.');
}

json_response(false, [], 'Unknown action.');
