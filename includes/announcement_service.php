<?php
/** Existing announcement records, with scheduled publication and Nurse governance. */
final class AnnouncementService {
    public function __construct(private PDO $db,private array $user) {}
    public function ready(): bool {
        $columns=$this->db->query('SHOW COLUMNS FROM announcements')->fetchAll(PDO::FETCH_COLUMN);
        return count(array_intersect(['category','publication_status','starts_at','expires_at','image_path'],$columns))===5;
    }
    public function canManage(): bool {return ($this->user['role']??'')==='nurse' && has_permission('clinical_messages',$this->user,$this->db);}
    public function listing(bool $manage=false): array {
        if($manage && !$this->canManage())throw new RuntimeException('Only authorized Nurses manage routine clinic announcements.');
        $audience=['student'=>'students','teacher'=>'teachers','nurse'=>'nurses','admin'=>'admin'][$this->user['role']]??'';
        $where=$manage?'1=1':"a.audience IN ('all',?)";$params=$manage?[]:[$audience];
        if(!$manage && $this->ready())$where.=" AND a.publication_status='published' AND (a.starts_at IS NULL OR a.starts_at<=NOW()) AND (a.expires_at IS NULL OR a.expires_at>NOW())";
        $q=$this->db->prepare("SELECT a.*,u.first_name,u.last_name FROM announcements a LEFT JOIN users u ON u.id=a.posted_by WHERE $where ORDER BY a.is_pinned DESC,a.created_at DESC,a.id DESC LIMIT 100");$q->execute($params);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
        foreach($rows as &$row) {
            $row+=['category'=>'general','publication_status'=>'published','starts_at'=>null,'expires_at'=>null,'image_path'=>null];
            $row['image_url']=$this->imageUrl($row['image_path']);unset($row['image_path']);
        }return $rows;
    }
    private function imageUrl(?string $path): ?string {
        if(!$path || !preg_match('#^assets/images/announcements/[a-f0-9]{32}\.(?:jpg|png|webp)$#D',$path))return null;
        $root=realpath(BASE_PATH.'/assets/images/announcements');$full=realpath(BASE_PATH.'/'.$path);
        return $root&&$full&&str_starts_with($full,$root.DIRECTORY_SEPARATOR)&&is_file($full)?app_url($path).'?v='.substr(hash_file('sha256',$full),0,12):null;
    }
    private function text(array $input,string $key,int $limit): string {
        if(!is_string($input[$key]??''))throw new InvalidArgumentException('Invalid announcement field.');
        $value=trim($input[$key]??'');if(mb_strlen($value)>$limit)throw new InvalidArgumentException('Announcement field exceeds its limit.');return $value;
    }
    private function date(array $input,string $key): ?string {
        $value=$this->text($input,$key,19);if($value==='')return null;
        $value=str_replace('T',' ',$value);if(strlen($value)===16)$value.=':00';
        $date=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$value,new DateTimeZone('Asia/Manila'));
        if(!$date||$date->format('Y-m-d H:i:s')!==$value)throw new InvalidArgumentException('Use a valid publication date and time (Asia/Manila).');return $value;
    }
    public function mutate(string $action,array $input,?array $file=null): int {
        if(!$this->canManage())throw new RuntimeException('Only authorized Nurses manage routine clinic announcements.');
        if(!$this->ready())throw new RuntimeException('Announcement publication controls require the reviewed database migration.');
        $id=$action==='add'?0:filter_var($input['id']??0,FILTER_VALIDATE_INT);if($id===false||$id<0)throw new InvalidArgumentException('Invalid announcement.');
        $image=null;
        if(in_array($action,['add','edit'],true)) {
            $title=$this->text($input,'title',200);$content=$this->text($input,'content',10000);$category=$this->text($input,'category',50)?:'general';
            $status=$this->text($input,'publication_status',20)?:'draft';$audience=$this->text($input,'audience',20)?:'all';
            if(!$title||!$content)throw new InvalidArgumentException('Title and description are required.');
            if(!in_array($status,['draft','published','archived'],true)||!in_array($audience,['all','students','teachers','nurses','admin'],true))throw new InvalidArgumentException('Choose a valid status and audience.');
            $start=$this->date($input,'starts_at');$end=$this->date($input,'expires_at');if($start&&$end&&$end<=$start)throw new InvalidArgumentException('Expiry must be later than publication start.');
            if($file&&($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE) {
                if($file['error']!==UPLOAD_ERR_OK || $file['size']>5*1024*1024 || !is_uploaded_file($file['tmp_name']))throw new InvalidArgumentException('Upload an image up to 5 MB.');
                $mime=(new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);$types=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
                if(!isset($types[$mime])||!@getimagesize($file['tmp_name']))throw new InvalidArgumentException('Use a valid JPEG, PNG or WebP image.');
                $directory=BASE_PATH.'/assets/images/announcements';if(!is_dir($directory)&&!mkdir($directory,0755,true)&&!is_dir($directory))throw new RuntimeException('Image storage unavailable.');
                $image='assets/images/announcements/'.bin2hex(random_bytes(16)).'.'.$types[$mime];
                if(!move_uploaded_file($file['tmp_name'],BASE_PATH.'/'.$image))throw new RuntimeException('Image could not be saved.');
            }
        }
        $this->db->beginTransaction();try {
            $q=$this->db->prepare("SELECT * FROM users WHERE id=? AND status='active' FOR UPDATE");$q->execute([$this->user['id']]);$actor=$q->fetch();
            if(!$actor||$actor['role']!=='nurse'||!has_permission('clinical_messages',$actor,$this->db))throw new RuntimeException('Nurse authority has changed. Sign in again.');
            $previous=null;
            if($action!=='add') {$q=$this->db->prepare('SELECT * FROM announcements WHERE id=? FOR UPDATE');$q->execute([$id]);$previous=$q->fetch();if(!$previous)throw new InvalidArgumentException('Announcement not found.');}
            if($action==='add') {$this->db->prepare('INSERT INTO announcements(title,content,category,publication_status,starts_at,expires_at,image_path,audience,is_pinned,posted_by) VALUES (?,?,?,?,?,?,?,?,?,?)')->execute([$title,$content,$category,$status,$start,$end,$image,$audience,!empty($input['is_pinned'])?1:0,$actor['id']]);$id=(int)$this->db->lastInsertId();}
            elseif($action==='edit')$this->db->prepare('UPDATE announcements SET title=?,content=?,category=?,publication_status=?,starts_at=?,expires_at=?,image_path=COALESCE(?,image_path),audience=?,is_pinned=? WHERE id=?')->execute([$title,$content,$category,$status,$start,$end,$image,$audience,!empty($input['is_pinned'])?1:0,$id]);
            elseif($action==='delete')$this->db->prepare("UPDATE announcements SET publication_status='archived' WHERE id=?")->execute([$id]);
            elseif($action==='toggle_pin')$this->db->prepare('UPDATE announcements SET is_pinned=1-is_pinned WHERE id=?')->execute([$id]);
            else throw new InvalidArgumentException('Unknown announcement action.');
            $this->db->prepare("INSERT INTO activity_logs(user_id,action,module,details) VALUES (?,'Announcement updated','announcements',?)")->execute([$actor['id'],'Announcement #'.$id.'; action '.$action]);
            $nowManila=(new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format('Y-m-d H:i:s');
            if(in_array($action,['add','edit'],true) && $status==='published' && (!$previous||$previous['publication_status']!=='published') && (!$start||$start<=$nowManila) && (!$end||$end>$nowManila)) {
                $target=['students'=>'student','teachers'=>'teacher','nurses'=>'nurse','admin'=>'admin'][$audience]??'';
                $q=$this->db->prepare("SELECT id,role FROM users WHERE status='active' AND id<>? AND (?='' OR role=?)");$q->execute([$actor['id'],$target,$target]);
                foreach($q->fetchAll() as $recipient){$role=canonical_role($recipient['role']);$directory=$role==='teacher'?'adviser':$role;if($role==='admin')$directory=permission_profile($recipient,$this->db)==='doctor'?'doctor':'admin';$link=$directory==='doctor'?app_url('pages/doctor/dashboard.php'):app_url('pages/'.$directory.'/announcements.php');$this->db->prepare('INSERT INTO notifications(user_id,type,title,message,link) VALUES (?,?,?,?,?)')->execute([$recipient['id'],'announcement','New Clinic Announcement',mb_substr($title,0,255),$link]);}
            }
            $this->db->commit();return $id;
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();if($image&&is_file(BASE_PATH.'/'.$image))unlink(BASE_PATH.'/'.$image);throw $e;}
    }
}
