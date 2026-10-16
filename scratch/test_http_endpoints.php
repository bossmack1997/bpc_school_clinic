<?php
/**
 * scratch/test_http_endpoints.php
 * HTTP-level end-to-end tests for session handling, 401s, redirects, and pages
 */

$baseUrl = 'http://localhost/bpc_clinic_system';

function http_get($url, $headers = []) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    if (!empty($headers)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }
    $raw = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $headerStr = substr($raw, 0, $headerSize);
    $body = substr($raw, $headerSize);
    curl_close($ch);

    return [
        'status' => $status,
        'headers' => $headerStr,
        'body' => $body
    ];
}

echo "Testing HTTP endpoints on $baseUrl ...\n\n";

// 1. Unauthenticated page access redirects to login (302)
$res = http_get($baseUrl . '/pages/nurse/dashboard.php');
echo "[1] GET /pages/nurse/dashboard.php without session: HTTP " . $res['status'] . "\n";
assert($res['status'] === 302, "Expected 302 redirect for protected web page");
assert(str_contains($res['headers'], 'login.php'), "Expected redirect to login.php");
echo "    -> PASS: Properly redirected to login.php\n";

// 2. Unauthenticated API access with Accept: application/json returns 401 JSON
$res = http_get($baseUrl . '/api/inventory.php?action=list', ['Accept: application/json']);
echo "[2] GET /api/inventory.php with Accept: application/json: HTTP " . $res['status'] . "\n";
assert($res['status'] === 401, "Expected 401 JSON response for unauthenticated API");
$json = json_decode($res['body'], true);
assert($json && $json['success'] === false, "Expected JSON with success=false");
echo "    -> PASS: Returned 401 JSON: " . ($json['message'] ?? '') . "\n";

// 3. Login page loads successfully (200)
$res = http_get($baseUrl . '/auth/login.php');
echo "[3] GET /auth/login.php: HTTP " . $res['status'] . "\n";
assert($res['status'] === 200, "Expected HTTP 200 on login page");
echo "    -> PASS: Login page rendered successfully\n";

echo "\nAll HTTP endpoint checks PASSED!\n";

