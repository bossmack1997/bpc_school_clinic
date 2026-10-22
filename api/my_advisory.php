<?php
/**
 * api/my_advisory.php
 * ------------------------------------------------------------------
 * TEACHER (adviser) self-service advisory class management.
 * Teachers select an EXISTING section (course/year/section master data)
 * and set it as their advisory class. No duplicate sections are created.
 *
 * Server-side rules:
 *  - role must be 'adviser'
 *  - max 3 ACTIVE advisory assignments per teacher
 *  - cannot set the same section twice while active
 *  - section must exist and be active
 * ------------------------------------------------------------------
 */
require_once __DIR__ . '/../config/config.php';
require_role('teacher');
if(!empty(current_user()['self_service_only']))admin_access_denied('Advisory access requires separate staff authorization.');

header('Content-Type: application/json');
$db = getDB();
$u = current_user();
$action = $_REQUEST['action'] ?? '';

const MAX_ACTIVE_ADVISORY = 3;

// ---------------------------------------------------------------------
// LIST: my assignments + all active sections (grouped client-side)
// ---------------------------------------------------------------------
if ($action === 'list') {
    $assignments = get_adviser_active_assignment($db, $u['id']);
    $sections = $db->query("SELECT id, name, course, year_level, school_year, semester
                            FROM sections WHERE status = 'active' ORDER BY course, year_level, name")->fetchAll();

    // Which sections are already taken by ANOTHER active adviser?
    $taken = $db->query("
        SELECT aa.section_id, u.first_name, u.last_name
        FROM advisory_assignments aa
        JOIN users u ON u.id = aa.teacher_id
        WHERE aa.status = 'active'
          AND (aa.end_date IS NULL OR aa.end_date >= CURDATE())
          AND (aa.start_date IS NULL OR aa.start_date <= CURDATE())
    ")->fetchAll();
    $takenMap = [];
    foreach ($taken as $t) {
        $takenMap[(int)$t['section_id']] = trim($t['first_name'] . ' ' . $t['last_name']);
    }

    json_response(true, [
        'assignments' => $assignments,
        'sections'    => $sections,
        'taken'       => $takenMap,
        'max_active'  => MAX_ACTIVE_ADVISORY,
    ]);
}

// ---------------------------------------------------------------------
// SET: assign myself to an existing section (idempotent per section)
// ---------------------------------------------------------------------
if ($action === 'set') {
    csrf_verify_or_die();
    $sectionId = (int)($_POST['section_id'] ?? 0);
    $schoolYear = trim($_POST['school_year'] ?? '');
    if ($sectionId <= 0) json_response(false, [], 'Please select a valid section.');

    // Section must exist and be active (never create sections here)
    $stmt = $db->prepare("SELECT id, name, course, year_level, status FROM sections WHERE id = ? LIMIT 1");
    $stmt->execute([$sectionId]);
    $section = $stmt->fetch();
    if (!$section || $section['status'] !== 'active') {
        json_response(false, [], 'That section is not available.');
    }

    // SERVER-SIDE DUPLICATE CHECK: Is this section already assigned to another active teacher?
    $occStmt = $db->prepare("
        SELECT u.first_name, u.last_name
        FROM advisory_assignments aa
        JOIN users u ON u.id = aa.teacher_id
        WHERE aa.section_id = ? AND aa.teacher_id <> ? AND aa.status = 'active'
          AND (aa.end_date IS NULL OR aa.end_date >= CURDATE())
          AND (aa.start_date IS NULL OR aa.start_date <= CURDATE())
        LIMIT 1
    ");
    $occStmt->execute([$sectionId, $u['id']]);
    $occupant = $occStmt->fetch();
    if ($occupant) {
        $occName = trim($occupant['first_name'] . ' ' . $occupant['last_name']);
        json_response(false, [], $section['name'] . ' is already assigned to another adviser (' . $occName . '). Please select another section.');
    }

    // Already my active advisory section? (idempotent success)
    $chk = $db->prepare("SELECT id FROM advisory_assignments WHERE teacher_id = ? AND section_id = ? AND status = 'active'");
    $chk->execute([$u['id'], $sectionId]);
    if ($chk->fetch()) {
        json_response(true, [], 'That section is already your advisory class.');
    }

    // Enforce max active assignments
    if (count_active_advisory_assignments($db, $u['id']) >= MAX_ACTIVE_ADVISORY) {
        json_response(false, [], 'You already have ' . MAX_ACTIVE_ADVISORY . ' active advisory class(es). Remove or deactivate your current advisory class before selecting a new one.');
    }

    try {
        $db->beginTransaction();

        // Reactivate a previous inactive assignment for this section if one exists
        $prev = $db->prepare("SELECT id FROM advisory_assignments WHERE teacher_id = ? AND section_id = ? AND status = 'inactive' ORDER BY id DESC LIMIT 1");
        $prev->execute([$u['id'], $sectionId]);
        $prevId = $prev->fetchColumn();

        if ($prevId) {
            $db->prepare("UPDATE advisory_assignments
                          SET status = 'active', start_date = CURDATE(), end_date = NULL, self_managed = 1, updated_at = NOW()
                          WHERE id = ?")
               ->execute([$prevId]);
        } else {
            $db->prepare("INSERT INTO advisory_assignments (teacher_id, section_id, school_year, status, self_managed, start_date)
                          VALUES (?, ?, ?, 'active', 1, CURDATE())")
               ->execute([$u['id'], $sectionId, $schoolYear ?: null]);
        }

        $db->commit();
        log_activity($u['id'], 'Set own advisory class', 'advisory', "section #{$sectionId} ({$section['name']})");
        json_response(true, [], 'Advisory class successfully assigned to ' . $section['name'] . '.');
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('Advisory set failed: ' . $e->getMessage());
        json_response(false, [], 'Could not save your advisory class. Please try again.');
    }
}

// ---------------------------------------------------------------------
// REMOVE: end my own advisory assignment for a section
// ---------------------------------------------------------------------
if ($action === 'remove') {
    csrf_verify_or_die();
    $assignmentId = (int)($_POST['assignment_id'] ?? 0);
    if ($assignmentId <= 0) json_response(false, [], 'Invalid assignment.');

    // Ownership check — a teacher can only remove their OWN assignment
    $stmt = $db->prepare("SELECT id FROM advisory_assignments WHERE id = ? AND teacher_id = ? AND status = 'active'");
    $stmt->execute([$assignmentId, $u['id']]);
    if (!$stmt->fetch()) json_response(false, [], 'Assignment not found or not yours.');

    $db->prepare("UPDATE advisory_assignments
                  SET status = 'inactive', end_date = COALESCE(end_date, CURDATE()), updated_at = NOW()
                  WHERE id = ?")
       ->execute([$assignmentId]);
    log_activity($u['id'], 'Removed own advisory class', 'advisory', "assignment #$assignmentId");
    json_response(true, [], 'Advisory class removed.');
}

json_response(false, [], 'Unknown action.');
