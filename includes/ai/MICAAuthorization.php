<?php
final class MICAAuthorization
{
    public static function forUser(array $user, string $action, PDO $db): bool
    {
        $profile = permission_profile($user, $db);
        if (($user['role'] ?? '') === 'nurse' && !has_permission('clinic_monitor', $user, $db)) return false;
        if (!self::actionAllowed((string)($user['role'] ?? ''), $action)) return false;
        if (in_array($action, ['chat','mica_chat','history','clear_history','sos_emergency'], true)) return true;
        if ($profile === 'mis' || $profile === 'doctor') {
            // Monitoring scans inspect technical and clinical sources together.
            // Keep that mixed-source writer out of both administrative profiles.
            return in_array($action, ['daily_summary','recommended_actions','alert_details','read_alert','archived_actions'], true);
        }
        return $profile === 'nurse';
    }

    public static function alertAllowed(array $user, array $alert, PDO $db): bool
    {
        $profile = permission_profile($user, $db);
        $role = $profile === 'mis' ? 'admin' : ($profile === 'doctor' ? 'nurse' : $profile);
        if (!in_array($profile, ['mis','doctor','nurse'], true)) return false;
        if ($profile === 'nurse' && !has_permission('clinic_monitor', $user, $db)) return false;
        if (!in_array($alert['target_role'] ?? '', [$role, 'all'], true)) return false;
        // Explicit allowlists also prevent legacy target_role=all alerts leaking.
        $types = $profile === 'mis'
            ? ['pending_review','registration_review','security_anomaly','graduating_student','account_invalid_registration','account_identity_failure','account_duplicate_indicator','account_otp_failure','account_exception','duplicate_account']
            : ['emergency_triage','hub_low_stock','expiring_medicine','low_stock_reorder','inventory_anomaly'];
        return in_array($alert['action_type'] ?? '', $types, true);
    }

    public static function actionAllowed(string $role,string $action): bool
    {
        $role=canonical_role($role);
        $basic=['chat','mica_chat','sos_emergency','history','clear_history'];
        if(!in_array($role,['student','teacher','nurse','admin'],true))return false;
        if(in_array($action,$basic,true)) return true;
        if($role==='admin')return in_array($action,['daily_summary','recommended_actions','run_automation','alert_details','read_alert','archived_actions'],true);
        if($role==='nurse')return in_array($action,['alert_details','read_alert','archived_actions','daily_summary','recommended_actions','update_action_status','reorder_list','create_reorder','approve_reorder','reject_reorder','run_automation'],true);
        return false;
    }

    public static function studentPromptAllowed(string $message): bool
    {
        $blocked='/\b(other\s+(student|patient)|classmate|all\s+(student|patient)|inventory|stock\s*(level|count)?|medicine\s+quantity|admin(?:istrator)?|staff\s+record|report\s+(data|export)|certificate\s+(issue|generate|approve)|password|database|sql|system\s+prompt|ignore\s+(previous|prior|instructions?)|bypass|override\s+(rules?|permissions?))\b/i';
        return preg_match($blocked,$message)!==1;
    }
}
