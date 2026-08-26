<?php
$pagesDir = __DIR__ . '/../pages';
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($pagesDir));

$results = [];
$missingAuth = [];

foreach ($iterator as $file) {
    if ($file->isFile() && $file->getExtension() === 'php') {
        $path = $file->getPathname();
        $relPath = str_replace(realpath(__DIR__ . '/..') . DIRECTORY_SEPARATOR, '', realpath($path));
        $content = file_get_contents($path);

        $hasRequireRole = preg_match('/require_role\s*\(/', $content);
        $hasRequireLogin = preg_match('/require_login\s*\(/', $content);
        $hasRequireAuth = preg_match('/require_auth\s*\(/', $content);

        $authProtected = $hasRequireRole || $hasRequireLogin || $hasRequireAuth;

        // Check if there are public pages in pages/
        // e.g. qr verification if public
        $results[$relPath] = [
            'protected' => $authProtected,
            'require_role' => (bool)$hasRequireRole,
            'require_login' => (bool)$hasRequireLogin,
        ];

        if (!$authProtected) {
            $missingAuth[] = $relPath;
        }
    }
}

echo "=== AUDIT OF ALL " . count($results) . " PAGES IN pages/ ===\n";
echo "Protected pages with server-side auth gate: " . (count($results) - count($missingAuth)) . "\n";
echo "Pages WITHOUT auth gate: " . count($missingAuth) . "\n\n";

if (!empty($missingAuth)) {
    echo "⚠️ The following pages have NO require_role / require_login:\n";
    foreach ($missingAuth as $p) {
        echo "  - $p\n";
    }
} else {
    echo "✅ ALL pages in pages/ have explicit server-side authentication/role gates!\n";
}

