<?php
/** Streaming SQL export and restore into a separate, empty recovery database. */
final class BackupService {
    public function __construct(private PDO $db, private string $directory, private string $schema) {}
    public function path(string $filename): string {
        if(!preg_match('/^bpc-backup-\d{8}-\d{6}-[a-f0-9]{8}\.sql$/D',$filename))throw new InvalidArgumentException('Invalid backup filename.');
        return $this->directory.DIRECTORY_SEPARATOR.$filename;
    }
    public function create(int $actor): int {
        if(!is_dir($this->directory)&&!mkdir($this->directory,0700,true)&&!is_dir($this->directory))throw new RuntimeException('Backup storage unavailable.');
        if(!is_writable($this->directory))throw new RuntimeException('Backup storage is not writable.');
        $name='bpc-backup-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(4)).'.sql';$path=$this->path($name);
        $q=$this->db->prepare("INSERT INTO system_backups(requested_by,status,filename) VALUES (?,'processing',?)");$q->execute([$actor,$name]);$id=(int)$this->db->lastInsertId();$handle=null;
        try {
            $handle=fopen($path,'xb');if(!$handle)throw new RuntimeException('Unable to open backup storage.');
            @chmod($path,0600);
            $write=static function(string $text)use($handle):void{if(fwrite($handle,$text)!==strlen($text))throw new RuntimeException('Backup storage write failed.');};
            $tables=$this->db->prepare("SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME");$tables->execute([$this->schema]);$list=$tables->fetchAll();
            if(!$list)throw new RuntimeException('No database tables available to back up.');
            foreach($list as $table)if(strcasecmp((string)$table['ENGINE'],'InnoDB')!==0)throw new RuntimeException('A consistent backup requires all tables to use InnoDB.');
            $write("-- BPC Clinic database backup v2\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n");
            $this->db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$this->db->beginTransaction();
            foreach($list as $table){
                $name=$table['TABLE_NAME'];if(!preg_match('/^[A-Za-z0-9_]+$/D',$name))throw new RuntimeException('Unsupported table identifier.');$quoted='`'.$name.'`';
                $definition=$this->db->query('SHOW CREATE TABLE '.$quoted)->fetch(PDO::FETCH_NUM);
                $write('DROP TABLE IF EXISTS '.$quoted.";\n".$definition[1].";\n");
                $rows=$this->db->query('SELECT * FROM '.$quoted);
                while($row=$rows->fetch(PDO::FETCH_ASSOC)){
                    $columns=array_map(static fn($key)=>'`'.str_replace('`','``',$key).'`',array_keys($row));
                    $values=array_map(static fn($value)=>$value===null?'NULL':"X'".bin2hex((string)$value)."'",array_values($row));
                    $write('INSERT INTO '.$quoted.' ('.implode(',',$columns).') VALUES ('.implode(',',$values).");\n");
                }
            }
            $this->db->commit();$write("SET FOREIGN_KEY_CHECKS=1;\n");fclose($handle);$handle=null;
            $sha=hash_file('sha256',$path);if(!$sha)throw new RuntimeException('Unable to verify backup checksum.');
            $this->db->prepare("UPDATE system_backups SET status='completed',sha256=?,completed_at=NOW(),error_message=NULL WHERE id=?")->execute([$sha,$id]);
            $this->audit($actor,'Created system backup',$id);return $id;
        }catch(Throwable $e){
            if($this->db->inTransaction())$this->db->rollBack();if(is_resource($handle))fclose($handle);if(is_file($path))unlink($path);
            $this->db->prepare("UPDATE system_backups SET status='failed',completed_at=NOW(),error_message='Backup generation failed; review the server log.' WHERE id=?")->execute([$id]);throw $e;
        }
    }
    public function verified(int $id): string {
        $q=$this->db->prepare("SELECT filename,sha256 FROM system_backups WHERE id=? AND status='completed'");$q->execute([$id]);$r=$q->fetch();
        if(!$r)throw new RuntimeException('Completed backup not found.',404);$path=$this->path($r['filename']);
        if(!is_readable($path)||!hash_equals((string)$r['sha256'],(string)hash_file('sha256',$path)))throw new RuntimeException('Backup is unavailable or failed integrity verification.',409);
        return $path;
    }
    public function restore(int $id,string $target,int $actor,string $password,string $confirmation): void {
        $q=$this->db->prepare("SELECT * FROM users WHERE id=? AND role='admin' AND status='active'");$q->execute([$actor]);$u=$q->fetch();
        if(!$u||permission_profile($u,$this->db)!=='mis'||!has_admin_permission('backups_manage',$u,$this->db)||!password_verify($password,$u['password_hash']))throw new RuntimeException('Authorized MIS reauthentication required.',403);
        if(!preg_match('/^[A-Za-z0-9_]{1,64}$/D',$target)||strcasecmp($target,$this->schema)===0||$confirmation!==$target)throw new InvalidArgumentException('Confirm a separate empty recovery database. The active database cannot be overwritten.');
        $path=$this->verified($id);
        $recovery=new PDO('mysql:host='.DB_HOST.';port='.DB_PORT.';dbname='.$target.';charset=utf8mb4',DB_USER,DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $recovery->exec("SET time_zone='+08:00'");
        if($recovery->query('SHOW FULL TABLES')->fetch())throw new RuntimeException('Recovery target must be empty. Existing records will not be overwritten.',409);
        $header=fopen($path,'rb');$version=fgets($header);fclose($header);
        if($version!=="-- BPC Clinic database backup v2\n")throw new RuntimeException('Use a verified v2 backup for isolated recovery.');
        foreach($this->statements($path) as $statement)if(!preg_match('/^(SET (NAMES utf8mb4|FOREIGN_KEY_CHECKS=[01])|DROP TABLE IF EXISTS `[A-Za-z0-9_]+`|CREATE TABLE `[A-Za-z0-9_]+`|INSERT INTO `[A-Za-z0-9_]+`)/D',$statement)||preg_match('/\\b(?:OUTFILE|DUMPFILE|LOAD_FILE|DEFINER)\\b/i',$statement))throw new RuntimeException('Unsupported recovery statement.');
        try {foreach($this->statements($path) as $statement)$recovery->exec($statement);$this->audit($actor,'Restored isolated system backup',$id);}
        catch(Throwable $e){$this->audit($actor,'Failed isolated backup recovery',$id);throw new RuntimeException('Recovery failed. The active database was unchanged; inspect the separate recovery database.');}
        finally{$recovery->exec('SET FOREIGN_KEY_CHECKS=1');}
    }
    private function statements(string $path): Generator {
        $handle=fopen($path,'rb');if(!$handle)throw new RuntimeException('Backup unavailable.');fgets($handle);
        $buffer='';$quote=null;$escaped=false;
        try {
            while(!feof($handle))foreach(str_split(fread($handle,65536)) as $char){
                if($quote!==null){$buffer.=$char;if($escaped){$escaped=false;continue;}if($char==='\\'){$escaped=true;continue;}if($char===$quote)$quote=null;continue;}
                if(in_array($char,["'",'"','`'],true)){$quote=$char;$buffer.=$char;continue;}
                if($char===';'){$statement=trim($buffer);$buffer='';if($statement!=='')yield $statement;}else $buffer.=$char;
            }
            if($quote!==null||trim($buffer)!=='')throw new RuntimeException('Backup statement framing is invalid.');
        }finally{fclose($handle);}
    }
    private function audit(int $actor,string $action,int $id): void {
        $this->db->prepare("INSERT INTO activity_logs(user_id,action,module,details) VALUES (?,?,'backups',?)")->execute([$actor,$action,'Backup record #'.$id]);
    }
}
