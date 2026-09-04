<?php
require_once __DIR__ . '/../../config/config.php';
require_role('nurse');
$db = getDB();
$u = current_user();

$stmt = $db->prepare("SELECT id FROM nurses WHERE user_id = ?");
$stmt->execute([$u['id']]);
$nurseId = $stmt->fetchColumn();

$todaysPatients = $db->prepare("SELECT COUNT(*) FROM consultations WHERE DATE(consultation_date) = CURDATE()");
$todaysPatients->execute();
$todaysPatients = $todaysPatients->fetchColumn();

$todaysAppts = $db->prepare("SELECT COUNT(*) FROM appointments WHERE appointment_date = CURDATE() AND status IN ('approved','pending')");
$todaysAppts->execute();
$todaysAppts = $todaysAppts->fetchColumn();

$pendingAppts = $db->query("SELECT COUNT(*) FROM appointments WHERE status='pending'")->fetchColumn();
$lowStock = $db->query("SELECT COUNT(*) FROM medicines WHERE is_archived = 0 AND quantity <= reorder_level")->fetchColumn();
$expiredMeds = $db->query("SELECT COUNT(*) FROM medicines WHERE is_archived = 0 AND expiration_date IS NOT NULL AND expiration_date < CURDATE()")->fetchColumn();

$upcoming = $db->query("
  SELECT a.*, s.student_number, u.first_name, u.last_name
  FROM appointments a
  JOIN students s ON s.id = a.student_id
  JOIN users u ON u.id = s.user_id
  WHERE a.appointment_date >= CURDATE() AND a.status IN ('pending','approved')
  ORDER BY a.appointment_date, a.appointment_time LIMIT 8
")->fetchAll();

$pageTitle = 'Nurse Dashboard';
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

      <!-- Welcome Card — Nurse Station -->
      <div class="welcome-card" data-aos="fade-down" data-aos-duration="600">
        <div class="welcome-content">
          <div class="clinic-status open"><span class="status-dot"></span> Nurse Station — Open</div>
          <h1>Nurse Station Dashboard</h1>
          <div class="welcome-sub">Welcome, <?= e($u['first_name']) ?>! 👋</div>
          <div class="welcome-desc">Manage patient consultations, appointments, and health monitoring for Bulacan Polytechnic College students.</div>
          <div class="welcome-actions">
            <a href="walkin.php" class="btn btn-white"><i class="bi bi-person-walking me-1"></i> Walk-in Patient</a>
            <a href="appointments.php" class="btn btn-outline-white"><i class="bi bi-calendar-check me-1"></i> Appointments</a>
            <a href="health_monitoring.php" class="btn btn-outline-white"><i class="bi bi-heart-pulse me-1"></i> Record Vitals</a>
          </div>
        </div>
        <div class="welcome-visual">
          <div class="welcome-icon"><i class="bi bi-hospital"></i></div>
        </div>
        <!-- Subtle ECG line -->
        <div class="ecg-line" style="position:absolute;bottom:0;left:0;right:0;opacity:0.06;">
          <svg viewBox="0 0 400 40" preserveAspectRatio="none">
            <path class="ecg-path" d="M0,20 L50,20 L60,8 L70,32 L80,20 L130,20 L140,10 L145,20 L150,20 L200,20 L210,12 L220,28 L230,20 L280,20 L290,15 L295,20 L300,20 L350,20 L360,18 L370,22 L380,20 L400,20" stroke="white" stroke-width="2" fill="none"/>
          </svg>
        </div>
      </div>

      <?php if (getenv('FIREBASE_PROJECT_ID')): ?>
      <div class="alert alert-success d-flex align-items-center gap-3 fade-in" data-aos="fade-up" data-aos-delay="200">
        <div class="fs-3"><i class="bi bi-bell"></i></div>
        <div class="flex-grow-1">
          <strong>Enable Push Notifications</strong>
          <div class="small text-muted">Receive SOS alerts and clinic updates directly on your device.</div>
        </div>
        <button class="btn btn-success" id="enableFcmBtn">
          <i class="bi bi-bell-fill me-1"></i> Enable Notifications
        </button>
      </div>
      <?php endif; ?>

      <!-- Medical Stat Cards -->
      <div class="row g-3 mb-4" data-aos="fade-up" data-aos-delay="100">
        <div class="col-md-6 col-xl-3">
          <div class="medical-stat-card gradient-green">
            <div class="msc-top">
              <div class="msc-icon"><i class="bi bi-hospital-fill"></i></div>
              <div class="msc-value"><span class="counter-animate" data-count="<?= (int)$todaysPatients ?>">0</span></div>
            </div>
            <div>
              <div class="msc-label">Today's Patients</div>
              <div class="msc-trend up"><i class="bi bi-arrow-up-short"></i> Active</div>
            </div>
          </div>
        </div>
        <div class="col-md-6 col-xl-3">
          <div class="medical-stat-card gradient-blue">
            <div class="msc-top">
              <div class="msc-icon"><i class="bi bi-calendar-check-fill"></i></div>
              <div class="msc-value"><span class="counter-animate" data-count="<?= (int)$todaysAppts ?>">0</span></div>
            </div>
            <div>
              <div class="msc-label">Today's Appointments</div>
              <div class="msc-trend up"><i class="bi bi-arrow-up-short"></i> Scheduled</div>
            </div>
          </div>
        </div>
        <div class="col-md-6 col-xl-3">
          <div class="medical-stat-card gradient-orange">
            <div class="msc-top">
              <div class="msc-icon"><i class="bi bi-hourglass-split"></i></div>
              <div class="msc-value"><span class="counter-animate" data-count="<?= (int)$pendingAppts ?>">0</span></div>
            </div>
            <div>
              <div class="msc-label">Pending Requests</div>
              <div class="msc-trend down"><i class="bi bi-arrow-down-short"></i> Awaiting</div>
            </div>
          </div>
        </div>
        <div class="col-md-6 col-xl-3">
          <div class="medical-stat-card gradient-red">
            <div class="msc-top">
              <div class="msc-icon"><i class="bi bi-exclamation-triangle-fill"></i></div>
              <div class="msc-value"><span class="counter-animate" data-count="<?= (int)$lowStock ?>">0</span></div>
            </div>
            <div>
              <div class="msc-label">Low Stock Medicines</div>
              <div class="msc-trend down"><i class="bi bi-arrow-down-short"></i> Needs restock</div>
            </div>
          </div>
        </div>
      </div>

      <?php if ($expiredMeds > 0): ?>
        <div class="alert alert-danger d-flex align-items-center gap-2 py-2 px-3 rounded-3" data-aos="fade-up" role="alert">
          <i class="bi bi-exclamation-octagon-fill"></i>
          <span><?= (int)$expiredMeds ?> medicine(s) have expired. Please review <a href="inventory.php" class="alert-link">Inventory</a>.</span>
        </div>
      <?php endif; ?>

      <!-- Quick Actions — Large Interactive Cards -->
      <h6 class="section-title mb-3" data-aos="fade-up"><i class="bi bi-lightning-fill"></i> Quick Actions</h6>
      <div class="quick-actions-grid" data-aos="fade-up" data-aos-delay="50">
        <a href="walkin.php" class="quick-action-card-lg text-decoration-none">
          <div class="qa-icon-lg"><i class="bi bi-person-walking"></i></div>
          <div class="qa-title-lg">Walk-in Patient</div>
          <div class="qa-sub-lg">Register a new visit</div>
        </a>
        <a href="appointments.php" class="quick-action-card-lg text-decoration-none">
          <div class="qa-icon-lg"><i class="bi bi-calendar-check-fill"></i></div>
          <div class="qa-title-lg">Appointments</div>
          <div class="qa-sub-lg">Manage bookings</div>
        </a>
        <a href="health_monitoring.php" class="quick-action-card-lg text-decoration-none">
          <div class="qa-icon-lg"><i class="bi bi-heart-pulse-fill"></i></div>
          <div class="qa-title-lg">Record Vitals</div>
          <div class="qa-sub-lg">Monitor student health</div>
        </a>
        <a href="dispensing.php" class="quick-action-card-lg text-decoration-none">
          <div class="qa-icon-lg"><i class="bi bi-capsule"></i></div>
          <div class="qa-title-lg">Dispense Medicine</div>
          <div class="qa-sub-lg">Issue medications</div>
        </a>
        <a href="certificates.php" class="quick-action-card-lg text-decoration-none">
          <div class="qa-icon-lg"><i class="bi bi-file-medical-fill"></i></div>
          <div class="qa-title-lg">Medical Certificates</div>
          <div class="qa-sub-lg">Issue medical certs</div>
        </a>
        <a href="#" class="quick-action-card-lg text-decoration-none" onclick="if(typeof MICA!=='undefined'&&MICA.open)MICA.open();return false;">
          <div class="qa-icon-lg"><i class="bi bi-robot"></i></div>
          <div class="qa-title-lg">Ask MICA</div>
          <div class="qa-sub-lg">Clinical AI assistant</div>
        </a>
      </div>

      <!-- Upcoming Appointments -->
      <div class="card" data-aos="fade-up" data-aos-delay="150">
        <div class="card-header d-flex align-items-center gap-2">
          <i class="bi bi-calendar-week text-primary"></i>
          <span>Upcoming Appointments</span>
        </div>
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
              <thead>
                <tr>
                  <th>Student</th>
                  <th>Student No.</th>
                  <th>Date</th>
                  <th>Time</th>
                  <th>Reason</th>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($upcoming as $a): ?>
                <tr>
                  <td class="fw-medium"><?= e($a['first_name'].' '.$a['last_name']) ?></td>
                  <td><?= e($a['student_number']) ?></td>
                  <td><?= format_date($a['appointment_date']) ?></td>
                  <td><?= date('g:i A', strtotime($a['appointment_time'])) ?></td>
                  <td><?= e($a['reason']) ?></td>
                  <td><span class="badge status-<?= e($a['status']) ?>"><?= ucfirst($a['status']) ?></span></td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$upcoming): ?>
                <tr><td colspan="6" class="text-center text-muted py-4"><i class="bi bi-inbox me-1"></i> No upcoming appointments</td></tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- Emergency Requests -->
      <div class="card mb-4 border-0 shadow-sm" data-aos="fade-up" data-aos-delay="200">
        <div class="card-header bg-white py-3 d-flex align-items-center justify-content-between flex-wrap gap-2 border-bottom">
          <div class="d-flex align-items-center gap-2">
            <span class="p-2 bg-danger-subtle text-danger rounded-3 d-inline-flex align-items-center justify-content-center">
              <i class="bi bi-exclamation-triangle-fill fs-5"></i>
            </span>
            <div>
              <h6 class="mb-0 fw-bold text-dark">Active Emergency Requests & Live SOS</h6>
              <small class="text-muted">Real-time student GPS tracking and adviser emergency reports</small>
            </div>
          </div>
          <span class="badge bg-danger fs-6 px-3 py-2" id="emgCountBadge" style="display:none">0 Active</span>
        </div>
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" id="emgTable" style="width:100%">
              <thead class="table-light">
                <tr>
                  <th>Student</th>
                  <th>Section</th>
                  <th>Source</th>
                  <th>Emergency Type</th>
                  <th>Location / GPS Coordinates</th>
                  <th>Status</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody></tbody>
            </table>
          </div>
          <div id="emgEmpty" class="text-center text-muted py-5" style="display:none;">
            <i class="bi bi-shield-check text-success fs-1 d-block mb-2"></i>
            No active emergencies at this time.
          </div>
        </div>
      </div>

    </div>
  </div>
</div>

<!-- ================================================================ -->
<!-- Nurse Live Emergency & Location Map Modal                        -->
<!-- ================================================================ -->
<div class="modal fade" id="nurseEmergencyModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
    <div class="modal-content border-0 rounded-4 shadow-lg">
      <div class="modal-header border-0 bg-danger text-white rounded-top-4 py-3">
        <h5 class="modal-title d-flex align-items-center gap-2">
          <i class="bi bi-shield-exclamation"></i>
          <span id="nemgModalTitle">Emergency Details & Location</span>
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body p-4">
        <div class="row g-3 mb-3">
          <div class="col-md-6">
            <div class="p-3 bg-light rounded-3">
              <div class="text-muted small">Student Name</div>
              <div class="fw-bold fs-6" id="nemgStudentName">—</div>
              <div class="text-muted small mt-1" id="nemgStudentMeta">—</div>
            </div>
          </div>
          <div class="col-md-6">
            <div class="p-3 bg-light rounded-3">
              <div class="text-muted small">Emergency Type / Priority</div>
              <div class="fw-bold fs-6 text-danger" id="nemgType">—</div>
              <div class="text-muted small mt-1" id="nemgStatusBadge">—</div>
            </div>
          </div>
        </div>

        <!-- Location Box -->
        <div class="card border-danger mb-3">
          <div class="card-header bg-danger-subtle text-danger py-2 fw-semibold d-flex align-items-center justify-content-between">
            <span><i class="bi bi-geo-alt-fill me-1"></i> Location Information</span>
            <span id="nemgSourceBadge" class="badge bg-danger">GPS Live</span>
          </div>
          <div class="card-body">
            <div id="nemgLocationText" class="fs-6 fw-bold mb-2">—</div>
            <div id="nemgLocationSubtext" class="small text-muted mb-3">—</div>

            <!-- Interactive Map Container for GPS SOS -->
            <div id="nemgMapContainer" style="display:none;">
              <div id="nurseTrackingMap" style="height: 320px; width: 100%; border-radius: 12px;" class="border shadow-sm mb-2"></div>
              <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                <small class="text-muted"><i class="bi bi-broadcast text-danger me-1"></i> Map auto-updates in real time while tracking is active.</small>
                <a id="nemgGoogleMapsLink" href="#" target="_blank" class="btn btn-sm btn-outline-danger">
                  <i class="bi bi-box-arrow-up-right me-1"></i> Open in Google Maps
                </a>
              </div>
            </div>
          </div>
        </div>

        <!-- Description & Notes -->
        <div class="p-3 bg-light rounded-3 mb-2">
          <div class="text-muted small">Description / Notes</div>
          <div id="nemgDescription" class="mt-1" style="white-space: pre-line;">—</div>
        </div>
      </div>
      <div class="modal-footer border-0 bg-light rounded-bottom-4 justify-content-between">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
        <div class="d-flex gap-2" id="nemgActionButtons"></div>
      </div>
    </div>
  </div>
</div>

<!-- Leaflet CSS & JS for OpenStreetMap (Local Vendor Assets) -->
<link rel="stylesheet" href="<?= asset_url('vendor/leaflet/leaflet.css') ?>" />
<script src="<?= asset_url('vendor/leaflet/leaflet.js') ?>"></script>

<?php include __DIR__ . '/../../components/footer.php'; ?>
<script>
// -------- Emergency Requests (unified: SOS + Adviser) --------
(function () {
  'use strict';
  const emgStatusLabels = {
    new: 'New', active: 'New', acknowledged: 'Acknowledged', responding: 'Responding',
    responded: 'Responded', resolved: 'Resolved', cancelled: 'Cancelled', false_alarm: 'False Alarm'
  };
  const emgStatusColors = {
    new: 'danger', active: 'danger', acknowledged: 'warning text-dark', responding: 'info',
    responded: 'warning text-dark', resolved: 'success', cancelled: 'secondary', false_alarm: 'secondary'
  };
  let emgDt = null;
  let activeModalEmgId = null;
  let modalPollInterval = null;
  let leafletMap = null;
  let leafletMarker = null;
  let leafletPolyline = null;
  let leafletCircle = null;

  function timeAgo(dateString) {
    if (!dateString) return 'Just now';
    const d = new Date(dateString.replace(/-/g, '/'));
    const seconds = Math.floor((new Date() - d) / 1000);
    if (isNaN(seconds) || seconds < 10) return 'Just now';
    if (seconds < 60) return seconds + 's ago';
    const minutes = Math.floor(seconds / 60);
    if (minutes < 60) return minutes + 'm ago';
    const hours = Math.floor(minutes / 60);
    if (hours < 24) return hours + 'h ago';
    return dateString.split(' ')[0];
  }

  function escapeHtml(str) {
    return $('<div>').text(str || '').html();
  }

  function renderLocationCell(e) {
    if (e.source === 'adviser' || e.location_source === 'manual') {
      return `<div>
                <div class="fw-bold text-dark"><i class="bi bi-geo-alt-fill text-danger me-1"></i>${escapeHtml(e.location || 'Reported by Adviser')}</div>
                <span class="badge bg-primary-subtle text-primary border border-primary-subtle mt-1"><i class="bi bi-person-workspace me-1"></i>Adviser Manual</span>
              </div>`;
    }

    if (e.latitude && e.longitude) {
      const lat = Number(e.latitude).toFixed(5);
      const lng = Number(e.longitude).toFixed(5);
      const acc = e.location_accuracy ? `<span class="badge bg-light text-secondary border ms-1">±${escapeHtml(e.location_accuracy)}</span>` : '';
      const updated = timeAgo(e.location_updated_at || e.created_at);
      return `<div>
                <div class="fw-bold text-danger"><i class="bi bi-crosshair text-danger me-1"></i>${lat}, ${lng}${acc}</div>
                <div class="small text-muted mt-1 d-flex align-items-center gap-1">
                  <span class="spinner-grow spinner-grow-sm text-danger" style="width: 8px; height: 8px;"></span>
                  <span>Live GPS · Updated ${updated}</span>
                </div>
              </div>`;
    }

    return `<span class="badge bg-warning-subtle text-dark border border-warning"><i class="bi bi-geo-alt me-1"></i>GPS Unavailable</span>`;
  }

  function loadEmergencies() {
    $.get(APP_URL + '/api/emergencies.php', { action: 'list' }, function (res) {
      if (!res.success) return;
      const data = res.data || [];
      const active = data.filter(e => !['resolved','cancelled','false_alarm'].includes(e.status));
      $('#emgCountBadge').text(active.length + ' Active').toggle(active.length > 0);
      $('#emgEmpty').toggle(data.length === 0);

      const rows = data.map(function (e) {
        const name = (e.first_name ? (e.first_name + ' ' + e.last_name) : 'Student #' + e.student_id);
        const studentCol = `<div>
                              <div class="fw-bold text-dark">${escapeHtml(name)}</div>
                              <div class="small text-muted">${escapeHtml(e.student_number || 'No Student No.')}</div>
                            </div>`;
        const reporter = e.source === 'adviser'
          ? `<span class="badge bg-info-subtle text-info-emphasis border"><i class="bi bi-person-fill me-1"></i>Adviser</span>`
          : `<span class="badge bg-danger-subtle text-danger border border-danger-subtle"><i class="bi bi-broadcast me-1"></i>Student SOS</span>`;

        const status = e.status;
        const isOpen = !['resolved','cancelled','false_alarm'].includes(status);
        const btns = `
          <div class="d-flex gap-1 flex-wrap">
            <button class="btn btn-sm btn-danger emg-view-loc" data-id="${e.id}" title="View Live Location & Details">
              <i class="bi bi-geo-alt-fill me-1"></i>${e.source === 'sos' && e.latitude ? 'Live Map' : 'Details'}
            </button>
            ${isOpen ? `
              <button class="btn btn-sm btn-outline-warning emg-act" data-id="${e.id}" data-op="acknowledge" title="Acknowledge">Ack</button>
              <button class="btn btn-sm btn-info text-white emg-act" data-id="${e.id}" data-op="respond" title="Mark as Responding">Respond</button>
              <button class="btn btn-sm btn-success emg-act" data-id="${e.id}" data-op="resolve" title="Mark as Resolved">Resolve</button>
            ` : `<span class="text-muted small align-self-center ms-1">Closed</span>`}
          </div>`;

        return [
          studentCol,
          e.section_name || e.section || '—',
          reporter,
          `<strong>${escapeHtml(e.emergency_type || 'Emergency')}</strong>`,
          renderLocationCell(e),
          `<span class="badge bg-${emgStatusColors[status] || 'secondary'}">${emgStatusLabels[status] || status}</span>`,
          btns
        ];
      });

      if (emgDt) emgDt.destroy();
      emgDt = $('#emgTable').DataTable({
        data: rows,
        columns: [{},{},{},{},{},{},{}],
        destroy: true,
        pageLength: 10,
        order: [],
        language: { emptyTable: "No active emergencies." }
      });
    }, 'json');
  }

  // Handle opening Live Location / Emergency Modal
  $(document).on('click', '.emg-view-loc', function () {
    const id = $(this).data('id');
    openEmergencyModal(id);
  });

  function openEmergencyModal(id) {
    activeModalEmgId = id;
    $('#nurseEmergencyModal').modal('show');
    fetchEmergencyDetails(id, true);

    if (modalPollInterval) clearInterval(modalPollInterval);
    modalPollInterval = setInterval(function () {
      if (activeModalEmgId && $('#nurseEmergencyModal').is(':visible')) {
        fetchEmergencyDetails(activeModalEmgId, false);
      }
    }, 3500);
  }

  $('#nurseEmergencyModal').on('hidden.bs.modal', function () {
    activeModalEmgId = null;
    if (modalPollInterval) {
      clearInterval(modalPollInterval);
      modalPollInterval = null;
    }
  });

  function fetchEmergencyDetails(id, fitMap) {
    $.get(APP_URL + '/api/emergencies.php', { action: 'live_tracking', emergency_id: id }, function (res) {
      if (!res.success || !res.emergency) return;
      const emg = res.emergency;
      const history = res.history || [];
      const studentName = emg.first_name ? (emg.first_name + ' ' + emg.last_name) : 'Student #' + emg.student_id;

      $('#nemgModalTitle').text(`Emergency #${emg.id} — ${studentName}`);
      $('#nemgStudentName').text(studentName);
      $('#nemgStudentMeta').text(`${emg.student_number || 'N/A'} · ${emg.section_name || emg.section || 'No Section'} ${emg.phone ? '· ' + emg.phone : ''}`);
      $('#nemgType').text(emg.emergency_type || 'Emergency');
      $('#nemgStatusBadge').html(`<span class="badge bg-${emgStatusColors[emg.status] || 'secondary'}">${emgStatusLabels[emg.status] || emg.status}</span> · Priority: <span class="text-uppercase fw-bold">${emg.priority || 'urgent'}</span>`);
      $('#nemgDescription').text(emg.description || 'No additional description provided.');

      // Action buttons in modal
      const isOpen = !['resolved','cancelled','false_alarm'].includes(emg.status);
      let actHtml = '';
      if (isOpen) {
        actHtml = `
          <button class="btn btn-outline-warning emg-act" data-id="${emg.id}" data-op="acknowledge"><i class="bi bi-check-lg me-1"></i> Acknowledge</button>
          <button class="btn btn-info text-white emg-act" data-id="${emg.id}" data-op="respond"><i class="bi bi-person-walking me-1"></i> Respond</button>
          <button class="btn btn-success emg-act" data-id="${emg.id}" data-op="resolve"><i class="bi bi-check-circle-fill me-1"></i> Mark Resolved</button>
        `;
      }
      $('#nemgActionButtons').html(actHtml);

      // Handle Location Presentation
      if (emg.source === 'adviser' || emg.location_source === 'manual') {
        $('#nemgSourceBadge').removeClass('bg-danger').addClass('bg-primary').text('Adviser Manual Location');
        $('#nemgLocationText').html(`<i class="bi bi-geo-alt-fill text-danger me-2"></i>${escapeHtml(emg.location || 'Reported Location')}`);
        $('#nemgLocationSubtext').html(`Manually reported by Adviser · ${emg.created_at || ''}`);
        $('#nemgMapContainer').hide();
      } else {
        // Student GPS SOS
        $('#nemgSourceBadge').removeClass('bg-primary').addClass('bg-danger').text('Student SOS GPS Tracking');
        if (emg.latitude && emg.longitude) {
          const lat = parseFloat(emg.latitude);
          const lng = parseFloat(emg.longitude);
          const acc = emg.location_accuracy ? ` (±${escapeHtml(emg.location_accuracy)})` : '';
          const updated = timeAgo(emg.location_updated_at || emg.created_at);

          $('#nemgLocationText').html(`<i class="bi bi-crosshair text-danger me-2"></i>GPS: ${lat.toFixed(6)}, ${lng.toFixed(6)}${acc}`);
          $('#nemgLocationSubtext').html(`Real-Time Device GPS · Last updated: <strong>${updated}</strong> (${emg.location_updated_at || emg.created_at})`);
          $('#nemgGoogleMapsLink').attr('href', `https://www.google.com/maps?q=${lat},${lng}`);
          $('#nemgMapContainer').show();

          // Render / Update Leaflet Map
          renderLeafletMap(lat, lng, emg.location_accuracy, studentName, emg.emergency_type, history, fitMap);
        } else {
          $('#nemgLocationText').html('<span class="text-warning"><i class="bi bi-exclamation-triangle me-2"></i>GPS Location Unavailable</span>');
          $('#nemgLocationSubtext').text('Student initiated SOS without granting device GPS access.');
          $('#nemgMapContainer').hide();
        }
      }
    }, 'json');
  }

  function renderLeafletMap(lat, lng, accuracy, name, type, history, fitMap) {
    const mapEl = document.getElementById('nurseTrackingMap');
    if (!mapEl || typeof L === 'undefined') return;

    if (!leafletMap) {
      leafletMap = L.map('nurseTrackingMap').setView([lat, lng], 17);
      L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; OpenStreetMap contributors'
      }).addTo(leafletMap);
    } else {
      if (fitMap) {
        leafletMap.setView([lat, lng], 17);
      }
      setTimeout(() => leafletMap.invalidateSize(), 200);
    }

    // Red Pulsing Marker
    const markerHtml = `<div style="background-color:#dc3545;width:20px;height:20px;border-radius:50%;border:3px solid white;box-shadow:0 0 10px rgba(220,53,69,0.8);animation:pulse 1.5s infinite;"></div>`;
    const icon = L.divIcon({ html: markerHtml, className: 'sos-map-marker', iconSize: [20, 20], iconAnchor: [10, 10] });

    if (leafletMarker) {
      leafletMarker.setLatLng([lat, lng]);
      leafletMarker.setPopupContent(`<strong>${escapeHtml(name)}</strong><br>${escapeHtml(type)}<br><small class="text-muted">${lat.toFixed(6)}, ${lng.toFixed(6)}</small>`);
    } else {
      leafletMarker = L.marker([lat, lng], { icon: icon }).addTo(leafletMap);
      leafletMarker.bindPopup(`<strong>${escapeHtml(name)}</strong><br>${escapeHtml(type)}<br><small class="text-muted">${lat.toFixed(6)}, ${lng.toFixed(6)}</small>`).openPopup();
    }

    // Accuracy Circle
    const accMeters = accuracy ? parseFloat(accuracy) : 15;
    if (leafletCircle) {
      leafletCircle.setLatLng([lat, lng]).setRadius(accMeters);
    } else {
      leafletCircle = L.circle([lat, lng], { radius: accMeters, color: '#dc3545', fillOpacity: 0.15 }).addTo(leafletMap);
    }

    // Movement History Polyline
    if (history && history.length > 1) {
      const latlngs = history.map(h => [parseFloat(h.latitude), parseFloat(h.longitude)]);
      if (leafletPolyline) {
        leafletPolyline.setLatLngs(latlngs);
      } else {
        leafletPolyline = L.polyline(latlngs, { color: '#dc3545', dashArray: '5, 8', weight: 3 }).addTo(leafletMap);
      }
    }
  }

  // Update Status Actions
  $(document).on('click', '.emg-act', function () {
    const id = $(this).data('id');
    const op = $(this).data('op');
    const data = { action: 'update_status', emergency_id: id, op: op, csrf_token: $('meta[name="csrf-token"]').attr('content') };
    if (op === 'resolve') {
      const notes = prompt('Resolution notes (optional):');
      data.resolution_notes = notes || '';
    }
    $.post(APP_URL + '/api/emergencies.php', data, function (res) {
      if (res.success) {
        toastSuccess(res.message);
        loadEmergencies();
        if (activeModalEmgId === id) fetchEmergencyDetails(id, false);
      } else {
        toastError(res.message);
      }
    }, 'json');
  });

  loadEmergencies();
  setInterval(loadEmergencies, 5000);
})();
</script>
<script>
(function () {
  'use strict';
  const btn = document.getElementById('enableFcmBtn');
  if (btn && typeof window.enableFCMNotifications === 'function') {
    btn.addEventListener('click', function () {
      btn.disabled = true;
      btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Requesting...';
      window.enableFCMNotifications();
      setTimeout(function () {
        btn.innerHTML = '<i class="bi bi-check-circle me-1"></i> Enabled';
        btn.classList.remove('btn-success');
        btn.classList.add('btn-outline-success');
      }, 2000);
    });
  }
})();
</script>
</body>
</html>

