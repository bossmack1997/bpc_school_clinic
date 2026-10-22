<?php
if(PHP_SAPI!=='cli')exit;
ini_set('session.save_path',__DIR__);require __DIR__.'/../config/config.php';require __DIR__.'/../includes/account_monitoring.php';$db=getDB();
if((int)$db->query("SELECT GET_LOCK('bpc_mica_hub_scan',5)")->fetchColumn()!==1)throw new RuntimeException('Scan in progress.');
try{
 if((int)$db->query("SELECT GET_LOCK('bpc_mica_action_write',5)")->fetchColumn()!==1)throw new RuntimeException('Alert writer busy.');
 try{$db->beginTransaction();$pending=(new AccountMonitoringService($db))->counts()['verification'];$count=0;
 if($pending===0){$rows=$db->query("SELECT id FROM ai_actions WHERE action_type IN ('pending_review','registration_review') AND status IN ('new','in_progress','reviewed') FOR UPDATE")->fetchAll();foreach($rows as $r){$db->prepare("UPDATE ai_actions SET status='resolved',executed_at=NOW(),executed_by=NULL WHERE id=?")->execute([$r['id']]);$db->prepare("INSERT INTO mica_alert_events (action_id,event_type,details) VALUES (?,'resolved',?)")->execute([$r['id'],'Verified users.status=pending count is zero. Historical alert preserved; no user records changed.']);$count++;}}
 $db->commit();echo "Verified pending=$pending; archived stale registration alerts=$count; no accounts modified.\n";
 }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}finally{$db->query("SELECT RELEASE_LOCK('bpc_mica_action_write')");}
}finally{$db->query("SELECT RELEASE_LOCK('bpc_mica_hub_scan')");}
