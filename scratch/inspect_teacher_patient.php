<?php
require __DIR__.'/../config/config.php';
$db=getDB();
$sql=file_get_contents(__DIR__.'/../database/migration_clinic_availability.sql');
$db->exec($sql);
echo "clinic availability settings applied\n";
foreach(['teachers','queue_numbers','walk_in_visits','consultations','medical_records','clinic_settings'] as $table){
 echo "\n[$table]\n";
 try{$rows=$db->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);foreach($rows as $r)echo $r['Field'].':'.$r['Type'].':'.$r['Null']."\n";}catch(Throwable $e){echo 'ERROR '.$e->getMessage()."\n";}
}
