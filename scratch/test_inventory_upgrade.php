<?php
if(PHP_SAPI!=='cli')exit;ob_start();ini_set('session.save_path',__DIR__);require __DIR__.'/../config/config.php';require __DIR__.'/../includes/medicine_inventory_service.php';
set_exception_handler(function(Throwable $e){fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);});
$db=getDB();foreach(['medicines','medicine_logs','inventory_logs','activity_logs','system_settings','notifications','users','admin_permissions','medicine_reorders','ai_actions'] as $table){$db->exec("CREATE TEMPORARY TABLE inv_test_$table LIKE $table");$db->exec("ALTER TABLE inv_test_$table RENAME TO $table");}
$db->exec("INSERT INTO users (id,username,email,password_hash,first_name,last_name,role,admin_type,status) VALUES (901,'inv-nurse','inv-nurse@example.invalid','x','Nurse','QA','nurse',NULL,'active'),(902,'inv-co','inv-co@example.invalid','x','Co','QA','admin','co_admin','active'),(903,'inv-primary','inv-primary@example.invalid','x','Primary','QA','admin','primary_admin','active'),(904,'inv-denied','inv-denied@example.invalid','x','Denied','QA','admin','co_admin','active')");
$db->exec("INSERT INTO admin_permissions (user_id,permission_key,granted_by) VALUES (902,'inventory_oversight',903)");
$q=$db->prepare('INSERT INTO medicines (id,medicine_name,generic_name,brand,supplier,category,quantity,unit,reorder_level,expiration_date,batch_number,status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
$today=$db->query('SELECT CURDATE()')->fetchColumn();
$fixtures=[[1,'Low Medicine','Generic A','Brand A','Verified Supplier','Tablet',5,'pcs',10,null,'BATCH-A','Low Stock'],[2,'Healthy','Generic B','Brand B',null,'Tablet',50,'pcs',10,null,'BATCH-B','Available'],[3,'Expired','Generic C',null,null,'Tablet',50,'pcs',10,date('Y-m-d',strtotime($today.' -1 day')),'BATCH-C','Expired'],[4,'Soon Expiring',null,null,null,'Tablet',30,'pcs',5,date('Y-m-d',strtotime($today.' +5 days')),'BATCH-D','Near Expiration'],[5,'Empty',null,null,null,'Liquid',0,'bottle',10,null,'BATCH-E','Out of Stock'],[6,'Expires Today',null,null,null,'Tablet',10,'pcs',10,$today,'BATCH-F','Low Stock']];
foreach($fixtures as $row)$q->execute($row);
$db->exec("INSERT INTO medicine_logs (medicine_id,action,quantity,reason,performed_by) VALUES (1,'stock_out',30,'Usage fixture',901)");
$service=new MedicineInventoryService($db);$items=$service->snapshot();
function invCheck($ok,$label){if(!$ok)throw new RuntimeException($label);echo 'PASS '.$label.PHP_EOL;}
function invReject(callable $fn,$label){try{$fn();throw new LogicException('Accepted unsafe operation');}catch(InvalidArgumentException $e){invCheck(true,$label);}}
$u=fn($id)=>$db->query('SELECT * FROM users WHERE id='.$id)->fetch();
invCheck(can_view_inventory($u(901),$db)&&can_view_inventory($u(902),$db)&&!can_view_inventory($u(903),$db)&&!can_view_inventory($u(904),$db),'Nurse/delegated co-admin access; Super Admin and ungranted co-admin data denied');
invCheck($items[1]['suggested_quantity']===32,'Usage-based target: ceil(30/30 x 37) less 5 stock = 32');
invCheck($items[5]['suggested_quantity']===20,'Minimum multiplier fallback without invented usage');
invCheck(count(MedicineInventoryService::filter($items,['status'=>'low_stock'],$today))===2,'Low-stock membership includes expiry today, excludes expired batches');
invCheck(count(MedicineInventoryService::filter($items,['status'=>'expired'],$today))===1,'Expired filter');
invCheck(count(MedicineInventoryService::filter($items,['status'=>'near_expiration'],$today))===2,'Near-expiry filter uses positive batch stock');
invCheck(count(MedicineInventoryService::filter($items,['search'=>'Verified Supplier'],$today))===1,'Supplier search');
invCheck(count(MedicineInventoryService::filter($items,['search'=>'batch-f'],$today))===1,'Case-insensitive batch search');
invCheck(count(MedicineInventoryService::filter($items,['category'=>'Liquid','status'=>'out_of_stock'],$today))===1,'Combined category/status filter');
$service->monitor();$before=$db->query('SELECT COUNT(*) FROM notifications')->fetchColumn();$service->monitor();invCheck($before==$db->query('SELECT COUNT(*) FROM notifications')->fetchColumn(),'Repeated low/expiry monitor does not duplicate alerts');
invCheck($db->query('SELECT COUNT(*) FROM notifications WHERE user_id IN (903,904)')->fetchColumn()==0,'No medicine alerts to technical Super Admin or unauthorized co-admin');
invCheck($db->query('SELECT COUNT(DISTINCT user_id) FROM notifications')->fetchColumn()==2,'Nurse and delegated co-admin receive low/expiry notifications');
$after=$service->adjust(1,'stock_out',2,'Test adjustment',901);invCheck($after===3,'Locked stock deduction and ledger');
invReject(fn()=>$service->adjust(1,'stock_out',99,'Insufficient',901),'Insufficient stock rejected without negative quantity');
invCheck($db->query('SELECT quantity FROM medicines WHERE id=1')->fetchColumn()==3,'Failed adjustment preserves prior quantity');
$db->beginTransaction();try{$service->lockForDispensing(3,1);throw new LogicException('Expired accepted');}catch(InvalidArgumentException $e){$db->rollBack();invCheck(true,'Expired batch cannot dispense');}
$db->beginTransaction();$medicine=$service->lockForDispensing(6,1);$db->rollBack();invCheck($medicine['id']==6,'Medicine expiring today remains usable under existing convention');
$db->beginTransaction();$m=$service->lockForDispensing(1,3);$db->prepare('UPDATE medicines SET quantity=quantity-? WHERE id=?')->execute([3,1]);$db->prepare("INSERT INTO medicine_logs (medicine_id,action,quantity,reason,performed_by) VALUES (?,'stock_out',?,'Dispense fixture',?)")->execute([1,3,901]);$db->commit();
invCheck($db->query('SELECT quantity FROM medicines WHERE id=1')->fetchColumn()==0&&$db->query("SELECT SUM(quantity) FROM medicine_logs WHERE medicine_id=1 AND action='stock_out'")->fetchColumn()==33,'Stock deduction and dispensing history persist in one transaction');
$db->exec('ALTER TABLE inventory_logs CHANGE remarks missing_remarks TEXT');invReject(fn()=>$service->adjust(2,'stock_out',51,'Excess',901),'Failed stock operation releases transaction');
try{$service->adjust(2,'stock_out',1,'Ledger failure',901);throw new LogicException('Ledger failure ignored');}catch(PDOException $e){}invCheck($db->query('SELECT quantity FROM medicines WHERE id=2')->fetchColumn()==50&&!$db->inTransaction(),'Ledger SQL failure rolls back stock');
// The legacy MICA request path must use calculated suggestions and remain unapproved.
require __DIR__.'/../includes/ai/MICAActionService.php';
$db->exec("UPDATE medicines SET quantity=5 WHERE id=1");
$mica=new MICAActionService($db,$u(902));
$result=$mica->createReorderRequest(1,999999,'Human-reviewed draft fixture');
invCheck($result['success'],'Delegated MICA draft request is authorized');
$reorder=$db->query('SELECT * FROM medicine_reorders LIMIT 1')->fetch();
invCheck((int)$reorder['suggested_quantity']!==(int)999999&&$reorder['status']==='for_approval'&&$reorder['approved_quantity']===null,'MICA draft ignores invented quantity and requires human review');
invCheck(!$mica->approveReorderRequest((int)$reorder['id'],20)['success'],'Co-admin cannot approve a draft');
$primaryMica=new MICAActionService($db,$u(903));invCheck(!$primaryMica->createReorderRequest(1,1,'Not authorized')['success'],'Super Admin cannot bypass technical-only inventory scope');
require __DIR__.'/../includes/ai/MICAAutomationEngine.php';
$engine=new MICAAutomationEngine($db);
$restricted=$engine->generateDailyClinicSummary(false);
invCheck($restricted['low_stock_medicines']===null&&$restricted['expiring_medicines']===null,'Technical daily summary excludes inventory facts');
$authorized=$engine->generateDailyClinicSummary(true);
invCheck($authorized['low_stock_medicines']===count(MedicineInventoryService::filter($service->snapshot(),['status'=>'low_stock'],$today)),'MICA daily summary matches shared low-stock filter');
session_destroy();echo "PASS isolated MySQL inventory tests; production records unchanged.\n";
