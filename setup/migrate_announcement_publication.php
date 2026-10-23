<?php
/** Local-only additive upgrade, with a verified single-table backup first. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/../config/config.php';
if(!in_array('--apply-local',$argv,true)||APP_ENV!=='local'||!in_array(DB_HOST,['localhost','127.0.0.1','::1'],true))throw new RuntimeException('Use --apply-local against local XAMPP only. Review the SQL separately for staging/hosting.');
$announcementDb=getDB();$sourceName=$announcementDb->query('SELECT DATABASE()')->fetchColumn();
if(!preg_match('/^[a-zA-Z0-9_]+$/D',$sourceName))throw new RuntimeException('Unexpected database identifier.');
$audience=$announcementDb->query("SHOW COLUMNS FROM announcements LIKE 'audience'")->fetch(PDO::FETCH_ASSOC);
preg_match_all("/'([^']*)'/",$audience['Type']??'',$audienceValues);
if(!str_starts_with(strtolower($audience['Type']??''),'enum(')||array_diff($audienceValues[1],['all','students','teachers','nurses','admin']))throw new RuntimeException('Custom audience schema requires manual review before migration.');
$columns=['id','title','content','posted_by','audience','is_pinned','created_at'];
$snapshot=static fn(PDO $db)=>$db->query('SELECT '.implode(',',$columns).' FROM announcements ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$before=$snapshot($announcementDb);$definition=$announcementDb->query('SHOW CREATE TABLE announcements')->fetch(PDO::FETCH_NUM)[1];
$allRows=$announcementDb->query('SELECT * FROM announcements ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$backup="-- Local announcement table backup; private, not for web serving.\nSET FOREIGN_KEY_CHECKS=0;\n".$definition.";\n";
foreach($allRows as $row){$names=array_map(static fn($column)=>'`'.str_replace('`','``',$column).'`',array_keys($row));$values=array_map(static fn($value)=>$value===null?'NULL':$announcementDb->quote((string)$value),array_values($row));$backup.='INSERT INTO announcements ('.implode(',',$names).') VALUES ('.implode(',',$values).");\n";}
$backup.="SET FOREIGN_KEY_CHECKS=1;\n";
$backupDir=BASE_PATH.'/storage/backups';if(!is_dir($backupDir)&&!mkdir($backupDir,0700,true)&&!is_dir($backupDir))throw new RuntimeException('Backup directory unavailable.');
$backupPath=$backupDir.'/announcements-before-publication-'.(new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format('Ymd-His').'-'.bin2hex(random_bytes(4)).'.sql';
if(file_put_contents($backupPath,$backup,LOCK_EX)===false)throw new RuntimeException('Backup could not be saved.');
$restoreName='bpc_ann_restore_'.bin2hex(random_bytes(5));$restoreDb=null;
try{
    $announcementDb->exec("CREATE DATABASE `$restoreName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $restoreDb=new PDO('mysql:host='.DB_HOST.';port='.DB_PORT.';dbname='.$restoreName.';charset=utf8mb4',DB_USER,DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    // PDO executes the exact saved SQL, including quoted newlines and semicolons.
    $saved=file_get_contents($backupPath);if($saved!==$backup)throw new RuntimeException('Backup read verification failed.');$restoreDb->exec($saved);
    if($restoreDb->query('SELECT * FROM announcements ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)!==$allRows)throw new RuntimeException('Backup restore did not match the original announcement rows.');
    echo 'Private table backup restored and verified ('.count($before)." announcement rows).\n";
}finally{
    $restoreDb=null;
    if(preg_match('/^bpc_ann_restore_[a-f0-9]{10}$/D',$restoreName))$announcementDb->exec("DROP DATABASE IF EXISTS `$restoreName`");
}
$migration=preg_replace('/^\s*--.*$/m','',file_get_contents(__DIR__.'/../database/migration_announcement_publication_20261024.sql'));
foreach(explode(';',$migration) as $statement)if(trim($statement)!=='')$announcementDb->exec($statement);
if($snapshot($announcementDb)!==$before)throw new RuntimeException('Original announcement values changed; inspect the private backup before proceeding.');
echo "Publication schema applied locally; original announcement values preserved. No patient tables were changed.\n";
echo 'Backup: storage/backups/'.basename($backupPath).PHP_EOL;
