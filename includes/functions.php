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

function app_url($path = '') {
    $base = rtrim(APP_URL, '/');
    $path = ltrim((string)$path, '/');
    return $path === '' ? $base : $base . '/' . $path;
}

function asset_url($path) {
    return app_url('assets/' . ltrim((string)$path, '/'));
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
    if (ob_get_length()) { ob_clean(); }
    header('Content-Type: application/json');
    header('X-Content-Type-Options: nosniff');
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $data));
    exit;
}

function handle_exception($throwable) {
    error_log('Unhandled exception: ' . $throwable->getMessage());
    if (ob_get_length()) { ob_clean(); }
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

function push_notification($userId, $type, $title, $message, $link = null, $sosEmergencyId = null, $priority = 'medium', $relatedId = null) {
    $db = getDB();

    // Full-featured insert with priority/related_id/read_at columns (added by
    // database/migration_qr_inventory_notifications.sql). Gracefully falls
    // back through progressively simpler schemas so it works on any install.
    $priority = in_array($priority, ['low', 'medium', 'high', 'emergency'], true) ? $priority : 'medium';

    try {
        $stmt = $db->prepare(
            "INSERT INTO notifications (user_id, type, priority, title, message, link, sos_emergency_id, related_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([$userId, $type, $priority, $title, $message, $link, $sosEmergencyId, $relatedId]);
    } catch (PDOException $e) {
        try {
            $stmt = $db->prepare(
                "INSERT INTO notifications (user_id, type, title, message, link, sos_emergency_id) VALUES (?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([$userId, $type, $title, $message, $link, $sosEmergencyId]);
        } catch (PDOException $e2) {
            error_log('push_notification fallback 2: ' . $e2->getMessage());
            $stmt = $db->prepare(
                "INSERT INTO notifications (user_id, type, title, message, link) VALUES (?, ?, ?, ?, ?)"
            );
            $stmt->execute([$userId, $type, $title, $message, $link]);
        }
    }
}

function notification_type_meta($type) {
    $type = trim((string)$type);
    $map = [
        'appointment' => ['label' => 'Appointment', 'icon' => 'calendar-check-fill', 'color' => 'primary'],
        'consultation' => ['label' => 'Consultation', 'icon' => 'stethoscope', 'color' => 'secondary'],
        'health' => ['label' => 'Health Monitoring', 'icon' => 'heart-pulse-fill', 'color' => 'success'],
        'bmi_update' => ['label' => 'BMI Update', 'icon' => 'activity', 'color' => 'success'],
        'medicine' => ['label' => 'Medicine', 'icon' => 'capsule-fill', 'color' => 'warning'],
        'inventory' => ['label' => 'Inventory', 'icon' => 'box-seam-fill', 'color' => 'info'],
        'stock_alert' => ['label' => 'Stock Alert', 'icon' => 'exclamation-triangle-fill', 'color' => 'danger'],
        'emergency' => ['label' => 'Emergency', 'icon' => 'exclamation-triangle-fill', 'color' => 'danger'],
        'announcement' => ['label' => 'Announcement', 'icon' => 'megaphone-fill', 'color' => 'warning'],
        'qr_checkin' => ['label' => 'QR Check-in', 'icon' => 'qrcode', 'color' => 'info'],
        'qr_scan' => ['label' => 'QR Scan', 'icon' => 'qrcode', 'color' => 'info'],
        'vaccination' => ['label' => 'Vaccination', 'icon' => 'syringe', 'color' => 'info'],
        'system' => ['label' => 'System', 'icon' => 'gear-fill', 'color' => 'secondary'],
        'account' => ['label' => 'Account', 'icon' => 'person-gear', 'color' => 'primary'],
        'ai_assistant' => ['label' => 'AI Assistant', 'icon' => 'robot', 'color' => 'info'],
        'walkin' => ['label' => 'Walk-in', 'icon' => 'person-walking', 'color' => 'info'],
    ];
    return $map[$type] ?? ['label' => ucwords(str_replace(['_', '-'], [' ', ' '], $type ?: 'Notification')), 'icon' => 'bell-fill', 'color' => 'secondary'];
}

function normalize_notification(array $notification) {
    $meta = notification_type_meta($notification['type'] ?? '');
    $notification['notification_id'] = $notification['id'] ?? null;
    $notification['type_label'] = $meta['label'];
    $notification['icon'] = $meta['icon'];
    $notification['color'] = $meta['color'];
    return $notification;
}

/**
 * Build the QR payload for a student.
 * ------------------------------------------------------------------
 * SECURITY: encodes ONLY an encrypted token (no PII) by default.
 *   Format:  BPC-4A8F91D72E3B6A8C...   (prefix + random hex token)
 * The token is random and decoupled from student identity; the system
 * later retrieves the student from the database using this token.
 *
 * The token is a random 40-hex string (configurable length) prefixed
 * with a configured prefix. It is NOT derived from student data, so it
 * leaks nothing about the student. The server stores the token in the
 * student_qrs table and looks the student up by it on scan.
 *
 * Note: verify_student_qr() keeps backward compatibility with legacy
 * JSON payloads ({v:1,sid,sno,t}) already issued to existing students.
 *
 * Returns ['token' => ..., 'payload' => 'BPC-...']
 */
function build_student_qr_payload($studentId, $studentNumber) {
    // If PII-in-payload is still enabled for a legacy install, fall back
    // to the JSON format so we never break old scanners.
    $db = null;
    try { $db = getDB(); } catch (Exception $e) { $db = null; }

    $allowPii = false;
    if ($db) {
        $v = get_qr_setting($db, 'qr_pii_in_payload', '0');
        $allowPii = strtolower(trim((string)$v)) === '1' || $v === 'true';
    }

    $prefix = 'BPC-';
    $length = 40;
    if ($db) {
        $prefix = (string)get_qr_setting($db, 'qr_token_prefix', 'BPC-');
        $length = max(16, (int)get_qr_setting($db, 'qr_token_length', 40));
    }

    if ($allowPii) {
        $appKey  = defined('APP_KEY') && APP_KEY ? APP_KEY : 'bpc_qr_ph1';
        $token   = substr(hash_hmac('sha256', $appKey . '|' . $studentId . '|' . $studentNumber, $appKey), 0, 40);
        $payload = json_encode([
            'v'   => 1,
            'sid' => (int)$studentId,
            'sno' => (string)$studentNumber,
            't'   => $token,
        ]);
        return ['token' => $token, 'payload' => $payload];
    }

    // SECURE TOKEN-ONLY format (default)
    $token = $prefix . strtoupper(bin2hex(random_bytes((int)ceil($length / 2))));
    $token = substr($token, 0, strlen($prefix) + $length);
    $payload = $token;   // the QR encodes ONLY this token string

    return ['token' => $token, 'payload' => $payload];
}

/**
 * Ensure a student has exactly ONE active permanent QR record.
 * Returns the student_qrs row (creating it if missing).
 */
function ensure_student_qr($db, $studentId, $generatedBy = null) {
    $stmt = $db->prepare("SELECT * FROM student_qrs WHERE student_id = ? LIMIT 1");
    $stmt->execute([$studentId]);
    $qr = $stmt->fetch();
    if ($qr) return $qr;

    // Look up student number
    $stmt = $db->prepare("SELECT student_number FROM students WHERE id = ?");
    $stmt->execute([$studentId]);
    $sno = $stmt->fetchColumn();
    if ($sno === false) return null;

    $built = build_student_qr_payload($studentId, $sno);

    $stmt = $db->prepare(
        "INSERT INTO student_qrs (student_id, qr_token, qr_data, status, generated_by)
         VALUES (?, ?, ?, 'active', ?)"
    );
    $stmt->execute([$studentId, $built['token'], $built['payload'], $generatedBy]);

    // Backfill students.qr_code for backward compatibility
    try {
        $db->prepare("UPDATE students SET qr_code = ? WHERE id = ? AND (qr_code IS NULL OR qr_code = '')")
           ->execute([$built['token'], $studentId]);
    } catch (Exception $e) {
        error_log('ensure_student_qr backfill: ' . $e->getMessage());
    }

    $stmt = $db->prepare("SELECT * FROM student_qrs WHERE student_id = ? LIMIT 1");
    $stmt->execute([$studentId]);
    return $stmt->fetch();
}

/**
 * Compute the inventory status of a medicine.
 * Returns one of: Available | Low Stock | Out of Stock | Near Expiration | Expired
 */
function medicine_status($quantity, $reorderLevel, $expirationDate) {
    $qty = (int)$quantity;
    $reorder = (int)$reorderLevel;
    $exp = $expirationDate;

    if ($exp) {
        $today = strtotime(date('Y-m-d'));
        $expTs = strtotime($exp);
        $days  = (int)(($expTs - $today) / 86400);
        if ($expTs < $today) return 'Expired';
        if ($days <= 30)     return 'Near Expiration';
    }
    if ($qty <= 0)          return 'Out of Stock';
    if ($qty <= $reorder)   return 'Low Stock';
    return 'Available';
}

/**
 * Notify all nurses and admins about a medicine alert.
 */
function notify_staff_medicine_alert($db, $medicine, $status) {
    $mname = $medicine['medicine_name'] ?? 'Medicine';
    $titles = [
        'Low Stock'       => '⚠️ Low Stock: ' . $mname,
        'Out of Stock'    => '🚫 Out of Stock: ' . $mname,
        'Near Expiration' => '🕒 Near Expiration: ' . $mname,
        'Expired'         => '⛔ Expired: ' . $mname,
    ];
    $msgs = [
        'Low Stock'       => "Stock for {$mname} has reached its minimum (qty: {$medicine['quantity']}). Please reorder.",
        'Out of Stock'    => "{$mname} is now out of stock. Please restock.",
        'Near Expiration' => "{$mname} expires within 30 days (" . ($medicine['expiration_date'] ?? 'N/A') . ').',
        'Expired'         => "{$mname} expired on " . ($medicine['expiration_date'] ?? 'N/A') . '. Do not dispense.',
    ];
    if (!isset($titles[$status])) return;

    $priority = in_array($status, ['Expired', 'Out of Stock'], true) ? 'high' : 'medium';
    $title  = $titles[$status];
    $msg    = $msgs[$status];
    $link   = APP_URL . '/pages/nurse/inventory.php';
    $relId  = (int)$medicine['id'];

    // Nurses
    $nurseStmt = $db->query("SELECT user_id FROM nurses");
    foreach ($nurseStmt->fetchAll() as $n) {
        push_notification($n['user_id'], 'medicine', $title, $msg, $link, null, $priority, $relId);
    }
    // Admins
    $adminStmt = $db->query("SELECT id FROM users WHERE role = 'admin'");
    foreach ($adminStmt->fetchAll() as $a) {
        push_notification($a['id'], 'medicine', $title, $msg, $link, null, $priority, $relId);
    }
}

/**
 * Send a dispensing/consultation notification to a student.
 */
function notify_student_dispense($db, $studentId, $medicineName, $quantity, $unit = '') {
    $stmt = $db->prepare("SELECT u.id FROM students s JOIN users u ON u.id = s.user_id WHERE s.id = ?");
    $stmt->execute([$studentId]);
    $sid = $stmt->fetchColumn();
    if (!$sid) return;
    push_notification(
        $sid,
        'medicine',
        '💊 Medicine Dispensed',
        "{$quantity} {$unit} of {$medicineName} was dispensed. It has been added to your medical record.",
        APP_URL . '/pages/student/medical_record.php'
    );
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

// ================================================================
// QR SETTINGS HELPERS
// ================================================================

/**
 * Get a QR system setting value (from qr_settings table or system_settings).
 */
function get_qr_setting($db, $key, $default = null) {
    $val = $default;
    try {
        $stmt = $db->prepare("SELECT setting_value FROM qr_settings WHERE setting_key = ? LIMIT 1");
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        if ($row) $val = $row['setting_value'];
    } catch (PDOException $e) {
        error_log('get_qr_setting failed: ' . $e->getMessage());
    }
    return $val;
}

/**
 * Get all QR settings as key => value array.
 */
function get_all_qr_settings($db) {
    $settings = [];
    try {
        $stmt = $db->query("SELECT setting_key, setting_value FROM qr_settings ORDER BY setting_key");
        foreach ($stmt->fetchAll() as $row) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
    } catch (PDOException $e) {
        error_log('get_all_qr_settings failed: ' . $e->getMessage());
    }
    return $settings;
}

/**
 * Update QR settings (array of key => value).
 */
function update_qr_settings($db, array $settings, $userId) {
    $stmt = $db->prepare("UPDATE qr_settings SET setting_value = ?, data_type = ? WHERE setting_key = ?");
    foreach ($settings as $key => $value) {
        $type = 'string';
        if (is_bool($value)) { $type = 'boolean'; $value = $value ? '1' : '0'; }
        elseif (is_int($value)) { $type = 'integer'; $value = (string)$value; }
        $stmt->execute([trim((string)$value), $type, $key]);
    }
    log_activity($userId, 'Updated QR settings', 'qr_management', json_encode($settings));
}

// ================================================================
// DYNAMIC QR HELPERS
// ================================================================

/**
 * Build a dynamic QR payload (no PII encoded — only purpose + reference + token).
 */
function build_dynamic_qr_payload($db, $purpose, $referenceId = null, $referenceType = null, $studentId = null, $expirationSeconds = 0) {
    $appKey  = defined('APP_KEY') && APP_KEY ? APP_KEY : 'bpc_qr_ph1';
    $randomToken = bin2hex(random_bytes(16));
    $token = $randomToken;
    $checksum = substr(hash_hmac('sha256', $token . '|' . $purpose . '|' . $referenceId, $appKey), 0, 16);

    $payload = json_encode([
        'v'    => 2,
        'type' => 'dynamic',
        'p'    => $purpose,
        'rid'  => (int)$referenceId,
        'rt'   => $referenceType,
        'sid'  => $studentId ? (int)$studentId : null,
        't'    => $token,
        'cs'   => $checksum,
    ]);

    return ['token' => $token, 'payload' => $payload, 'checksum' => $checksum];
}

/**
 * Generate and persist a dynamic QR code.
 * Returns ['qr_data' => ..., 'qr_token' => ..., 'expires_at' => ...].
 */
function generate_dynamic_qr($db, $purpose, $referenceId = null, $referenceType = null, $studentId = null, $expirationSeconds = 0, $issuedBy = null) {
    $built = build_dynamic_qr_payload($db, $purpose, $referenceId, $referenceType, $studentId, $expirationSeconds);

    $expiresAt = null;
    if ($expirationSeconds > 0) {
        $expiresAt = date('Y-m-d H:i:s', time() + $expirationSeconds);
    }

    $stmt = $db->prepare("
        INSERT INTO dynamic_qrs (purpose, reference_id, reference_type, subject_student_id, qr_token, qr_data, status, issued_by, expires_at)
        VALUES (?, ?, ?, ?, ?, ?, 'active', ?, ?)
    ");
    $stmt->execute([
        $purpose, $referenceId, $referenceType, $studentId,
        $built['token'], $built['payload'], $issuedBy, $expiresAt
    ]);

    return [
        'qr_data'     => $built['payload'],
        'qr_token'    => $built['token'],
        'expires_at'  => $expiresAt,
        'dynamic_id'  => $db->lastInsertId(),
    ];
}

/**
 * Verify a dynamic QR payload. Returns ['valid' => bool, 'purpose' => ..., 'reference_id' => ..., 'student_id' => ...].
 */
function verify_dynamic_qr($db, $payload) {
    $decoded = json_decode($payload, true);
    if (!is_array($decoded) || ($decoded['v'] ?? null) !== 2 || ($decoded['type'] ?? null) !== 'dynamic') {
        return ['valid' => false, 'reason' => 'invalid_format'];
    }

    $token     = $decoded['t'] ?? '';
    $purpose   = $decoded['p'] ?? '';
    $refId     = (int)($decoded['rid'] ?? 0);
    $checksum  = $decoded['cs'] ?? '';

    $appKey = defined('APP_KEY') && APP_KEY ? APP_KEY : 'bpc_qr_ph1';
    $expected = substr(hash_hmac('sha256', $token . '|' . $purpose . '|' . $refId, $appKey), 0, 16);
    if (!hash_equals($expected, $checksum)) {
        return ['valid' => false, 'reason' => 'invalid_checksum'];
    }

    $stmt = $db->prepare("SELECT * FROM dynamic_qrs WHERE qr_token = ? LIMIT 1");
    $stmt->execute([$token]);
    $row = $stmt->fetch();
    if (!$row) return ['valid' => false, 'reason' => 'not_found'];

    if ($row['status'] !== 'active') return ['valid' => false, 'reason' => 'status_' . $row['status']];

    if ($row['expires_at'] && strtotime($row['expires_at']) < time()) {
        $db->prepare("UPDATE dynamic_qrs SET status = 'expired' WHERE id = ?")->execute([$row['id']]);
        return ['valid' => false, 'reason' => 'expired'];
    }

    return [
        'valid'        => true,
        'purpose'      => $row['purpose'],
        'reference_id' => (int)$row['reference_id'],
        'reference_type' => $row['reference_type'],
        'student_id'   => (int)($row['subject_student_id'] ?? 0),
        'row'          => $row,
    ];
}

// ================================================================
// QUEUE NUMBER HELPERS
// ================================================================

/**
 * Generate a queue number with the configured prefix (default A).
 * Example: A-001, A-002, ...
 * Resets daily.
 */
function generate_queue_number($db) {
    $prefix = get_qr_setting($db, 'queue_prefix', 'A');
    $stmt = $db->prepare("SELECT COUNT(*) FROM queue_numbers WHERE DATE(created_at) = CURDATE() AND status NOT IN ('cancelled','completed')");
    $stmt->execute();
    $count = (int)$stmt->fetchColumn();
    return $prefix . '-' . str_pad($count + 1, 3, '0', STR_PAD_LEFT);
}

// ================================================================
// QR SCAN LOGGING & DUPLICATE PREVENTION
// ================================================================

/**
 * Log a QR scan to qr_scan_logs.
 */
function log_qr_scan($db, $studentId, $qrToken, $purpose, $scannerId, $status = 'success', $location = null, $device = null, $metadata = null, $scanMethod = 'unknown') {
    $stmt = $db->prepare("
        INSERT INTO qr_scan_logs (student_id, qr_token, purpose, scanner_id, scanner_name, scan_location, device, ip_address, status, metadata, scan_method)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $scannerName = null;
    if ($scannerId) {
        $s = $db->prepare("SELECT CONCAT(first_name, ' ', last_name) AS name FROM users WHERE id = ?");
        $s->execute([$scannerId]);
        $scannerName = $s->fetchColumn();
    }
    $device = $device ?? substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 120);
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    $location = $location ?? get_qr_setting($db, 'scan_location_default', 'Clinic Entrance');
    $metadata = $metadata ? json_encode($metadata) : null;
    $scanMethod = in_array($scanMethod, ['camera', 'upload', 'walk_in'], true) ? $scanMethod : 'unknown';

    $stmt->execute([$studentId, $qrToken, $purpose, $scannerId, $scannerName, $location, $device, $ip, $status, $metadata, $scanMethod]);
    return $db->lastInsertId();
}

/**
 * Check if a duplicate scan occurred within the configured window.
 */
function is_duplicate_scan($db, $qrToken, $windowSeconds = 30) {
    $stmt = $db->prepare("
        SELECT COUNT(*) FROM qr_scan_logs
        WHERE qr_token = ? AND status = 'success'
        AND scan_time >= DATE_SUB(NOW(), INTERVAL ? SECOND)
    ");
    $stmt->execute([$qrToken, $windowSeconds]);
    return (int)$stmt->fetchColumn() > 0;
}

/**
 * Verify a permanent student QR payload.
 * ==================================================================
 * Supports TWO formats:
 *   1. SECURE TOKEN-ONLY (new):  "BPC-4A8F91D72E3B6A8C..."
 *      The token is looked up directly in student_qrs (no PII decoded).
 *   2. LEGACY JSON (backward compat): {"v":1,"sid":...,"sno":...,"t":...}
 *      The embedded student_id is used to find the active QR then the
 *      token is compared (HMAC-safe).
 *
 * Returns ['valid' => bool, 'student_id' => int|null, 'student' => array|null, 'reason' => string|null].
 */
function verify_student_qr($db, $payload) {
    $raw = trim((string)$payload);
    if ($raw === '') {
        return ['valid' => false, 'student_id' => null, 'student' => null, 'reason' => 'empty'];
    }

    // --- Determine which format we have ---
    $decoded = json_decode($raw, true);
    if (is_array($decoded) && ($decoded['v'] ?? null) === 1 && !empty($decoded['sid'])) {
        // LEGACY JSON format
        $studentId = (int)$decoded['sid'];
        $token = $decoded['t'] ?? '';
        if ($token === '') {
            return ['valid' => false, 'student_id' => null, 'student' => null, 'reason' => 'missing_token'];
        }
        $stmt = $db->prepare("SELECT * FROM student_qrs WHERE student_id = ? AND status = 'active' LIMIT 1");
        $stmt->execute([$studentId]);
        $qr = $stmt->fetch();
        if (!$qr) {
            return ['valid' => false, 'student_id' => null, 'student' => null, 'reason' => 'no_active_qr'];
        }
        if (!hash_equals((string)$qr['qr_token'], (string)$token)) {
            return ['valid' => false, 'student_id' => $studentId, 'student' => null, 'reason' => 'token_mismatch'];
        }

        // Load full student row
        $stmt = $db->prepare("
            SELECT sq.*, s.student_number, s.course, s.year_level, s.section, s.blood_type, s.allergies,
                   u.id AS user_id, u.first_name, u.last_name, u.email, u.phone, u.gender, s.birthdate, u.profile_picture
            FROM student_qrs sq
            JOIN students s ON s.id = sq.student_id
            JOIN users u ON u.id = s.user_id
            WHERE sq.id = ?
            LIMIT 1
        ");
        $stmt->execute([$qr['id']]);
        $full = $stmt->fetch();
        return ['valid' => true, 'student_id' => $studentId, 'student' => $full, 'reason' => null];
    }

    // --- SECURE TOKEN-ONLY format (default) ---
    // Trim any prefix that ships in the QR (settings may use "BPC-"). We
    // compare against the stored token directly so the exact configured
    // prefix is respected. We also accept the raw token without prefix.
    $stmt = $db->prepare("
        SELECT sq.*, s.student_number, s.course, s.year_level, s.section, s.blood_type, s.allergies,
                u.id AS user_id, u.first_name, u.last_name, u.email, u.phone, u.gender, s.birthdate, u.profile_picture
        FROM student_qrs sq
        JOIN students s ON s.id = sq.student_id
        JOIN users u ON u.id = s.user_id
        WHERE sq.qr_token = ? AND sq.status IN ('active', 'regenerated')
        LIMIT 1
    ");
    $stmt->execute([$raw]);
    $qr = $stmt->fetch();
    if (!$qr) {
        return ['valid' => false, 'student_id' => null, 'student' => null, 'reason' => 'invalid_token'];
    }

    return ['valid' => true, 'student_id' => (int)$qr['student_id'], 'student' => $qr, 'reason' => null];
}

/**
 * Archive a QR status change into the student_qr_history audit table.
 * Best-effort — never throws.
 */
function archive_student_qr($db, $studentId, $action, $oldToken = null, $newToken = null, $reason = null, $performedBy = null) {
    try {
        $stmt = $db->prepare("
            INSERT INTO student_qr_history (student_id, action, old_token, new_token, reason, performed_by, ip_address)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $studentId,
            $action,
            $oldToken,
            $newToken,
            $reason,
            $performedBy,
            $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (Throwable $e) {
        error_log('archive_student_qr failed: ' . $e->getMessage());
    }
}

/**
 * Revoke a student's QR (admin-only). Sets status to 'revoked' and records
 * the reason in the audit history. The QR can be reactivated later or
 * replaced by regenerating.
 */
function revoke_student_qr($db, $studentId, $reason = null, $performedBy = null) {
    $stmt = $db->prepare("SELECT * FROM student_qrs WHERE student_id = ? LIMIT 1");
    $stmt->execute([$studentId]);
    $qr = $stmt->fetch();
    if (!$qr) return false;

    $db->prepare("UPDATE student_qrs SET status = 'revoked', updated_at = NOW() WHERE id = ?")
        ->execute([$qr['id']]);

    archive_student_qr($db, $studentId, 'revoked', $qr['qr_token'], null, $reason, $performedBy);
    log_activity($performedBy, 'Revoked student QR', 'qr_management', "Student #$studentId");

    return true;
}

// ================================================================
// RESILIENT QUERY HELPERS
// These gracefully degrade to safe defaults when optional tables or
// columns (added by migration files) are missing. This prevents a
// PDOException from crashing the Health Pass / API with HTTP 500.
// ================================================================

/**
 * Attempt a prepared statement execute; on failure return $fallback.
 * Never throws — missing tables/columns just yield a safe default.
 */
function safe_fetch_all($db, $sql, $params = [], $fallback = []) {
    try {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        error_log('safe_fetch_all failed: ' . $e->getMessage());
        return $fallback;
    }
}

/**
 * Attempt to fetch a single row. Returns $fallback on failure/not found.
 */
function safe_fetch_one($db, $sql, $params = [], $fallback = null) {
    try {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row === false ? $fallback : $row;
    } catch (Throwable $e) {
        error_log('safe_fetch_one failed: ' . $e->getMessage());
        return $fallback;
    }
}

/**
 * Attempt to fetch a single scalar value. Returns $fallback on failure.
 */
function safe_fetch_column($db, $sql, $params = [], $fallback = null) {
    try {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $val = $stmt->fetchColumn();
        return $val === false ? $fallback : $val;
    } catch (Throwable $e) {
        error_log('safe_fetch_column failed: ' . $e->getMessage());
        return $fallback;
    }
}

/**
 * Get a student's full medical record summary for MICA integration.
 */
function get_student_medical_summary($db, $studentId) {
    $summary = [];

    $stmt = $db->prepare("
        SELECT c.*, n.license_number
        FROM consultations c
        LEFT JOIN nurses n ON n.id = c.nurse_id
        WHERE c.student_id = ?
        ORDER BY c.consultation_date DESC
        LIMIT 10
    ");
    $stmt->execute([$studentId]);
    $summary['consultations'] = $stmt->fetchAll();

    $stmt = $db->prepare("SELECT * FROM health_monitoring WHERE student_id = ? ORDER BY recorded_at DESC LIMIT 5");
    $stmt->execute([$studentId]);
    $summary['health_monitoring'] = $stmt->fetchAll();

    $stmt = $db->prepare("SELECT * FROM vaccinations WHERE student_id = ? ORDER BY date_administered DESC");
    $stmt->execute([$studentId]);
    $summary['vaccinations'] = $stmt->fetchAll();

    $stmt = $db->prepare("SELECT * FROM medicines m JOIN medicine_logs ml ON ml.medicine_id = m.id WHERE ml.student_id = ? AND ml.action = 'stock_out' ORDER BY ml.performed_at DESC LIMIT 10");
    $stmt->execute([$studentId]);
    $summary['medicine_history'] = $stmt->fetchAll();

    return $summary;
}

// ================================================================
// SMART SINGLE QR SYSTEM HELPERS
// ================================================================

/**
 * Normalize a QR status into a canonical label. Keeps backward
 * compatibility with the older 'deactivated' value.
 */
function qr_status_label($status) {
    return match (strtolower((string)$status)) {
        'active'      => 'Active',
        'inactive'    => 'Inactive',
        'revoked'     => 'Revoked',
        'regenerated' => 'Regenerated',
        'deactivated' => 'Deactivated',
        default       => ucfirst((string)$status),
    };
}

/**
 * Return a Bootstrap badge class for a QR status.
 */
function qr_status_badge_class($status) {
    return match (strtolower((string)$status)) {
        'active'      => 'bg-success',
        'inactive'    => 'bg-secondary',
        'revoked'     => 'bg-danger',
        'regenerated' => 'bg-info',
        'deactivated' => 'bg-danger',
        default       => 'bg-secondary',
    };
}

/**
 * Determine the Smart QR workflow scenario for a scanned student.
 *
 * Returns an array describing the recommended action:
 *   [
 *     'scenario'      => 'appointment' | 'walk_in' | 'emergency_ready' | 'medicine' | 'certificate' | 'check_in',
 *     'today_appointment' => appointment row | null,
 *     'latest_vitals'     => latest health_monitoring row | null,
 *     'allergies'         => string|null,
 *     'reason'            => human-readable summary,
 *     'queue_ready'       => bool
 *   ]
 *
 * Order of detection (highest priority first):
 *   1. Has a same-day approved/pending appointment → appointment scenario
 *   2. No appointment → walk-in (generic check-in) scenario
 * The nurse scanner UI still offers Medicine / Certificate / Emergency actions
 * from the same panel; the backend simply reports what it found.
 */
function smart_qr_detect_workflow($db, $studentId) {
    $today = date('Y-m-d');

    $scenario = 'walk_in';
    $todayAppt = null;
    $reason = 'No appointment found today. Student may be registered as a walk-in.';

    // 1. Check for a same-day appointment
    $stmt = $db->prepare("
        SELECT id, appointment_date, appointment_time, reason, status, nurse_id
        FROM appointments
        WHERE student_id = ? AND appointment_date = ?
        ORDER BY appointment_time ASC
        LIMIT 1
    ");
    $stmt->execute([$studentId, $today]);
    $appt = $stmt->fetch();

    if ($appt && in_array($appt['status'], ['approved', 'pending', 'arrived'], true)) {
        $scenario = 'appointment';
        $todayAppt = $appt;
        if ($appt['status'] === 'arrived') {
            $reason = 'Student has already arrived for their appointment today (Queue assigned).';
        } elseif ($appt['status'] === 'approved') {
            $reason = 'Student has an approved appointment today at ' . ($appt['appointment_time'] ?? '') . '. Mark as arrived and issue a queue number.';
        } else {
            $reason = 'Student has a pending appointment today. Confirm with the nurse before check-in.';
        }
    }

    // 2. Latest vitals (for the Emergency scenario preview)
    $latestVitals = null;
    $stmt = $db->prepare("
        SELECT * FROM health_monitoring
        WHERE student_id = ?
        ORDER BY recorded_at DESC
        LIMIT 1
    ");
    $stmt->execute([$studentId]);
    $latestVitals = $stmt->fetch() ?: null;

    // 3. Allergies / emergency contact (for Emergency Info)
    $stmt = $db->prepare("
        SELECT s.allergies, s.emergency_contact_name, s.emergency_contact_number, s.blood_type
        FROM students s
        WHERE s.id = ?
    ");
    $stmt->execute([$studentId]);
    $emergencyInfo = $stmt->fetch() ?: null;

    return [
        'scenario'          => $scenario,
        'today_appointment' => $todayAppt,
        'latest_vitals'     => $latestVitals,
        'allergies'         => $emergencyInfo['allergies'] ?? null,
        'blood_type'        => $emergencyInfo['blood_type'] ?? null,
        'emergency_contact' => $emergencyInfo ? [
            'name'   => $emergencyInfo['emergency_contact_name'] ?? null,
            'number' => $emergencyInfo['emergency_contact_number'] ?? null,
        ] : null,
        'queue_ready'       => true,
        'reason'            => $reason,
    ];
}

/**
 * Mark a student's same-day appointment as "arrived" (check-in via QR).
 * Only applies to approved/pending appointments. Idempotent — safe to call
 * multiple times (second call is a no-op).
 */
function mark_appointment_arrived($db, $studentId, $appointmentId, $qrToken = null) {
    $stmt = $db->prepare("
        UPDATE appointments
        SET status = 'arrived',
            arrived_at = COALESCE(arrived_at, NOW()),
            qr_token = COALESCE(qr_token, ?)
        WHERE id = ? AND student_id = ?
          AND status IN ('approved', 'pending', 'arrived')
    ");
    $stmt->execute([$qrToken, $appointmentId, $studentId]);
    return $stmt->rowCount() > 0;
}

/**
 * Build the MICA post-scan greeting message for a student.
 * Returns a friendly, spoken-ready sentence that includes the queue
 * number, position, and estimated wait when available.
 */
function build_mica_scan_greeting($db, $studentId, $queueNumber = null) {
    $stmt = $db->prepare("
        SELECT u.first_name, u.last_name, s.student_number, s.course, s.year_level
        FROM students s JOIN users u ON u.id = s.user_id
        WHERE s.id = ?
    ");
    $stmt->execute([$studentId]);
    $s = $stmt->fetch();
    if (!$s) return '';

    $firstName = $s['first_name'] ?? 'Student';

    $hour = (int)date('G');
    if ($hour >= 5 && $hour < 12) $greeting = 'Good morning';
    elseif ($hour >= 12 && $hour < 18) $greeting = 'Good afternoon';
    else $greeting = 'Good evening';

    // Build position + estimated wait if we have a queue number
    $positionNote = '';
    if ($queueNumber) {
        $stmt = $db->prepare("
            SELECT COUNT(*) FROM queue_numbers
            WHERE status IN ('waiting','serving')
              AND DATE(created_at) = CURDATE()
              AND queue_number < ?
        ");
        $stmt->execute([$queueNumber]);
        $ahead = (int)$stmt->fetchColumn();
        $avgService = (int)get_qr_setting($db, 'avg_service_time', 5);
        $estMin = max(1, ($ahead + 1) * $avgService);

        $positionNote = " Your queue number is {$queueNumber}, with {$ahead} student(s) ahead of you. Estimated waiting time is about {$estMin} minutes.";
    }

    return "{$greeting}, {$firstName}. Welcome to the BPC School Clinic. Your check-in has been completed successfully." . $positionNote . ' Please wait while our nurse prepares for your consultation.';
}

/**
 * Export QR scan logs / QR-related data as CSV rows.
 * Returns an array of associative arrays suitable for CSV/Excel export.
 */
function qr_export_rows($db, $type, $from, $to, $search = '') {
    $from = $from ?: date('Y-m-01');
    $to   = $to   ?: date('Y-m-d');

    switch ($type) {
        case 'qr_scan_logs':
            $where = "qsl.scan_time BETWEEN ? AND ?";
            $params = [$from . ' 00:00:00', $to . ' 23:59:59'];
            if ($search !== '') {
                $where .= " AND (s.student_number LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ? OR qsl.purpose LIKE ? OR qsl.scan_intent LIKE ?)";
                $like = '%' . $search . '%';
                $params = array_merge($params, [$like, $like, $like, $like, $like]);
            }
            $stmt = $db->prepare("
                SELECT qsl.scan_time AS 'Date/Time', qsl.purpose AS 'Purpose', qsl.scan_intent AS 'Intent',
                       qsl.status AS 'Status', qsl.scan_location AS 'Location',
                       COALESCE(qsl.scanner_name, '') AS 'Scanner',
                       CONCAT(u.first_name, ' ', u.last_name) AS 'Student',
                       COALESCE(s.student_number, '') AS 'Student No.'
                FROM qr_scan_logs qsl
                LEFT JOIN students s ON s.id = qsl.student_id
                LEFT JOIN users u ON u.id = s.user_id
                WHERE $where
                ORDER BY qsl.scan_time DESC
            ");
            $stmt->execute($params);
            return $stmt->fetchAll();

        case 'daily_checkins':
            $stmt = $db->prepare("
                SELECT DATE(qsl.scan_time) AS 'Date',
                       COUNT(*) AS 'Total Scans',
                       SUM(CASE WHEN qsl.status = 'success' THEN 1 ELSE 0 END) AS 'Successful',
                       SUM(CASE WHEN qsl.status = 'duplicate' THEN 1 ELSE 0 END) AS 'Duplicates',
                       COUNT(DISTINCT qsl.student_id) AS 'Unique Students'
                FROM qr_scan_logs qsl
                WHERE qsl.scan_time BETWEEN ? AND ?
                GROUP BY DATE(qsl.scan_time)
                ORDER BY DATE(qsl.scan_time) DESC
            ");
            $stmt->execute([$from . ' 00:00:00', $to . ' 23:59:59']);
            return $stmt->fetchAll();

        case 'walkin_statistics':
            $stmt = $db->prepare("
                SELECT DATE(w.visit_date) AS 'Date',
                       COUNT(*) AS 'Walk-ins',
                       SUM(CASE WHEN w.status = 'completed' THEN 1 ELSE 0 END) AS 'Completed',
                       SUM(CASE WHEN w.status = 'waiting' THEN 1 ELSE 0 END) AS 'Waiting',
                       SUM(CASE WHEN w.status = 'cancelled' THEN 1 ELSE 0 END) AS 'Cancelled',
                       COUNT(w.student_id) AS 'Registered Students'
                FROM walk_in_visits w
                WHERE w.visit_date BETWEEN ? AND ?
                GROUP BY w.visit_date
                ORDER BY w.visit_date DESC
            ");
            $stmt->execute([$from, $to]);
            return $stmt->fetchAll();

        case 'appointment_attendance':
            $stmt = $db->prepare("
                SELECT a.appointment_date AS 'Date',
                       CONCAT(u.first_name, ' ', u.last_name) AS 'Student',
                       COALESCE(s.student_number, '') AS 'Student No.',
                       a.appointment_time AS 'Time',
                       a.reason AS 'Reason',
                       a.status AS 'Status',
                       a.arrived_at AS 'Arrived At'
                FROM appointments a
                JOIN students s ON s.id = a.student_id
                JOIN users u ON u.id = s.user_id
                WHERE a.appointment_date BETWEEN ? AND ?
                ORDER BY a.appointment_date DESC, a.appointment_time ASC
            ");
            $stmt->execute([$from, $to]);
            return $stmt->fetchAll();

        case 'queue_report':
            $stmt = $db->prepare("
                SELECT q.queue_number AS 'Queue No.',
                       DATE(q.created_at) AS 'Date',
                       q.visit_type AS 'Visit Type',
                       q.status AS 'Status',
                       q.priority AS 'Priority',
                       COALESCE(CONCAT(u.first_name, ' ', u.last_name), q.full_name) AS 'Student',
                       COALESCE(s.student_number, '') AS 'Student No.',
                       q.created_at AS 'Created At',
                       q.called_at AS 'Called At',
                       q.served_at AS 'Served At',
                       q.estimated_wait_minutes AS 'Est. Wait (min)'
                FROM queue_numbers q
                LEFT JOIN students s ON s.id = q.student_id
                LEFT JOIN users u ON u.id = s.user_id
                WHERE DATE(q.created_at) BETWEEN ? AND ?
                ORDER BY q.created_at ASC
            ");
            $stmt->execute([$from, $to]);
            return $stmt->fetchAll();

        default:
            return [];
    }
}

/**
 * Emit an in-memory CSV file for download.
 */
function qr_export_csv($rows, $filename) {
    if (empty($rows)) {
        header('Content-Type: text/plain');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo "No data found for the selected period.";
        exit;
    }

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $out = fopen('php://output', 'w');
    // BOM for Excel UTF-8 compatibility
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, array_keys($rows[0]));
    foreach ($rows as $row) {
        fputcsv($out, array_values($row));
    }
    fclose($out);
    exit;
}
