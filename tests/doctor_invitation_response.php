<?php
/** Isolated response-contract tests; no database, sessions, mail or real accounts. */
if (PHP_SAPI !== 'cli') exit;
if (($argv[1] ?? '') === '--fixture') {
    http_response_code(200);
    ob_start(static fn(string $body): string => json_encode(['status'=>http_response_code(), 'body'=>$body]));
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['HTTP_ACCEPT'] = 'application/json';
    require __DIR__ . '/../includes/doctor_invitation_response.php';
    $case = $argv[2];
    if ($case === 'bootstrap') { http_response_code(503); exit('private bootstrap diagnostic'); }
    if ($case === 'redirect') { header('Location: /auth/login.php'); exit('<html>redirect</html>'); }
    if ($case === 'fatal') { throw new Error('private fatal diagnostic'); }
    if ($case === 'malformed') { http_response_code(500); exit('{broken'); }
    if ($case === 'clean') { echo 'private warning'; ob_clean(); }
    http_response_code($case === 'csrf' ? 403 : 202);
    echo json_encode(['success' => $case !== 'csrf', 'message' => 'safe fixture']);
    exit;
}
$checks = 0;
foreach (['bootstrap', 'redirect', 'fatal', 'malformed', 'clean', 'csrf', 'valid'] as $case) {
    $process = proc_open([PHP_BINARY, '-d', 'display_errors=0', __FILE__, '--fixture', $case],
        [0=>['pipe','r'], 1=>['pipe','w'], 2=>['file', 'NUL', 'a']], $pipes);
    fclose($pipes[0]); $body = stream_get_contents($pipes[1]); fclose($pipes[1]); proc_close($process);
    $envelope = json_decode($body, true);
    $body = $envelope['body'] ?? '';
    $json = json_decode($body, true);
    if (!is_array($json) || !is_bool($json['success'] ?? null) || str_contains($body, 'private')) throw new RuntimeException('Unsafe response: ' . $case);
    $unknown = in_array($case, ['bootstrap', 'redirect', 'fatal', 'malformed'], true);
    $status = $envelope['status'] ?? 0;
    if ($unknown ? $status < 500 : $status !== ($case === 'csrf' ? 403 : 202)) throw new RuntimeException('Incorrect status: ' . $case);
    if ($unknown !== (($json['error_code'] ?? '') === 'INVITATION_OUTCOME_UNKNOWN')) throw new RuntimeException('Incorrect outcome: ' . $case);
    echo 'PASS ' . $case . " response contract\n"; $checks++;
}
echo "$checks response checks passed.\n";
