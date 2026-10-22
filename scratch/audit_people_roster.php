<?php
ini_set('session.save_path',__DIR__);require __DIR__.'/../config/config.php';echo json_encode(getDB()->query('SELECT role,status,COUNT(*) AS total FROM institutional_roster GROUP BY role,status')->fetchAll()).PHP_EOL;
