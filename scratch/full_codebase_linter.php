<?php
/**
 * Full Codebase Syntax & Integrity Linter
 */

$baseDir = dirname(__DIR__);

echo "========================================================\n";
echo "CODEBASE SYNTAX & STATIC INTEGRITY SCAN\n";
echo "========================================================\n\n";

$phpFiles = [];
$dirIterator = new RecursiveDirectoryIterator($baseDir, RecursiveDirectoryIterator::SKIP_DOTS);
$iterator = new RecursiveIteratorIterator($dirIterator);

foreach ($iterator as $file) {
    if ($file->isFile() && $file->getExtension() === 'php') {
        // Skip scratch files
        if (strpos($file->getPathname(), 'scratch') !== false) continue;
        $phpFiles[] = $file->getPathname();
    }
}

echo "Found " . count($phpFiles) . " PHP files to check.\n";

$syntaxErrors = [];
foreach ($phpFiles as $file) {
    $output = [];
    $returnVar = 0;
    exec("php -l " . escapeshellarg($file) . " 2>&1", $output, $returnVar);
    if ($returnVar !== 0) {
        $syntaxErrors[$file] = implode("\n", $output);
    }
}

if (empty($syntaxErrors)) {
    echo "✅ 100% Syntax Valid: Zero PHP syntax errors detected across all " . count($phpFiles) . " files!\n\n";
} else {
    echo "❌ Syntax Errors Found (" . count($syntaxErrors) . "):\n";
    foreach ($syntaxErrors as $f => $err) {
        echo "File: $f\n$err\n\n";
    }
}

echo "========================================================\n";

