<?php
/**
 * Authentication / Authorization helpers
 */

function current_user() {
    $user = $_SESSION['user'] ?? null;
    if (!is_array($user) || empty($user['id'])) {
        return null;
    }

    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT id, role, status, admin_type, credential_version, username, email, first_name, last_name, phone, profile_picture FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([(int)$user['id']]);
        $dbUser = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($dbUser && isset($_SESSION['credential_version']) && (int)$_SESSION['credential_version'] !== (int)$dbUser['credential_version']) return null;
        if (!$dbUser || ($dbUser['status'] ?? '') !== 'active' || !is_supported_role($dbUser['role'] ?? '')) {
            return null;
        }

        $_SESSION['user'] = [
            'id' => (int)$dbUser['id'],
            'role' => canonical_role($dbUser['role']),
            'admin_type' => $dbUser['admin_type'] ?? null,
            'username' => $dbUser['username'] ?? ($user['username'] ?? null),
            'email' => $dbUser['email'] ?? ($user['email'] ?? null),
            'first_name' => $dbUser['first_name'] ?? ($user['first_name'] ?? null),
            'last_name' => $dbUser['last_name'] ?? ($user['last_name'] ?? null),
            'phone' => $dbUser['phone'] ?? ($user['phone'] ?? ''),
            'profile_picture' => $dbUser['profile_picture'] ?? ($user['profile_picture'] ?? null),
        ];

        return $_SESSION['user'];
    } catch (Throwable $e) {
        error_log('current_user verification failed: ' . $e->getMessage());
        return null;
    }
}

function is_logged_in() {
    $user = current_user();
    return is_array($user) && !empty($user['id']);
}

function is_api_request(): bool {
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        return true;
    }
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    if (str_contains($uri, '/api/')) {
        return true;
    }
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    if (str_contains($accept, 'application/json')) {
        return true;
    }
    return false;
}

function require_login() {
    if (!is_logged_in()) {
        attempt_remember_me_login();
    }
    if (!is_logged_in()) {
        if (is_api_request()) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Session expired or not authenticated. Please log in again.']);
            exit;
        }
        redirect(APP_URL . '/auth/login.php');
    }
    if (!validate_session()) {
        logout_user();
        if (is_api_request()) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Session timed out. Please log in again.']);
            exit;
        }
        redirect(APP_URL . '/auth/login.php?timeout=1');
    }
    // Prevent caching of protected pages
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
}

function check_session_timeout() {
    if (!validate_session()) {
        logout_user();
        if (is_api_request()) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Session timed out. Please log in again.']);
            exit;
        }
        redirect(APP_URL . '/auth/login.php?timeout=1');
    }
}

function require_role($roles) {
    require_login();

    $currentUser = current_user();
    if (!$currentUser) {
        logout_user();
        if (is_api_request()) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Session expired. Please log in again.']);
            exit;
        }
        redirect(APP_URL . '/auth/login.php?timeout=1');
        exit;
    }

    $roles = is_array($roles) ? $roles : [$roles];
    // `adviser` is a legacy database value. The application deliberately
    // exposes it as Teacher while the data migration is being applied.
    $roles = array_map('canonical_role', $roles);
    $userRole = canonical_role($currentUser['role'] ?? '');
    if (!in_array($userRole, $roles, true)) {
        if (is_api_request()) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Access denied.']);
            exit;
        }
        set_flash('danger', 'Access denied: you do not have permission to view this page.');
        redirect(dashboard_url_for($userRole ?: 'student'));
        exit;
    }
}

/** Return one of the four supported application roles. */
function canonical_role($role) {
    $role = strtolower(trim((string)$role));
    return match ($role) {
        'administrator' => 'admin',
        'adviser' => 'teacher',
        default => $role,
    };
}

function is_supported_role($role) {
    return in_array(canonical_role($role), ['student', 'nurse', 'teacher', 'admin'], true);
}

/**
 * Check if the system has an active Primary Administrator in the database.
 */
function has_active_primary_admin(?PDO $db = null): bool {
    $db = $db ?? getDB();
    try {
        $stmt = $db->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND admin_type = 'primary_admin' AND status = 'active'");
        return ((int)$stmt->fetchColumn()) > 0;
    } catch (Throwable $e) {
        $stmt = $db->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND status = 'active'");
        return ((int)$stmt->fetchColumn()) > 0;
    }
}

/**
 * Check if a given user array or the currently logged-in user is a Primary Administrator.
 */
function is_primary_admin(?array $user = null): bool {
    $user = $user ?? current_user();
    if (!$user) return false;
    return canonical_role($user['role'] ?? '') === 'admin' && (($user['admin_type'] ?? '') === 'primary_admin');
}

/**
 * Check if an administrator has a specific permission.
 * Primary Administrator has all permissions unconditionally.
 * Co-Administrators are verified against the admin_permissions table.
 */
function has_admin_permission(string $permission, ?array $user = null, ?PDO $db = null): bool {
    return has_permission($permission,$user,$db);
}

/**
 * Enforce a specific admin permission, throwing 403 or redirecting if unauthorized.
 */
function admin_access_denied(string $message = 'Access denied: this module is not assigned to your account.'): never {
    http_response_code(403);
    if (is_api_request()) json_response(false, ['error_code'=>'ADMIN_PERMISSION_DENIED'], $message);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="en"><meta charset="utf-8"><title>Access denied</title><h1>Access denied</h1><p>'.htmlspecialchars($message,ENT_QUOTES,'UTF-8').'</p><p><a href="'.htmlspecialchars(APP_URL.'/pages/admin/dashboard.php',ENT_QUOTES,'UTF-8').'">Return to dashboard</a></p></html>';
    exit;
}
function require_admin_permission(string $permission): void {
    require_role('admin');
    if (!has_admin_permission($permission)) admin_access_denied();
}
function require_primary_admin(): void {
    require_role('admin');
    if (!is_primary_admin()) admin_access_denied('Only the Primary Administrator can manage privileged accounts or permissions.');
}
function admin_can_view_emergencies(?array $user = null): bool {
    $user=$user??current_user();
    return $user && ($user['role']==='nurse'||($user['role']==='admin'&&has_admin_permission('view_emergencies',$user)));
}
function admin_can_respond_sos(?array $user = null): bool {
    $user=$user??current_user();
    return $user && ($user['role']==='nurse');
}

/** Inventory delegation is deliberately independent of Primary Admin's general bypass. */
function can_view_inventory(?array $user=null, ?PDO $db=null): bool {
    $user=$user??current_user();
    return $user && has_permission('inventory_oversight',$user,$db);
}
function require_inventory_access(bool $manage=false): void {
    require_role(['nurse','admin']);
    $user=current_user();
    if($manage ? $user['role']!=='nurse' : !can_view_inventory($user))admin_access_denied($manage?'Only nurses can manage medicine stock.':'Inventory data requires authorized clinic or delegated oversight access.');
}

function login_user(array $userRow) {
    $_SESSION['credential_version'] = (int)($userRow['credential_version'] ?? 0);
    $_SESSION['user'] = [
        'id'         => (int)$userRow['id'],
        'role'       => canonical_role($userRow['role']),
        'admin_type' => $userRow['admin_type'] ?? null,
        'username'   => $userRow['username'],
        'email'      => $userRow['email'],
        'first_name' => $userRow['first_name'],
        'last_name'  => $userRow['last_name'],
        'phone'      => $userRow['phone'] ?? '',
        'profile_picture' => $userRow['profile_picture'],
    ];
    $_SESSION['created'] = time();
    $_SESSION['last_activity'] = time();
    secure_session_regenerate_id();
}

function logout_user() {
    $userId = current_user()['id'] ?? null;
    if ($userId) {
        try {
            $db = getDB();
            $stmt = $db->prepare("UPDATE users SET remember_token = NULL WHERE id = ?");
            $stmt->execute([$userId]);
            log_activity($userId, 'Logged out', 'auth');
        } catch (Exception $e) {
            error_log('logout_user DB error: ' . $e->getMessage());
        }
    }

    // Clear remember-me cookies with proper path and params
    setcookie('remember_token', '', time() - 3600, '/', '', false, true);
    setcookie('remember_uid', '', time() - 3600, '/', '', false, true);

    // Clear session data
    $_SESSION = [];

    // Destroy session cookie
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', $params['secure'] ?? false, $params['httponly'] ?? true);
    }

    session_destroy();
}

function set_remember_me_cookie($userId) {
    $selector = bin2hex(random_bytes(16));
    $validator = bin2hex(random_bytes(32));
    $hashedValidator = hash('sha256', $validator);
    $expires = time() + (REMEMBER_ME_DAYS * 86400);

    $db = getDB();
    $stmt = $db->prepare("UPDATE users SET remember_token = ? WHERE id = ?");
    $stmt->execute([$selector . ':' . $hashedValidator, $userId]);

    $isHttps = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off')
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');

    setcookie('remember_uid', $userId, $expires, '/', '', $isHttps, true);
    setcookie('remember_token', $selector . ':' . $validator, $expires, '/', '', $isHttps, true);
}

function attempt_remember_me_login() {
    if (empty($_COOKIE['remember_token']) || empty($_COOKIE['remember_uid'])) {
        return false;
    }
    [$selector, $validator] = array_pad(explode(':', $_COOKIE['remember_token']), 2, '');
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM users WHERE id = ? AND status = 'active' LIMIT 1");
    $stmt->execute([$_COOKIE['remember_uid']]);
    $user = $stmt->fetch();

    if (!$user || empty($user['remember_token'])) return false;
    if (!is_supported_role($user['role'] ?? '')) return false;

    [$storedSelector, $storedHash] = array_pad(explode(':', $user['remember_token']), 2, '');
    if (!hash_equals($storedSelector, $selector)) return false;
    if (!hash_equals($storedHash, hash('sha256', $validator))) return false;

    login_user($user);
    set_remember_me_cookie($user['id']); // rotate token

    // Create a "New login detected" notification for remember-me auto-login.
    try {
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $device = 'Unknown device';
        if ($ua !== '') {
            if (preg_match('/Mobile|Android|iPhone|iPad/i', $ua)) {
                $device = 'Mobile device';
            } elseif (preg_match('/Windows/i', $ua)) {
                $device = 'Windows device';
            } elseif (preg_match('/Macintosh|Mac OS/i', $ua)) {
                $device = 'Mac device';
            } elseif (preg_match('/Linux/i', $ua)) {
                $device = 'Linux device';
            }
        }
        $loginTime = date('M d, Y g:i A');
        push_notification(
            (int)$user['id'],
            'account',
            'New login detected',
            "You logged in on {$loginTime} from {$device}.",
            null,
            null,
            'low'
        );
    } catch (Throwable $e) {
        error_log('Remember-me login notification failed: ' . $e->getMessage());
    }

    return true;
}

/** Returns the dashboard URL for a given role */
function dashboard_url_for($role) {
    $role = canonical_role($role);
    if ($role === 'admin') {
        $sessionUser = $_SESSION['user'] ?? null;
        if (is_array($sessionUser) && !empty($sessionUser['id'])) {
            try {
                if (permission_profile($sessionUser) === 'doctor') {
                    return APP_URL . '/pages/doctor/dashboard.php';
                }
            } catch (Throwable $e) {
                error_log('Dashboard profile resolution failed: ' . $e->getMessage());
            }
        }
    }
    return match ($role) {
        'admin'   => APP_URL . '/pages/admin/dashboard.php',
        'nurse'   => APP_URL . '/pages/nurse/dashboard.php',
        'student' => APP_URL . '/pages/student/dashboard.php',
        // Existing adviser pages are retained as the teacher portal while
        // their filenames are migrated; their access checks use canonical roles.
        'teacher' => APP_URL . '/pages/adviser/dashboard.php',
        default   => APP_URL . '/auth/login.php',
    };
}

// ================================================================
// ADVISORY ASSIGNMENT ACCESS CONTROL
// ================================================================

/**
 * Return the active advisory assignment rows for a teacher (adviser), or [].
 * A teacher may have up to 3 active sections. "Active" = status='active' and
 * (end_date IS NULL OR end_date >= CURDATE()) and start_date IS NULL OR start_date <= CURDATE().
 */
function get_adviser_active_assignment($db, $userId) {
    $stmt = $db->prepare("
        SELECT aa.*, s.name AS section_name, s.course, s.year_level
        FROM advisory_assignments aa
        JOIN sections s ON s.id = aa.section_id
        WHERE aa.teacher_id = ? AND aa.status = 'active'
          AND (aa.start_date IS NULL OR aa.start_date <= CURDATE())
          AND (aa.end_date IS NULL OR aa.end_date >= CURDATE())
        ORDER BY aa.created_at DESC
    ");
    $stmt->execute([(int)$userId]);
    return $stmt->fetchAll();
}

/**
 * Return ALL currently-active advisory section names for a teacher (array of strings).
 */
function get_adviser_active_sections($db, $userId) {
    $rows = get_adviser_active_assignment($db, $userId);
    return array_map(fn($r) => $r['section_name'], $rows);
}

/**
 * Return the section name the adviser currently manages, or null if none.
 * (Kept for single-section compatibility; first active section is returned.)
 */
function get_adviser_current_section($db, $userId) {
    $rows = get_adviser_active_assignment($db, $userId);
    return $rows ? $rows[0]['section_name'] : null;
}

/**
 * Count of currently-active advisory assignments for a teacher (for max-3 enforcement).
 */
function count_active_advisory_assignments($db, $userId) {
    $stmt = $db->prepare("
        SELECT COUNT(*) FROM advisory_assignments
        WHERE teacher_id = ? AND status = 'active'
          AND (start_date IS NULL OR start_date <= CURDATE())
          AND (end_date IS NULL OR end_date >= CURDATE())
    ");
    $stmt->execute([(int)$userId]);
    return (int)$stmt->fetchColumn();
}

/**
 * Return all student rows for a teacher's active advisory assignment(s).
 * Robustly matches students by section name, course, year level, and letter/suffix
 * regardless of casing, spacing, or punctuation variations.
 * Returns array of enriched student objects with assigned_section_name & assigned_section_id.
 */
function get_adviser_students($db, $userId) {
    $assignments = get_adviser_active_assignment($db, $userId);
    if (empty($assignments)) {
        return [];
    }

    $whereClauses = [];
    $params = [];

    foreach ($assignments as $a) {
        $secName = trim((string)$a['section_name']);
        $secCourse = trim((string)($a['course'] ?? ''));
        $secYear = trim((string)($a['year_level'] ?? ''));

        $letter = $secName;
        $yearLetter = '';
        $yearDigit = '';

        if (preg_match('/(\d+)/', $secYear, $ym)) {
            $yearDigit = $ym[1];
        }

        // Deconstruct section name like "BSIS 4A", "BSIS 4-B", "4A", "A"
        if (preg_match('/(?:^|[\s\-_])(?:(\d+)\s*[\-_]?\s*)?([a-zA-Z0-9]+)$/i', $secName, $m)) {
            if (!empty($m[1])) {
                $yearDigit = $yearDigit ?: $m[1];
                $yearLetter = $m[1] . $m[2];
            }
            $letter = $m[2];
        }

        if (empty($secCourse) && preg_match('/^([a-zA-Z]+)/', $secName, $cm)) {
            $secCourse = strtoupper($cm[1]);
        }

        // Section conditions:
        $secConds = [
            "TRIM(LOWER(s.section)) = TRIM(LOWER(?))",
            "REPLACE(REPLACE(LOWER(s.section), ' ', ''), '-', '') = REPLACE(REPLACE(LOWER(?), ' ', ''), '-', '')"
        ];
        $secParams = [$secName, $secName];

        if ($letter !== '' && strcasecmp($letter, $secName) !== 0) {
            $secConds[] = "TRIM(LOWER(s.section)) = TRIM(LOWER(?))";
            $secParams[] = $letter;
        }
        if ($yearLetter !== '' && strcasecmp($yearLetter, $secName) !== 0) {
            $secConds[] = "TRIM(LOWER(s.section)) = TRIM(LOWER(?))";
            $secParams[] = $yearLetter;
        }

        $secCondSql = '(' . implode(' OR ', $secConds) . ')';

        // Course condition
        $courseCondSql = "1=1";
        $courseParams = [];
        if (!empty($secCourse)) {
            $courseCondSql = "(s.course IS NULL OR s.course = '' OR TRIM(LOWER(s.course)) = TRIM(LOWER(?)))";
            $courseParams[] = $secCourse;
        }

        // Year condition
        $yearCondSql = "1=1";
        $yearParams = [];
        if (!empty($secYear)) {
            $yearCondSql = "(s.year_level IS NULL OR s.year_level = '' OR TRIM(LOWER(s.year_level)) = TRIM(LOWER(?)) OR s.year_level LIKE ?)";
            $yearParams[] = $secYear;
            $yearParams[] = '%' . ($yearDigit ?: $secYear) . '%';
        }

        // Combined for this section assignment
        $assignmentClause = "(
            (TRIM(LOWER(s.section)) = TRIM(LOWER(?)) OR REPLACE(REPLACE(LOWER(s.section), ' ', ''), '-', '') = REPLACE(REPLACE(LOWER(?), ' ', ''), '-', ''))
            OR
            ($secCondSql AND $courseCondSql AND $yearCondSql)
        )";

        $whereClauses[] = $assignmentClause;
        $params[] = $secName;
        $params[] = $secName;
        $params = array_merge($params, $secParams, $courseParams, $yearParams);
    }

    $whereSql = implode(' OR ', $whereClauses);

    $sql = "SELECT DISTINCT s.id, s.user_id, s.student_number, s.course, s.year_level, s.section, s.gender, s.blood_type,
                   s.emergency_contact_name, s.emergency_contact_number, s.allergies,
                   u.first_name, u.middle_name, u.last_name, u.email, u.phone, u.status, u.profile_picture
            FROM students s
            JOIN users u ON u.id = s.user_id
            WHERE u.status = 'active' AND ($whereSql)
            ORDER BY u.last_name, u.first_name";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    // Enrich each student with their specific assigned section information
    foreach ($rows as &$st) {
        $st['assigned_section_name'] = $st['section'];
        $st['assigned_section_id'] = 0;
        foreach ($assignments as $a) {
            $secName = trim((string)$a['section_name']);
            $normSt = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', (string)$st['section']));
            $normSec = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $secName));

            if (strcasecmp($st['section'], $secName) === 0 ||
                $normSt === $normSec ||
                (!empty($normSt) && str_ends_with($normSec, $normSt) && strlen($normSt) <= 3)) {
                $st['assigned_section_name'] = $a['section_name'];
                $st['assigned_section_id'] = (int)$a['section_id'];
                break;
            }
        }
    }
    unset($st);

    return $rows;
}

/**
 * Server-side: does the given adviser have access to this student?
 * Access is granted ONLY when the student belongs to ONE of the teacher's
 * CURRENT active advisory assignments.
 */
function adviser_can_access_student($db, $userId, $studentId) {
    $students = get_adviser_students($db, $userId);
    if (empty($students)) return false;
    foreach ($students as $st) {
        if ((int)$st['id'] === (int)$studentId) {
            return true;
        }
    }
    return false;
}

/**
 * Enforce that the current adviser may only view their current-section students.
 * Kills the request (403) if not permitted. Students may view only themselves,
 * handled elsewhere.
 */
function require_adviser_student_access($db, $studentId) {
    $u = current_user();
    if (!$u) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access denied.']);
        exit;
    }
    if (canonical_role($u['role'] ?? '') === 'teacher') {
        if (!adviser_can_access_student($db, $u['id'], $studentId)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'You do not have access to this student.']);
            exit;
        }
    }
}

// ================================================================
// MEDICAL DATA ACCESS CONTROL
// ================================================================

/**
 * May the current user view a student's general medical information (conditions, allergies, meds)?
 *  - Nurse: YES (for clinical care)
 *  - Student: YES (own personal record)
 *  - Admin: YES (administrative oversight)
 *  - Others: NO
 */
function can_view_medical_info($viewerRole) {
    return in_array($viewerRole, ['nurse', 'admin', 'student'], true);
}
