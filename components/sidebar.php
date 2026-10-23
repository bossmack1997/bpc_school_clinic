<?php
/**
 * components/sidebar.php
 * ------------------------------------------------------------------
 * Single role-aware sidebar for every authenticated page.
 *
 * Icons are inline SVG (bpc_icon) so navigation never depends on an
 * icon webfont or CDN. Items only link to pages that exist for the
 * current role — no decorative, non-functional entries.
 * ------------------------------------------------------------------
 */

$sbUser   = current_user();
$sbRole   = $sbUser['role'] ?? 'student';
$sbCur    = basename($_SERVER['SCRIPT_NAME']);
$sbTabs   = $_GET['tab'] ?? null;

/**
 * Build one sidebar entry.
 * [ icon, label, file, type ] — type: link | logout | sos
 */
$sbNav = [
    'admin' => [
        'sections' => [
            'Overview' => [
                ['dashboard', 'Dashboard', 'dashboard.php'],
                ['alert-triangle', 'Emergency Oversight', 'emergencies.php'],
            ],
            'Management' => [
                ['users', 'People Directory', 'users.php'],
                ['shield-lock', 'Admin Accounts', 'admin_accounts.php'],
            ],
            'Settings' => [
                ['settings', 'Settings', 'settings.php'],
            ],
            'Account' => [
                ['user', 'My Profile', 'profile.php'],
                ['logout', 'Logout', null, 'logout'],
            ],
        ],
        'brand' => 'System Administrator',
    ],

    'nurse' => [
        'sections' => [
            'Overview' => [
                ['dashboard', 'Dashboard', 'dashboard.php'],
            ],
            'Clinic Operations' => [
                ['list', 'Clinic Queue', 'queue.php'],
            ],
            'Communication' => [
                ['chat', 'Messages', 'messages.php'],
            ],
            'Settings' => [
                ['settings', 'Settings', 'settings.php'],
            ],
            'Account' => [
                ['user', 'Profile', 'profile.php'],
                ['logout', 'Logout', null, 'logout'],
            ],
        ],
        'brand' => 'Clinic Nurse',
    ],

    'student' => [
        'sections' => [
            'Overview' => [
                ['dashboard', 'Dashboard', 'dashboard.php'],
            ],
            'Health Services' => [
                ['list', 'Clinic Queue', 'queue.php'],
                ['file-medical', 'Health Record', 'medical_record.php'],
            ],
            'Communication' => [
                ['chat', 'Messages', 'messages.php'],
            ],
            'Account' => [
                ['settings', 'Settings', 'settings.php'],
                ['user', 'My Profile', 'profile.php'],
                ['logout', 'Logout', null, 'logout'],
            ],
        ],
        'brand' => 'Student',
    ],

    'teacher' => [
        'sections' => [
            'Overview' => [
                ['dashboard', 'Dashboard', 'dashboard.php'],
            ],
            'Teacher Management' => [
                ['users', 'My Students', 'students.php'],
            ],
            'Personal Clinic Services' => [
                ['list', 'Clinic Queue', 'queue.php'],
                ['file-medical', 'My Health Record', 'consultations.php'],
            ],
            'Communication' => [
                ['chat', 'Messages', 'messages.php'],
            ],
            'Account' => [
                ['settings', 'Settings', 'settings.php'],
                ['user', 'Profile', 'profile.php'],
                ['logout', 'Logout', null, 'logout'],
            ],
        ],
        'brand' => 'Teacher / Adviser',
    ],
];

$sbProfile=permission_profile($sbUser);
if($sbProfile==='mis')$sbNav['admin']=['brand'=>'MIS / System Administrator','sections'=>[
 'System Administration'=>[
     ['dashboard','Dashboard','dashboard.php'],
     ['users','People Management','users.php'],
     ['shield-lock','Administrator Management','admin_accounts.php'],
     ['activity','System Monitoring','system_monitoring.php'],
     ['shield-check','Security Center','security.php'],
     ['clock','Activity & Audit Logs','activity_logs.php'],
     ['database','Backup & Recovery','backups.php'],
     ['settings','System Settings','settings.php']
 ],
 'Account'=>[
     ['user','My Profile','profile.php'],
     ['logout','Logout',null,'logout']
 ]
]];
if($sbProfile==='doctor')$sbNav['admin']=['brand'=>'Doctor / Clinical Co-Administrator','sections'=>[
 'Clinical Overview'=>[
     ['dashboard','Dashboard','dashboard.php'],
     ['activity','Clinic Monitoring','clinic_monitoring.php'],
 ],
 'Patient Care'=>[
     ['users','Patient Records','patients.php'],
     ['file-medical','Consultations','consultations.php'],
 ],
 'Clinical Supervision'=>[
     ['pill','Medicine Oversight','inventory.php'],
     ['alert-triangle','Emergency Oversight','emergencies.php'],
     ['file-medical','Clinical Reports','reports.php']
 ],
 'Communication'=>[
     ['chat','Messages','messages.php'],
     ['sparkles','MICA AI','../ai_assistant.php']
 ],
 'Account'=>[
     ['user','My Profile','profile.php'],
     ['settings','Settings','settings.php'],
     ['logout','Logout',null,'logout']
 ]
]];
$sbMenus = $sbNav[$sbRole] ?? $sbNav['student'];
if($sbRole==='nurse' && !empty($sbUser['self_service_only'])){
    $sbMenus = [
        'sections' => [
            'Overview' => [['dashboard', 'Account Overview', 'account.php']],
            'Account' => [['user', 'My Profile', 'profile.php'], ['settings', 'Settings', 'settings.php'], ['logout', 'Logout', null, 'logout']],
        ],
        'brand' => 'Clinic Nurse',
    ];
} elseif($sbRole==='nurse'){
    $sbMenus['sections']['Clinic Operations'][]=['pill','Medicine Inventory','inventory.php'];
}
if ($sbRole === 'admin' && $sbProfile!=='doctor') {
    $settingsVisible=is_primary_admin($sbUser)||has_admin_permission('manage_settings',$sbUser)||has_admin_permission('manage_security',$sbUser)||has_admin_permission('view_audit_logs',$sbUser);
    $rules=[
        'users.php'=>has_admin_permission('manage_users',$sbUser)||has_admin_permission('approve_users',$sbUser),
        'admin_accounts.php'=>is_primary_admin($sbUser),
        'system_monitoring.php'=>has_admin_permission('system_manage',$sbUser),
        'emergencies.php'=>has_admin_permission('view_emergencies',$sbUser),
        'settings.php'=>$settingsVisible,
        'security.php'=>has_admin_permission('manage_security',$sbUser),
        'backups.php'=>has_admin_permission('backups_manage',$sbUser),
        'activity_logs.php'=>has_admin_permission('view_audit_logs',$sbUser),
    ];
    foreach($sbMenus['sections'] as $section=>$items){
        $sbMenus['sections'][$section]=array_values(array_filter($items,fn($item)=>!isset($rules[$item[2]??''])||$rules[$item[2]]));
        if(!$sbMenus['sections'][$section])unset($sbMenus['sections'][$section]);
    }
}

?>

<a class="skip-link" href="#mainContent">Skip to main content</a>

<aside class="sidebar" id="sidebar" aria-label="Primary navigation">
  <div class="brand">
    <div class="brand-logo">
      <img src="<?= APP_URL ?>/assets/images/bpc_logo.png" alt="" width="38" height="38">
    </div>
    <div class="brand-text">
      BPC School Clinic
      <small>Healthcare Management System</small>
    </div>
  </div>

  <div class="sidebar-role">
    <span class="sidebar-role__label">Signed in as</span>
    <span class="sidebar-role__value"><?= e($sbMenus['brand']) ?></span>
  </div>

  <nav class="sidebar-nav">
    <?php foreach ($sbMenus['sections'] as $sbSection => $sbItems): ?>
      <div class="nav-section-label"><?= e($sbSection) ?></div>
      <?php foreach ($sbItems as $sbItem):
        $sbIcon     = $sbItem[0];
        $sbLabel    = $sbItem[1];
        $sbFile     = $sbItem[2];
        $sbType     = $sbItem[3] ?? 'link';
        $sbFileBase = $sbFile ? explode('?', (string)$sbFile)[0] : '';
        if($sbRole==='teacher' && !empty($sbUser['self_service_only']) && in_array($sbFileBase,['students.php','my_advisory.php'],true))continue;
        $sbTabQuery = (strpos((string)$sbFile, 'tab=') !== false) ? explode('tab=', (string)$sbFile)[1] : null;

        $sbActive = false;
        if ($sbFileBase && $sbCur === $sbFileBase) {
            if ($sbTabQuery !== null) {
                $sbActive = ((string)$sbTabs === $sbTabQuery);
            } else {
                $sbActive = ($sbTabs === null || $sbTabs === '');
            }
        }

        // Settings owns every admin settings tab, regardless of its query string.
        if ($sbRole === 'admin' && $sbFileBase === 'settings.php' && $sbCur === 'settings.php') {
            $sbActive = true;
        }

        $activeClass = $sbActive ? 'active' : '';
        $sbClass     = $sbType === 'logout' ? 'nav-link logout-link' : 'nav-link';
        $sbDirectory = $sbProfile==='doctor'?'doctor':($sbRole === 'teacher' ? 'adviser' : $sbRole);

        if ($sbType === 'sos') {
            $sbHref   = '#';
            $sbExtra  = ' data-action="open-sos"';
            $sbAria   = '';
        } else {
            $sbHref   = $sbType === 'logout' ? '#' : APP_URL . '/pages/' . $sbDirectory . '/' . $sbFile;
            $sbExtra  = $sbType === 'logout' ? ' data-logout-url="' . APP_URL . '/auth/logout.php"' : '';
            $sbAria   = $sbActive ? ' aria-current="page"' : '';
        }
      ?>
        <a class="<?= $sbClass ?> <?= $activeClass ?>" href="<?= $sbHref ?>"<?= $sbExtra ?><?= $sbAria ?>>
          <?= bpc_icon($sbIcon, 'nav-ico') ?><span><?= e($sbLabel) ?></span>
        </a>
      <?php endforeach; ?>
    <?php endforeach; ?>
  </nav>

  <div class="sidebar-footer">
    <div class="info-item">
      <?= bpc_icon('building') ?>
      <span>Bulacan Polytechnic College</span>
    </div>
    <div class="info-item">
      <?= bpc_icon('phone') ?>
      <span>09639958414</span>
    </div>
    <div class="info-item">
      <?= bpc_icon('mail') ?>
      <span>clinic@bpc.edu.ph</span>
    </div>
    <div class="copyright">
      &copy; <?= date('Y') ?> Bulacan Polytechnic College
    </div>
  </div>

  <button type="button" class="sidebar-toggle-collapse d-none d-xl-flex"
          aria-label="Collapse sidebar" title="Collapse sidebar"
          data-bs-toggle="tooltip" data-bs-placement="right">
    <?= bpc_icon('chevron-left') ?>
  </button>
</aside>

<div class="app-scrim" id="sidebarScrim" aria-hidden="true"></div>
