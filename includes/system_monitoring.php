<?php
require_once __DIR__.'/account_monitoring.php';
final class SystemMonitoringService
{
    public function __construct(private PDO $db){}
    public function summary(): array {
        $count=(new AccountMonitoringService($this->db))->counts();
        $failed=(int)$this->db->query('SELECT COUNT(*) FROM login_attempts WHERE attempted_at>=DATE_SUB(NOW(),INTERVAL 24 HOUR)')->fetchColumn();
        $audit=$this->db->query("SELECT action,module,created_at FROM activity_logs WHERE module IN ('auth','security','admin','account_security','admin_profiles','backup','backups','settings','admin_permissions') ORDER BY id DESC LIMIT 10")->fetchAll();
        $backup=$this->db->query('SELECT id,status,sha256,created_at,completed_at FROM system_backups ORDER BY id DESC LIMIT 1')->fetch()?:null;
        $adminCount=(int)$this->db->query("SELECT COUNT(*) FROM users WHERE role='admin' AND status='active'")->fetchColumn();
        $pending=(int)$this->db->query("SELECT COUNT(*) FROM users WHERE status='pending' AND email_verified=1 AND registration_status='review'")->fetchColumn();
        $scheduled=(int)$this->db->query("SELECT COUNT(*) FROM admin_departures WHERE status='scheduled'")->fetchColumn();
        $overdue=(int)$this->db->query("SELECT COUNT(*) FROM admin_departures WHERE status='scheduled' AND effective_at<=NOW()")->fetchColumn();
        $worker=$this->db->query("SELECT setting_value FROM system_settings WHERE setting_key='admin_departures_last_run'")->fetchColumn();
        return ['departure_worker_last_run'=>$worker?:null,'active_administrators'=>$adminCount,'pending_approval'=>$pending,'scheduled_departures'=>$scheduled,'overdue_departures'=>$overdue,'accounts'=>$count,'database_connected'=>(int)$this->db->query('SELECT 1')->fetchColumn()===1,'failed_logins_24h'=>$failed,'open_security_exceptions'=>(int)$this->db->query("SELECT COUNT(*) FROM account_security_exceptions WHERE status='open'")->fetchColumn(),'recent_audit'=>$audit,'last_backup'=>$backup,'application_error_telemetry'=>'Unavailable: application error logs are not a structured event source.','generated_at'=>date('c')];
    }
}
