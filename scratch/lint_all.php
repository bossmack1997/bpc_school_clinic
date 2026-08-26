<?php
$dirs = ['pages', 'components', 'includes', 'api'];
$errors = 0;
$total = 0;

foreach ($dirs as $dir) {
    $fullDir = __DIR__ . '/../' . $dir;
    if (!is_dir($fullDir)) continue;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fullDir));
    foreach ($it as $f) {
        if ($f->isFile() && $f->getExtension() === 'php') {
            $total++;
            $out = shell_exec('php -l "' . $f->getPathname() . '" 2>&1');
            if (strpos($out, 'No syntax errors detected') === false) {
                echo "Syntax error in " . $f->getPathname() . ":\n" . $out . "\n";
                $errors++;
            }
        }
    }
}

echo "Linted $total PHP files. Errors: $errors\n";

