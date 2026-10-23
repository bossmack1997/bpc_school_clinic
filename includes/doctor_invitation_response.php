<?php
/** Keep invitation POST responses JSON even when bootstrap exits before the page handler. */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
    && (strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest'
        || str_contains(strtolower($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json'))) {
    ini_set('display_errors', '0');
    header('Cache-Control: no-store');
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    ob_start(static function (string $body, int $phase): string {
        if ($phase & PHP_OUTPUT_HANDLER_CLEAN) return '';
        $result = json_decode($body, true);
        if (is_array($result) && isset($result['success']) && is_bool($result['success'])) {
            header('Content-Type: application/json; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
            return $body;
        }
        // Never return bootstrap diagnostics, HTML, tokens or exception messages.
        $status = http_response_code();
        if ($status < 400) $status = 503;
        http_response_code($status);
        header_remove('Location');
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        error_log('Doctor invitation response rejected; status=' . $status);
        return json_encode(['success' => false, 'error_code' => 'INVITATION_OUTCOME_UNKNOWN',
            'message' => 'Invitation service unavailable. Check the account list before retrying; an account may already be pending.']);
    });
}
