<?php
/**
 * Authentication / Authorization helpers
 */

function current_user() {
    return $_SESSION['user'] ?? null;
}

function is_logged_in() {
    return isset($_SESSION['user']);
}

function require_login() {
    if (!is_logged_in()) {
        attempt_remember_me_login();
    }
    if (!is_logged_in()) {
        redirect(APP_URL . '/auth/login.php');
    }
    if (!validate_session()) {
        logout_user();
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
        redirect(APP_URL . '/auth/login.php?timeout=1');
    }
}

function require_role($roles) {
    require_login();
    $roles = is_array($roles) ? $roles : [$roles];
    if (!in_array(current_user()['role'], $roles, true)) {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Access denied.']);
            exit;
        }
        set_flash('danger', 'Access denied: you do not have permission to view this page.');
        redirect(APP_URL . '/dashboard.php');
        exit;
    }
}

function login_user(array $userRow) {
    $_SESSION['user'] = [
        'id'         => (int)$userRow['id'],
        'role'       => $userRow['role'],
        'username'   => $userRow['username'],
        'email'      => $userRow['email'],
        'first_name' => $userRow['first_name'],
        'last_name'  => $userRow['last_name'],
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

    setcookie('remember_uid', $userId, $expires, '/', '', false, true);
    setcookie('remember_token', $selector . ':' . $validator, $expires, '/', '', false, true);
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

    [$storedSelector, $storedHash] = array_pad(explode(':', $user['remember_token']), 2, '');
    if (!hash_equals($storedSelector, $selector)) return false;
    if (!hash_equals($storedHash, hash('sha256', $validator))) return false;

    login_user($user);
    set_remember_me_cookie($user['id']); // rotate token
    return true;
}

/** Returns the dashboard URL for a given role */
function dashboard_url_for($role) {
    return match ($role) {
        'admin'   => APP_URL . '/pages/admin/dashboard.php',
        'nurse'   => APP_URL . '/pages/nurse/dashboard.php',
        'student' => APP_URL . '/pages/student/dashboard.php',
        default   => APP_URL . '/auth/login.php',
    };
}
