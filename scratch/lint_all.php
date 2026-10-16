<?php
// Comprehensive audit script for BPC Clinic System
$basePath = realpath(__DIR__ . '/..');

echo "Auditing PHP files for syntax errors...\n";
$phpFiles = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($basePath));
foreach ($iterator as $file) {
    if ($file->isFile() && $file->getExtension() === 'php') {
        $path = $file->getRealPath();
        // Skip vendor if exists
        if (str_contains($path, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR)) continue;
        if (str_contains($path, DIRECTORY_SEPARATOR . '.kilo' . DIRECTORY_SEPARATOR)) continue;
        if (str_contains($path, DIRECTORY_SEPARATOR . 'scratch' . DIRECTORY_SEPARATOR)) continue;
        $phpFiles[] = $path;
    }
}

echo "Found " . count($phpFiles) . " PHP files to inspect.\n";

$syntaxErrors = [];
foreach ($phpFiles as $file) {
    $rel = str_replace($basePath . DIRECTORY_SEPARATOR, '', $file);
    $out = [];
    $ret = 0;
    exec("php -l \"$file\" 2>&1", $out, $ret);
    if ($ret !== 0) {
        $syntaxErrors[$rel] = implode("\n", $out);
    }
}

if (!empty($syntaxErrors)) {
    echo "\n[!] SYNTAX ERRORS (" . count($syntaxErrors) . "):\n";
    foreach ($syntaxErrors as $f => $err) {
        echo " - $f:\n   $err\n";
    }
} else {
    echo "✓ No syntax errors found in any of the " . count($phpFiles) . " files.\n";
}

