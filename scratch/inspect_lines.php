<?php
$f = file('api/medical_records.php');
for ($i = 9; $i < 15; $i++) {
    echo json_encode($f[$i]) . "\n";
}

