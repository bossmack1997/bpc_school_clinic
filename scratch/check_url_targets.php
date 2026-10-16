<?php
require_once __DIR__ . '/../config/config.php';

$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(BASE_PATH));
$brokenLinks = [];

foreach ($iterator as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') continue;
    $path = $file->getRealPath();
    if (str_contains($path, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR)) continue;
    if (str_contains($path, DIRECTORY_SEPARATOR . '.kilo' . DIRECTORY_SEPARATOR)) continue;
    if (str_contains($path, DIRECTORY_SEPARATOR . 'scratch' . DIRECTORY_SEPARATOR)) continue;
    
    $content = file_get_contents($path);
    $rel = str_replace(BASE_PATH . DIRECTORY_SEPARATOR, '', $path);
    
    // Match patterns like APP_URL . '/pages/...' or '/pages/...'
    if (preg_match_all('/(?:APP_URL\s*\.\s*)?[\'"]\/pages\/([a-zA-Z0-9_\-\/]+\.php)(?:\?[^\'"]*)?[\'"]/i', $content, $m)) {
        foreach ($m[1] as $target) {
            $abs = BASE_PATH . '/pages/' . $target;
            if (!file_exists($abs)) {
                $brokenLinks[] = "File $rel -> target '/pages/$target' does not exist!";
            }
        }
    }
}

if (empty($brokenLinks)) {
    echo "✓ All /pages/*.php URL references are valid.\n";
} else {
    $unique = array_unique($brokenLinks);
    echo "[!] Found " . count($unique) . " broken page references:\n";
    foreach ($unique as $b) {
        echo " - $b\n";
    }
}

