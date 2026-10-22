<?php
if(PHP_SAPI!=='cli')exit;
ini_set('session.save_path',__DIR__);require __DIR__.'/../config/config.php';require_once __DIR__.'/../includes/medicine_inventory_service.php';
$db=getDB();foreach(['users','nurses','students','medicines','medicine_logs','inventory_logs','activity_logs','notifications','admin_permissions','system_settings','consultations'] as $table){$db->exec("CREATE TEMPORARY TABLE inv_api_$table LIKE $table");$db->exec("ALTER TABLE inv_api_$table RENAME TO $table");}
$db->exec("INSERT INTO users (id,username,email,password_hash,first_name,last_name,role,admin_type,status) VALUES (2000000001,'inv-api-nurse','inv-api-nurse@example.invalid','x','Nurse','Fixture','nurse',NULL,'active'),(2000000002,'inv-api-co','inv-api-co@example.invalid','x','Co','Fixture','admin','co_admin','active'),(2000000003,'inv-api-primary','inv-api-primary@example.invalid','x','Primary','Fixture','admin','primary_admin','active'),(2000000004,'inv-api-student','inv-api-student@example.invalid','x','Student','Fixture','student',NULL,'active')");
$db->exec("INSERT INTO nurses (id,user_id) VALUES (2000000011,2000000001)");$db->exec("INSERT INTO students (id,user_id,student_number) VALUES (2000000012,2000000004,'INV-API-QA')");
$db->exec("INSERT INTO medicines (id,medicine_name,generic_name,brand,supplier,quantity,unit,reorder_level,batch_number) VALUES (1,'Medicine Fixture','Generic Fixture','Brand Fixture','Supplier Fixture',5,'pcs',10,'API-BATCH')");
$role=$argv[1]??'nurse';$case=$argv[2]??'list';$grant=($argv[3]??'')==='grant';$userId=['nurse'=>2000000001,'co'=>2000000002,'primary'=>2000000003,'student'=>2000000004][$role];
if($grant)$db->exec("INSERT INTO admin_permissions (user_id,permission_key,granted_by) VALUES (2000000002,'inventory_oversight',2000000003)");
$user=$db->query('SELECT * FROM users WHERE id='.$userId)->fetch();$_SESSION['user']=$user;$_SESSION['credential_version']=0;$_SESSION['created']=time();$_SESSION['last_activity']=time();$_SESSION['last_db_activity_update']=time();$_SESSION['csrf_token']='inv-api-csrf';
$_SERVER['REQUEST_METHOD']='GET';$_GET=['action'=>$case];$_POST=[];$endpoint='inventory.php';$expected=($role==='nurse'||($role==='co'&&$grant))?200:403;$pdf=false;
if($case==='health')$expected=$role==='primary'?200:403;
if($case==='stock_update'){$_SERVER['REQUEST_METHOD']='POST';$_POST=['action'=>$case,'id'=>1,'type'=>'stock_out','quantity'=>2,'csrf_token'=>'inv-api-csrf'];$_GET=[];$expected=$role==='nurse'?200:403;}
if($case==='add'){$_SERVER['REQUEST_METHOD']='POST';$_POST=['action'=>'add','medicine_name'=>'Added Fixture','generic_name'=>'Known Generic','brand'=>'Known Brand','category'=>'Tablet','quantity'=>50,'reorder_level'=>10,'unit'=>'pcs','expiration_date'=>'2099-01-01','csrf_token'=>'inv-api-csrf'];$_GET=[];$expected=$role==='nurse'?200:403;}
if($case==='update'){$_SERVER['REQUEST_METHOD']='POST';$_POST=['action'=>'update','id'=>1,'medicine_name'=>'Edited Fixture','quantity'=>5,'original_quantity'=>5,'reorder_level'=>10,'unit'=>'pcs','csrf_token'=>'inv-api-csrf'];$_GET=[];$expected=$role==='nurse'?200:403;}
if($case==='mica'){$endpoint='mica_inventory.php';$_GET=['action'=>'snapshot'];}
if($case==='dispense'){$endpoint='dispensing.php';$_SERVER['REQUEST_METHOD']='POST';$_POST=['action'=>'dispense','student_id'=>2000000012,'medicine_id'=>1,'quantity'=>2,'purpose'=>'QA','csrf_token'=>'inv-api-csrf'];$_GET=[];$expected=$role==='nurse'?200:403;}
if($case==='pdf'){
 $endpoint='medicine_requisition_download.php';$_SERVER['REQUEST_METHOD']='POST';$items=(new MedicineInventoryService($db))->snapshot();$selected=[1=>$items[1]];$_SESSION['medicine_requisitions']['qa']=['owner'=>$userId,'confirmed'=>true,'reference'=>'BPC-MR-ENDPOINT-QA','prepared'=>date('Y-m-d H:i:s'),'quantities'=>[1=>15],'confirmed_hash'=>MedicineInventoryService::fingerprint($selected),'revision'=>'qa-revision'];$_POST=['format'=>'pdf','token'=>'qa','revision'=>'qa-revision','csrf_token'=>'inv-api-csrf'];$_GET=[];$pdf=true;
}
$_REQUEST=array_merge($_GET,$_POST);$_SERVER['REQUEST_URI']='/api/'.$endpoint;$_SERVER['SCRIPT_NAME']=$_SERVER['REQUEST_URI'];
ob_start();register_shutdown_function(function()use($db,$role,$case,$expected,$pdf){$bytes=ob_get_clean();$code=http_response_code()?:200;$body=$pdf&&$code===200?null:json_decode($bytes,true);$ok=$code===$expected;
 if($expected===200){$ok=$ok&&($pdf?str_starts_with($bytes,'%PDF-'):($body['success']??false));if($case==='stock_update'||$case==='dispense')$ok=$ok&&$db->query('SELECT quantity FROM medicines WHERE id=1')->fetchColumn()==3;if($case==='dispense')$ok=$ok&&$db->query("SELECT COUNT(*) FROM medicine_logs WHERE action='stock_out'")->fetchColumn()==1;if($pdf){$dir=__DIR__.'/inventory-upgrade-qa';if(!is_dir($dir))mkdir($dir);file_put_contents($dir.'/'.$role.'-endpoint.pdf',$bytes);}}
 else $ok=$ok&&is_array($body)&&!($body['success']??true);
 session_destroy();echo ($ok?'PASS':'FAIL').' inventory endpoint '.$role.' '.$case.' HTTP '.$code.PHP_EOL;if(!$ok)exit(1);
});
require __DIR__.'/../api/'.$endpoint;
