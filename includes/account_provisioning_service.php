<?php
/** MIS account setup. No generated passwords or raw activation tokens are returned. */
final class AccountProvisioningService
{
    public const COLUMNS=['role','username','email','first_name','last_name','institutional_id'];
    private $send;
    public function __construct(private PDO $db, ?callable $send=null)
    {
        $this->send=$send??'send_account_activation_email';
    }

    private function authorize(int $actor): void
    {
        $q=$this->db->prepare('SELECT * FROM users WHERE id=? FOR UPDATE');$q->execute([$actor]);$u=$q->fetch();
        if(!$u||$u['status']!=='active'||permission_profile($u,$this->db)!=='mis'||!has_permission('manage_users',$u,$this->db))throw new RuntimeException('MIS account provisioning permission required.');
    }

    public static function readCsv(string $path): array
    {
        $file=fopen($path,'rb');if(!$file)throw new InvalidArgumentException('Import file could not be read.');
        try {
            $header=fgetcsv($file,0,',','"','');
            if(!$header)throw new InvalidArgumentException('The CSV is empty.');
            $header[0]=preg_replace('/^\xEF\xBB\xBF/','',$header[0]);
            if($header!==self::COLUMNS)throw new InvalidArgumentException('Use the template column headers in the same order. Save Excel files as UTF-8 CSV.');
            $rows=[];while(($row=fgetcsv($file,0,',','"',''))!==false){
                if($row===[null])continue;
                if(count($rows)>=100)throw new InvalidArgumentException('Import at most 100 accounts per batch.');
                if(count($row)!==count($header))throw new InvalidArgumentException('Each CSV row must contain exactly six columns.');
                $rows[]=array_combine($header,$row);
            }
            if(!$rows)throw new InvalidArgumentException('The CSV has no account rows.');
            return $rows;
        }finally{fclose($file);}
    }

    public static function readXlsx(string $path):array
    {
        if(!class_exists('ZipArchive'))throw new InvalidArgumentException('Excel import requires the PHP ZIP extension. Use the CSV template instead.');
        $zip=new ZipArchive();if($zip->open($path)!==true)throw new InvalidArgumentException('Upload a valid Excel .xlsx workbook.');
        try{
            $size=0;$sheets=[];
            for($i=0;$i<$zip->numFiles;$i++){
                $entry=$zip->statIndex($i);$size+=$entry['size'];
                if($size>10485760||$zip->numFiles>200)throw new InvalidArgumentException('The expanded workbook exceeds import limits.');
                if(preg_match('#^xl/worksheets/sheet\d+\.xml$#D',$entry['name']))$sheets[]=$entry['name'];
                if(str_contains($entry['name'],'externalLinks')||str_ends_with(strtolower($entry['name']),'vbaproject.bin'))throw new InvalidArgumentException('External links and macros are not allowed in imports.');
            }
            if(count($sheets)!==1)throw new InvalidArgumentException('Use a workbook with exactly one worksheet.');
            $parse=static function(string $xml):SimpleXMLElement{
                if(preg_match('/<!DOCTYPE|<!ENTITY/i',$xml))throw new InvalidArgumentException('Workbook XML declarations are not allowed.');
                $previous=libxml_use_internal_errors(true);try{$node=simplexml_load_string($xml,SimpleXMLElement::class,LIBXML_NONET);if(!$node)throw new InvalidArgumentException('Workbook XML is malformed.');return $node;}finally{libxml_clear_errors();libxml_use_internal_errors($previous);}
            };
            $strings=[];$shared=$zip->getFromName('xl/sharedStrings.xml');
            if($shared!==false){$node=$parse($shared);foreach($node->xpath('//*[local-name()="si"]') as $si){$parts=$si->xpath('.//*[local-name()="t"]');$strings[]=implode('',array_map(static fn($part)=>(string)$part,$parts));}}
            $raw=$zip->getFromName($sheets[0]);if($raw===false)throw new InvalidArgumentException('Workbook worksheet is missing.');
            $sheet=$parse($raw);if($sheet->xpath('//*[local-name()="f"]'))throw new InvalidArgumentException('Replace Excel formulas with plain values before importing.');
            $rows=[];$header=null;
            foreach($sheet->xpath('//*[local-name()="sheetData"]/*[local-name()="row"]') as $row){
                $values=array_fill(0,6,'');
                foreach($row->xpath('./*[local-name()="c"]') as $cell){
                    $ref=(string)$cell['r'];if(!preg_match('/^([A-Z]+)\d+$/D',$ref,$match)||strlen($match[1])!==1||$match[1]>'F')throw new InvalidArgumentException('Use only template columns A through F.');
                    $valueNodes=$cell->xpath('./*[local-name()="v"]');$value=$valueNodes?(string)$valueNodes[0]:'';$type=(string)$cell['t'];
                    if($type==='s'){$value=$strings[(int)$value]??'';}
                    elseif($type==='inlineStr'){$parts=$cell->xpath('.//*[local-name()="t"]');$value=implode('',array_map(static fn($part)=>(string)$part,$parts));}
                    elseif(!in_array($type,['','n','str'],true))throw new InvalidArgumentException('Use plain text or numeric cell values.');
                    $values[ord($match[1])-65]=$value;
                }
                if(!array_filter($values,static fn($v)=>$v!==''))continue;
                if($header===null){if($values!==self::COLUMNS)throw new InvalidArgumentException('Use the Excel template headers in the same order.');$header=$values;continue;}
                if(count($rows)>=100)throw new InvalidArgumentException('Import at most 100 accounts per batch.');$rows[]=array_combine(self::COLUMNS,$values);
            }
            if(!$rows)throw new InvalidArgumentException('The workbook has no account rows.');return $rows;
        }finally{$zip->close();}
    }

    public function preview(array $rows): array
    {
        if(count($rows)<1||count($rows)>100)throw new InvalidArgumentException('Provide between 1 and 100 accounts.');
        $seen=[];$out=[];
        foreach($rows as $index=>$row){
            $p=[];$errors=[];
            foreach(self::COLUMNS as $key){$v=$row[$key]??'';if(!is_string($v)||!mb_check_encoding($v,'UTF-8'))throw new InvalidArgumentException('Account fields must be valid UTF-8 text.');$p[$key]=trim($v);}
            $p['email']=strtolower($p['email']);
            if(!in_array($p['role'],['student','teacher','nurse'],true))$errors[]='Role must be Student, Teacher or Nurse. Use the privileged Doctor invitation process for Doctors.';
            if(!preg_match('/^[A-Za-z0-9_]{3,50}$/D',$p['username']))$errors[]='Username must contain 3–50 letters, numbers or underscores.';
            if(strlen($p['email'])>120||!filter_var($p['email'],FILTER_VALIDATE_EMAIL))$errors[]='Enter a valid official email address.';
            foreach(['first_name','last_name'] as $key)if($p[$key]===''||mb_strlen($p[$key])>80||preg_match('/[\x00-\x1F<>]/',$p[$key]))$errors[]='First and last names are required (maximum 80 characters).';
            if(!preg_match('/^[A-Za-z0-9][A-Za-z0-9._\/-]{0,29}$/D',$p['institutional_id']))$errors[]='Institutional ID must contain 1–30 letters, numbers or . _ / -.';
            foreach(['email'=>$p['email'],'username'=>strtolower($p['username']),'identifier'=>$p['role'].':'.strtolower($p['institutional_id'])] as $key=>$value){
                if(isset($seen[$key][$value]))$errors[]='Duplicate '.$key.' within this batch.';$seen[$key][$value]=true;
            }
            $q=$this->db->prepare('SELECT id FROM users WHERE email=? OR username=? LIMIT 1');$q->execute([$p['email'],$p['username']]);
            if($q->fetch())$errors[]='Email or username already exists.';
            if(in_array($p['role'],['student','teacher','nurse'],true)){
                $table=['student'=>'students','teacher'=>'teachers','nurse'=>'nurses'][$p['role']];$field=$p['role']==='student'?'student_number':'employee_id';
                $q=$this->db->prepare("SELECT id FROM $table WHERE $field=? LIMIT 1");$q->execute([$p['institutional_id']]);if($q->fetch())$errors[]='Institutional ID already exists for this role.';
            }
            $out[]=['row'=>$index+2,'account'=>$p,'errors'=>array_values(array_unique($errors))];
        }
        return $out;
    }

    private function challenge(int $id,int $actor): array
    {
        $token=bin2hex(random_bytes(32));
        $this->db->prepare('INSERT INTO account_activation_invitations(user_id,token_hash,created_by,expires_at) VALUES (?,?,?,DATE_ADD(NOW(),INTERVAL 24 HOUR))')->execute([$id,hash('sha256',$token),$actor]);
        return ['id'=>(int)$this->db->lastInsertId(),'token'=>$token];
    }

    private function deliver(array $challenge,array $person): string
    {
        try {$sent=(bool)($this->send)($person['email'],$person['first_name'].' '.$person['last_name'],app_url('auth/activate_account.php#token='.$challenge['token']));}
        catch(Throwable $e){$sent=false;error_log('Account activation transport failed; no recipient or token logged.');}
        $status=$sent?'sent':'failed';
        try{$this->db->prepare('UPDATE account_activation_invitations SET delivery_status=? WHERE id=?')->execute([$status,$challenge['id']]);}
        catch(PDOException $e){error_log('Account activation delivery status unavailable.');return 'pending';}
        return $status;
    }

    public function create(int $actor,array $rows): array
    {
        foreach(['users','students','teachers','nurses','account_activation_invitations','account_provisioning_lock','activity_logs'] as $table){
            $engine=$this->db->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND BINARY TABLE_NAME=BINARY ?');$engine->execute([$table]);
            if($engine->fetchColumn()!=='InnoDB')throw new RuntimeException('Provisioning requires its reviewed InnoDB schema.');
        }
        foreach(['username','email'] as $column){
            $unique=$this->db->prepare("SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND NON_UNIQUE=0 GROUP BY INDEX_NAME HAVING COUNT(*)=1 AND MAX(COLUMN_NAME)=? AND MAX(COALESCE(SUB_PART,0))=0");$unique->execute([$column]);
            if(!$unique->fetchColumn())throw new RuntimeException('Provisioning requires unique username and email constraints.');
        }
        $pending=[];$this->db->beginTransaction();
        try {
            $this->authorize($actor);
            $lock=$this->db->query('SELECT id FROM account_provisioning_lock WHERE id=1 FOR UPDATE')->fetchColumn();
            if(!$lock)throw new RuntimeException('Account provisioning migration is incomplete.');
            $preview=$this->preview($rows);
            foreach($preview as $row)if($row['errors'])throw new InvalidArgumentException('Row '.$row['row'].': '.implode(' ',$row['errors']));
            foreach($preview as $row){$p=$row['account'];
                $this->db->prepare("INSERT INTO users(role,username,email,password_hash,first_name,last_name,status,email_verified,self_service_only,registration_status) VALUES (?,?,?,?,?,?,'pending',0,1,'approved')")
                    ->execute([$p['role'],$p['username'],$p['email'],password_hash(bin2hex(random_bytes(32)),PASSWORD_DEFAULT),$p['first_name'],$p['last_name']]);$id=(int)$this->db->lastInsertId();
                if($p['role']==='student')$this->db->prepare('INSERT INTO students(user_id,student_number) VALUES (?,?)')->execute([$id,$p['institutional_id']]);
                elseif($p['role']==='teacher')$this->db->prepare('INSERT INTO teachers(user_id,employee_id,first_name,last_name) VALUES (?,?,?,?)')->execute([$id,$p['institutional_id'],$p['first_name'],$p['last_name']]);
                else $this->db->prepare("INSERT INTO nurses(user_id,employee_id,clinical_authorization_status) VALUES (?,?,'pending')")->execute([$id,$p['institutional_id']]);
                $pending[]=['user_id'=>$id,'person'=>$p,'challenge'=>$this->challenge($id,$actor)];
            }
            $this->db->prepare("INSERT INTO activity_logs(user_id,action,module,details) VALUES (?,'Provisioned account batch','users',?)")->execute([$actor,count($pending).' pending accounts; private setup required; no credentials logged.']);
            $this->db->commit();
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
        $result=[];foreach($pending as $row)$result[]=['user_id'=>$row['user_id'],'delivery_status'=>$this->deliver($row['challenge'],$row['person'])];return $result;
    }

    public function resend(int $actor,int $id): string
    {
        $this->db->beginTransaction();
        try{
            $this->authorize($actor);
            $q=$this->db->prepare("SELECT u.* FROM users u WHERE u.id=? AND u.role IN ('student','teacher','nurse') AND u.status='pending' AND u.registration_status='approved' FOR UPDATE");$q->execute([$id]);$person=$q->fetch();
            $q=$this->db->prepare('SELECT MAX(created_at) FROM account_activation_invitations WHERE user_id=? AND delivery_status<>\'failed\'');$q->execute([$id]);$last=$q->fetchColumn();
            if(!$person)throw new InvalidArgumentException('Select a pending provisioned account.');
            if($last&&strtotime($last)>time()-60)throw new InvalidArgumentException('Wait one minute before resending.');
            $this->db->prepare('UPDATE account_activation_invitations SET used_at=COALESCE(used_at,NOW()) WHERE user_id=?')->execute([$id]);
            $challenge=$this->challenge($id,$actor);
            $this->db->prepare("INSERT INTO activity_logs(user_id,action,module,details) VALUES (?,'Resent account activation','users',?)")->execute([$actor,'Account #'.$id.'; previous links invalidated.']);$this->db->commit();
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
        return $this->deliver($challenge,$person);
    }

    public function inspect(string $token): ?array
    {
        if(!preg_match('/^[a-f0-9]{64}$/D',$token))return null;
        $q=$this->db->prepare("SELECT i.id,i.user_id,i.expires_at,i.used_at,u.role,u.first_name FROM account_activation_invitations i JOIN users u ON u.id=i.user_id WHERE i.token_hash=? AND u.status='pending' AND u.registration_status='approved' AND u.role IN ('student','teacher','nurse') AND i.used_at IS NULL AND i.expires_at>NOW()");$q->execute([hash('sha256',$token)]);return $q->fetch()?:null;
    }

    public function activate(string $token,string $password,string $confirmation): void
    {
        if(strlen($password)>72)throw new InvalidArgumentException('Password must be at most 72 bytes.');
        $errors=validate_password_strength($password);if($password!==$confirmation)$errors[]='Passwords do not match.';
        if($errors)throw new InvalidArgumentException(implode(' ',$errors));
        $invite=$this->inspect($token);if(!$invite)throw new InvalidArgumentException('The activation link is invalid, expired or already used. Ask MIS for a new link.');
        $this->db->beginTransaction();
        try{
            $q=$this->db->prepare('SELECT id FROM users WHERE id=? FOR UPDATE');$q->execute([$invite['user_id']]);
            $q=$this->db->prepare('SELECT id FROM account_activation_invitations WHERE id=? FOR UPDATE');$q->execute([$invite['id']]);
            if(!$this->inspect($token))throw new InvalidArgumentException('The activation link is invalid, expired or already used.');
            $this->db->prepare('UPDATE account_activation_invitations SET used_at=NOW() WHERE user_id=? AND used_at IS NULL')->execute([$invite['user_id']]);
            $this->db->prepare("UPDATE users SET password_hash=?,status='active',email_verified=1,email_verified_at=NOW(),credential_version=COALESCE(credential_version,0)+1,remember_token=NULL WHERE id=?")->execute([password_hash($password,PASSWORD_DEFAULT),$invite['user_id']]);
            $this->db->prepare("INSERT INTO activity_logs(user_id,action,module,details) VALUES (?,'Provisioned account activated','auth','Recipient verified email ownership and privately chose an initial password.')")->execute([$invite['user_id']]);
            $this->db->commit();
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
}
