<?php
if(PHP_SAPI!=='cli')exit;
require __DIR__.'/../config/database.php';
$before=json_decode(file_get_contents(__DIR__.'/backups/inventory-mica-20261022/data-before.json'),true);
$db=getDB();
foreach(['medicines','medicine_logs','admin_permissions'] as $table){
    $rows=$db->query("SELECT * FROM $table ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    if($rows!==$before[$table])throw new RuntimeException("Preservation mismatch: $table");
    echo 'PASS unchanged '.$table.': '.count($rows).' rows'.PHP_EOL;
}
