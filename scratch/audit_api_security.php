<?php
$apiDir = __DIR__ . '/../api';
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($apiDir));

$results = [];

foreach ($it as $f) {
    if ($f->isFile() && $f->getExtension() === 'php') {
        $relPath = str_replace(realpath(__DIR__ . '/..') . DIRECTORY_SEPARATOR, '', realpath($f->getPathname()));
        $content = file_get_contents($f->getPathname());

        $hasRequireLogin = (bool)preg_match('/require_login\s*\(/', $content);
        $hasRequireRole  = (bool)preg_match('/require_role\s*\(/', $content);
        $hasRequireAuth  = (bool)preg_match('/require_auth\s*\(/', $content);
        $hasIsLoggedIn   = (bool)preg_match('/is_logged_in\s*\(/', $content);
        $hasCurrentUser  = (bool)preg_match('/current_user\s*\(/', $content);
        $hasCsrfVerify   = (bool)preg_match('/csrf_verify/', $content);

        $results[$relPath] = [
            'has_auth_gate' => ($hasRequireLogin || $hasRequireRole || $hasRequireAuth || $hasIsLoggedIn || $hasCurrentUser),
            'has_role_gate' => $hasRequireRole,
            'has_csrf'      => $hasCsrfVerify,
        ];
    }
}

echo "=== API ENDPOINTS AUDIT (" . count($results) . " endpoints) ===\n\n";
foreach ($results as $path => $info) {
    $status = $info['has_auth_gate'] ? "✅ AUTH GATE" : "❌ NO AUTH GATE";
    $role = $info['has_role_gate'] ? "[Role-Restricted]" : "[General/Any Auth]";
    $csrf = $info['has_csrf'] ? "[CSRF Protected]" : "";
    echo sprintf("%-35s %s %s %s\n", $path, $status, $role, $csrf);
}

