<?php
require __DIR__ . '/../includes/medicine_inventory_service.php';
function verify($condition, $message) { if (!$condition) throw new RuntimeException($message); }
$base=['id'=>1,'medicine_name'=>'Fixture','quantity'=>20,'unit'=>'pcs','reorder_level'=>10,'expiration_date'=>null];
foreach ([[11,'AVAILABLE'],[10,'LOW STOCK'],[9,'LOW STOCK'],[0,'OUT OF STOCK']] as [$q,$status]) {
 $m=MedicineInventoryService::classify(array_replace($base,['quantity'=>$q]),'2026-10-17');
 verify($m['stock_status']===$status,'Threshold boundary');
 verify($m['suggested_quantity']===null,'No invented requested quantity');
}
$expired=MedicineInventoryService::classify(array_replace($base,['expiration_date'=>'2026-10-16']),'2026-10-17');
verify($expired['usable_stock']===0 && $expired['stock_status']==='OUT OF STOCK','Expired stock excluded');
$today=MedicineInventoryService::classify(array_replace($base,['expiration_date'=>'2026-10-17']),'2026-10-17');
verify($today['usable_stock']===20,'Same-day expiration');
foreach (['is_quarantined'=>1,'is_available'=>0,'status'=>'recalled'] as $key=>$v) {
 $m=MedicineInventoryService::classify(array_replace($base,[$key=>$v]),'2026-10-17'); verify($m['usable_stock']===0,'Unusable stock excluded');
}
$missing=MedicineInventoryService::classify(array_replace($base,['reorder_level'=>null]),'2026-10-17');
verify($missing['stock_status']==='THRESHOLD REQUIRED','Missing threshold must be explicit');
$target=MedicineInventoryService::classify(array_replace($base,['quantity'=>4,'target_stock_level'=>30]),'2026-10-17');
verify($target['suggested_quantity']===26,'Configured target only');
$low=[1=>$target,2=>array_replace($target,['id'=>2])];
verify(count(MedicineInventoryService::low([]))===0,'No low-stock items');
verify(count(MedicineInventoryService::low($low))===2,'Distinct batch IDs preserved');
$hash=MedicineInventoryService::fingerprint($low); $changed=$low; $changed[1]['quantity']++;
verify($hash!==MedicineInventoryService::fingerprint($changed),'Changed stock must invalidate draft');
verify(MedicineInventoryService::quantities([1=>'10',2=>'3'],$low)===[1=>10,2=>3],'Valid quantities');
foreach ([[1=>'0'],[1=>'-1'],[1=>'2.5'],[1=>'10000000'],[1=>[]],[999=>'2'],[]] as $bad) {
 try { MedicineInventoryService::quantities($bad,$low); throw new RuntimeException('Invalid quantity accepted'); }
 catch (InvalidArgumentException $e) {}
}
echo "PASS: thresholds, zero, expiration, unusable stock, missing thresholds, targets, no/multiple items, batches, stale fingerprints, quantity validation.\n";

class InventoryTestPDO extends PDO {
 public array $medicines=[]; public array $states=[]; public int $notices=0; private bool $transaction=false;
 public function __construct() {}
 public function query(string $query, ?int $fetchMode=null, mixed ...$fetchModeArgs): PDOStatement|false { return new InventoryTestStatement($this,$query); }
 public function prepare(string $query, array $options=[]): PDOStatement|false { return new InventoryTestStatement($this,$query); }
 public function beginTransaction():bool { $this->transaction=true; return true; }
 public function commit():bool { $this->transaction=false; return true; }
 public function rollBack():bool { $this->transaction=false; return true; }
 public function inTransaction():bool { return $this->transaction; }
}
class InventoryTestStatement extends PDOStatement {
 private array $params=[];
 public function __construct(private InventoryTestPDO $db, private string $sql) {}
 public function execute(?array $params=null):bool {
  $this->params=$params??[];
  if(str_starts_with($this->sql,'INSERT INTO system_settings')) $this->db->states[$params[0]]=$params[1];
  if(str_starts_with($this->sql,'INSERT INTO notifications')) $this->db->notices++;
  return true;
 }
 public function fetchColumn(int $column=0):mixed {
  if(str_contains($this->sql,'GET_LOCK') || str_contains($this->sql,'RELEASE_LOCK')) return 1;
  if(str_contains($this->sql,'CURDATE')) return '2026-10-17';
  return $this->db->states[$this->params[0]]??false;
 }
 public function fetchAll(int $mode=PDO::FETCH_DEFAULT, mixed ...$args):array { if(str_contains($this->sql,'system_settings')||str_contains($this->sql,'GROUP BY medicine_id'))return [];return $this->db->medicines; }
}
if(!defined('APP_URL')) define('APP_URL','/clinic');
$fake=new InventoryTestPDO(); $fake->medicines=[$base]; $monitor=new MedicineInventoryService($fake);
$monitor->monitor(); verify($fake->notices===0,'Healthy medicine notification');
$fake->medicines[0]['quantity']=10; $monitor->monitor(); verify($fake->notices===1,'New low notification');
$monitor->monitor(); verify($fake->notices===1,'Repeated notification');
$fake->medicines[0]['quantity']=8; $monitor->monitor(); verify($fake->notices===2,'Worsening stock notification');
$fake->medicines[0]['quantity']=0; $monitor->monitor(); verify($fake->notices===3,'Out of stock notification');
$monitor->monitor(); verify($fake->notices===3,'Repeated out of stock notification');
$fake->medicines[0]['quantity']=30; $monitor->monitor(); verify($fake->notices===3,'Recovery notification');
$fake->medicines[0]['quantity']=7; $monitor->monitor(); verify($fake->notices===4,'New low after recovery');
require __DIR__.'/../includes/ai/MICAWorkflowService.php';
foreach(['student','teacher','adviser'] as $role) {
 $workflow=new MICAWorkflowService($fake,['role'=>$role]);
 $reply=$workflow->handleNaturalLanguageQuery('Show low stock medicines');
 verify($reply['type']==='access_denied','Unauthorized workflow inventory access');
}
echo "PASS: notification deduplication, worsening/zero/recovery transitions and unauthorized role denials (PDO fixtures).\n";
