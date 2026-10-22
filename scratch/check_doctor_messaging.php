<?php
ini_set('session.save_path',__DIR__);require __DIR__.'/../config/config.php';$db=getDB();foreach(['conversations','conversation_participants','messages'] as $t){echo $t.PHP_EOL;foreach($db->query("SHOW COLUMNS FROM $t")->fetchAll() as $r)echo $r['Field'].' '.$r['Type'].' null='.$r['Null'].PHP_EOL;}
