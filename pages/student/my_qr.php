<?php
require_once __DIR__ . '/../../config/config.php';
require_role('student');
$pageTitle = 'Smart Health Pass';
?>
<!DOCTYPE html>
<html lang="en">
<head><?php include __DIR__ . '/../../includes/head.php'; ?></head>
<body>
<div class="app-wrapper">
  <?php include __DIR__ . '/../../components/sidebar.php'; ?>
  <input type="hidden" id="micaCurrentPage" value="student/my_qr">
  <script>window.MICA_CURRENT_PAGE = 'student/my_qr';</script>
  <div class="main-content">
    <?php include __DIR__ . '/../../components/navbar.php'; ?>
    <div class="content-area page-enter fade-in">
      <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <h5 class="section-title mb-0"><i class="bi bi-credit-card-2-front text-success me-1"></i> Smart Digital Health Pass</h5>
        <div class="d-flex flex-wrap gap-2">
          <button class="btn btn-sm btn-outline-primary" id="refreshHealthPass"><i class="bi bi-arrow-clockwise me-1"></i> Refresh</button>
          <button class="btn btn-sm btn-outline-success" id="downloadQrBtn"><i class="bi bi-download me-1"></i> Download QR</button>
          <button class="btn btn-sm btn-outline-success" id="printQrBtn"><i class="bi bi-printer me-1"></i> Print</button>
        </div>
      </div>

      <!-- ===== Student Health Pass Header (photo + QR + details) ===== -->
      <div class="row g-3 mb-4">
        <!-- QR Code Card -->
        <div class="col-lg-4 col-xl-3">
          <div class="card p-4 text-center shadow-sm h-100">
            <div class="text-center mb-3">
              <img src="<?= APP_URL ?>/assets/images/bpc_logo.png" alt="BPC Logo" style="height:48px;">
            </div>
            <div id="qrStudentInfo" class="mb-2">
              <div class="text-muted small">Loading health pass...</div>
            </div>
            <div id="qrCanvas" class="d-inline-block p-3 bg-white border rounded-3 mx-auto"></div>
            <div class="small text-muted mt-3">BPC School Clinic</div>
            <div class="small text-muted">Present this QR for check-in, appointments, and verification.</div>
            <div class="d-grid gap-2 mt-3">
              <button class="btn btn-sm btn-success" id="downloadQrBtn2"><i class="bi bi-download me-1"></i> Download QR</button>
              <button class="btn btn-sm btn-success" id="printQrBtn2"><i class="bi bi-printer me-1"></i> Print</button>
            </div>
          </div>
        </div>

        <!-- Student Details + QR Status Card -->
        <div class="col-lg-8 col-xl-9">
          <div class="card p-4 shadow-sm h-100">
            <div class="d-flex align-items-start gap-3 mb-3">
                  <img id="studentPhoto" src="<?= asset_url('images/default-avatar.svg') ?>" alt="Student Photo"
                   style="width:80px;height:80px;border-radius:50%;object-fit:cover;border:3px solid var(--accent);">
              <div class="flex-grow-1">
                <h4 class="fw-bold mb-1" id="studentName">—</h4>
                <div class="text-muted" id="studentNumber">—</div>
                <div class="text-muted small" id="studentCourse">—</div>
              </div>
              <div class="text-end">
                <span class="badge fs-6" id="qrStatusBadge">—</span>
                <div class="small text-muted mt-1" id="qrLastScan">Last scan: —</div>
                <div class="small text-muted" id="qrExpiry">—</div>
              </div>
            </div>

            <div class="row g-2 small">
              <div class="col-md-6">
                <div class="d-flex justify-content-between py-1 border-bottom"><span class="text-muted">Blood Type</span><span class="fw-medium" id="studentBlood">—</span></div>
                <div class="d-flex justify-content-between py-1 border-bottom"><span class="text-muted">Allergies</span><span class="fw-medium" id="studentAllergies">—</span></div>
                <div class="d-flex justify-content-between py-1"><span class="text-muted">Emergency Contact</span><span class="fw-medium" id="studentEmergency">—</span></div>
              </div>
              <div class="col-md-6">
                <div class="d-flex justify-content-between py-1 border-bottom"><span class="text-muted">Gender</span><span class="fw-medium" id="studentGender">—</span></div>
                <div class="d-flex justify-content-between py-1 border-bottom"><span class="text-muted">Birthdate</span><span class="fw-medium" id="studentBirthdate">—</span></div>
                <div class="d-flex justify-content-between py-1"><span class="text-muted">Email</span><span class="fw-medium" id="studentEmail">—</span></div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- ===== Dashboard Cards ===== -->
      <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
          <div class="card p-3 h-100 shadow-sm text-center">
            <div class="text-success mb-2"><i class="bi bi-calendar-event fs-3"></i></div>
            <div class="small text-muted fw-medium">Today's Appointment</div>
            <div id="cardAppointment" class="fw-bold mt-1">—</div>
          </div>
        </div>
        <div class="col-6 col-md-3">
          <div class="card p-3 h-100 shadow-sm text-center">
            <div class="text-success mb-2"><i class="bi bi-qr-code-scan fs-3"></i></div>
            <div class="small text-muted fw-medium">Current Queue</div>
            <div id="cardQueue" class="fw-bold mt-1">—</div>
          </div>
        </div>
        <div class="col-6 col-md-3">
          <div class="card p-3 h-100 shadow-sm text-center">
            <div class="text-primary mb-2"><i class="bi bi-clock-history fs-3"></i></div>
            <div class="small text-muted fw-medium">Est. Wait</div>
            <div id="cardWaitTime" class="fw-bold mt-1">—</div>
          </div>
        </div>
        <div class="col-6 col-md-3">
          <div class="card p-3 h-100 shadow-sm text-center">
            <div class="text-info mb-2"><i class="bi bi-graph-up fs-3"></i></div>
            <div class="small text-muted fw-medium">Latest BMI</div>
            <div id="cardBmi" class="fw-bold mt-1">—</div>
          </div>
        </div>
      </div>

      <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
          <div class="card p-3 h-100 shadow-sm text-center">
            <div class="text-success mb-2"><i class="bi bi-clipboard-data fs-3"></i></div>
            <div class="small text-muted fw-medium">Recent Visits</div>
            <div id="cardVisits" class="fw-bold mt-1">—</div>
          </div>
        </div>
        <div class="col-6 col-md-3">
          <div class="card p-3 h-100 shadow-sm text-center">
            <div class="text-warning mb-2"><i class="bi bi-capsule fs-3"></i></div>
            <div class="small text-muted fw-medium">Recent Medicines</div>
            <div id="cardMedicines" class="fw-bold mt-1">—</div>
          </div>
        </div>
        <div class="col-6 col-md-3">
          <div class="card p-3 h-100 shadow-sm text-center">
            <div class="text-danger mb-2"><i class="bi bi-shield-check fs-3"></i></div>
            <div class="small text-muted fw-medium">Vaccination Status</div>
            <div id="cardVaccination" class="fw-bold mt-1">—</div>
          </div>
        </div>
        <div class="col-6 col-md-3">
          <div class="card p-3 h-100 shadow-sm text-center">
            <div class="text-primary mb-2"><i class="bi bi-clock-fill fs-3"></i></div>
            <div class="small text-muted fw-medium">QR Timeline</div>
            <div id="cardTimeline" class="fw-bold mt-1">—</div>
          </div>
        </div>
      </div>

      <!-- ===== QR Timeline ===== -->
      <div class="card p-4 shadow-sm mb-4">
        <h6 class="section-title mb-3"><i class="bi bi-clock-history text-success me-1"></i> Visit Timeline</h6>
        <div id="qrTimeline" class="text-muted small">
          <div class="text-center py-3">No timeline events yet. Scan your QR at the clinic to begin.</div>
        </div>
      </div>

      <!-- ===== Searchable Scan History Table ===== -->
      <div class="card p-4 shadow-sm mb-4">
        <h6 class="section-title mb-3"><i class="bi bi-table text-success me-1"></i> QR Scan History</h6>
        <div class="table-responsive">
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
    $('#studentName').text((s.first_name || '') + ' ' + (s.last_name || '') || '—');
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
    statusBadge.className = 'badge ' + qs.cls + ' fs-6';
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
      '<div class="fw-bold">' + escapeHtml((s.first_name || '') + ' ' + (s.last_name || '')) + '</div>' +
      '<div class="text-muted small">' + escapeHtml(s.student_number || '') + '</div>' +
      '<div class="text-muted small">' + escapeHtml([s.course, s.year_level].filter(Boolean).join(' · ')) + '</div>'
    );

    // Render QR code
    $('#qrCanvas').empty();
    if (window.QRCode && qr.qr_data) {
      new QRCode(document.getElementById('qrCanvas'), {
        text: qr.qr_data,
        width: 200,
        height: 200,
        correctLevel: QRCode.CorrectLevel.M,
      });
    } else {
      $('#qrCanvas').html('<div class="text-danger small">QR library not loaded.</div>');
    }

    // ===== Dashboard Cards =====
    // Today's Appointment
    if (res.today_appointment) {
      const a = res.today_appointment;
      $('#cardAppointment').html(
        '<div class="text-success small">' + escapeHtml(a.reason || '') + '</div>' +
        '<div class="small text-muted">' + escapeHtml(formatTime(a.appointment_time || '')) + '</div>' +
        '<span class="badge bg-' + (a.status === 'approved' ? 'success' : a.status === 'pending' ? 'warning text-dark' : 'secondary') + ' small">' +
        a.status + '</span>'
      );
    } else {
      $('#cardAppointment').text('No appointment today');
    }

    // Current Queue
    if (res.current_queue) {
      const q = res.current_queue;
      $('#cardQueue').html(
        '<div class="fw-bold">' + escapeHtml(q.queue_number || '—') + '</div>' +
        '<span class="badge bg-' + (q.status === 'waiting' ? 'warning text-dark' : 'info') + ' small">' + q.status + '</span>'
      );
    } else {
      $('#cardQueue').text('No active queue');
    }

    // Estimated Wait
    if (res.estimated_wait) {
      $('#cardWaitTime').html(
        '<div class="fw-bold">' + res.estimated_wait + ' min</div>' +
        '<div class="small text-muted">estimated</div>'
      );
    } else {
      $('#cardWaitTime').text('— min');
    }

    // Latest Self-Recorded BMI
    if (res.latest_self_bmi) {
      const b = res.latest_self_bmi;
      const statusClass = b.bmi_status === 'Normal' ? 'success' : b.bmi_status === 'Underweight' ? 'warning text-dark' : b.bmi_status === 'Overweight' ? 'warning' : 'danger';
      $('#cardBmi').html(
        '<div class="fw-bold">' + escapeHtml(b.bmi || '—') + '</div>' +
        '<span class="badge bg-' + statusClass + ' small">' + escapeHtml(b.bmi_status || '—') + '</span>' +
        (res.latest_nurse_assessment ? '<div class="small text-muted mt-1">Nurse assessment also available</div>' : '')
      );
    } else if (res.latest_nurse_assessment) {
      const b = res.latest_nurse_assessment;
      const statusClass = b.bmi_status === 'Normal' ? 'success' : b.bmi_status === 'Underweight' ? 'warning text-dark' : b.bmi_status === 'Overweight' ? 'warning' : 'danger';
      $('#cardBmi').html(
        '<div class="fw-bold">' + escapeHtml(b.bmi || '—') + '</div>' +
        '<span class="badge bg-' + statusClass + ' small">' + escapeHtml(b.bmi_status || '—') + '</span>' +
        '<div class="small text-muted mt-1">From nurse assessment</div>'
      );
    } else {
      $('#cardBmi').text('No records');
    }

    // Recent Visits
    const visitCount = (res.recent_visits || []).length;
    $('#cardVisits').html(
      '<div class="fw-bold">' + visitCount + '</div>' +
      '<div class="small text-muted">past consultations</div>'
    );

    // Recent Medicines
    const medCount = (res.recent_medicines || []).length;
    if (medCount > 0) {
      const medNames = (res.recent_medicines || []).map(function (m) {
        return escapeHtml(m.medicine_name || '') + ' (' + m.quantity + ')';
      }).join(', ');
      $('#cardMedicines').html(
        '<div class="fw-bold">' + medCount + '</div>' +
        '<div class="small text-muted text-truncate">' + medNames + '</div>'
      );
    } else {
      $('#cardMedicines').text('No dispensed records');
    }

    // Vaccination Status
    const vaxCount = res.vaccination_count || 0;
    if (vaxCount > 0) {
      $('#cardVaccination').html(
        '<div class="fw-bold text-success">' + vaxCount + ' ✓</div>' +
        '<div class="small text-muted">vaccines recorded</div>'
      );
    } else {
      $('#cardVaccination').html(
        '<div class="fw-bold text-warning">0</div>' +
        '<div class="small text-muted">no records</div>'
      );
    }
  }

  // ============================================================
  // QR Timeline
  // ============================================================
  function loadQrTimeline() {
    $.get(APP + '/api/qr_codes.php', { action: 'student_qr_timeline', limit: 15 }, function (res) {
      if (!res.success || !res.timeline || !res.timeline.length) {
        $('#qrTimeline').html('<div class="text-center py-3 text-muted small">No timeline events yet. Scan your QR at the clinic to begin.</div>');
        return;
      }
      const events = res.timeline;
      let html = '';
      events.forEach(function (e, i) {
        const isFirst = i === 0;
        const statusClass = e.status === 'success' || e.status === 'completed' ? 'text-success' :
                            e.status === 'duplicate' ? 'text-warning' : 'text-danger';
        html += '<div class="d-flex align-items-start gap-2 mb-2">';
        html += '<div class="' + (isFirst ? 'text-success' : 'text-muted') + ' fs-5">' + e.icon + '</div>';
        html += '<div class="flex-grow-1">';
        html += '<div class="fw-medium">' + escapeHtml(e.title || '') + '</div>';
        html += '<div class="text-muted small">' + escapeHtml(e.detail || '') + '</div>';
        html += '<div class="text-muted xsmall">' + formatDateTime(e.timestamp) + '</div>';
        html += '</div>';
        html +=         '<span class="badge bg-' + (e.status === 'success' || e.status === 'completed' ? 'success' : e.status === 'duplicate' ? 'warning text-dark' : 'danger') + ' small mt-1 align-top">' + escapeHtml(e.status || '') + '</span>';
        html += '</div>';
      });
      $('#qrTimeline').html(html);
      $('#cardTimeline').html(events.length + ' events');
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
        const st = l.status === 'success' ? '<span class="badge bg-success">Success</span>' :
                   l.status === 'duplicate' ? '<span class="badge bg-warning text-dark">Duplicate</span>' :
                   l.status === 'expired' ? '<span class="badge bg-info">Expired</span>' :
                   '<span class="badge bg-danger">' + escapeHtml(l.status || 'Error') + '</span>';
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
      <style>body{font-family:Arial,sans-serif;text-align:center;padding:30px}
      img{max-width:250px}.muted{color:#777;font-size:12px;margin-top:8px}.brand{font-weight:bold;font-size:16px;margin-bottom:4px}
      .green{color:#0B6B3A;font-weight:bold}</style>
      </head><body>
      <div class="brand green">BPC School Clinic</div>
      <div class="muted">Bulacan Polytechnic College</div>
      <hr>
      <div class="green">${escapeHtml((s.first_name || '') + ' ' + (s.last_name || ''))}</div>
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
