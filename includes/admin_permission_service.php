<?php
/** Existing admin_permissions schema; only managed key deltas are written. */
final class AdminPermissionService
{
    public const KEYS=['manage_users','manage_sections','view_reports','manage_security','manage_settings','view_audit_logs','view_emergencies','approve_users','inventory_oversight'];
    public function __construct(private PDO $db) {}
    public static function revision(array $keys): string {sort($keys,SORT_STRING);return hash('sha256',json_encode(array_values(array_unique($keys))));}
    private function primary(int $actor,bool $lock=false): array {
        $q=$this->db->prepare('SELECT id,role,admin_type,status FROM users WHERE id=?'.($lock?' FOR UPDATE':''));$q->execute([$actor]);$user=$q->fetch();
        if(!$user||$user['role']!=='admin'||$user['admin_type']!=='primary_admin'||$user['status']!=='active')throw new RuntimeException('Only the Primary Administrator can manage co-admin permissions.',403);
        return $user;
    }
    public function read(int $actor,int $target): array {
        $this->primary($actor);
        $q=$this->db->prepare("SELECT id,first_name,last_name,admin_type FROM users WHERE id=? AND role='admin'");$q->execute([$target]);$row=$q->fetch();
        if(!$row)throw new RuntimeException('Administrator not found.',404);
        if($row['admin_type']==='primary_admin'||$actor===$target)throw new RuntimeException('Only another co-admin account can be configured.',403);
        $q=$this->db->prepare('SELECT permission_key FROM admin_permissions WHERE user_id=? ORDER BY permission_key');$q->execute([$target]);$keys=$q->fetchAll(PDO::FETCH_COLUMN);
        return ['target_user_id'=>$target,'target_name'=>trim($row['first_name'].' '.$row['last_name']),'permissions'=>$keys,'revision'=>self::revision($keys)];
    }
    public function save(int $actor,int $target,mixed $selected,mixed $revision): array {
        if(!is_array($selected)||count($selected)>count(self::KEYS)||!is_string($revision)||!preg_match('/^[a-f0-9]{64}$/D',$revision))throw new RuntimeException('Reload the permission form and select valid permissions.',422);
        foreach($selected as $key)if(!is_string($key)||!in_array($key,self::KEYS,true))throw new RuntimeException('An unsupported permission was submitted.',422);
        $selected=array_values(array_unique($selected));
        $this->db->beginTransaction();
        try {
            // Recheck authority against the DB while holding the actor lock.
            $this->primary($actor,true);
            $q=$this->db->prepare('SELECT id,role,admin_type FROM users WHERE id=? FOR UPDATE');$q->execute([$target]);$user=$q->fetch();
            if(!$user||$user['role']!=='admin')throw new RuntimeException('Administrator not found.',404);
            if($target===$actor||$user['admin_type']==='primary_admin')throw new RuntimeException('The Primary Administrator and your own permissions cannot be changed here.',403);
            $q=$this->db->prepare('SELECT permission_key FROM admin_permissions WHERE user_id=? ORDER BY permission_key FOR UPDATE');$q->execute([$target]);$before=$q->fetchAll(PDO::FETCH_COLUMN);
            $managed=array_values(array_intersect($before,self::KEYS));sort($managed);$desired=$selected;sort($desired);
            // An exact retry is a no-op even if its previous revision is now old.
            if($managed!==$desired&&!hash_equals(self::revision($before),$revision))throw new RuntimeException('Permissions changed in another session. Reopen the form before saving.',409);
            $grant=array_values(array_diff($selected,$before));$revoke=array_values(array_diff($managed,$selected));
            $insert=$this->db->prepare('INSERT INTO admin_permissions (user_id,permission_key,granted_by,granted_at) VALUES (?,?,?,NOW())');
            foreach($grant as $key)$insert->execute([$target,$key,$actor]);
            $remove=$this->db->prepare('DELETE FROM admin_permissions WHERE user_id=? AND permission_key=?');
            foreach($revoke as $key)$remove->execute([$target,$key]);
            if($grant||$revoke){
                $metadata=json_encode(['target'=>$target,'granted'=>$grant,'revoked'=>$revoke]);
                $this->db->prepare('INSERT INTO activity_logs (user_id,action,module,details,ip_address) VALUES (?,?,?,?,?)')->execute([$actor,'Updated Co-Admin permissions','admin',mb_strcut($metadata,0,255,'UTF-8'),$_SERVER['REMOTE_ADDR']??null]);
            }
            if($grant||$revoke)$this->db->prepare('UPDATE users SET credential_version=COALESCE(credential_version,0)+1 WHERE id=?')->execute([$target]);
            $result=$this->read($actor,$target);
            $this->db->commit();
            return $result;
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
}
