<?php
$css = file_get_contents(__DIR__ . '/../assets/css/bootstrap-icons.min.css');
$icons = ['person-heart', 'emoji-heart-eyes', 'emoji-smile', 'heart-pulse', 'heart-pulse-fill', 'heart-fill', 'heart', 'person-check-fill', 'people-fill'];
foreach ($icons as $ic) {
    echo $ic . ': ' . (strpos($css, 'bi-' . $ic) !== false ? 'FOUND' : 'NOT FOUND') . "\n";
}

