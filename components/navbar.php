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
$navRoleDir = in_array($u['role'] ?? '', ['admin', 'nurse'], true) ? $u['role'] : 'student';
?>
<!-- Page Loader Overlay -->
<div class="page-loader">
  <div class="loader-icon"><i class="bi bi-hospital"></i></div>
</div>

<div class="topbar">
  <div class="topbar-left">
    <!-- Brand (hidden on mobile — sidebar shows brand) -->
    <div class="topbar-brand">
      <div class="topbar-brand-img">
        <img src="<?= APP_URL ?>/assets/images/bpc_logo.png" alt="BPC Logo">
      </div>
      <div class="topbar-brand-text">
        <span class="topbar-brand-title">BPC School Clinic</span>
        <span class="topbar-brand-sub">Health Monitoring & Appointment System</span>
      </div>
    </div>
    <!-- Mobile Toggle -->
    <button id="sidebarToggle" class="btn btn-secondary btn-icon d-xl-none">
      <i class="bi bi-list"></i>
    </button>
    <div>
      <div class="page-title"><?= e($pageTitle ?? 'Dashboard') ?></div>
      <div class="welcome-text">Welcome back, <?= e($u['first_name']) ?>! <span class="current-date">· <?= date('l, F j, Y') ?></span></div>
    </div>
  </div>
  <div class="topbar-right">
    <!-- Real-time Clock -->
    <div class="topbar-clock" title="Current Date & Time">
      <i class="bi bi-clock"></i>
      <span class="topbar-clock-time">--:--:--</span>
      <span class="topbar-clock-date ms-1" style="font-weight:400;opacity:0.7;"><?= date('M d, Y') ?></span>
    </div>

    <!-- MICA Status Indicator (opens the SINGLE consolidated AI assistant page) -->
    <a class="mica-status-indicator" href="<?= APP_URL ?>/pages/ai_assistant.php" title="Open MICA Assistant Page">
      <span class="mica-dot"></span>
      MICA Online
    </a>

    <!-- Dark Mode Toggle -->
    <button class="topbar-btn dark-toggle" id="darkModeToggle" title="Toggle Dark Mode">
      <i class="bi bi-moon-stars"></i>
    </button>

    <!-- Notifications -->
    <div class="dropdown">
      <button class="topbar-btn position-relative" data-bs-toggle="dropdown" aria-label="Notifications">
        <i class="bi bi-bell"></i>
        <span id="notifBadge" class="notif-badge d-none">0</span>
      </button>
      <ul class="dropdown-menu dropdown-menu-end shadow" style="width:340px; max-height:420px; overflow-y:auto;" id="notifList">
        <li class="dropdown-item text-muted small">Loading...</li>
      </ul>
      <script>window.NOTIFICATION_PAGE_URL = "<?= APP_URL ?>/pages/<?= $navRoleDir ?>/notifications.php";</script>
    </div>

    <!-- Profile -->
    <div class="dropdown">
      <button class="profile-trigger" data-bs-toggle="dropdown">
        <?php $profileImage = trim((string)($u['profile_picture'] ?? '')); ?>
        <img src="<?= e($profileImage !== '' ? app_url($profileImage) : asset_url('images/default-avatar.svg')) ?>" onerror="this.src='https://via.placeholder.com/32/0B6B3A/ffffff?text=U'" class="avatar" alt="Profile">
        <span class="name d-none d-md-inline"><?= e($u['first_name'] . ' ' . $u['last_name']) ?></span>
        <span class="role-badge bg-primary-light text-primary d-none d-lg-inline"><?= e(ucfirst($u['role'])) ?></span>
      </button>
      <ul class="dropdown-menu dropdown-menu-end shadow">
        <li><a class="dropdown-item" href="<?= APP_URL ?>/pages/<?= $navRoleDir ?>/profile.php"><i class="bi bi-person me-2"></i>My Profile</a></li>
        <li><a class="dropdown-item" href="<?= APP_URL ?>/pages/<?= $navRoleDir ?>/notifications.php"><i class="bi bi-bell me-2"></i>Notifications</a></li>
        <?php if ($u['role'] !== 'student'): ?>
          <li><a class="dropdown-item" href="<?= APP_URL ?>/pages/<?= $navRoleDir ?>/reports.php"><i class="bi bi-file-earmark-bar-graph me-2"></i>Reports</a></li>
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
<div class="modal fade" id="sosDetailsModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
  <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content border-0 rounded-4 shadow-lg">
      <div class="modal-header border-0 bg-danger text-white rounded-top-4">
        <h5 class="modal-title"><i class="bi bi-exclamation-triangle-fill me-2"></i>SOS Emergency Details</h5>
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



