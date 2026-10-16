<?php
require_once __DIR__ . '/../includes/functions.php';
$r = new ReflectionFunction('resolve_patient_qr');
echo $r->getFileName() . ':' . $r->getStartLine() . "\n";

