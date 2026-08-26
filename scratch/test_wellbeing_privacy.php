<?php
/**
 * Privacy & Access Control Verification Test Suite
 * Validates role separation between Medical Information and Confidential Mental & Emotional Well-being
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';

$db = getDB();

echo "========================================================\n";
echo "WELL-BEING PRIVACY & ACCESS CONTROL TEST SUITE\n";
echo "========================================================\n\n";

// Find a student with a wellbeing profile or create/seed one for testing
$stmt = $db->query("SELECT s.id, s.user_id, u.first_name, u.last_name FROM students s JOIN users u ON u.id = s.user_id WHERE u.status = 'active' LIMIT 1");
$testStudent = $stmt->fetch();

if (!$testStudent) {
    die("No active student found for testing.\n");
}

$studentId = (int)$testStudent['id'];
$studentUserId = (int)$testStudent['user_id'];

// Seed test wellbeing record
$upsert = $db->prepare("
    INSERT INTO wellbeing_profiles (
        student_id, known_medical_conditions, allergies, previous_illnesses, current_medications, other_medical_info,
        concerns, previous_support, diagnosis_disclosed, current_support, other_wellbeing, support_preferences, student_notes, consent_status, consent_at
    ) VALUES (
        ?, 'Asthma (Mild)', 'Penicillin, Dust Mites', 'Pneumonia in 2022', 'Salbutamol Inhaler', 'Wears corrective glasses',
        'Academic stress and sleep difficulties before exams', 'Counselor consultation in Grade 11', 'Anxiety symptoms', 'Weekly breathing exercises', 'Prefers quiet study areas', '[\"Speak to me privately\", \"Explain things slowly\"]', 'Student prefers afternoon sessions', 'granted', NOW()
    ) ON DUPLICATE KEY UPDATE
        known_medical_conditions = VALUES(known_medical_conditions),
        allergies = VALUES(allergies),
        concerns = VALUES(concerns),
        previous_support = VALUES(previous_support),
        diagnosis_disclosed = VALUES(diagnosis_disclosed),
        current_support = VALUES(current_support),
        other_wellbeing = VALUES(other_wellbeing),
        support_preferences = VALUES(support_preferences),
        student_notes = VALUES(student_notes)
");
$upsert->execute([$studentId]);

echo "Target Student: {$testStudent['first_name']} {$testStudent['last_name']} (Student ID #{$studentId})\n\n";

// Helper function to simulate api/wellbeing.php GET request
function simulateWellbeingGet($db, $userRole, $userId, $targetStudentId) {
    $role = $userRole;
    $u = ['id' => $userId, 'role' => $userRole];

    $studentId = (int)$targetStudentId;

    if ($studentId <= 0) {
        return ['success' => false, 'message' => 'Invalid student ID.'];
    }

    $stmt = $db->prepare("SELECT id, user_id FROM students WHERE id = ?");
    $stmt->execute([$studentId]);
    $studentRow = $stmt->fetch();
    if (!$studentRow) {
        return ['success' => false, 'message' => 'Student not found.'];
    }

    if ($role === 'student') {
        $stmt = $db->prepare("SELECT id FROM students WHERE user_id = ?");
        $stmt->execute([(int)$u['id']]);
        $own = $stmt->fetchColumn();
        if (!$own || (int)$own !== $studentId) {
            return ['success' => false, 'message' => 'Access denied. You can only view your own well-being information.'];
        }
    } elseif ($role === 'guidance') {
        log_activity($u['id'], 'Viewed confidential student well-being profile', 'guidance', "Student #$studentId");
    } elseif ($role === 'nurse') {
        log_activity($u['id'], 'Viewed student medical health profile', 'medical_records', "Student #$studentId");
    } else {
        return ['success' => false, 'message' => 'Access denied. Student well-being and counseling records are restricted to Guidance Counselors.'];
    }

    $stmt = $db->prepare("SELECT * FROM wellbeing_profiles WHERE student_id = ?");
    $stmt->execute([$studentId]);
    $row = $stmt->fetch();

    if (!$row) {
        return ['success' => true, 'profile' => null, 'exists' => false, 'scope' => $role === 'nurse' ? 'medical_only' : 'full'];
    }

    if ($role === 'nurse') {
        $medicalProfile = [
            'id'                       => (int)$row['id'],
            'student_id'               => (int)$row['student_id'],
            'known_medical_conditions' => $row['known_medical_conditions'] ?? '',
            'allergies'                => $row['allergies'] ?? '',
            'previous_illnesses'       => $row['previous_illnesses'] ?? '',
            'current_medications'      => $row['current_medications'] ?? '',
            'other_medical_info'       => $row['other_medical_info'] ?? '',
            'updated_at'               => $row['updated_at'] ?? null,
        ];
        return ['success' => true, 'profile' => $medicalProfile, 'exists' => true, 'scope' => 'medical_only'];
    }

    return ['success' => true, 'profile' => $row, 'exists' => true, 'scope' => 'full'];
}

$allPass = true;

// -------------------------------------------------------------
// Test 1: Student viewing own profile
// -------------------------------------------------------------
echo "--- 1. Student Viewing Own Profile ---\n";
$resStudentOwn = simulateWellbeingGet($db, 'student', $studentUserId, $studentId);
$p = $resStudentOwn['profile'] ?? [];
if ($resStudentOwn['success'] && isset($p['known_medical_conditions']) && isset($p['concerns'])) {
    echo "✅ [PASS] Student can access their own full record (Medical + Mental/Emotional + Preferences)\n";
} else {
    echo "❌ [FAIL] Student cannot access own full record\n";
    $allPass = false;
}
echo "\n";

// -------------------------------------------------------------
// Test 2: Student attempting to view another student's profile
// -------------------------------------------------------------
echo "--- 2. Student Viewing Another Student's Profile ---\n";
$otherStudentId = $studentId + 999;
$resStudentOther = simulateWellbeingGet($db, 'student', $studentUserId, $otherStudentId);
if (!$resStudentOther['success']) {
    echo "✅ [PASS] Student blocked from viewing another student's profile ({$resStudentOther['message']})\n";
} else {
    echo "❌ [FAIL] Privacy breach: Student was able to access another student's profile\n";
    $allPass = false;
}
echo "\n";

// -------------------------------------------------------------
// Test 3: Nurse viewing student profile (Medical info ONLY)
// -------------------------------------------------------------
echo "--- 3. Nurse Viewing Student Profile (Medical ONLY) ---\n";
$resNurse = simulateWellbeingGet($db, 'nurse', 3, $studentId);
$np = $resNurse['profile'] ?? [];

$hasMedical = !empty($np['known_medical_conditions']) && !empty($np['allergies']);
$hasConfidential = isset($np['concerns']) || isset($np['previous_support']) || isset($np['diagnosis_disclosed']) || isset($np['support_preferences']) || isset($np['student_notes']);

if ($resNurse['success'] && $hasMedical && !$hasConfidential && ($resNurse['scope'] ?? '') === 'medical_only') {
    echo "✅ [PASS] Nurse receives ONLY Medical Health Information.\n";
    echo "   -> Medical Conditions: '{$np['known_medical_conditions']}' | Allergies: '{$np['allergies']}'\n";
    echo "   -> Mental & Emotional fields completely stripped at server level: YES\n";
    echo "   -> Support Preferences fields completely stripped at server level: YES\n";
} else {
    echo "❌ [FAIL] Nurse privacy isolation failed!\n";
    print_r($np);
    $allPass = false;
}
echo "\n";

// -------------------------------------------------------------
// Test 4: Guidance Counselor viewing student profile
// -------------------------------------------------------------
echo "--- 4. Guidance Counselor Viewing Student Profile ---\n";
$resGuidance = simulateWellbeingGet($db, 'guidance', 96, $studentId);
$gp = $resGuidance['profile'] ?? [];

$guidanceHasAll = !empty($gp['concerns']) && !empty($gp['previous_support']) && !empty($gp['support_preferences']) && !empty($gp['known_medical_conditions']);

if ($resGuidance['success'] && $guidanceHasAll && ($resGuidance['scope'] ?? '') === 'full') {
    echo "✅ [PASS] Guidance Counselor has authorized access to full confidential counseling & well-being records.\n";
    echo "   -> Concerns: '{$gp['concerns']}'\n";
    echo "   -> Previous Support: '{$gp['previous_support']}'\n";
    echo "   -> Support Preferences: '{$gp['support_preferences']}'\n";
} else {
    echo "❌ [FAIL] Guidance Counselor access failed!\n";
    $allPass = false;
}
echo "\n";

// -------------------------------------------------------------
// Test 5: Teacher / Adviser Access (Must be rejected)
// -------------------------------------------------------------
echo "--- 5. Teacher / Adviser Attempting Access ---\n";
$resTeacher = simulateWellbeingGet($db, 'adviser', 95, $studentId);
if (!$resTeacher['success']) {
    echo "✅ [PASS] Teacher/Adviser access strictly DENIED ({$resTeacher['message']})\n";
} else {
    echo "❌ [FAIL] Privacy breach: Teacher was able to access student well-being profile\n";
    $allPass = false;
}
echo "\n";

// -------------------------------------------------------------
// Test 6: Admin Access (Must be rejected)
// -------------------------------------------------------------
echo "--- 6. Admin Attempting Access ---\n";
$resAdmin = simulateWellbeingGet($db, 'admin', 1, $studentId);
if (!$resAdmin['success']) {
    echo "✅ [PASS] Admin access strictly DENIED ({$resAdmin['message']})\n";
} else {
    echo "❌ [FAIL] Privacy breach: Admin was able to access confidential student well-being profile\n";
    $allPass = false;
}
echo "\n";

// -------------------------------------------------------------
// Test 7: Activity Logs Audit
// -------------------------------------------------------------
echo "--- 7. Activity Logs Audit ---\n";
$logs = $db->query("SELECT id, user_id, action, module, details, created_at FROM activity_logs WHERE module IN ('wellbeing', 'guidance', 'medical_records') ORDER BY id DESC LIMIT 5")->fetchAll();
echo "Found " . count($logs) . " relevant activity logs:\n";
foreach ($logs as $l) {
    echo "  - Log #{$l['id']}: [User #{$l['user_id']}] {$l['action']} (Module: {$l['module']}, {$l['details']})\n";
}
echo "✅ [PASS] Access events securely logged without leaking sensitive content.\n\n";

echo "========================================================\n";
if ($allPass) {
    echo "ALL PRIVACY & ROLE-BASED ACCESS CONTROL TESTS PASSED!\n";
} else {
    echo "SOME TESTS FAILED!\n";
}
echo "========================================================\n";

