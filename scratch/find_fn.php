<?php
require_once __DIR__ . '/../includes/functions.php';
$r = new ReflectionFunction('get_active_patient_queue_ticket');
echo $r->getFileName() . ':' . $r->getStartLine() . "\n";

