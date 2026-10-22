<?php
/**
 * pages/admin/settings.php
 * =====================================================================
 * AI-Enhanced School Clinic Management System for Bulacan Polytechnic College Healthcare Service Improvement
 * System Settings — Institutional Configuration Only
 * =====================================================================
 */

require_once __DIR__ . '/../../config/config.php';
require_role('admin');
$db = getDB();
$u = current_user();
$pageTitle = 'System Settings';

$requestedTab = $_GET['tab'] ?? null;
$activeTab = in_array($requestedTab, ['general', 'personal', 'password', 'policies', 'integrations', 'maintenance'], true) ? $requestedTab : null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $activeTab = match ($_POST['action'] ?? '') {
        'update_general_settings' => 'general',
        'update_security_policies' => 'policies',
        default => $activeTab,
    };
}

$settingsAccess = [
    'general'      => has_admin_permission('manage_settings', $u, $db),
    'personal'     => permission_profile($u, $db) === 'mis',
    'password'     => permission_profile($u, $db) === 'mis',
    'policies'     => has_admin_permission('manage_security', $u, $db),
    'integrations' => has_admin_permission('manage_settings', $u, $db),
    'maintenance'  => is_primary_admin($u),
];
if (!array_filter($settingsAccess)) {
    admin_access_denied();
}
if ($activeTab !== null && empty($settingsAccess[$activeTab])) {
    admin_access_denied();
}
if ($activeTab === null) {
    $activeTab = array_key_first(array_filter($settingsAccess));
}

$userStmt = $db->prepare('SELECT first_name,middle_name,last_name,email,phone FROM users WHERE id=? LIMIT 1');
$userStmt->execute([(int)$u['id']]);
$userRecord = $userStmt->fetch(PDO::FETCH_ASSOC) ?: $u;

// -------------------------------------------------------------
// POST Request Actions (Settings Update)
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_die();
    $action = $_POST['action'] ?? '';

    // 1. Update General System Settings
    if ($action === 'update_general_settings') {
        require_admin_permission('manage_settings');
        $schoolName    = trim($_POST['school_name'] ?? '');
        $clinicName    = trim($_POST['clinic_name'] ?? '');
        $schoolAddress = trim($_POST['school_address'] ?? '');
        $clinicContact = trim($_POST['clinic_contact'] ?? '');
        $themeMode     = trim($_POST['theme_mode'] ?? 'light');

        $settings = [
            'school_name'    => $schoolName,
            'clinic_name'    => $clinicName,
            'school_address' => $schoolAddress,
            'clinic_contact' => $clinicContact,
            'theme_mode'     => $themeMode,
        ];

        foreach ($settings as $k => $v) {
            $stmt = $db->prepare("INSERT INTO system_settings (setting_key, setting_value, updated_at) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()");
            $stmt->execute([$k, $v]);
        }

        log_activity($u['id'], 'Updated institutional system settings', 'settings');
        set_flash('success', 'System configuration updated successfully.');
        redirect('settings.php?tab=general');
    }

    // 2. Update Security Policies (configuration only)
    if ($action === 'update_security_policies') {
        require_admin_permission('manage_security');
        $sessionTimeout  = (int)($_POST['session_timeout'] ?? 3600);
        $loginAttempts   = (int)($_POST['max_login_attempts'] ?? 5);
        $lockoutMinutes  = (int)($_POST['lockout_minutes'] ?? 15);
        $passwordMinLen  = (int)($_POST['password_min_length'] ?? 8);

        $settings = [
            'security_session_timeout'  => (string)$sessionTimeout,
            'security_max_login_attempts' => (string)$loginAttempts,
            'security_lockout_minutes'  => (string)$lockoutMinutes,
            'security_password_min_length' => (string)$passwordMinLen,
        ];

        foreach ($settings as $k => $v) {
            $stmt = $db->prepare("INSERT INTO system_settings (setting_key, setting_value, updated_at) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()");
            $stmt->execute([$k, $v]);
        }

        log_activity($u['id'], 'Updated security policies', 'settings');
        set_flash('success', 'Security policies updated successfully.');
        redirect('settings.php?tab=policies');
    }
}

// -------------------------------------------------------------
// Data Fetching
// -------------------------------------------------------------
$settingsRows = $db->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$schoolName    = $settingsRows['school_name'] ?? 'Bulacan Polytechnic College';
$clinicName    = $settingsRows['clinic_name'] ?? 'BPC Healthcare Clinic';
$schoolAddress = $settingsRows['school_address'] ?? 'Bulihan, City of Malolos, Bulacan';
$clinicContact = $settingsRows['clinic_contact'] ?? '(044) 791-2345';
$themeMode     = $settingsRows['theme_mode'] ?? 'light';

$sessionTimeout  = (int)($settingsRows['security_session_timeout'] ?? 3600);
$loginAttempts   = (int)($settingsRows['security_max_login_attempts'] ?? 5);
$lockoutMinutes  = (int)($settingsRows['security_lockout_minutes'] ?? 15);
$passwordMinLen  = (int)($settingsRows['security_password_min_length'] ?? 8);

// Integration status (read-only, no secrets exposed)
$integrationStatus = [
    'email'      => function_exists('send_registration_otp_email') ? 'configured' : 'not configured',
    'micai'      => class_exists('MICAHubService') ? 'available' : 'not available',
    'qr'         => class_exists('MICARegistrationValidator') ? 'available' : 'not available',
    'pwa'        => file_exists(__DIR__ . '/../../manifest.json') ? 'available' : 'not available',
];

// Maintenance info
$lastBackup = $db->query("SELECT status, filename, created_at FROM system_backups ORDER BY created_at DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php include __DIR__ . '/../../includes/head.php'; ?>
  <style>
    .settings-menu { display: grid; gap: 12px; min-width: 0; }
    .settings-menu__item { min-width: 0; background: var(--surface, #fff); border: 1px solid var(--border); border-radius: var(--radius-lg, 16px); box-shadow: var(--shadow-sm); overflow: hidden; }
    .settings-menu__toggle { list-style: none; cursor: pointer; }
    .settings-menu__toggle::-webkit-details-marker { display: none; }
    .settings-menu__item:not([open]) > [role="region"] { display: none; }
    .settings-menu__toggle { display: flex; align-items: center; gap: 16px; width: 100%; min-height: 84px; padding: 18px 20px; border: 0; text-align: left; background: transparent; color: var(--text-primary); }
    .settings-menu__toggle:hover,
    .settings-menu__item[open] > .settings-menu__toggle { background: var(--primary-light); }
    .settings-menu__toggle:focus-visible { outline: 3px solid var(--primary); outline-offset: -3px; }
    .settings-menu__icon { display: grid; place-items: center; flex: 0 0 44px; height: 44px; border-radius: 50%; background: var(--primary-light); color: var(--primary); font-size: 20px; }
    .settings-menu__label { flex: 1; min-width: 0; overflow-wrap: anywhere; }
    .settings-menu__title { display: block; font-size: 1rem; font-weight: 650; line-height: 1.4; }
    .settings-menu__description { display: block; margin-top: 4px; color: var(--text-secondary); font-size: .825rem; font-weight: 400; line-height: 1.5; }
    .settings-menu__chevron { flex-shrink: 0; color: var(--primary); transition: transform .2s ease; }
    .settings-menu__item[open] > .settings-menu__toggle .settings-menu__chevron { transform: rotate(90deg); }
    .settings-menu__body { min-width: 0; padding: 20px; border-top: 1px solid var(--border); overflow-wrap: anywhere; }
    .settings-menu__body .card { min-width: 0; }
    .settings-menu__body .d-flex { flex-wrap: wrap; gap: 8px; }
    .status-chip { display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; border-radius: 999px; font-size: 0.82rem; font-weight: 600; }
    .status-ok { background: #e7f7ee; color: #1e7a3a; border: 1px solid #bfe6cf; }
    .status-warn { background: #fdf3e3; color: #9a6210; border: 1px solid #f3d3a8; }
    .status-off { background: #fbeaea; color: #b3261e; border: 1px solid #f5c2c2; }
    @media (max-width: 575.98px) {
      .settings-menu__toggle { padding: 16px 12px; gap: 10px; }
      .settings-menu__icon { flex-basis: 38px; height: 38px; font-size: 18px; }
      .settings-menu__body { padding: 12px; }
      .settings-menu__body .card.p-4 { padding: 16px !important; }
    }
    @media (prefers-reduced-motion: reduce) {
      .settings-menu__chevron { transition: none; }
    }
  </style>
</head>
<body>
<div class="app-wrapper">
  <?php include __DIR__ . '/../../components/sidebar.php'; ?>
  <div class="main-content">
    <?php include __DIR__ . '/../../components/navbar.php'; ?>
    <div class="content-area page-enter">

      <!-- Header -->
      <div class="app-page-head d-flex align-items-center justify-content-between flex-wrap gap-2">
        <div>
          <h5 class="fw-bold text-dark mb-1 d-flex align-items-center gap-2">
            <i class="bi bi-gear-wide-connected text-success"></i> System Settings
          </h5>
          <p class="text-muted small mb-0">Institutional clinic configuration, security policies, integration status, and maintenance information.</p>
        </div>
      </div>

      <div class="settings-menu" id="settingsMenu">
        <?php if ($settingsAccess['general']): ?>
        <details class="settings-menu__item" name="admin-settings" <?= $activeTab === 'general' ? 'open' : '' ?>>
          <summary class="settings-menu__toggle" id="settingsHeading-general" aria-controls="settingsPanel-general">
              <span class="settings-menu__icon"><i class="bi bi-sliders" aria-hidden="true"></i></span>
              <span class="settings-menu__label"><span class="settings-menu__title">General Settings</span><span class="settings-menu__description">Institutional clinic configuration and system information.</span></span>
              <i class="bi bi-chevron-right settings-menu__chevron" aria-hidden="true"></i>
          </summary>
          <div id="settingsPanel-general" role="region" aria-labelledby="settingsHeading-general">
            <div class="settings-menu__body">
        <div class="row g-3">
          <div class="col-lg-8">
            <div class="card p-4 shadow-sm border-0 rounded-3">
              <h6 class="fw-bold text-dark mb-3"><i class="bi bi-building text-success me-1"></i> Institutional Clinic Configuration</h6>
              <form method="POST" action="settings.php?tab=general">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="update_general_settings">

                <div class="row g-3">
                  <div class="col-md-6">
                    <label class="form-label small fw-semibold">School Name</label>
                    <input type="text" class="form-control" name="school_name" value="<?= e($schoolName) ?>" required>
                  </div>
                  <div class="col-md-6">
                    <label class="form-label small fw-semibold">Clinic / Healthcare Unit</label>
                    <input type="text" class="form-control" name="clinic_name" value="<?= e($clinicName) ?>" required>
                  </div>
                  <div class="col-12">
                    <label class="form-label small fw-semibold">Institutional Campus Address</label>
                    <input type="text" class="form-control" name="school_address" value="<?= e($schoolAddress) ?>" required>
                  </div>
                  <div class="col-md-6">
                    <label class="form-label small fw-semibold">Clinic Contact Number / Hotline</label>
                    <input type="text" class="form-control" name="clinic_contact" value="<?= e($clinicContact) ?>" required>
                  </div>
                  <div class="col-md-6">
                    <label class="form-label small fw-semibold">Interface Default Theme</label>
                    <select class="form-select" name="theme_mode">
                      <option value="light" <?= $themeMode === 'light' ? 'selected' : '' ?>>Light Theme (Standard)</option>
                      <option value="dark" <?= $themeMode === 'dark' ? 'selected' : '' ?>>Dark Theme</option>
                    </select>
                  </div>
                </div>

                <div class="mt-4 pt-3 border-top">
                  <button type="submit" class="btn btn-success"><i class="bi bi-check-circle me-1"></i> Save Configuration</button>
                </div>
              </form>
            </div>
          </div>

          <div class="col-lg-4">
            <div class="card p-3 shadow-sm border-0 rounded-3">
              <h6 class="fw-bold text-dark mb-2"><i class="bi bi-info-circle text-primary me-1"></i> System Environment</h6>
              <div class="small">
                <div class="d-flex justify-content-between py-1 border-bottom">
                  <span class="text-muted">Application:</span>
                  <span class="fw-semibold text-dark"><?= APP_NAME ?></span>
                </div>
                <div class="d-flex justify-content-between py-1 border-bottom">
                  <span class="text-muted">Database Engine:</span>
                  <span class="fw-semibold text-dark">MySQL / MariaDB</span>
                </div>
                <div class="d-flex justify-content-between py-1 border-bottom">
                  <span class="text-muted">PHP Runtime:</span>
                  <span class="fw-semibold text-dark">PHP <?= phpversion() ?></span>
                </div>
                <div class="d-flex justify-content-between py-1 border-bottom">
                  <span class="text-muted">Environment:</span>
                  <span class="badge bg-success">Active Production / Localhost</span>
                </div>
              </div>
            </div>
          </div>
        </div>
            </div>
          </div>
        </details>
        <?php endif; ?>

        <?php if ($settingsAccess['personal']): ?>
        <details class="settings-menu__item" name="admin-settings" <?= $activeTab === 'personal' ? 'open' : '' ?>>
          <summary class="settings-menu__toggle" id="settingsHeading-personal" aria-controls="settingsPanel-personal">
            <span class="settings-menu__icon"><i class="bi bi-person-vcard" aria-hidden="true"></i></span>
            <span class="settings-menu__label"><span class="settings-menu__title">Personal Information</span><span class="settings-menu__description">Update your MIS administrator name, email address, and contact number.</span></span>
            <i class="bi bi-chevron-right settings-menu__chevron" aria-hidden="true"></i>
          </summary>
          <div id="settingsPanel-personal" role="region" aria-labelledby="settingsHeading-personal">
            <div class="settings-menu__body">
              <div class="card p-4 shadow-sm border-0 rounded-3">
                <h2 class="h6 fw-bold text-dark mb-3"><i class="bi bi-person-check text-success me-1"></i> Administrator Personal Information</h2>
                <form id="adminInfoForm" novalidate>
                  <div class="row g-3">
                    <div class="col-md-4"><label class="form-label small fw-semibold" for="admin-first-name">First Name</label><input id="admin-first-name" type="text" class="form-control" name="first_name" maxlength="60" required value="<?= e($userRecord['first_name'] ?? '') ?>"></div>
                    <div class="col-md-4"><label class="form-label small fw-semibold" for="admin-middle-name">Middle Name</label><input id="admin-middle-name" type="text" class="form-control" name="middle_name" maxlength="60" value="<?= e($userRecord['middle_name'] ?? '') ?>"></div>
                    <div class="col-md-4"><label class="form-label small fw-semibold" for="admin-last-name">Last Name</label><input id="admin-last-name" type="text" class="form-control" name="last_name" maxlength="60" required value="<?= e($userRecord['last_name'] ?? '') ?>"></div>
                    <div class="col-md-6"><label class="form-label small fw-semibold" for="admin-email">Email Address</label><input id="admin-email" type="email" class="form-control" name="email" maxlength="150" required value="<?= e($userRecord['email'] ?? '') ?>"></div>
                    <div class="col-md-6"><label class="form-label small fw-semibold" for="admin-phone">Contact Number</label><input id="admin-phone" type="tel" class="form-control" name="phone" maxlength="30" autocomplete="tel" value="<?= e($userRecord['phone'] ?? '') ?>"></div>
                  </div>
                  <div class="mt-4 pt-3 border-top"><button class="btn btn-success" type="submit"><i class="bi bi-check-circle me-1"></i>Save Personal Information</button></div>
                </form>
              </div>
            </div>
          </div>
        </details>
        <?php endif; ?>

        <?php if ($settingsAccess['password']): ?>
        <details class="settings-menu__item" name="admin-settings" <?= $activeTab === 'password' ? 'open' : '' ?>>
          <summary class="settings-menu__toggle" id="settingsHeading-password" aria-controls="settingsPanel-password">
            <span class="settings-menu__icon"><i class="bi bi-key" aria-hidden="true"></i></span>
            <span class="settings-menu__label"><span class="settings-menu__title">Password &amp; Security</span><span class="settings-menu__description">Change your own password after verifying the current password.</span></span>
            <i class="bi bi-chevron-right settings-menu__chevron" aria-hidden="true"></i>
          </summary>
          <div id="settingsPanel-password" role="region" aria-labelledby="settingsHeading-password">
            <div class="settings-menu__body">
              <div class="card p-4 shadow-sm border-0 rounded-3">
                <h2 class="h6 fw-bold text-dark mb-2"><i class="bi bi-shield-lock text-success me-1"></i> Password Settings</h2>
                <p class="small text-muted mb-3">Use at least 8 characters with uppercase, lowercase, a number, and a special character.</p>
                <form id="adminPasswordForm" novalidate>
                  <div class="row g-3">
                    <div class="col-12"><label class="form-label small fw-semibold" for="admin-current-password">Current Password</label><input id="admin-current-password" type="password" class="form-control" name="current_password" autocomplete="current-password" required></div>
                    <div class="col-md-6"><label class="form-label small fw-semibold" for="admin-new-password">New Password</label><input id="admin-new-password" type="password" class="form-control" name="new_password" autocomplete="new-password" minlength="8" maxlength="72" required></div>
                    <div class="col-md-6"><label class="form-label small fw-semibold" for="admin-confirm-password">Confirm New Password</label><input id="admin-confirm-password" type="password" class="form-control" name="confirm_password" autocomplete="new-password" minlength="8" maxlength="72" required></div>
                  </div>
                  <div class="mt-4 pt-3 border-top"><button class="btn btn-outline-success" type="submit"><i class="bi bi-key me-1"></i>Update Password</button></div>
                </form>
              </div>
            </div>
          </div>
        </details>
        <?php endif; ?>

        <?php if ($settingsAccess['policies']): ?>
        <details class="settings-menu__item" name="admin-settings" <?= $activeTab === 'policies' ? 'open' : '' ?>>
          <summary class="settings-menu__toggle" id="settingsHeading-policies" aria-controls="settingsPanel-policies">
              <span class="settings-menu__icon"><i class="bi bi-shield-lock-fill" aria-hidden="true"></i></span>
              <span class="settings-menu__label"><span class="settings-menu__title">Security Policies</span><span class="settings-menu__description">Session expiration, login attempt limits, and password requirements.</span></span>
              <i class="bi bi-chevron-right settings-menu__chevron" aria-hidden="true"></i>
          </summary>
          <div id="settingsPanel-policies" role="region" aria-labelledby="settingsHeading-policies">
            <div class="settings-menu__body">
              <div class="card p-4 shadow-sm border-0 rounded-3">
                <h6 class="fw-bold text-dark mb-3"><i class="bi bi-shield-check text-success me-1"></i> Security Policy Configuration</h6>
                <p class="text-muted small mb-4">These values configure authentication and session behavior. Changes apply to new sessions; active sessions retain their current policy until renewed.</p>
                <form method="POST" action="settings.php?tab=policies">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="update_security_policies">
                  <div class="row g-3">
                    <div class="col-md-3">
                      <label class="form-label small fw-semibold">Session Timeout (seconds)</label>
                      <input type="number" class="form-control" name="session_timeout" value="<?= e($sessionTimeout) ?>" min="300" max="86400" required>
                    </div>
                    <div class="col-md-3">
                      <label class="form-label small fw-semibold">Max Failed Login Attempts</label>
                      <input type="number" class="form-control" name="max_login_attempts" value="<?= e($loginAttempts) ?>" min="3" max="20" required>
                    </div>
                    <div class="col-md-3">
                      <label class="form-label small fw-semibold">Lockout Duration (minutes)</label>
                      <input type="number" class="form-control" name="lockout_minutes" value="<?= e($lockoutMinutes) ?>" min="5" max="1440" required>
                    </div>
                    <div class="col-md-3">
                      <label class="form-label small fw-semibold">Minimum Password Length</label>
                      <input type="number" class="form-control" name="password_min_length" value="<?= e($passwordMinLen) ?>" min="6" max="64" required>
                    </div>
                  </div>
                  <div class="mt-4 pt-3 border-top">
                    <button type="submit" class="btn btn-success"><i class="bi bi-check-circle me-1"></i> Save Security Policies</button>
                  </div>
                </form>
              </div>
            </div>
          </div>
        </details>
        <?php endif; ?>

        <?php if ($settingsAccess['integrations']): ?>
        <details class="settings-menu__item" name="admin-settings" <?= $activeTab === 'integrations' ? 'open' : '' ?>>
          <summary class="settings-menu__toggle" id="settingsHeading-integrations" aria-controls="settingsPanel-integrations">
              <span class="settings-menu__icon"><i class="bi bi-plug" aria-hidden="true"></i></span>
              <span class="settings-menu__label"><span class="settings-menu__title">Integration Configuration</span><span class="settings-menu__description">Status of connected services and system integrations.</span></span>
              <i class="bi bi-chevron-right settings-menu__chevron" aria-hidden="true"></i>
          </summary>
          <div id="settingsPanel-integrations" role="region" aria-labelledby="settingsHeading-integrations">
            <div class="settings-menu__body">
              <div class="card p-4 shadow-sm border-0 rounded-3">
                <h6 class="fw-bold text-dark mb-3"><i class="bi bi-plug text-success me-1"></i> Service Integration Status</h6>
                <p class="text-muted small mb-4">Read-only status indicators. No credentials or secrets are displayed here.</p>
                <div class="row g-3">
                  <div class="col-md-4">
                    <div class="card p-3 shadow-sm border-0 rounded-3 h-100">
                      <div class="d-flex align-items-center gap-2 mb-2">
                        <span class="status-chip <?= $integrationStatus['email'] === 'configured' ? 'status-ok' : 'status-off' ?>">
                          <span class="status-chip__dot"></span> Email Service
                        </span>
                      </div>
                      <p class="small text-muted mb-0">Registration OTP delivery and notification email transport.</p>
                    </div>
                  </div>
                  <div class="col-md-4">
                    <div class="card p-3 shadow-sm border-0 rounded-3 h-100">
                      <div class="d-flex align-items-center gap-2 mb-2">
                        <span class="status-chip <?= $integrationStatus['micai'] === 'available' ? 'status-ok' : 'status-off' ?>">
                          <span class="status-chip__dot"></span> MICA Intelligence
                        </span>
                      </div>
                      <p class="small text-muted mb-0">Security monitoring, alert reconciliation, and account verification intelligence.</p>
                    </div>
                  </div>
                  <div class="col-md-4">
                    <div class="card p-3 shadow-sm border-0 rounded-3 h-100">
                      <div class="d-flex align-items-center gap-2 mb-2">
                        <span class="status-chip <?= $integrationStatus['qr'] === 'available' ? 'status-ok' : 'status-off' ?>">
                          <span class="status-chip__dot"></span> QR Code System
                        </span>
                      </div>
                      <p class="small text-muted mb-0">Student and teacher clinic check-in QR generation and verification.</p>
                    </div>
                  </div>
                  <div class="col-md-4">
                    <div class="card p-3 shadow-sm border-0 rounded-3 h-100">
                      <div class="d-flex align-items-center gap-2 mb-2">
                        <span class="status-chip <?= $integrationStatus['pwa'] === 'available' ? 'status-ok' : 'status-off' ?>">
                          <span class="status-chip__dot"></span> PWA / Offline Support
                        </span>
                      </div>
                      <p class="small text-muted mb-0">Progressive Web App manifest and service worker availability.</p>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </details>
        <?php endif; ?>

        <?php if ($settingsAccess['maintenance']): ?>
        <details class="settings-menu__item" name="admin-settings" <?= $activeTab === 'maintenance' ? 'open' : '' ?>>
          <summary class="settings-menu__toggle" id="settingsHeading-maintenance" aria-controls="settingsPanel-maintenance">
              <span class="settings-menu__icon"><i class="bi bi-database" aria-hidden="true"></i></span>
              <span class="settings-menu__label"><span class="settings-menu__title">Maintenance Configuration</span><span class="settings-menu__description">Backup status and system maintenance information.</span></span>
              <i class="bi bi-chevron-right settings-menu__chevron" aria-hidden="true"></i>
          </summary>
          <div id="settingsPanel-maintenance" role="region" aria-labelledby="settingsHeading-maintenance">
            <div class="settings-menu__body">
              <div class="card p-4 shadow-sm border-0 rounded-3">
                <h6 class="fw-bold text-dark mb-3"><i class="bi bi-database text-success me-1"></i> Backup &amp; Maintenance</h6>
                <div class="row g-3">
                  <div class="col-md-6">
                    <div class="dbg-stat p-3 shadow-sm border-0 rounded-3">
                      <div class="text-muted small fw-semibold text-uppercase">Last Backup</div>
                      <h3 class="fw-bold text-dark mt-2 mb-1">
                        <?= $lastBackup ? e($lastBackup['filename'] ?? '—') : '—' ?>
                      </h3>
                      <div class="small text-muted">
                        <?= $lastBackup ? e($lastBackup['status']) : 'No backup recorded.' ?>
                        <?= $lastBackup && !empty($lastBackup['created_at']) ? ' · ' . e(format_datetime($lastBackup['created_at'])) : '' ?>
                      </div>
                    </div>
                  </div>
                  <div class="col-md-6">
                    <div class="alert alert-info small border-0 shadow-sm">
                      <i class="bi bi-info-circle me-1"></i>
                      Backup creation and restoration are managed through the Security Center. Configuration values here are read-only.
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </details>
        <?php endif; ?>
      </div>

    </div>
  </div>
</div>
<?php include __DIR__ . '/../../components/footer.php'; ?>
<script>
(function () {
  'use strict';
  const token = document.querySelector('meta[name="csrf-token"]')?.content || '';

  async function submitAdminSettings(form, action) {
    if (form.dataset.saving === 'true') return;
    if (!form.checkValidity()) { form.reportValidity(); return; }
    if (action === 'change_password') {
      const next = form.elements.new_password.value;
      const confirmation = form.elements.confirm_password.value;
      if (next !== confirmation) { toastError('New passwords do not match.'); form.elements.confirm_password.focus(); return; }
    }
    const button = form.querySelector('[type="submit"]');
    const original = button.innerHTML;
    form.dataset.saving = 'true';
    button.disabled = true;
    button.innerHTML = '<span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>Saving…';
    try {
      const data = new FormData(form);
      data.append('action', action);
      data.append('csrf_token', token);
      const response = await fetch(APP_URL + '/api/profile.php', {method:'POST', credentials:'same-origin', headers:{'Accept':'application/json'}, body:data});
      const result = await response.json();
      if (!response.ok || !result.success) throw new Error(result.message || 'Unable to save changes.');
      toastSuccess(result.message);
      if (action === 'change_password') form.reset();
      else setTimeout(function () { window.location.href = 'settings.php?tab=personal'; }, 700);
    } catch (error) {
      toastError(error.message || 'Unable to save changes. Please try again.');
    } finally {
      delete form.dataset.saving;
      button.disabled = false;
      button.innerHTML = original;
    }
  }

  const infoForm = document.getElementById('adminInfoForm');
  if (infoForm) infoForm.addEventListener('submit', function (event) { event.preventDefault(); submitAdminSettings(infoForm, 'update_info'); });
  const passwordForm = document.getElementById('adminPasswordForm');
  if (passwordForm) passwordForm.addEventListener('submit', function (event) { event.preventDefault(); submitAdminSettings(passwordForm, 'change_password'); });
})();
</script>
</body>
</html>
