<?php
require_once __DIR__.'/security_policy.php';
final class SecurityMonitoringService {
    public function __construct(private PDO $db) {}
    public function lockouts(): array {
        $window=security_policy('lockout_minutes')*60;$limit=security_policy('max_login_attempts');
        $q=$this->db->prepare("SELECT l.ip_address,l.username AS targeted_accounts,COUNT(*) attempt_count,MAX(l.attempted_at) last_attempt,DATE_ADD(MAX(l.attempted_at),INTERVAL $window SECOND) expires_at FROM login_attempts l
          LEFT JOIN system_settings i ON i.setting_key=CONCAT('login_release_',SHA2(CONCAT(l.ip_address,'|*'),256))
          LEFT JOIN system_settings a ON a.setting_key=CONCAT('login_release_',SHA2(CONCAT(l.ip_address,'|',l.username),256))
          WHERE l.attempted_at>=DATE_SUB(NOW(),INTERVAL $window SECOND) AND l.id>GREATEST(COALESCE(CAST(i.setting_value AS UNSIGNED),0),COALESCE(CAST(a.setting_value AS UNSIGNED),0))
          GROUP BY l.ip_address,l.username HAVING COUNT(*)>=? ORDER BY last_attempt DESC");$q->execute([$limit]);return $q->fetchAll();
    }
    public static function severity(string $type,int $repetitions=1): string {
        return match(true){
            in_array($type,['privilege_escalation','audit_integrity_failure'],true)=>'critical',
            in_array($type,['unauthorized_access','suspicious_privilege_change'],true)=>'high',
            in_array($type,['otp_failure','invalid_registration','identity_failure','duplicate_indicator'],true)&&$repetitions>=5=>'medium',
            in_array($type,['otp_failure','identity_failure'],true)=>'low',
            default=>'informational'
        };
    }
    public function events(): array {
        $rows=$this->db->query("SELECT e.*,(SELECT COUNT(*) FROM account_security_events r WHERE r.subject_key=e.subject_key AND r.event_type=e.event_type AND r.occurred_at>=DATE_SUB(NOW(),INTERVAL 24 HOUR)) repetitions FROM account_security_events e WHERE e.occurred_at>=DATE_SUB(NOW(),INTERVAL 24 HOUR) ORDER BY e.id DESC LIMIT 50")->fetchAll();
        foreach($rows as &$row)$row['severity']=self::severity($row['event_type'],(int)$row['repetitions']);unset($row);return $rows;
    }
}
