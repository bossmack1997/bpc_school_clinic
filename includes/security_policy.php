<?php
/** Bounded runtime policy shared by settings, authentication and monitoring. */
function security_policy(string $key): int {
    $rules = ['session_timeout'=>[3600,300,86400], 'max_login_attempts'=>[5,3,20], 'lockout_minutes'=>[15,1,1440], 'password_min_length'=>[8,8,72]];
    if (!isset($rules[$key])) throw new InvalidArgumentException('Unknown security policy.');
    static $values = null;
    if ($values === null) {
        try { $values = getDB()->query("SELECT setting_key,setting_value FROM system_settings WHERE setting_key LIKE 'security_%'")->fetchAll(PDO::FETCH_KEY_PAIR); }
        catch (Throwable $e) { $values = []; }
    }
    [$default,$min,$max] = $rules[$key];
    $value = filter_var($values['security_'.$key] ?? $default, FILTER_VALIDATE_INT);
    return $value !== false && $value >= $min && $value <= $max ? $value : $default;
}
