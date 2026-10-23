<?php
/**
 * components/navbar.php
 * ------------------------------------------------------------------
 * SINGLE navbar (topbar) for every authenticated page.
 * Replaces includes/topbar.php — provides brand, clock, MICA status,
 * dark-mode toggle, notifications dropdown, and profile menu.
 * ------------------------------------------------------------------
 */
$u = current_user();
// Role directory used for absolute links (works on every page, including
// the consolidated pages/ai_assistant.php at the pages/ root).
$uRole = $u['role'] ?? 'student';
$navDoctor = $uRole === 'admin' && permission_profile($u) === 'doctor';
$navRoleDir = ($uRole === 'teacher' || $uRole === 'adviser') ? 'adviser' : (in_array($uRole, ['admin', 'nurse', 'student'], true) ? $uRole : 'student');
?>
<!-- Page Loader Overlay -->
<div class="page-loader">
  <div class="loader-icon"><i class="bi bi-hospital"></i></div>
</div>

<div class="topbar">
  <div class="topbar-left">
    <!-- Mobile Toggle -->
    <button id="sidebarToggle" class="btn btn-secondary btn-icon d-xl-none" aria-label="Toggle sidebar" title="Toggle sidebar">
      <i class="bi bi-list"></i>
    </button>
    <div class="topbar-headings">
      <h1 class="page-title"><?= e($pageTitle ?? 'Dashboard') ?></h1>
      <div class="welcome-text">Welcome back, <?= e($u['first_name']) ?>!</div>
    </div>
  </div>
  <div class="topbar-right">
    <?php if ($uRole === 'admin' && admin_can_view_emergencies()): ?>
      <a class="topbar-btn" href="<?= APP_URL ?>/pages/admin/emergencies.php" title="Emergency Management" aria-label="Open Emergency Management" style="color:#a10e22"><i class="bi bi-shield-exclamation" aria-hidden="true"></i></a>
    <?php elseif ($uRole === 'nurse'): ?>
      <button type="button" class="topbar-btn position-relative" id="emergencyShieldBtn" onclick="document.getElementById('globalSosIndicator')?.click()" title="Emergency Alerts" aria-label="Emergency Alerts" style="color:#a10e22">
        <i class="bi bi-shield-exclamation" aria-hidden="true"></i>
        <span id="emergencyBadge" class="emergency-badge d-none">0</span>
      </button>
    <?php elseif ($uRole === 'student'): ?>
      <button type="button" class="topbar-btn" data-action="open-sos" title="Emergency SOS" aria-label="Open Emergency SOS" style="color:#a10e22"><i class="bi bi-shield-exclamation" aria-hidden="true"></i></button>
    <?php elseif ($uRole === 'teacher' || $uRole === 'adviser'): ?>
      <a class="topbar-btn" href="<?= APP_URL ?>/pages/adviser/emergencies.php?report=1" title="Report Student Emergency" aria-label="Open teacher emergency reporting" style="color:#a10e22"><i class="bi bi-shield-exclamation" aria-hidden="true"></i></a>
    <?php endif; ?>
    <!-- Real-time Clock -->
    <div class="topbar-clock" title="Current Date & Time">
      <i class="bi bi-clock"></i>
      <span class="topbar-clock-time">--:--:--</span>
      <span class="topbar-clock-date ms-1"><?= format_datetime('now', 'M d, Y') ?></span>
    </div>

    <!-- MICA Status Indicator (opens the SINGLE consolidated AI assistant page) -->
    <a class="mica-status-indicator" href="<?= APP_URL ?>/pages/ai_assistant.php" title="Open MICA Assistant Page">
      <span class="mica-dot"></span>
      MICA Online
    </a>

    <!-- Dark Mode Toggle -->
    <button class="topbar-btn dark-toggle" id="darkModeToggle" title="Toggle Dark Mode" aria-label="Toggle dark mode">
      <i class="bi bi-moon-stars"></i>
    </button>

    <!-- Notifications -->
    <div class="dropdown">
      <button type="button" id="notificationBell" class="topbar-btn position-relative" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" aria-controls="notificationDropdown" aria-label="Notifications">
        <i class="bi bi-bell"></i>
        <span id="notifBadge" class="notif-badge d-none">0</span>
      </button>
      <div class="dropdown-menu dropdown-menu-end shadow topbar-notification-menu" id="notificationDropdown" aria-labelledby="notificationBell">
        <div class="notification-toolbar"><strong>Notifications</strong><button type="button" id="markAllNotificationsRead" class="btn btn-sm btn-link">Mark All as Read</button></div>
        <div id="notificationActionStatus" class="small px-3" role="status" aria-live="polite"></div>
        <ul id="notifList" class="list-unstyled m-0"><li class="dropdown-item text-muted small">Loading...</li></ul>
        <div class="notification-footer"><a href="<?= APP_URL ?>/pages/<?= $navRoleDir ?>/notifications.php">View All Notifications</a><a href="<?= APP_URL ?>/pages/<?= $navRoleDir ?>/announcements.php">Announcements</a></div>
      </div>
      <script>window.NOTIFICATION_PAGE_URL = "<?= APP_URL ?>/pages/<?= $navRoleDir ?>/notifications.php";</script>
    </div>

    <!-- Profile -->
    <div class="dropdown">
      <button class="profile-trigger" data-bs-toggle="dropdown">
        <?php $profileImage = trim((string)($u['profile_picture'] ?? '')); ?>
        <img src="<?= e($profileImage !== '' ? app_url($profileImage) : asset_url('images/default-avatar.svg')) ?>" onerror="this.onerror=null;this.src='<?= e(asset_url('images/default-avatar.svg')) ?>'" class="avatar" alt="Profile">
        <span class="name d-none d-md-inline"><?= e($u['first_name'] . ' ' . $u['last_name']) ?></span>
        <span class="role-badge bg-primary-light text-primary d-none d-lg-inline"><?= e($navDoctor ? 'Doctor / Clinical Co-Administrator' : get_role_display_name($u['role'])) ?></span>
      </button>
      <ul class="dropdown-menu dropdown-menu-end shadow">
        <li><a class="dropdown-item" href="<?= APP_URL ?>/pages/<?= $navDoctor ? 'doctor' : $navRoleDir ?>/profile.php"><i class="bi bi-person me-2"></i>My Profile</a></li>
        <?php if($navDoctor): ?><li><a class="dropdown-item" href="<?= APP_URL ?>/pages/doctor/settings.php"><i class="bi bi-gear me-2"></i>Personal settings</a></li><?php endif; ?>
        <?php if ($uRole === 'admin'): ?>
          <li><a class="dropdown-item" href="<?= APP_URL ?>/pages/<?= $navRoleDir ?>/emergencies.php"><i class="bi bi-shield-exclamation me-2"></i>Emergency Oversight</a></li>
        <?php endif; ?>
        <?php if ($uRole === 'nurse'): ?>
          <li><a class="dropdown-item" href="<?= APP_URL ?>/pages/nurse/settings.php"><i class="bi bi-gear me-2"></i>Settings</a></li>
        <?php endif; ?>
        <?php if ($uRole === 'teacher' || $uRole === 'adviser'): ?>
          <li><a class="dropdown-item" href="<?= APP_URL ?>/pages/adviser/settings.php"><i class="bi bi-gear me-2"></i>Settings</a></li>
        <?php endif; ?>
        <li><hr class="dropdown-divider"></li>
        <li><a class="dropdown-item text-danger logout-link" href="#" data-logout-url="<?= APP_URL ?>/auth/logout.php"><i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
      </ul>
    </div>
  </div>
</div>

<!-- ================================================================ -->
<!-- SOS Emergency Details Modal (global — populated by JS on click)  -->
<!-- ================================================================ -->
<div class="modal fade" id="sosDetailsModal" tabindex="-1" aria-hidden="true" aria-labelledby="sosDetailsModalTitle" data-bs-backdrop="static">
  <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header modal-header--danger">
      <h5 class="modal-title" id="sosDetailsModalTitle"><?= bpc_icon('alert-triangle') ?> SOS Emergency Details</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body" id="sosDetailsBody">
        <div class="text-center text-muted py-4">Loading...</div>
      </div>
      <div class="modal-footer border-0 justify-content-center gap-2" id="sosDetailsFooter" style="display:none;">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
        <button type="button" class="btn btn-success px-4" id="sosAcceptBtn">
          <i class="bi bi-check-circle me-1"></i> Accept SOS
        </button>
        <button type="button" class="btn btn-outline-success px-4" id="sosResolveBtn">
          <i class="bi bi-check2-all me-1"></i> Resolved
        </button>
      </div>
    </div>
  </div>
</div>



