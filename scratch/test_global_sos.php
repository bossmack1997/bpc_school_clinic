<?php
require __DIR__.'/../includes/global_sos_service.php';
function check($ok,$message){if(!$ok)throw new RuntimeException($message);}
$audit=[];function log_activity(...$args){$GLOBALS['audit'][]=$args;}
foreach(['admin','nurse'] as $r)check(GlobalSosService::authorized($r),'Authorized responder');
foreach(['student','teacher','adviser','','doctor'] as $r)check(!GlobalSosService::authorized($r),'Unauthorized role');
check(GlobalSosService::identifiers(['1',1,2])===[1,2],'Deduplicate IDs');
foreach([null,[],['1 OR 1=1'],[-1],[0],[[]],['2147483648']] as $bad){try{GlobalSosService::identifiers($bad);throw new RuntimeException('Invalid ID accepted');}catch(InvalidArgumentException $e){}}
foreach(['resolved','cancelled','false_alarm'] as $status)foreach(['acknowledge','respond','resolve','cancel'] as $op)check(!GlobalSosService::operationAllowed($status,$op),'Closed emergency transition');
check(GlobalSosService::operationAllowed('new','acknowledge'),'Acknowledge new');
check(GlobalSosService::operationAllowed('acknowledged','respond'),'Respond acknowledged');
check(GlobalSosService::operationAllowed('responding','resolve'),'Resolve responding');
check(!GlobalSosService::operationAllowed('responding','acknowledge'),'No backwards acknowledgment');
class SosTestPDO extends PDO {
 public array $rows=[],$receipts=[];private bool $tx=false;
 public function __construct(){}
 public function prepare(string $query,array $options=[]):PDOStatement|false{return new SosTestStatement($this,$query);}
 public function beginTransaction():bool{$this->tx=true;return true;}
 public function commit():bool{$this->tx=false;return true;}
 public function rollBack():bool{$this->tx=false;return true;}
 public function inTransaction():bool{return $this->tx;}
}
class SosTestStatement extends PDOStatement {
 private array $params=[];private int $count=0;
 public function __construct(private SosTestPDO $db,private string $sql){}
 public function execute(?array $params=null):bool {
  $this->params=$params??[];
  if(str_starts_with($this->sql,'INSERT IGNORE')){
   $uid=array_shift($params);
   foreach($params as $id)if(isset($this->db->rows[$id])&&!isset($this->db->receipts[$uid][$id])){$this->db->receipts[$uid][$id]=true;$this->count++;}
  }
  check(!preg_match('/UPDATE\s+sos_emergencies/i',$this->sql),'Screen acknowledgment must not mutate emergency status');return true;
 }
 public function rowCount():int{return $this->count;}
 public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args):array {
  $result=[];
  foreach($this->db->rows as $id=>$r)if(in_array($r['status'],GlobalSosService::ACTIVE,true)&&empty($r['resolved_at'])){$r['notification_acknowledged']=isset($this->db->receipts[$this->params[0]][$id])?1:0;$result[]=$r;}
  return $result;
 }
}
$db=new SosTestPDO();$base=['id'=>1,'status'=>'active','resolved_at'=>null,'first_name'=>'Fixture','last_name'=>'Reporter','emergency_type'=>'Test','description'=>null,'location'=>null,'latitude'=>null,'longitude'=>null,'created_at'=>'2026-10-17 12:00:00','source'=>'sos','reporter_role'=>'student','phone'=>null];
$db->rows=[1=>$base,2=>array_replace($base,['id'=>2])];$svc=new GlobalSosService($db);
check($svc->snapshot(7)['pending_ids']===[1,2],'Simultaneous reports');
$svc->acknowledgeNotifications(7,[1]);check($svc->snapshot(7)['pending_ids']===[2],'Acknowledge only selected notice');
check($db->rows[1]['status']==='active','No automatic resolution');
check((new GlobalSosService($db))->snapshot(7)['pending_ids']===[2],'Receipts survive page/service reload');
$svc->acknowledgeNotifications(7,[1]);check(count($audit)===1,'No duplicate receipts or audit entries');
check($svc->snapshot(8)['pending_ids']===[1,2],'Per-user isolation');
$svc->acknowledgeNotifications(7,[2]);check($svc->snapshot(7)['pending_ids']===[],'Dismissed notices suppressed');
$db->rows[3]=array_replace($base,['id'=>3]);check($svc->snapshot(7)['pending_ids']===[3],'New report interrupts again');
$db->rows[3]['status']='resolved';$db->rows[3]['resolved_at']='2026-10-17 12:01:00';check($svc->snapshot(7)['pending_ids']===[],'Resolved reports removed');
check($svc->snapshot(7)['active_count']===2,'Correct active count');
echo "PASS: responder roles, valid/deduplicated IDs, status transitions, simultaneous/new/resolved reports, per-user receipts, refresh persistence, duplicate suppression, and no emergency mutations (PDO fixtures).\n";
