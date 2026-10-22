<?php
/** Functional permission profiles preserve the four existing database roles. */
function permission_profile(?array $user=null,?PDO $db=null): string {
    $user=$user??current_user();if(!$user)return 'anonymous';
    if(($user['role']??'')!=='admin')return canonical_role($user['role']??'');
    try{$q=($db??getDB())->prepare('SELECT profile FROM admin_profiles WHERE user_id=?');$q->execute([(int)$user['id']]);$p=$q->fetchColumn();if(in_array($p,['mis','doctor','legacy'],true))return $p;}catch(PDOException $e){/* Backward-compatible, no clinical grant on missing schema. */}
    return ($user['admin_type']??'')==='primary_admin'?'mis':'legacy';
}
function has_permission(string $permission,?array $user=null,?PDO $db=null): bool {
    $user=$user??current_user();$p=permission_profile($user,$db);
    $technical=['system_manage','manage_users','roles_assign','manage_security','view_audit_logs','backups_manage','manage_settings','manage_sections','approve_users'];
    $clinical=['clinic_monitor','clinical_records_read','consultations_manage','inventory_oversight','view_emergencies','view_reports','clinical_messages'];
    if($p==='mis')return in_array($permission,$technical,true);
    if($p==='doctor')return in_array($permission,$clinical,true);
    if($p==='nurse')return in_array($permission,array_merge($clinical,['inventory_manage','emergencies_respond']),true);
    if($p==='legacy' && ($user['role']??'')==='admin'){
        // Retain explicitly assigned old oversight grants, never infer Doctor privileges.
        if(in_array($permission,['clinical_records_read','consultations_manage','roles_assign','backups_manage'],true))return false;
        $q=($db??getDB())->prepare('SELECT COUNT(*) FROM admin_permissions WHERE user_id=? AND permission_key=?');$q->execute([$user['id'],$permission]);return (bool)$q->fetchColumn();
    }
    return false;
}
function require_permission(string $permission): void {require_login();if(!has_permission($permission))admin_access_denied('This operation requires '.$permission.' authorization.');}
function require_doctor(): void {require_login();if(permission_profile()!=='doctor')admin_access_denied('Verified Doctor profile required.');}
function enforce_profile_route(): void {
    if(empty($_SESSION['user']['id']))return;
    $u=current_user();if(!$u||($u['role']??'')!=='admin')return;
    // APIs that use only is_logged_in must also reject revoked/expired sessions.
    if(!validate_session()){http_response_code(401);header('Content-Type: application/json');echo json_encode(['success'=>false,'message'=>'Reauthentication required.']);exit;}
    $p=permission_profile($u);$path=str_replace('\\','/',$_SERVER['SCRIPT_NAME']??'');$file=basename($path);
    $clinicalApis=['patients.php','medical_records.php','health_monitoring.php','walkin.php','dispensing.php','vaccinations.php','certificates.php','nurse_dashboard.php','students_api.php','my_advisory.php','reports.php','clinical.php'];
    if($p==='mis' && str_contains($path,'/api/') && in_array($file,$clinicalApis,true))admin_access_denied('MIS access excludes clinical records and operations.');
    if($p==='mis' && str_contains($path,'/pages/doctor/'))admin_access_denied('Clinical authorization required.');
    if($p==='mis' && str_contains($path,'/api/') && in_array($file,['emergencies.php','global_sos.php','queue.php','qr_codes.php'],true))admin_access_denied('Clinical monitoring is restricted to authorized clinic personnel.');
    if($p==='doctor'){
        $technicalPages=['users.php','account_exceptions.php','admin_accounts.php','security.php','activity_logs.php','sections.php','qr_management.php','qr_scanner.php','pwa_debug.php','system_monitoring.php','backups.php','role_profiles.php'];
        $technicalApis=['users.php','settings.php','sections.php','activity_logs.php','admin_profiles.php','system_backups.php'];
        if((str_contains($path,'/pages/admin/')&&in_array($file,$technicalPages,true))||(str_contains($path,'/api/')&&in_array($file,$technicalApis,true)))admin_access_denied('Doctor profiles cannot administer technical security or accounts.');
        if(str_contains($path,'/pages/admin/')&&$file==='settings.php')admin_access_denied('Use Doctor personal settings.');
    }
}
