<?php
require_once __DIR__ . '/../includes/functions.php';
$r = new ReflectionFunction('push_notification');
echo $r->getFileName() . ':' . $r->getStartLine() . "\n";
foreach ($r->getParameters() as $p) {
    echo "  " . $p->getName() . ($p->isDefaultValueAvailable() ? ' = ' . var_export($p->getDefaultValue(), true) : '') . "\n";
}

