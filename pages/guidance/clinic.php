<?php
require_once __DIR__ . '/../../config/config.php';
require_role('guidance');
$db = getDB();
$u = current_user();

// Active Support Referrals count
$pendingRefs = 0;
$inSupportRefs = 0;
try {
    $stmt = $db->query("SELECT COUNT(*) FROM support_referrals WHERE status IN ('pending','accepted')");
    $pendingRefs = (int)$stmt->fetchColumn();

    $stmt2 = $db->query("SELECT COUNT(*) FROM support_referrals WHERE status = 'in_support'");
    $inSupportRefs = (int)$stmt2->fetchColumn();
} catch (Exception $e) {
    error_log('Clinic page referral query error: ' . $e->getMessage());
}

$pageTitle = 'Clinic Information';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php include __DIR__ . '/../../includes/head.php'; ?>
</head>
<body>
<div class="app-wrapper">
  <?php include __DIR__ . '/../../components/sidebar.php'; ?>
  <div class="main-content">
    <?php include __DIR__ . '/../../components/navbar.php'; ?>
    <div class="content-area page-enter">

      <!-- Clinic Welcome & Operating Info Card -->
      <div class="welcome-card mb-4" data-aos="fade-down" data-aos-duration="600">
        <div class="welcome-content">
          <div class="clinic-status open"><span class="status-dot"></span> BPC Clinic Station — Open Today</div>
          <h1>BPC Healthcare Services &amp; Guidance Coordination</h1>
          <div class="welcome-sub">Bulacan Polytechnic College Health &amp; Well-being Center</div>
          <div class="welcome-desc">Integrated healthcare services, emergency response, and student guidance support for Bulacan Polytechnic College.</div>
          <div class="welcome-actions">
            <a href="referrals.php" class="btn btn-white"><i class="bi bi-send-fill me-1"></i> Support Referrals (<?= $pendingRefs + $inSupportRefs ?>)</a>
            <a href="announcements.php" class="btn btn-outline-white"><i class="bi bi-megaphone-fill me-1"></i> Announcements</a>
          </div>
        </div>
        <div class="welcome-visual">
          <div class="welcome-icon"><i class="bi bi-hospital"></i></div>
        </div>
      </div>

      <!-- Quick Info Grid -->
      <div class="row g-3 mb-4">
        <div class="col-md-6 col-xl-3">
          <div class="card p-3 h-100 shadow-sm">
            <div class="d-flex align-items-center gap-3">
              <div class="p-2 bg-success-light text-success rounded-circle fs-4 d-flex align-items-center justify-content-center" style="width:48px;height:48px;">
                <i class="bi bi-clock-fill"></i>
              </div>
              <div>
                <div class="text-muted small fw-medium">Clinic Operating Hours</div>
                <div class="fw-bold text-dark">Mon – Fri: 8:00 AM – 5:00 PM</div>
              </div>
            </div>
          </div>
        </div>
        <div class="col-md-6 col-xl-3">
          <div class="card p-3 h-100 shadow-sm">
            <div class="d-flex align-items-center gap-3">
              <div class="p-2 bg-primary-light text-primary rounded-circle fs-4 d-flex align-items-center justify-content-center" style="width:48px;height:48px;">
                <i class="bi bi-geo-alt-fill"></i>
              </div>
              <div>
                <div class="text-muted small fw-medium">Clinic Station Location</div>
                <div class="fw-bold text-dark">BPC Main Building, Ground Floor</div>
              </div>
            </div>
          </div>
        </div>
        <div class="col-md-6 col-xl-3">
          <div class="card p-3 h-100 shadow-sm">
            <div class="d-flex align-items-center gap-3">
              <div class="p-2 bg-info-light text-info rounded-circle fs-4 d-flex align-items-center justify-content-center" style="width:48px;height:48px;">
                <i class="bi bi-telephone-fill"></i>
              </div>
              <div>
                <div class="text-muted small fw-medium">Emergency Contact</div>
                <div class="fw-bold text-dark">09639958414 · clinic@bpc.edu.ph</div>
              </div>
            </div>
          </div>
        </div>
        <div class="col-md-6 col-xl-3">
          <div class="card p-3 h-100 shadow-sm">
            <div class="d-flex align-items-center gap-3">
              <div class="p-2 bg-warning-light text-warning rounded-circle fs-4 d-flex align-items-center justify-content-center" style="width:48px;height:48px;">
                <i class="bi bi-person-heart"></i>
              </div>
              <div>
                <div class="text-muted small fw-medium">Active Referrals</div>
                <div class="fw-bold text-dark"><?= $pendingRefs ?> Pending · <?= $inSupportRefs ?> In Support</div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="row g-3">
        <!-- Clinic Services Overview -->
        <div class="col-lg-6">
          <div class="card p-3 h-100">
            <h6 class="section-title mb-3"><i class="bi bi-shield-plus text-success me-1"></i> Clinic Healthcare Services</h6>
            <div class="list-group list-group-flush small">
              <div class="list-group-item bg-transparent px-0 py-2 border-bottom">
                <div class="fw-bold text-dark mb-1"><i class="bi bi-check-circle-fill text-success me-2"></i> First Aid &amp; Acute Care</div>
                <div class="text-muted">Immediate care for walk-in injuries, sudden illnesses, and emergency stabilization.</div>
              </div>
              <div class="list-group-item bg-transparent px-0 py-2 border-bottom">
                <div class="fw-bold text-dark mb-1"><i class="bi bi-check-circle-fill text-success me-2"></i> Student Support &amp; Well-being Coordination</div>
                <div class="text-muted">Joint coordination with Guidance Counselors for students needing mental health or academic support.</div>
              </div>
              <div class="list-group-item bg-transparent px-0 py-2 border-bottom">
                <div class="fw-bold text-dark mb-1"><i class="bi bi-check-circle-fill text-success me-2"></i> Health &amp; Vital Signs Monitoring</div>
                <div class="text-muted">Monitoring of BMI, blood pressure, temperature, and vaccination compliance.</div>
              </div>
              <div class="list-group-item bg-transparent px-0 py-2 border-bottom">
                <div class="fw-bold text-dark mb-1"><i class="bi bi-check-circle-fill text-success me-2"></i> Medical Clearance &amp; Certificates</div>
                <div class="text-muted">Issuance of medical certificates for physical education, OJT, and class absences.</div>
              </div>
            </div>
          </div>
        </div>

        <!-- Latest Clinic Announcements -->
        <div class="col-lg-6">
          <div class="card p-3 h-100">
            <div class="d-flex justify-content-between align-items-center mb-3">
              <h6 class="section-title mb-0"><i class="bi bi-megaphone text-success me-1"></i> Clinic Announcements</h6>
              <a href="announcements.php" class="btn btn-sm btn-outline-primary">View All</a>
            </div>
            <div id="clinicAnnouncements">
              <div class="text-center text-muted py-4"><span class="spinner-border spinner-border-sm me-1"></span> Loading announcements...</div>
            </div>
          </div>
        </div>
      </div>

    </div>
  </div>
</div>
<?php include __DIR__ . '/../../components/footer.php'; ?>
<script>
$(document).ready(function () {
  $.get(APP_URL + '/api/announcements.php', { action: 'list' }, function (res) {
    const $container = $('#clinicAnnouncements').empty();
    if (!res.success || !res.data || res.data.length === 0) {
      $container.html('<div class="text-center text-muted py-4"><i class="bi bi-info-circle fs-3 d-block mb-2"></i>No new clinic announcements.</div>');
      return;
    }
    const items = res.data.slice(0, 4);
    items.forEach(function (a) {
      const pinned = a.is_pinned == 1 ? '<span class="badge bg-warning text-dark ms-1">Pinned</span>' : '';
      $container.append(
        `<div class="border-bottom py-2">
          <div class="fw-bold small text-dark">${$('<div>').text(a.title).html()} ${pinned}</div>
          <div class="text-muted small mt-1" style="font-size:.82rem;">${$('<div>').text(a.content).html()}</div>
          <div class="text-muted mt-1" style="font-size:.75rem;"><i class="bi bi-clock me-1"></i>${a.created_at}</div>
        </div>`
      );
    });
  }, 'json').fail(function () {
    $('#clinicAnnouncements').html('<div class="text-center text-muted py-4">Unable to load announcements.</div>');
  });
});
</script>
</body>
</html>