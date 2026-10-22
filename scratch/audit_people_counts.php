<?php
ini_set('session.save_path',__DIR__);require __DIR__.'/../config/config.php';require __DIR__.'/../includes/account_monitoring.php';
$db=getDB();echo json_encode(['counts'=>(new AccountMonitoringService($db))->counts(),'pending_by_email_verification'=>$db->query("SELECT email_verified,COUNT(*) AS total FROM users WHERE status='pending' GROUP BY email_verified")->fetchAll(),'active_pending_alerts'=>(int)$db->query("SELECT COUNT(*) FROM ai_actions WHERE action_type IN ('pending_review','registration_review') AND status IN ('new','in_progress')")->fetchColumn()]).PHP_EOL;
