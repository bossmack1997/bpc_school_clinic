<?php
final class MICAAuthorization
{
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
