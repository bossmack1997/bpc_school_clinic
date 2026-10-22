<?php
/**
 * api/sections.php
 * ------------------------------------------------------------------
 * ADMIN Section & Course Management API
 * Provides endpoints for creating, editing, activating/deactivating
 * sections, assigning advisers, and inspecting enrolled students.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../config/config.php';
require_role(['admin', 'teacher']);
if(current_user()['role']==='teacher' && !empty(current_user()['self_service_only']))admin_access_denied('Section access requires separate staff authorization.');
if(current_user()['role']==='admin')require_admin_permission('manage_sections');

header('Content-Type: application/json');
$db = getDB();
$user = current_user();
$action = $_REQUEST['action'] ?? '';

// ---------------------------------------------------------------------
// LIST: all sections with adviser assignments & student counts
// ---------------------------------------------------------------------
if ($action === 'list') {
    $stmt = $db->query("
        SELECT s.*,
               aa.id AS assignment_id,
               aa.teacher_id,
               u.first_name AS adviser_first_name,
               u.last_name AS adviser_last_name,
               u.email AS adviser_email,
               t.employee_id AS adviser_employee_id
        FROM sections s
        LEFT JOIN advisory_assignments aa ON aa.section_id = s.id AND aa.status = 'active'
            AND (aa.end_date IS NULL OR aa.end_date >= CURDATE())
            AND (aa.start_date IS NULL OR aa.start_date <= CURDATE())
        LEFT JOIN users u ON u.id = aa.teacher_id
        LEFT JOIN teachers t ON t.user_id = u.id
        ORDER BY s.course ASC, s.year_level ASC, s.name ASC
    ");
    $sections = $stmt->fetchAll();

    // Fetch active advisers for dropdown
    $advisers = $db->query("
        SELECT u.id, u.first_name, u.last_name, u.email, t.employee_id, t.department
        FROM users u
        LEFT JOIN teachers t ON t.user_id = u.id
        WHERE u.role = 'teacher' AND u.status = 'active'
        ORDER BY u.last_name, u.first_name
    ")->fetchAll();

    // Fetch all active students to calculate real enrollment count per section
    $allStudents = $db->query("
        SELECT s.id, s.course, s.year_level, s.section, u.first_name, u.last_name
        FROM students s
        JOIN users u ON u.id = s.user_id
        WHERE u.status = 'active'
    ")->fetchAll();

    foreach ($sections as &$sec) {
        $secName = trim((string)$sec['name']);
        $secCourse = trim((string)($sec['course'] ?? ''));
        $secYear = trim((string)($sec['year_level'] ?? ''));

        $normSec = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $secName));
        $normCourse = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $secCourse));

        $count = 0;
        foreach ($allStudents as $st) {
            $stNormSec = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', (string)$st['section']));
            $stNormCourse = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', (string)$st['course']));

            if (!empty($stNormSec)) {
                if ($stNormSec === $normSec || strcasecmp($st['section'], $secName) === 0) {
                    $count++;
                } elseif (!empty($normCourse) && (empty($stNormCourse) || $stNormCourse === $normCourse) &&
                          str_ends_with($normSec, $stNormSec) && strlen($stNormSec) <= 3) {
                    $count++;
                }
            }
        }
        $sec['student_count'] = $count;
        $sec['adviser_name'] = !empty($sec['adviser_first_name']) ? trim($sec['adviser_first_name'] . ' ' . $sec['adviser_last_name']) : null;
    }
    unset($sec);

    json_response(true, [
        'sections' => $sections,
        'advisers' => $advisers
    ]);
}

// ---------------------------------------------------------------------
// CREATE: add a new section (Admin only)
// ---------------------------------------------------------------------
if ($action === 'create') {
    if ($user['role'] !== 'admin') json_response(false, [], 'Admin authorization required.');
    csrf_verify_or_die();

    $name = trim($_POST['name'] ?? '');
    $course = trim($_POST['course'] ?? '');
    $yearLevel = trim($_POST['year_level'] ?? '');
    $schoolYear = trim($_POST['school_year'] ?? '');
    $semester = trim($_POST['semester'] ?? '');
    $adviserId = (int)($_POST['adviser_id'] ?? 0);

    if ($name === '') {
        json_response(false, [], 'Section name is required (e.g. BSIS 4A).');
    }

    // Check duplicate name
    $chk = $db->prepare("SELECT id FROM sections WHERE TRIM(LOWER(name)) = TRIM(LOWER(?)) LIMIT 1");
    $chk->execute([$name]);
    if ($chk->fetch()) {
        json_response(false, [], 'A section with this name already exists.');
    }

    try {
        $db->beginTransaction();

        $stmt = $db->prepare("
            INSERT INTO sections (name, course, year_level, school_year, semester, status)
            VALUES (?, ?, ?, ?, ?, 'active')
        ");
        $stmt->execute([
            $name,
            $course ?: null,
            $yearLevel ?: null,
            $schoolYear ?: null,
            $semester ?: null
        ]);
        $sectionId = (int)$db->lastInsertId();

        // If adviser was selected, assign them
        if ($adviserId > 0) {
            $db->prepare("
                INSERT INTO advisory_assignments (teacher_id, section_id, school_year, semester, status, self_managed, start_date)
                VALUES (?, ?, ?, ?, 'active', 0, CURDATE())
            ")->execute([$adviserId, $sectionId, $schoolYear ?: null, $semester ?: null]);
        }

        $db->commit();
        log_activity($user['id'], 'Created section', 'sections', "Section #$sectionId ($name)");
        json_response(true, ['id' => $sectionId], "Section '$name' created successfully.");
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('Section create failed: ' . $e->getMessage());
        json_response(false, [], 'Could not create section. Please try again.');
    }
}

// ---------------------------------------------------------------------
// UPDATE: update section details (Admin only)
// ---------------------------------------------------------------------
if ($action === 'update') {
    if ($user['role'] !== 'admin') json_response(false, [], 'Admin authorization required.');
    csrf_verify_or_die();

    $id = (int)($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $course = trim($_POST['course'] ?? '');
    $yearLevel = trim($_POST['year_level'] ?? '');
    $schoolYear = trim($_POST['school_year'] ?? '');
    $semester = trim($_POST['semester'] ?? '');
    $status = trim($_POST['status'] ?? 'active');
    $adviserId = (int)($_POST['adviser_id'] ?? 0);

    if ($id <= 0 || $name === '') {
        json_response(false, [], 'Valid section ID and name are required.');
    }

    // Check duplicate name on other sections
    $chk = $db->prepare("SELECT id FROM sections WHERE TRIM(LOWER(name)) = TRIM(LOWER(?)) AND id <> ? LIMIT 1");
    $chk->execute([$name, $id]);
    if ($chk->fetch()) {
        json_response(false, [], 'Another section with this name already exists.');
    }

    try {
        $db->beginTransaction();

        $stmt = $db->prepare("
            UPDATE sections
            SET name = ?, course = ?, year_level = ?, school_year = ?, semester = ?, status = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([
            $name,
            $course ?: null,
            $yearLevel ?: null,
            $schoolYear ?: null,
            $semester ?: null,
            $status === 'inactive' ? 'inactive' : 'active',
            $id
        ]);

        // Manage adviser assignment
        $currAssign = $db->prepare("SELECT id, teacher_id FROM advisory_assignments WHERE section_id = ? AND status = 'active' LIMIT 1");
        $currAssign->execute([$id]);
        $activeAssign = $currAssign->fetch();

        if ($adviserId > 0) {
            if ($activeAssign) {
                if ((int)$activeAssign['teacher_id'] !== $adviserId) {
                    // Deactivate previous
                    $db->prepare("UPDATE advisory_assignments SET status = 'inactive', end_date = CURDATE(), updated_at = NOW() WHERE id = ?")
                       ->execute([$activeAssign['id']]);
                    // Insert new
                    $db->prepare("INSERT INTO advisory_assignments (teacher_id, section_id, school_year, semester, status, self_managed, start_date)
                                  VALUES (?, ?, ?, ?, 'active', 0, CURDATE())")
                       ->execute([$adviserId, $id, $schoolYear ?: null, $semester ?: null]);
                }
            } else {
                $db->prepare("INSERT INTO advisory_assignments (teacher_id, section_id, school_year, semester, status, self_managed, start_date)
                              VALUES (?, ?, ?, ?, 'active', 0, CURDATE())")
                   ->execute([$adviserId, $id, $schoolYear ?: null, $semester ?: null]);
            }
        } else {
            // Remove adviser assignment if set to 0 (unassigned)
            if ($activeAssign) {
                $db->prepare("UPDATE advisory_assignments SET status = 'inactive', end_date = CURDATE(), updated_at = NOW() WHERE id = ?")
                   ->execute([$activeAssign['id']]);
            }
        }

        $db->commit();
        log_activity($user['id'], 'Updated section', 'sections', "Section #$id ($name)");
        json_response(true, [], "Section '$name' updated successfully.");
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('Section update failed: ' . $e->getMessage());
        json_response(false, [], 'Could not update section. Please try again.');
    }
}

// ---------------------------------------------------------------------
// TOGGLE STATUS: Activate / Deactivate section (Admin only)
// ---------------------------------------------------------------------
if ($action === 'toggle_status') {
    if ($user['role'] !== 'admin') json_response(false, [], 'Admin authorization required.');
    csrf_verify_or_die();

    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) json_response(false, [], 'Invalid section ID.');

    $stmt = $db->prepare("SELECT id, name, status FROM sections WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $sec = $stmt->fetch();
    if (!$sec) json_response(false, [], 'Section not found.');

    $newStatus = $sec['status'] === 'active' ? 'inactive' : 'active';
    $db->prepare("UPDATE sections SET status = ?, updated_at = NOW() WHERE id = ?")->execute([$newStatus, $id]);

    log_activity($user['id'], 'Toggled section status', 'sections', "Section #$id ({$sec['name']}) -> $newStatus");
    json_response(true, ['status' => $newStatus], "Section '{$sec['name']}' is now {$newStatus}.");
}

// ---------------------------------------------------------------------
// SECTION STUDENTS: list all students matching this section
// ---------------------------------------------------------------------
if ($action === 'section_students') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) json_response(false, [], 'Invalid section ID.');

    $secStmt = $db->prepare("SELECT * FROM sections WHERE id = ? LIMIT 1");
    $secStmt->execute([$id]);
    $sec = $secStmt->fetch();
    if (!$sec) json_response(false, [], 'Section not found.');

    if (canonical_role($user['role'] ?? '') === 'teacher') {
        $assignedStmt = $db->prepare("
            SELECT id FROM advisory_assignments 
            WHERE teacher_id = ? AND section_id = ? AND status = 'active'
              AND (end_date IS NULL OR end_date >= CURDATE())
              AND (start_date IS NULL OR start_date <= CURDATE())
            LIMIT 1
        ");
        $assignedStmt->execute([(int)$user['id'], $id]);
        if (!$assignedStmt->fetch()) {
            json_response(false, [], 'Access denied. You are not assigned as adviser to this section.');
        }
    }

    $secName = trim((string)$sec['name']);
    $secCourse = trim((string)($sec['course'] ?? ''));
    $secYear = trim((string)($sec['year_level'] ?? ''));

    $allStudents = $db->query("
        SELECT s.id, s.student_number, s.course, s.year_level, s.section, s.gender, s.blood_type,
               u.first_name, u.last_name, u.email, u.phone, u.status, u.profile_picture
        FROM students s
        JOIN users u ON u.id = s.user_id
        WHERE u.status = 'active'
        ORDER BY u.last_name, u.first_name
    ")->fetchAll();

    $normSec = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $secName));
    $normCourse = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $secCourse));

    $matched = [];
    foreach ($allStudents as $st) {
        $stNormSec = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', (string)$st['section']));
        $stNormCourse = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', (string)$st['course']));

        if (!empty($stNormSec)) {
            if ($stNormSec === $normSec || strcasecmp($st['section'], $secName) === 0) {
                $matched[] = $st;
            } elseif (!empty($normCourse) && (empty($stNormCourse) || $stNormCourse === $normCourse) &&
                      str_ends_with($normSec, $stNormSec) && strlen($stNormSec) <= 3) {
                $matched[] = $st;
            }
        }
    }

    json_response(true, [
        'section'  => $sec,
        'students' => $matched
    ]);
}

json_response(false, [], 'Unknown action.');
