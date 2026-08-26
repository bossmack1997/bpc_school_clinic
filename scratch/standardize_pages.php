<?php
$dir = __DIR__ . '/../pages';

$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
$count = 0;

foreach ($iterator as $file) {
    if ($file->isFile() && $file->getExtension() === 'php') {
        $path = $file->getPathname();
        $content = file_get_contents($path);

        $updated = preg_replace('/<div class="content-area">(?!\s*page-enter)/', '<div class="content-area page-enter">', $content);

        if ($updated !== $content) {
            file_put_contents($path, $updated);
            echo "Updated page-enter in: " . str_replace(__DIR__ . '/../', '', $path) . "\n";
            $count++;
        }
    }
}

echo "Total pages updated with page-enter: $count\n";

