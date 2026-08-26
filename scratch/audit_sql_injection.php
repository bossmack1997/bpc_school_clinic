<?php
$dirs = ['api', 'pages', 'includes', 'auth'];
$suspicious = [];

foreach ($dirs as $dir) {
    $fullDir = __DIR__ . '/../' . $dir;
    if (!is_dir($fullDir)) continue;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fullDir));
    foreach ($it as $f) {
        if ($f->isFile() && $f->getExtension() === 'php') {
            $path = $f->getPathname();
            $relPath = str_replace(realpath(__DIR__ . '/..') . DIRECTORY_SEPARATOR, '', realpath($path));
            $content = file_get_contents($path);
            $lines = explode("\n", $content);

            foreach ($lines as $lineNum => $line) {
                // Check if query() has variable interpolation that isn't just implode of ints/placeholders
                if (preg_match('/\$db->query\s*\(\s*["\']\s*SELECT.+?WHERE.+?\$[a-zA-Z]/i', $line)) {
                    $suspicious[] = [
                        'file' => $relPath,
                        'line' => $lineNum + 1,
                        'code' => trim($line),
                    ];
                }
                // Check if prepare has directly concatenated variables inside quotes instead of ? or :named
                if (preg_match('/\$db->prepare\s*\(\s*["\'].+?WHERE.+?\'\s*\.\s*\$[a-zA-Z]/i', $line)) {
                    $suspicious[] = [
                        'file' => $relPath,
                        'line' => $lineNum + 1,
                        'code' => trim($line),
                    ];
                }
            }
        }
    }
}

echo "=== SQL INJECTION SECURITY SCAN ===\n";
echo "Suspicious raw interpolated queries found: " . count($suspicious) . "\n\n";
foreach ($suspicious as $s) {
    echo "⚠️ {$s['file']}:{$s['line']}\n   {$s['code']}\n\n";
}
if (empty($suspicious)) {
    echo "✅ No unsafe raw SQL concatenations found in query/prepare calls.\n";
}

