<?php
require __DIR__.'/../includes/announcement_notifications.php';
function check($ok,$m){if(!$ok)throw new RuntimeException($m);}
class AnnouncementTestPDO extends PDO {
 public array $rows=[];public int $last=0;
 public function __construct(){}
 public function prepare(string $query,array $options=[]):PDOStatement|false{return new AnnouncementTestStmt($this,$query);}
 public function lastInsertId(?string $name=null):string|false{return (string)$this->last;}
}
class AnnouncementTestStmt extends PDOStatement {
 private array $params=[];
 public function __construct(private AnnouncementTestPDO $db,private string $sql){}
 public function execute(?array $params=null):bool{$this->params=$params??[];if(str_starts_with($this->sql,'INSERT INTO notifications')){$this->db->last++;$this->db->rows[]=['id'=>$this->db->last,'user_id'=>$params[0],'related_id'=>$params[4],'link'=>$params[3],'is_read'=>0];}return true;}
 public function fetchColumn(int $column=0):mixed{if(str_contains($this->sql,'GET_LOCK'))return 1;foreach($this->db->rows as $r)if($r['user_id']===$this->params[0]&&($r['related_id']===$this->params[1]||$r['link']===$this->params[2]))return $r['id'];return false;}
}
$db=new AnnouncementTestPDO();
$send=fn($u,$a)=>push_announcement_notification($db,$u,$a,'New Clinic Announcement','Announcement title','/pages/student/announcements.php');
check($send(7,10)===1,'First delivery');check($send(7,10)===1&&count($db->rows)===1,'No duplicate delivery');
$db->rows[0]['is_read']=1;check($send(7,10)===1&&count($db->rows)===1,'Read flag must not reset announcement identity');
check($send(7,11)===2,'Different announcements with same title must deliver');check($send(8,10)===3,'Per-recipient delivery');
echo "PASS: announcement recipient/item deduplication, read-state independence, distinct same-title announcements and per-recipient delivery (PDO fixtures).\n";
