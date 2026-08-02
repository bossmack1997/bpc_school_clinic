<?php
/**
 * Shared helper functions
 */

function sanitize($value) {
    if (is_array($value)) {
        return array_map('sanitize', $value);
    }
    return htmlspecialchars(trim($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function redirect($path) {
    header('Location: ' . $path);
    exit;
}

function set_flash($type, $message) {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function get_flash() {
    if (!empty($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

function json_response($success, $data = [], $message = '') {
    header('Content-Type: application/json');
    header('X-Content-Type-Options: nosniff');
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $data));
    exit;
}

function handle_exception($throwable) {
    error_log('Unhandled exception: ' . $throwable->getMessage());
    if (!headers_sent()) {
        header('Content-Type: application/json');
        header('HTTP/1.1 500 Internal Server Error');
    }
    echo json_encode(['success' => false, 'message' => 'An unexpected error occurred. Please try again.']);
    exit;
}

function handle_error($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
}

function log_activity($userId, $action, $module = null, $details = null) {
    try {
        $db = getDB();
        $stmt = $db->prepare(
            "INSERT INTO activity_logs (user_id, action, module, details, ip_address) VALUES (?, ?, ?, ?, ?)"
        );
        $stmt->execute([$userId, $action, $module, $details, $_SERVER['REMOTE_ADDR'] ?? null]);
    } catch (Exception $e) {
        error_log('log_activity failed: ' . $e->getMessage());
    }
}

function push_notification($userId, $type, $title, $message, $link = null, $sosEmergencyId = null) {
    $db = getDB();

    // Support the optional sos_emergency_id column (added by
    // database/migration_sos_enhance.sql) without breaking existing
    // installations where the column does not yet exist.
    try {
        $stmt = $db->prepare(
            "INSERT INTO notifications (user_id, type, title, message, link, sos_emergency_id) VALUES (?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([$userId, $type, $title, $message, $link, $sosEmergencyId]);
    } catch (PDOException $e) {
        // Fallback: column not present — insert without it.
        error_log('push_notification (sos_emergency_id) fallback: ' . $e->getMessage());
        $stmt = $db->prepare(
            "INSERT INTO notifications (user_id, type, title, message, link) VALUES (?, ?, ?, ?, ?)"
        );
        $stmt->execute([$userId, $type, $title, $message, $link]);
    }
}

function calculate_bmi($weightKg, $heightCm) {
    if (!$weightKg || !$heightCm) return null;
    $heightM = $heightCm / 100;
    if ($heightM <= 0) return null;
    return round($weightKg / ($heightM * $heightM), 2);
}

function bmi_status($bmi) {
    if ($bmi === null) return null;
    if ($bmi < 18.5) return 'Underweight';
    if ($bmi < 25) return 'Normal';
    if ($bmi < 30) return 'Overweight';
    return 'Obese';
}

function bmi_badge_class($status) {
    return match ($status) {
        'Underweight' => 'bg-info',
        'Normal'      => 'bg-success',
        'Overweight'  => 'bg-warning text-dark',
        'Obese'       => 'bg-danger',
        default       => 'bg-secondary',
    };
}

function format_date($date, $fmt = 'M d, Y') {
    if (!$date) return '—';
    return date($fmt, strtotime($date));
}

function format_datetime($date, $fmt = 'M d, Y g:i A') {
    if (!$date) return '—';
    return date($fmt, strtotime($date));
}

function e($value) {
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}
