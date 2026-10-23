<?php
/**
 * CSRF Protection Helpers
 */

function csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_regenerate(): void {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function csrf_field() {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token()) . '">';
}

function csrf_verify($token) {
    if (!is_string($_SESSION['csrf_token'] ?? null) || !is_string($token) || $_SESSION['csrf_token'] === '' || $token === '') {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

function csrf_verify_or_die() {
    $token = $_POST['csrf_token'] ?? '';
    if (!csrf_verify($token)) {
        http_response_code(403);
        die('Invalid CSRF token. Please refresh the page and try again.');
    }
}
