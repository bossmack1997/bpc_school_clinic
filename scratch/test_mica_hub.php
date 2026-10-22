<?php
if(PHP_SAPI!=='cli')exit;
require __DIR__.'/../config/config.php';
require __DIR__.'/../includes/ai/MICAHubService.php';
$db=getDB();
$db->exec(file_get_contents(__DIR__.'/../database/migration_mica_hub.sql'));
foreach(['students','users','teachers','nurses','account_security_events','account_security_exceptions','medicines','medicine_logs','inventory_logs','system_settings','notifications','admin_permissions','sos_emergencies','ai_actions','mica_scans','mica_alert_events','mica_alert_reads'] as $t){$db->exec("CREATE TEMPORARY TABLE hub_test_$t LIKE $t");$db->exec("ALTER TABLE hub_test_$t RENAME TO $t");}
function checkHub($ok,$label){if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
$db->exec("INSERT INTO users (id,username,email,password_hash,first_name,last_name,role,status) VALUES (991,'hub-admin','hub-admin@example.invalid','x','Hub','Admin','admin','active'),(992,'hub-nurse','hub-nurse@example.invalid','x','Hub','Nurse','nurse','active')");
$admin=$db->query('SELECT * FROM users WHERE id=991')->fetch();$nurse=$db->query('SELECT * FROM users WHERE id=992')->fetch();
$hub=new MICAHubService($db,$admin);
$r=$hub->scan();checkHub($r['new']===0,'Zero pending and empty inventory produce no alerts');
$db->exec("INSERT INTO users (id,username,email,password_hash,first_name,last_name,role,status) VALUES (993,'hub-pending','hub-pending@example.invalid','x','Hub','Pending','teacher','pending')");
$r=$hub->scan();checkHub($r['new']===1,'One pending account creates one grouped alert');
$id=(int)$db->query('SELECT id FROM ai_actions')->fetchColumn();
$r=$hub->scan();checkHub($r['new']===0 && $db->query('SELECT COUNT(*) FROM ai_actions')->fetchColumn()==1,'Repeated scan creates no duplicate');
checkHub($db->query('SELECT COUNT(*) FROM notifications')->fetchColumn()==1,'Notification persisted once to responsible role');
$db->exec("INSERT INTO users (id,username,email,password_hash,first_name,last_name,role,status) VALUES (994,'hub-pending2','hub-pending2@example.invalid','x','Hub','Pending','student','pending')");
$r=$hub->scan();checkHub($r['updated']===1 && str_starts_with($db->query('SELECT title FROM ai_actions')->fetchColumn(),'2 '),'Changed pending count refreshes existing alert');
checkHub((new MICAActionService($db,$nurse))->getRecommendedActions()===[],'Nurse cannot read admin-only alerts');
checkHub((new MICAHubService($db,$nurse))->detail($id)===null,'Nurse cannot retrieve admin-only detail');
$hub->detail($id,true);checkHub($db->query('SELECT status FROM ai_actions')->fetchColumn()==='new' && $db->query('SELECT COUNT(*) FROM mica_alert_reads')->fetchColumn()==1,'Reading records receipt without resolving issue');
$db->exec("UPDATE users SET status='active' WHERE id IN (993,994)");$r=$hub->scan();checkHub($r['resolved']===1,'Approval resolves alert on verified scan');
checkHub(count($hub->detail($id)['events'])>=3,'Details contain persisted lifecycle history');
checkHub(!(new MICAActionService($db,$nurse))->updateActionStatus($id,'resolved')['success'],'Manual resolution rejected');
$db->exec("INSERT INTO medicines (id,medicine_name,quantity,unit,reorder_level,status) VALUES (995,'Hub Stock',2,'pcs',10,'Low Stock')");
$r=$hub->scan();checkHub($r['new']===1,'Verified low stock creates an alert');
$db->exec('UPDATE medicines SET quantity=30 WHERE id=995');$r=$hub->scan();checkHub($r['resolved']===1,'Replenishment resolves stock alert');
$db->exec("INSERT INTO sos_emergencies (id,user_id,emergency_type,status) VALUES (996,992,'QA emergency','active')");
$r=$hub->scan();checkHub($r['new']===1,'Actual SOS source creates an alert');
$db->exec("UPDATE sos_emergencies SET status='resolved' WHERE id=996");$r=$hub->scan();checkHub($r['resolved']===1,'Handled SOS resolves monitoring alert');
checkHub($db->query('SELECT status FROM sos_emergencies WHERE id=996')->fetchColumn()==='resolved','Monitor preserves SOS source status');
$other=new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4',DB_USER,DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$other->query("SELECT GET_LOCK('bpc_mica_hub_scan',0)");
try{$hub->scan();checkHub(false,'Concurrent scan must reject');}catch(RuntimeException $e){checkHub(str_contains($e->getMessage(),'Another intelligence scan'),'Concurrent connection lock rejects overlapping scan');}
$other->query("SELECT RELEASE_LOCK('bpc_mica_hub_scan')");
require_once __DIR__.'/../includes/ai/MICAWorkflowService.php';
$workflow=new MICAWorkflowService($db,$admin);
checkHub(str_contains($workflow->handleNaturalLanguageQuery('How many registrations are actually pending?')['reply'],'0'),'Pending registration natural language question uses deterministic facts');
$db->exec('DROP TEMPORARY TABLE medicines');$db->exec('CREATE TEMPORARY TABLE medicines (id INT)');
try{$hub->scan();checkHub(false,'Source failure must throw');}catch(PDOException $e){checkHub($db->query('SELECT status FROM mica_scans ORDER BY id DESC LIMIT 1')->fetchColumn()==='failed','Source failure saves failed scan rather than success');}
echo "All hub integration checks passed. Real records untouched; only additive migration applied.\n";
