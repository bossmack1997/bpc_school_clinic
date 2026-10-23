<?php
/** Additive deployment migration. Never called implicitly by a public request. */
function migrate_basic_registration_schema(PDO $db): array {
    $columns=$db->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN);
    $added=[];
    foreach(['email_verified_at'=>'DATETIME NULL','self_service_only'=>'TINYINT(1) NOT NULL DEFAULT 0'] as $name=>$definition){
        if(in_array($name,$columns,true))continue;
        try{$db->exec("ALTER TABLE users ADD COLUMN $name $definition");$added[]=$name;}
        catch(PDOException $e){if((int)($e->errorInfo[1]??0)!==1060)throw $e;}
    }
    return $added;
}
