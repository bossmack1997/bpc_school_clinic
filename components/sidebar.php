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
            ],
            'Clinic Operations' => [
                ['shield-alert', 'Emergencies', 'emergencies.php'],
                ['qr', 'QR & Verification', 'qr_management.php'],
            ],
            'Management' => [
                ['users', 'Users & Roles', 'users.php'],
                ['shield-lock', 'Admin Accounts', 'admin_accounts.php'],
            ],
            'Inventory' => [
                ['pill', 'Medicine Stocks', 'inventory.php'],
            ],
            'Communication' => [
                ['megaphone', 'Announcements', 'announcements.php'],
                ['bell', 'Notifications', 'notifications.php'],
            ],
            'Reports' => [
                ['chart', 'Reports', 'reports.php'],
                ['history', 'Activity Logs', 'activity_logs.php'],
            ],
            'Settings' => [
                ['settings', 'Settings', 'settings.php'],
                ['lock', 'Security', 'security.php'],
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
                ['stethoscope', 'Consultation Desk', 'walkin.php'],
                ['clipboard-list', 'Student Records', 'medical_records.php'],
                ['shield-alert', 'Emergency Center', 'emergencies.php'],
            ],
            'Inventory & Docs' => [
                ['pill', 'Medicine Inventory', 'inventory.php'],
                ['file-medical', 'Certificates', 'certificates.php'],
            ],
            'Reports & Comms' => [
                ['chart', 'Reports', 'reports.php'],
                ['chat', 'Messages', 'messages.php'],
                ['megaphone', 'Announcements', 'announcements.php'],
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
                ['clipboard-check', 'Certificates', 'certificates.php'],
                ['heart-pulse', 'Health Monitoring', 'health_monitoring.php'],
                ['qr', 'Health Pass QR', 'my_qr.php'],
            ],
            'Emergency & Comms' => [
                ['shield-alert', 'SOS Emergency', null, 'sos'],
                ['chat', 'Messages', 'messages.php'],
                ['bell', 'Notifications', 'notifications.php'],
            ],
            'Account' => [
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
            'My Advisory' => [
                ['users', 'My Students', 'students.php'],
                ['list', 'Clinic Queue', 'queue.php'],
            ],
            'Emergency & Comms' => [
                ['shield-alert', 'Emergency', 'emergencies.php'],
                ['megaphone', 'Announcements', 'announcements.php'],
                ['chat', 'Messages', 'messages.php'],
            ],
            'Account' => [
                ['user', 'Profile', 'profile.php'],
                ['logout', 'Logout', null, 'logout'],
            ],
        ],
        'brand' => 'Teacher / Adviser',
    ],
];

$sbMenus = $sbNav[$sbRole] ?? $sbNav['student'];
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
        $sbTabQuery = (strpos((string)$sbFile, 'tab=') !== false) ? explode('tab=', (string)$sbFile)[1] : null;

        $sbActive = false;
        if ($sbFileBase && $sbCur === $sbFileBase) {
            if ($sbTabQuery !== null) {
                $sbActive = ((string)$sbTabs === $sbTabQuery);
            } else {
                $sbActive = ($sbTabs === null || $sbTabs === '');
            }
        }

        $activeClass = $sbActive ? 'active' : '';
        $sbClass     = $sbType === 'logout' ? 'nav-link logout-link' : 'nav-link';
        $sbDirectory = $sbRole === 'teacher' ? 'adviser' : $sbRole;

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
