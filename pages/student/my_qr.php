<?php
require_once __DIR__ . '/../../config/config.php';
require_role('student');
$pageTitle = 'Smart Health Pass';
?>
<!DOCTYPE html>
<html lang="en">
<head><?php include __DIR__ . '/../../includes/head.php'; ?>
<style>
  .health-pass-page { background-color: #FAFAFC; }
</style></head>
<body class="health-pass-page">
<div class="app-wrapper">
  <?php include __DIR__ . '/../../components/sidebar.php'; ?>
  <input type="hidden" id="micaCurrentPage" value="student/my_qr">
  <script>window.MICA_CURRENT_PAGE = 'student/my_qr';</script>
  <div class="main-content">
    <?php include __DIR__ . '/../../components/navbar.php'; ?>
    <div class="content-area page-enter fade-in">

      <!-- ===== Header Bar ===== -->
      <div class="hp-header-bar mb-4">
        <h6 class="hp-title"><i class="bi bi-credit-card-2-front"></i> Smart Digital Health Pass</h6>
        <div class="hp-actions">
          <button class="btn btn-outline-primary" id="refreshHealthPass"><i class="bi bi-arrow-clockwise me-1"></i> Refresh</button>
          <button class="btn btn-outline-success" id="downloadQrBtn"><i class="bi bi-download me-1"></i> Download QR</button>
          <button class="btn btn-outline-success" id="printQrBtn"><i class="bi bi-printer me-1"></i> Print</button>
        </div>
      </div>

      <!-- ===== QR Code + Student Details ===== -->
      <div class="row g-3 mb-4">
        <!-- QR Code Card -->
        <div class="col-lg-4 col-xl-3">
          <div class="hp-qr-card p-4 text-center">
            <div class="text-center mb-3">
              <img src="<?= APP_URL ?>/assets/images/bpc_logo.png" alt="BPC Logo" class="hp-qr-logo">
            </div>
            <div id="qrStudentInfo" class="hp-qr-info mb-3">
              <div class="text-muted small">Loading health pass...</div>
            </div>
            <div id="qrCanvas" class="hp-qr-canvas d-inline-block mx-auto"></div>
            <div class="hp-qr-meta mt-3">
              <div>Present this QR for check-in, appointments, and verification.</div>
              <div>BPC School Clinic</div>
            </div>
            <div class="hp-qr-actions mt-3">
              <button class="btn btn-outline-success" id="downloadQrBtn2"><i class="bi bi-download me-1"></i> Download</button>
              <button class="btn btn-outline-success" id="printQrBtn2"><i class="bi bi-printer me-1"></i> Print</button>
            </div>
          </div>
        </div>

        <!-- Student Details + QR Status Card -->
        <div class="col-lg-8 col-xl-9">
          <div class="hp-details-card p-4">
            <div class="d-flex align-items-start gap-3 mb-3">
              <img id="studentPhoto" src="<?= asset_url('images/default-avatar.svg') ?>" alt="Student Photo" class="hp-student-photo">
              <div class="flex-grow-1">
                <h4 class="hp-student-name mb-1" id="studentName">—</h4>
                <div class="hp-student-id" id="studentNumber">—</div>
                <div class="hp-student-course" id="studentCourse">—</div>
              </div>
              <div class="text-end">
                <span class="hp-qr-status-badge" id="qrStatusBadge">—</span>
                <div class="hp-qr-meta mt-1" id="qrLastScan">Last scan: —</div>
                <div class="hp-qr-meta" id="qrExpiry">—</div>
              </div>
            </div>

            <div class="hp-student-grid">
              <div class="hp-dt-row"><span class="hp-dt-label">Blood Type</span><span class="hp-dt-value" id="studentBlood">—</span></div>
              <div class="hp-dt-row"><span class="hp-dt-label">Allergies</span><span class="hp-dt-value" id="studentAllergies">—</span></div>
              <div class="hp-dt-row"><span class="hp-dt-label">Emergency Contact</span><span class="hp-dt-value" id="studentEmergency">—</span></div>
              <div class="hp-dt-row"><span class="hp-dt-label">Gender</span><span class="hp-dt-value" id="studentGender">—</span></div>
              <div class="hp-dt-row"><span class="hp-dt-label">Birthdate</span><span class="hp-dt-value" id="studentBirthdate">—</span></div>
              <div class="hp-dt-row"><span class="hp-dt-label">Email</span><span class="hp-dt-value" id="studentEmail">—</span></div>
            </div>
          </div>
        </div>
      </div>

      <!-- ===== Summary Cards ===== -->
      <div class="hp-summary-grid mb-4">
        <div class="hp-summary-card hp-card-appointment">
          <div class="hp-card-icon"><i class="bi bi-calendar-event"></i></div>
          <div class="hp-card-label">Today's Appointment</div>
          <div id="cardAppointment" class="hp-card-value">—</div>
        </div>
        <div class="hp-summary-card hp-card-queue">
          <div class="hp-card-icon"><i class="bi bi-qr-code-scan"></i></div>
          <div class="hp-card-label">Current Queue</div>
          <div id="cardQueue" class="hp-card-value">—</div>
        </div>
        <div class="hp-summary-card hp-card-wait">
          <div class="hp-card-icon"><i class="bi bi-clock-history"></i></div>
          <div class="hp-card-label">Est. Wait</div>
          <div id="cardWaitTime" class="hp-card-value">—</div>
        </div>
        <div class="hp-summary-card hp-card-bmi">
          <div class="hp-card-icon"><i class="bi bi-graph-up"></i></div>
          <div class="hp-card-label">Latest BMI</div>
          <div id="cardBmi" class="hp-card-value">—</div>
        </div>
      </div>

      <div class="hp-summary-grid mb-4">
        <div class="hp-summary-card hp-card-visits">
          <div class="hp-card-icon"><i class="bi bi-clipboard-data"></i></div>
          <div class="hp-card-label">Recent Visits</div>
          <div id="cardVisits" class="hp-card-value">—</div>
        </div>
        <div class="hp-summary-card hp-card-meds">
          <div class="hp-card-icon"><i class="bi bi-capsule"></i></div>
          <div class="hp-card-label">Recent Medicines</div>
          <div id="cardMedicines" class="hp-card-value">—</div>
        </div>
        <div class="hp-summary-card hp-card-vax">
          <div class="hp-card-icon"><i class="bi bi-shield-check"></i></div>
          <div class="hp-card-label">Vaccination Status</div>
          <div id="cardVaccination" class="hp-card-value">—</div>
        </div>
        <div class="hp-summary-card hp-card-timeline">
          <div class="hp-card-icon"><i class="bi bi-clock-fill"></i></div>
          <div class="hp-card-label">QR Timeline</div>
          <div id="cardTimeline" class="hp-card-value">—</div>
        </div>
      </div>

      <!-- ===== Visit Timeline ===== -->
      <div class="hp-timeline-card mb-4">
        <div class="hp-timeline-header"><i class="bi bi-clock-history"></i> Visit Timeline</div>
        <div id="qrTimeline" class="hp-minimal-timeline">
          <div class="text-center py-3 text-muted small">Loading...</div>
        </div>
      </div>

      <!-- ===== Searchable Scan History Table ===== -->
      <div class="hp-history-card">
        <div class="hp-history-title"><i class="bi bi-table"></i> QR Scan History</div>
        <div class="hp-history-table table-responsive">
          <table class="table table-hover align-middle" id="scanHistoryTable" style="width:100%">
            <thead>
              <tr>
                <th>Date</th><th>Time</th><th>Purpose</th><th>Scanner</th><th>Status</th>
              </tr>
            </thead>
            <tbody></tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../../components/footer.php'; ?>
<script>
(function () {
  'use strict';
  const APP = window.APP_URL || (function () {
    var p = window.location.pathname;
    var i = p.indexOf('/pages/');
    return window.location.origin + (i > 0 ? p.substring(0, i) : '');
  })();
  let healthPassData = null;

  // ============================================================
  // Load the full Smart Health Pass data
  // ============================================================
  function loadHealthPass() {
    $.ajax({
      url: APP + '/api/qr_codes.php',
      method: 'GET',
      dataType: 'json',
      data: { action: 'student_health_pass' },
      success: function (res) {
        if (!res.success) { toastError(res.message || 'Failed to load health pass.'); return; }
        healthPassData = res;
        renderHealthPass(res);
        loadQrTimeline();
        loadScanHistory();
      },
      error: function (jqXHR) {
        if (jqXHR.status === 401) {
          toastError('Session expired. Please log in again.');
          return;
        }
        var parsed = jqXHR.responseJSON || null;
        if (parsed && parsed.message) {
          toastError(parsed.message);
        } else if (jqXHR.status === 404) {
          toastError('Health Pass service not found. Please contact support.');
        } else if (jqXHR.status === 500) {
          toastError('Server error. Please try again later.');
        } else if (jqXHR.status === 0) {
          toastError('Cannot reach the server. Check your connection.');
        } else {
          toastError('Unable to load Health Pass (HTTP ' + jqXHR.status + ').');
        }
      }
    });
  }

  // ============================================================
  // Helper: escape HTML
  // ============================================================
  function escapeHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  // ============================================================
  // Render the health pass header, QR, and dashboard cards
  // ============================================================
  function renderHealthPass(res) {
    const s = res.student || {};
    const qr = res.qr || {};

    // Student photo
    const photoEl = document.getElementById('studentPhoto');
    if (s.profile_picture) {
      photoEl.src = APP + '/' + s.profile_picture;
    } else {
      photoEl.src = '<?= asset_url('images/default-avatar.svg') ?>';
    }

    // Student details
    $('#studentName').text(((s.first_name || '') + ' ' + (s.last_name || '')).trim() || '—');
    $('#studentNumber').text(s.student_number || '—');
    $('#studentCourse').text([s.course, s.year_level].filter(Boolean).join(' · ') || '—');
    $('#studentBlood').text(s.blood_type || '—');
    $('#studentAllergies').text(s.allergies || 'None');
    $('#studentEmergency').text([s.emergency_contact_name, s.emergency_contact_number].filter(Boolean).join(' · ') || '—');
    $('#studentGender').text(s.gender || '—');
    $('#studentBirthdate').text(s.birthdate || '—');
    $('#studentEmail').text(s.email || '—');

    // QR Status badge
    const statusBadge = document.getElementById('qrStatusBadge');
    const qrStatusMap = {
      'active':      { cls: 'bg-success', label: 'Active' },
      'inactive':    { cls: 'bg-secondary', label: 'Inactive' },
      'revoked':     { cls: 'bg-danger', label: 'Revoked' },
      'regenerated': { cls: 'bg-info', label: 'Regenerated' },
      'deactivated': { cls: 'bg-danger', label: 'Deactivated' },
    };
    const qs = qrStatusMap[qr.status] || { cls: 'bg-secondary', label: qr.status || 'Unknown' };
    statusBadge.className = 'hp-qr-status-badge ' + qs.cls;
    statusBadge.textContent = qs.label;

    // Last scan date/time
    if (res.last_scan) {
      $('#qrLastScan').text('Last scan: ' + res.last_scan.scan_time);
    }
    // QR Expiration
    if (qr.expires_at) {
      $('#qrExpiry').text('Expires: ' + qr.expires_at);
    } else if (qr.token_expires_in_seconds > 0) {
      $('#qrExpiry').text('Token TTL: ' + qr.token_expires_in_seconds + 's');
    } else {
      $('#qrExpiry').text('Token: Permanent');
    }

    // Student info in QR card
    $('#qrStudentInfo').html(
      '<div class="hp-name">' + escapeHtml(((s.first_name || '') + ' ' + (s.last_name || '')).trim()) + '</div>' +
      '<div class="hp-sub">' + escapeHtml(s.student_number || '') + '</div>' +
      '<div class="hp-sub">' + escapeHtml([s.course, s.year_level].filter(Boolean).join(' · ')) + '</div>'
    );

    // Render QR code
    $('#qrCanvas').empty();
    if (window.QRCode && qr.qr_data) {
      new QRCode(document.getElementById('qrCanvas'), {
        text: qr.qr_data,
        width: 150,
        height: 150,
        correctLevel: QRCode.CorrectLevel.M,
      });
    } else {
      $('#qrCanvas').html('<div class="text-danger small">QR library not loaded.</div>');
    }

    // ===== Dashboard Cards =====
    // Today's Appointment
    if (res.today_appointment) {
      const a = res.today_appointment;
      const bgClass = a.status === 'approved' ? 'bg-success-subtle' : a.status === 'pending' ? 'bg-warning-subtle' : 'bg-secondary-subtle';
      const textClass = a.status === 'approved' ? 'text-success' : a.status === 'pending' ? 'text-warning' : 'text-secondary';
      $('#cardAppointment').html(
        '<div class="hp-card-brief">' + escapeHtml(a.reason || '') + '</div>' +
        '<div class="hp-card-sub">' + escapeHtml(formatTime(a.appointment_time || '')) + '</div>' +
        '<span class="hp-card-badge ' + bgClass + ' ' + textClass + '">' + escapeHtml(a.status || '') + '</span>'
      );
    } else {
      $('#cardAppointment').text('No appointment today');
    }

    // Current Queue
    if (res.current_queue) {
      const q = res.current_queue;
      const queueCls = q.status === 'waiting' ? 'hp-queue-waiting' : 'bg-info-subtle text-info';
      $('#cardQueue').html(
        '<div class="hp-queue-num">' + escapeHtml(q.queue_number || '—') + '</div>' +
        '<span class="hp-queue-pill ' + queueCls + '">' + escapeHtml(q.status || '') + '</span>'
      );
    } else {
      $('#cardQueue').text('No active queue');
    }

    // Estimated Wait
    if (res.estimated_wait) {
      $('#cardWaitTime').html(
        '<div class="hp-card-value">' + res.estimated_wait + 'min</div>' +
        '<div class="hp-card-sub">estimated</div>'
      );
    } else {
      $('#cardWaitTime').text('— min');
    }

    // Latest BMI
    if (res.latest_self_bmi) {
      const b = res.latest_self_bmi;
      const statusCls = b.bmi_status === 'Normal' ? 'hp-status-normal' : b.bmi_status === 'Underweight' ? 'hp-status-underweight' : b.bmi_status === 'Overweight' ? 'hp-status-overweight' : 'hp-status-obese';
      $('#cardBmi').html(
        '<div class="hp-card-value">' + escapeHtml(b.bmi || '—') + '</div>' +
        '<span class="hp-status-pill ' + statusCls + '">' + escapeHtml(b.bmi_status || '—') + '</span>' +
        (res.latest_nurse_assessment ? '<div class="hp-card-sub mt-1">Nurse assessment also available</div>' : '')
      );
    } else if (res.latest_nurse_assessment) {
      const b = res.latest_nurse_assessment;
      const statusCls = b.bmi_status === 'Normal' ? 'hp-status-normal' : b.bmi_status === 'Underweight' ? 'hp-status-underweight' : b.bmi_status === 'Overweight' ? 'hp-status-overweight' : 'hp-status-obese';
      $('#cardBmi').html(
        '<div class="hp-card-value">' + escapeHtml(b.bmi || '—') + '</div>' +
        '<span class="hp-status-pill ' + statusCls + '">' + escapeHtml(b.bmi_status || '—') + '</span>' +
        '<div class="hp-card-sub mt-1">From nurse assessment</div>'
      );
    } else {
      $('#cardBmi').text('No records');
    }

    // Recent Visits
    const visitCount = (res.recent_visits || []).length;
    $('#cardVisits').html(
      '<div class="hp-card-value">' + visitCount + '</div>' +
      '<div class="hp-card-sub">past consultations</div>'
    );

    // Recent Medicines
    const medCount = (res.recent_medicines || []).length;
    if (medCount > 0) {
      const medNames = (res.recent_medicines || []).map(function (m) {
        return escapeHtml(m.medicine_name || '') + ' (' + m.quantity + ')';
      }).join(', ');
      $('#cardMedicines').html(
        '<div class="hp-card-value">' + medCount + '</div>' +
        '<div class="hp-card-sub text-truncate">' + medNames + '</div>'
      );
    } else {
      $('#cardMedicines').text('No dispensed records');
    }

    // Vaccination Status
    const vaxCount = res.vaccination_count || 0;
    if (vaxCount > 0) {
      $('#cardVaccination').html(
        '<div class="hp-card-value text-success">' + vaxCount + ' </div>' +
        '<div class="hp-card-sub">vaccines recorded</div>'
      );
    } else {
      $('#cardVaccination').html(
        '<div class="hp-card-value text-warning">0</div>' +
        '<div class="hp-card-sub">no records</div>'
      );
    }
  }

  // ============================================================
  // QR Timeline — Minimalist vertical timeline
  // ============================================================
  const timelineIcons = {
    'visit':      'bi bi-credit-card-2-front',
    'consultation': 'bi bi-stethoscope',
    'queue':      'bi bi-ticket-detailed',
    'certificate': 'bi bi-file-medical',
    'medicine':   'bi bi-capsule',
    'scan':       'bi bi-qr-code-scan',
    'appointment': 'bi bi-calendar-event',
    'vaccination': 'bi bi-syringe',
    'default':    'bi bi-circle'
  };

  function getTimelineIcon(type) {
    return 'bi ' + (timelineIcons[type] || timelineIcons['default']);
  }

  const timelineStatusMap = {
    'completed': { cls: 'bg-success-subtle text-success', icon: 'bi bi-check-lg' },
    'success':   { cls: 'bg-success-subtle text-success', icon: 'bi bi-check-lg' },
    'serving':   { cls: 'bg-info-subtle text-info', icon: 'bi bi-arrow-right-circle' },
    'pending':   { cls: 'bg-warning-subtle text-warning', icon: 'bi bi-hourglass' },
    'duplicate': { cls: 'bg-warning-subtle text-warning', icon: 'bi bi-exclamation-triangle' },
    'error':     { cls: 'bg-danger-subtle text-danger', icon: 'bi bi-exclamation-octagon' }
  };

  function loadQrTimeline() {
    $.get(APP + '/api/qr_codes.php', { action: 'student_qr_timeline', limit: 15 }, function (res) {
      if (!res.success || !res.timeline || !res.timeline.length) {
        $('#qrTimeline').html('<div class="text-center py-4 text-muted small"><i class="bi bi-clock-history fs-4 d-block mb-1"></i>No timeline events yet. Scan your QR at the clinic to begin.</div>');
        return;
      }
      const events = res.timeline;
      let html = '<div class="hp-minimal-timeline">';
      events.forEach(function (e, i) {
        const isFirst = i === 0;
        const statusMap = timelineStatusMap[e.status] || { cls: 'bg-secondary-subtle text-secondary', icon: 'bi bi-circle' };
        const iconClass = getTimelineIcon(e.type || e.icon_type || '');
        const statusBadge = `<span class="hp-event-status ${statusMap.cls}"><i class="${statusMap.icon} me-1"></i>${escapeHtml(e.status || '')}</span>`;

        html += '<div class="hp-timeline-item">';
        html += '<div class="hp-timeline-marker ' + (isFirst ? 'bg-success-subtle text-success' : '') + '"><i class="' + iconClass + '"></i></div>';
        html += '<div class="hp-event-content">';
        html += '<div class="d-flex justify-content-between align-items-center mb-1">';
        html += '<span class="hp-event-title">' + escapeHtml(e.title || '') + '</span>';
        html += statusBadge;
        html += '</div>';
        if (e.detail) html += '<div class="hp-event-detail">' + escapeHtml(e.detail || '') + '</div>';
        if (e.timestamp) html += '<div class="hp-event-time">' + formatDateTime(e.timestamp) + '</div>';
        html += '</div>';
        html += '</div>';
      });
      html += '</div>';
      $('#qrTimeline').html(html);
      $('#cardTimeline').text(events.length + 'events');
    }, 'json');
  }

  // ============================================================
  // Searchable Scan History Table
  // ============================================================
  function loadScanHistory() {
    if (!healthPassData || !healthPassData.student) return;
    $.get(APP + '/api/qr_codes.php', {
      action: 'scan_logs',
      limit: 50,
      search: healthPassData.student.student_number || '',
    }, function (res) {
      if (!res.success) return;
      const rows = (res.data || []).map(function (l) {
        const dt = (l.scan_time || '').split(' ');
        const st = l.status === 'success' ? '<span class="badge bg-success-subtle text-success">Success</span>' :
                   l.status === 'duplicate' ? '<span class="badge bg-warning-subtle text-warning">Duplicate</span>' :
                   l.status === 'expired' ? '<span class="badge bg-info-subtle text-info">Expired</span>' :
                   '<span class="badge bg-danger-subtle text-danger">' + escapeHtml(l.status || 'Error') + '</span>';
        return [
          dt[0] || '—',
          dt[1] || '—',
          escapeHtml(l.purpose || 'check-in'),
          escapeHtml(l.scanner_name || '—'),
          st,
        ];
      });
      $('#scanHistoryTable').DataTable({
        data: rows,
        columns: [null, null, null, null, null],
        pageLength: 10,
        order: [[0, 'desc']],
        destroy: true,
      });
    }, 'json');
  }

  // ============================================================
  // Download / Print QR
  // ============================================================
  function getCleanQrDataUrl() {
    var el = document.getElementById('qrCanvas');
    if (!el) return null;
    var qrInstance = el._qrInstance;
    if (qrInstance && qrInstance._oQRCode) {
      var modules = qrInstance._oQRCode.modules;
      var moduleCount = qrInstance._oQRCode.moduleCount;
      var margin = 4;
      var targetSize = 600;
      var cellSize = Math.max(1, Math.floor(targetSize / (moduleCount + margin * 2)));
      var totalSize = cellSize * (moduleCount + margin * 2);
      var offset = Math.floor((targetSize - totalSize) / 2);
      var canvas = document.createElement('canvas');
      canvas.width = targetSize;
      canvas.height = targetSize;
      var ctx = canvas.getContext('2d');
      ctx.fillStyle = '#FFFFFF';
      ctx.fillRect(0, 0, targetSize, targetSize);
      ctx.fillStyle = '#000000';
      for (var row = 0; row < moduleCount; row++) {
        for (var col = 0; col < moduleCount; col++) {
          if (modules[row] && modules[row][col]) {
            var x = offset + (margin + col) * cellSize;
            var y = offset + (margin + row) * cellSize;
            ctx.fillRect(x, y, cellSize, cellSize);
          }
        }
      }
      return canvas.toDataURL('image/png');
    }
    const $img = $('#qrCanvas img');
    if ($img.length) return $img.attr('src');
    const c = $('#qrCanvas canvas')[0];
    if (c && typeof c.toDataURL === 'function') return c.toDataURL('image/png');
    return null;
  }

  function getQrDataUrl() {
    return getCleanQrDataUrl();
  }

  function downloadQr() {
    const img = getQrDataUrl();
    if (!img) { toastError('No QR image available.'); return; }
    const a = document.createElement('a');
    a.href = img;
    a.download = 'BPC_SmartHealthPass_' + (healthPassData && healthPassData.student ? healthPassData.student.student_number : 'student') + '.png';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
  }

  function printQr() {
    const s = healthPassData && healthPassData.student ? healthPassData.student : {};
    const img = getQrDataUrl();
    if (!img) { toastError('No QR image available.'); return; }
    const w = window.open('', '_blank');
    w.document.write(`
      <html><head><title>BPC Smart Health Pass</title>
      <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
      <style>body{font-family:'Inter',Arial,sans-serif;text-align:center;padding:30px}
      img{max-width:250px}.muted{color:#777;font-size:12px;margin-top:8px}.brand{font-weight:bold;font-size:16px;margin-bottom:4px}
      .green{color:#0B6B3A;font-weight:bold}</style>
      </head><body>
      <div class="brand green">BPC School Clinic</div>
      <div class="muted">Bulacan Polytechnic College</div>
      <hr>
      <div class="green">${escapeHtml(((s.first_name || '') + ' ' + (s.last_name || '')).trim())}</div>
      <div class="muted">${escapeHtml(s.student_number || '')} · ${escapeHtml(s.course || '')} ${escapeHtml(s.year_level || '')}</div>
      <div style="margin-top:15px"><img src="${img}"></div>
      <div class="muted" style="margin-top:10px">BPC Smart Digital Health Pass — Present at the clinic for check-in, appointments, medical records, and certificates.</div>
      </body></html>`);
    w.document.close();
    w.focus();
    setTimeout(function () { w.print(); }, 400);
  }

  // ============================================================
  // Helpers
  // ============================================================
  function formatTime(t) {
    if (!t) return '';
    const parts = t.split(':');
    if (parts.length < 2) return t;
    const h = parseInt(parts[0], 10);
    const m = parts[1];
    const ampm = h >= 12 ? 'PM' : 'AM';
    const dh = h % 12 === 0 ? 12 : h % 12;
    return dh + ':' + m + ' ' + ampm;
  }

  function formatDateTime(ts) {
    if (!ts) return '—';
    return new Date(ts).toLocaleString('en-PH', {
      year: 'numeric', month: 'short', day: 'numeric',
      hour: '2-digit', minute: '2-digit', hour12: true,
      timeZone: 'Asia/Manila',
    });
  }

  // ============================================================
  // Event bindings
  // ============================================================
  $('#refreshHealthPass').on('click', function () {
    loadHealthPass();
    toastSuccess('Health pass refreshed.');
  });

  $('#downloadQrBtn, #downloadQrBtn2').on('click', downloadQr);
  $('#printQrBtn, #printQrBtn2').on('click', printQr);

  // ============================================================
  // Initialize
  // ============================================================
  loadHealthPass();

  // Refresh every 60 seconds (for queue number updates)
  setInterval(function () {
    if (document.hidden) return;
    if (healthPassData && healthPassData.current_queue) {
      loadHealthPass();
    }
  }, 60000);
})();
</script>
</body>
</html>
