<?php
require_once __DIR__ . '/../config/config.php';

$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(BASE_PATH));
$calls = [];

foreach ($iterator as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') continue;
    $path = $file->getRealPath();
    if (str_contains($path, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR)) continue;
    if (str_contains($path, DIRECTORY_SEPARATOR . '.kilo' . DIRECTORY_SEPARATOR)) continue;
    if (str_contains($path, DIRECTORY_SEPARATOR . 'scratch' . DIRECTORY_SEPARATOR)) continue;
    
    $content = file_get_contents($path);
    $rel = str_replace(BASE_PATH . DIRECTORY_SEPARATOR, '', $path);
    
    if (preg_match_all('/push_notification\s*\(([^;]+)\)/s', $content, $matches)) {
        foreach ($matches[1] as $argStr) {
            $calls[] = [
                'file' => $rel,
                'args' => trim(preg_replace('/\s+/', ' ', $argStr))
            ];
        }
    }
}

echo "Found " . count($calls) . " calls to push_notification:\n";
foreach ($calls as $c) {
    // Check if 6th argument looks like a ticket or ID instead of null or sos
    $args = explode(',', $c['args']);
    if (count($args) >= 6) {
        $arg6 = trim($args[5] ?? '');
        $arg7 = trim($args[6] ?? '');
        if ($arg6 !== 'null' && !str_contains($arg6, 'sos') && !str_contains($arg6, 'emergency') && !str_contains($arg6, '$sos')) {
            echo "[SUSPICIOUS PARAM ORDER] {$c['file']}: push_notification(" . substr($c['args'], 0, 80) . "...)\n";
            echo "   Arg 6 (sos_emergency_id): '$arg6' | Arg 7 (priority): '$arg7'\n";
        }
    }
}

