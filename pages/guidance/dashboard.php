<?php
require_once __DIR__ . '/../../config/config.php';
require_role('guidance');
$db = getDB();
$u = current_user();
$pageTitle = 'Guidance Dashboard';
?>
<!DOCTYPE html>
<html lang="en">
<head><?php include __DIR__ . '/../../includes/head.php'; ?></head>
<body>
<div class="app-wrapper">
  <?php include __DIR__ . '/../../components/sidebar.php'; ?>
  <div class="main-content">
    <?php include __DIR__ . '/../../components/navbar.php'; ?>
    <div class="content-area page-enter">

      <!-- Welcome Card — Guidance & Student Support -->
      <div class="welcome-card" data-aos="fade-down" data-aos-duration="600">
        <div class="welcome-content">
          <div class="clinic-status open"><span class="status-dot"></span> Guidance &amp; Counseling Station</div>
          <h1>Welcome, <?= e($u['first_name']) ?>! 👋</h1>
          <div class="welcome-sub">Student Well-being &amp; Support Portal</div>
          <div class="welcome-desc">Confidential guidance platform for Bulacan Polytechnic College. Coordinate student referrals, review support preferences, and monitor counseling cases.</div>
          <div class="welcome-actions">
            <a href="referrals.php" class="btn btn-white"><i class="bi bi-send-fill me-1"></i> Support Referrals</a>
            <a href="wellbeing.php" class="btn btn-outline-white"><i class="bi bi-person-heart me-1"></i> Student Well-being</a>
            <a href="clinic.php" class="btn btn-outline-white"><i class="bi bi-hospital me-1"></i> Clinic Info</a>
          </div>
        </div>
        <div class="welcome-visual">
          <div class="welcome-icon"><i class="bi bi-heart-pulse"></i></div>
        </div>
        <!-- Subtle ECG line -->
        <div class="ecg-line" style="position:absolute;bottom:0;left:0;right:0;opacity:0.06;">
          <svg viewBox="0 0 400 40" preserveAspectRatio="none">
            <path class="ecg-path" d="M0,20 L50,20 L60,8 L70,32 L80,20 L130,20 L140,10 L145,20 L150,20 L200,20 L210,12 L220,28 L230,20 L280,20 L290,15 L295,20 L300,20 L350,20 L360,18 L370,22 L380,20 L400,20" stroke="white" stroke-width="2" fill="none"/>
          </svg>
        </div>
      </div>

      <!-- Medical Stat Cards -->
      <div class="row g-3 mb-4" data-aos="fade-up" data-aos-delay="100">
        <div class="col-6 col-md-3">
          <div class="medical-stat-card gradient-green">
            <div class="msc-top">
              <div class="msc-icon"><i class="bi bi-inboxes-fill"></i></div>
              <div class="msc-value" id="statPending">—</div>
            </div>
            <div>
              <div class="msc-label">Pending Referrals</div>
              <div class="msc-trend up"><i class="bi bi-hourglass-split"></i> Awaiting review</div>
            </div>
          </div>
        </div>
        <div class="col-6 col-md-3">
          <div class="medical-stat-card gradient-blue">
            <div class="msc-top">
              <div class="msc-icon"><i class="bi bi-person-heart"></i></div>
              <div class="msc-value" id="statSupport">—</div>
            </div>
            <div>
              <div class="msc-label">In Support</div>
              <div class="msc-trend up"><i class="bi bi-shield-check"></i> Active counseling</div>
            </div>
          </div>
        </div>
        <div class="col-6 col-md-3">
          <div class="medical-stat-card gradient-orange">
            <div class="msc-top">
              <div class="msc-icon"><i class="bi bi-clock-history"></i></div>
              <div class="msc-value" id="statFollowup">—</div>
            </div>
            <div>
              <div class="msc-label">Follow-up Required</div>
              <div class="msc-trend down"><i class="bi bi-arrow-repeat"></i> Action needed</div>
            </div>
          </div>
        </div>
        <div class="col-6 col-md-3">
          <div class="medical-stat-card gradient-teal">
            <div class="msc-top">
              <div class="msc-icon"><i class="bi bi-check2-circle"></i></div>
              <div class="msc-value" id="statClosed">—</div>
            </div>
            <div>
              <div class="msc-label">Closed Cases</div>
              <div class="msc-trend up"><i class="bi bi-check-circle-fill"></i> Resolved</div>
            </div>
          </div>
        </div>
      </div>

      <!-- Quick Actions -->
      <h6 class="section-title mb-3" data-aos="fade-up"><i class="bi bi-lightning-fill text-warning me-1"></i> Quick Actions</h6>
      <div class="quick-actions-grid mb-4" data-aos="fade-up" data-aos-delay="50">
        <a href="referrals.php" class="quick-action-card-lg text-decoration-none">
          <div class="qa-icon-lg"><i class="bi bi-send-fill text-success"></i></div>
          <div class="qa-title-lg">Support Referrals</div>
          <div class="qa-sub-lg">Manage student referrals</div>
        </a>
        <a href="wellbeing.php" class="quick-action-card-lg text-decoration-none">
          <div class="qa-icon-lg"><i class="bi bi-person-heart text-info"></i></div>
          <div class="qa-title-lg">Student Well-being</div>
          <div class="qa-sub-lg">Confidential mental &amp; emotional records</div>
        </a>
        <a href="clinic.php" class="quick-action-card-lg text-decoration-none">
          <div class="qa-icon-lg"><i class="bi bi-hospital-fill text-primary"></i></div>
          <div class="qa-title-lg">Clinic Information</div>
          <div class="qa-sub-lg">View clinic operating info</div>
        </a>
        <a href="announcements.php" class="quick-action-card-lg text-decoration-none">
          <div class="qa-icon-lg"><i class="bi bi-megaphone-fill text-warning"></i></div>
          <div class="qa-title-lg">Announcements</div>
          <div class="qa-sub-lg">Read clinic notices</div>
        </a>
        <a href="notifications.php" class="quick-action-card-lg text-decoration-none">
          <div class="qa-icon-lg"><i class="bi bi-bell-fill text-info"></i></div>
          <div class="qa-title-lg">Notifications</div>
          <div class="qa-sub-lg">View system alerts</div>
        </a>
        <a href="profile.php" class="quick-action-card-lg text-decoration-none">
          <div class="qa-icon-lg"><i class="bi bi-person-circle text-secondary"></i></div>
          <div class="qa-title-lg">My Profile</div>
          <div class="qa-sub-lg">Manage account settings</div>
        </a>
        <a href="<?= APP_URL ?>/pages/ai_assistant.php" class="quick-action-card-lg text-decoration-none">
          <div class="qa-icon-lg"><i class="bi bi-robot text-success"></i></div>
          <div class="qa-title-lg">Ask MICA</div>
          <div class="qa-sub-lg">AI Health Assistant</div>
        </a>
      </div>

      <div class="card p-3 shadow-sm rounded-4 border-0" data-aos="fade-up" data-aos-delay="100">
        <div class="d-flex justify-content-between align-items-center mb-3">
          <h6 class="section-title mb-0"><i class="bi bi-list-ul text-success me-1"></i> Assigned Referrals</h6>
          <a href="referrals.php" class="btn btn-sm btn-success"><i class="bi bi-arrow-right me-1"></i> Manage Referrals</a>
        </div>
        <div class="table-responsive">
          <table class="table table-hover align-middle mb-0" id="refTable" style="width:100%">
            <thead><tr><th>Student</th><th>Referred To</th><th>Priority</th><th>Status</th><th>Created</th></tr></thead>
            <tbody></tbody>
          </table>
        </div>
      </div>

    </div>
  </div>
</div>
<?php include __DIR__ . '/../../components/footer.php'; ?>
<script>
$(function () {
  $.get(APP_URL + '/api/referrals.php', { action: 'list' }, function (res) {
    if (!res.success) return;
    const data = res.data || [];
    const counts = { pending: 0, accepted: 0, in_support: 0, follow_up_required: 0, closed: 0 };
    data.forEach(r => { if (counts[r.status] !== undefined) counts[r.status]++; });
    $('#statPending').text(counts.pending);
    $('#statSupport').text((counts.in_support || 0) + (counts.accepted || 0));
    $('#statFollowup').text(counts.follow_up_required);
    $('#statClosed').text(counts.closed);

    const rows = data.map(r => [
      (r.first_name ? (r.first_name + ' ' + r.last_name) : 'Student #' + r.student_id) + '<div class="small text-muted">' + (r.student_number || '') + '</div>',
      r.referred_to,
      '<span class="badge bg-' + (r.priority === 'urgent' ? 'danger' : r.priority === 'soon' ? 'warning text-dark' : 'secondary') + ' text-capitalize">' + r.priority + '</span>',
      statusBadge(r.status),
      r.created_at ? r.created_at.replace(' ', ' · ') : '—'
    ]);
    if (window.__gdDt) window.__gdDt.destroy();
    window.__gdDt = $('#refTable').DataTable({ data: rows, columns: [{},{},{},{},{}], destroy: true, pageLength: 10 });
  }, 'json');

  function statusBadge(s) {
    const map = {
      pending: ['secondary', 'Pending'],
      accepted: ['info', 'Accepted'],
      in_support: ['primary', 'In Support'],
      follow_up_required: ['warning text-dark', 'Follow-up'],
      closed: ['success', 'Closed']
    };
    const b = map[s] || ['secondary', s];
    return '<span class="badge bg-' + b[0] + '">' + b[1] + '</span>';
  }
});
</script>
</body>
</html>
