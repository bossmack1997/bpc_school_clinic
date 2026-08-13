 <?php
/**
 * components/sidebar.php
 * ------------------------------------------------------------------
 * SINGLE role-aware sidebar for the entire BPC Clinic system.
 * Replaces includes/sidebar_admin.php, includes/sidebar_nurse.php,
 * includes/sidebar_student.php — one source of truth, no duplication.
 *
 * The active page is highlighted automatically using the current
 * script filename. The layout is responsive: desktop collapse,
 * mobile off-canvas (controlled by assets/js/main.js).
 * ------------------------------------------------------------------
 */

$sbUser   = current_user();
$sbRole   = $sbUser['role'] ?? 'student';
$sbCur    = basename($_SERVER['SCRIPT_NAME']);

/**
 * Per-role navigation definition.
 * Each item: [section, icon, label, file, badge(optional)]
 */
$sbNav = [
    'admin' => [
        'sections' => [
            'Dashboard' => [
                ['grid-1x2-fill', 'Dashboard', 'dashboard.php'],
            ],
            'Patient Management' => [
                ['people-fill', 'Students', 'students.php'],
                ['file-earmark-medical-fill', 'Medical Records', 'medical_records.php'],
                ['calendar-check-fill', 'Appointments', 'appointments.php'],
            ],
             'Clinic Services' => [
               ['capsule-pill', 'Medicine Inventory', 'inventory.php'],
               ['file-earmark-bar-graph-fill', 'Reports', 'reports.php'],
               ['graph-up', 'Health Analytics', 'analytics.php'],
             ],
             'Communication' => [
                 ['bell-fill', 'Notifications', 'notifications.php'],
                 ['megaphone-fill', 'Announcements', 'announcements.php'],
             ],
             'Administration' => [
                 ['person-badge-fill', 'User Management', 'users.php'],
                 ['hourglass-split', 'Pending Accounts', 'pending_accounts.php'],
                 ['clock-history', 'Audit Logs', 'activity_logs.php'],
                 ['gear-fill', 'System Settings', 'settings.php'],
             ],
              'QR System' => [
                  ['qr-code', 'QR Management', 'qr_management.php'],
              ],
             'Account' => [
                 ['person-circle', 'Profile', 'profile.php'],
                 ['box-arrow-right', 'Logout', null, 'logout'],
             ],
         ],
         'brand' => 'Management System',
    ],

    'nurse' => [
        'sections' => [
            'Dashboard' => [
                ['grid-1x2-fill', 'Dashboard', 'dashboard.php'],
            ],
            'Patient Management' => [
                ['hospital-fill', 'Today\'s Patients', 'patients_today.php'],
                ['person-walking', 'Walk-in Patients', 'walkin.php'],
                ['calendar-check-fill', 'Appointments', 'appointments.php'],
                ['clock-history', 'Patient History', 'patient_history.php'],
            ],
            'Clinic Services' => [
                ['heart-pulse-fill', 'Health Monitoring', 'health_monitoring.php'],
                ['file-earmark-medical-fill', 'Medical Records', 'medical_records.php'],
                // 'prescription2' is not available in all Bootstrap Icon versions — use 'capsule' for consistency
                ['capsule', 'Medicine Dispensing', 'dispensing.php'],
                ['file-medical-fill', 'Medical Certificates', 'certificates.php'],
            ],
            'QR' => [
              ['qr-code-scan', 'QR Scanner', 'qr_scanner.php'],
              ['qr-code', 'QR Management', 'qr_management.php'],
            ],
            'Inventory & Reports' => [
                ['capsule-pill', 'Medicine Inventory', 'inventory.php'],
                ['file-earmark-bar-graph-fill', 'Reports', 'reports.php'],
            ],
            'Updates' => [
                ['bell-fill', 'Notifications', 'notifications.php'],
            ],
            'Account' => [
                ['person-circle', 'Profile', 'profile.php'],
                ['box-arrow-right', 'Logout', null, 'logout'],
            ],
        ],
        'brand' => 'Nurse Station',
    ],

    'student' => [
        'sections' => [
            'Dashboard' => [
                ['grid-1x2-fill', 'Dashboard', 'dashboard.php'],
            ],
            'My Health' => [
              ['qr-code', 'My QR Code', 'my_qr.php'],
              ['file-earmark-medical-fill', 'Medical Record', 'medical_record.php'],
              ['heart-pulse-fill', 'Health Monitoring', 'health_monitoring.php'],
              ['shield-check', 'Vaccination Record', 'vaccination.php'],
            ],
            'Clinic Services' => [
                ['calendar-check-fill', 'Appointment Booking', 'appointments.php'],
                ['file-medical-fill', 'Medical Certificates', 'certificates.php'],
            ],
            'Updates' => [
                ['megaphone-fill', 'Clinic Announcements', 'announcements.php'],
                ['bell-fill', 'Notifications', 'notifications.php'],
            ],
            'Account' => [
                ['person-circle', 'Profile', 'profile.php'],
                ['box-arrow-right', 'Logout', null, 'logout'],
            ],
        ],
        'brand' => 'Student Portal',
    ],
];

$sbMenus = $sbNav[$sbRole] ?? $sbNav['student'];
?>

<aside class="sidebar" id="sidebar">
  <!-- Brand -->
  <div class="brand">
    <div class="brand-logo">
      <img src="<?= APP_URL ?>/assets/images/bpc_logo.png" alt="BPC Clinic Logo">
    </div>
    <div class="brand-text">
      BPC School Clinic
      <small><?= e($sbMenus['brand']) ?></small>
    </div>
  </div>

  <!-- Collapse Toggle (Desktop) 
  <button class="sidebar-toggle-collapse" title="Toggle Sidebar">
    <i class="bi bi-chevron-left"></i>
  </button>
-->
  <!-- Navigation -->
  <nav class="sidebar-nav">
    <?php foreach ($sbMenus['sections'] as $sbSection => $sbItems): ?>
      <div class="nav-section-label"><?= e($sbSection) ?></div>
      <?php foreach ($sbItems as $sbItem): ?>
<?php
        $sbIcon   = $sbItem[0];
        $sbLabel  = $sbItem[1];
        $sbFile   = $sbItem[2];
        $sbType   = $sbItem[3] ?? 'link';
        $sbActive = $sbFile && $sbCur === $sbFile ? 'active' : '';
        $sbClass  = $sbType === 'logout' ? 'nav-link logout-link' : 'nav-link';
        // Absolute, role-prefixed URL — works from role subdirectories AND
        // the consolidated pages/ai_assistant.php at the pages/ root.
        $sbHref   = $sbType === 'logout' ? '#' : APP_URL . '/pages/' . $sbRole . '/' . $sbFile;
        $sbExtra  = $sbType === 'logout' ? ' data-logout-url="' . APP_URL . '/auth/logout.php"' : '';
        ?>
        <a class="<?= $sbClass ?> <?= $sbActive ?>" href="<?= $sbHref ?>"<?= $sbExtra ?>>
          <i class="bi bi-<?= $sbIcon ?>"></i><span> <?= e($sbLabel) ?></span>
        </a>
      <?php endforeach; ?>
    <?php endforeach; ?>
  </nav>

  <!-- Footer -->
  <div class="sidebar-footer">
    <div class="info-item">
      <i class="bi bi-geo-alt-fill"></i>
      <span>Bulacan Polytechnic College</span>
    </div>
    <div class="info-item">
      <i class="bi bi-telephone-fill"></i>
      <span>09639958414</span>
    </div>
    <div class="info-item">
      <i class="bi bi-envelope-fill"></i>
      <span>clinic@bpc.edu.ph</span>
    </div>
    <div class="copyright">
      &copy; 2026 Bulacan Polytechnic College
    </div>
  </div>
</aside>

