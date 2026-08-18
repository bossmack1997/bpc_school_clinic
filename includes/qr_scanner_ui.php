<?php
/**
 * includes/qr_scanner_ui.php
 * Reusable QR Scanner Component
 * Usage: $scannerMode = 'check_in'; $scannerTitle = 'QR Check-In'; include ...
 * Modes: check_in | verify | certificate
 */
$scannerMode = isset($scannerMode) ? $scannerMode : 'check_in';
$scannerTitle = isset($scannerTitle) ? $scannerTitle : 'QR Scanner';
$visitTypes = ['walk-in' => 'Walk-in', 'appointment' => 'Appointment', 'emergency' => 'Emergency'];

$qrScanLocation = 'Clinic Entrance';
if (function_exists('get_qr_setting')) {
    try {
        $qrScanLocation = get_qr_setting(getDB(), 'scan_location_default', 'Clinic Entrance');
    } catch (Exception $e) {
        error_log('QR setting fetch failed: ' . $e->getMessage());
    }
}
?>
<div class="card shadow-sm mb-4" id="qrScannerCard">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0"><i class="bi bi-qr-code-scan text-success me-1"></i> <?= e($scannerTitle) ?></h6>
    <span class="badge bg-secondary" id="scannerModeBadge"><?= e(ucfirst($scannerMode)) ?></span>
  </div>
  <div class="card-body">
    <div class="row g-3 align-items-start">
      <div class="col-md-5">
        <div id="qrVideoContainer" class="position-relative border rounded-3 overflow-hidden bg-dark" style="height: 300px;">
          <video id="qrVideo" class="w-100 h-100 object-fit-cover" autoplay playsinline muted></video>
          <canvas id="qrCanvas" class="position-absolute top-0 start-0 d-none"></canvas>
          <div id="qrScannerOverlay"
               class="position-absolute top-0 start-0 w-100 h-100 d-flex align-items-center justify-content-center text-center text-white"
               style="background: rgba(0,0,0,0.6);">
            <div>
              <i class="bi bi-camera-video" style="font-size: 2rem;"></i>
              <p class="mb-0 mt-2 small">Waiting for camera access...</p>
            </div>
          </div>
          <div id="qrScanFrame" class="position-absolute top-50 start-50 translate-middle" style="width: 260px; height: 260px; border: 2px dashed rgba(255,255,255,0.5); border-radius: 12px; display: none;"></div>
        </div>
        <div class="d-grid gap-2 mt-3">
          <button type="button" class="btn btn-outline-success btn-sm" id="startCameraBtn">
            <i class="bi bi-camera-video me-1"></i> Start Camera
          </button>
          <button type="button" class="btn btn-outline-danger btn-sm" id="stopCameraBtn" style="display: none;">
            <i class="bi bi-stop-circle me-1"></i> Stop Camera
          </button>
        </div>
        <div id="scannerError" class="alert alert-warning alert-dismissible mt-2" style="display: none;">
          <i class="bi bi-exclamation-triangle me-1"></i> <span id="scannerErrorText"></span>
        </div>
      </div>
      <div class="col-md-7">
        <div class="alert alert-secondary">
          <i class="bi bi-info-circle me-1"></i> Scan a student QR code with the camera below.
        </div>

        <?php if ($scannerMode === 'check_in'): ?>
        <div class="mb-3">
          <label class="form-label fw-semibold">Visit Type</label>
          <select class="form-select form-select-sm" id="visitTypeSelect">
            <?php foreach ($visitTypes as $val => $label): ?>
              <option value="<?= e($val) ?>"><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>

        <div id="qrScannerResult" class="border rounded-3 p-3 bg-light" style="min-height: 150px;">
          <p class="text-muted small mb-0">Scan a QR code with the camera to see results here.</p>
        </div>
      </div>
    </div>
  </div>
</div>

<?php if ($scannerMode === 'check_in' || $scannerMode === 'smart'): ?>
<div class="modal fade" id="queueResultModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header bg-success text-white">
        <h6 class="mb-0"><i class="bi bi-clipboard-check me-1"></i> Check-In Successful</h6>
      </div>
      <div class="modal-body text-center py-4">
        <div id="queueNumberDisplay" class="display-2 fw-bold text-success mb-2">—</div>
        <div class="text-muted" id="queueStudentName">—</div>
        <div class="text-muted small" id="queueStudentDetails">—</div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">
          <i class="bi bi-x me-1"></i> Close
        </button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
(function () {
  'use strict';

  function initQrScanner() {
    const $ = window.jQuery;
    const APP = window.APP_URL || '';
    const MODE = '<?= e($scannerMode) ?>';
    const csrfToken = $('meta[name="csrf-token"]').attr('content');

    let html5QrCode = null;
    let scanActive = false;

    async function startCamera() {
      const $err = $('#scannerError');
      const $overlay = $('#qrScannerOverlay');
      const $frame = $('#qrScanFrame');

      // Prevent duplicate initialization — only one active camera stream.
      if (scanActive) return;

      if (!window.Html5Qrcode) {
        $('#scannerErrorText').text('QR scanner library not loaded. Please refresh the page or contact support.');
        $err.show();
        return;
      }

      // Safe browser camera API detection.
      if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        $('#scannerErrorText').text('This browser does not support camera access required by the QR scanner.');
        $err.show();
        return;
      }

      console.log('[QR] Camera API available');
      console.log('[QR] getUserMedia:', typeof navigator.mediaDevices.getUserMedia);
      console.log('[QR] Secure context:', window.isSecureContext);
      console.log('[QR] Current URL:', window.location.href);

      try {
        html5QrCode = new Html5Qrcode('qrVideo');
        $frame.show();
        $overlay.find('p').text('Starting camera…');

        const config = { fps: 10, qrbox: 250 };

        // Desktop-compatible camera request.
        // Html5Qrcode requires the camera config object to have exactly ONE key
        // (facingMode OR deviceId). { facingMode: "environment" } lets the
        // browser pick any available camera (built-in, USB, external).
        console.log('[QR] Requesting camera...');
        await html5QrCode.start(
          { facingMode: "environment" },
          config,
          function (decoded) { onQrScanSuccess(decoded, 'camera'); },
          onQrScanError
        );

        scanActive = true;
        console.log('[QR] Camera stream received');
        console.log('[QR] Camera started');
        console.log('[QR] QR scanner initialized');

        $('#startCameraBtn').hide();
        $('#stopCameraBtn').show();
        $overlay.hide();
      } catch (err) {
        console.error('[QR] Camera initialization failed:', err);
        console.error('[QR] Error name:', err && err.name);
        console.error('[QR] Error message:', err && err.message);

        let msg = 'Camera access failed: ' + ((err && err.message) || err || 'Unknown error').substring(0, 200);
        const errName = err && err.name;

        if (errName === 'NotAllowedError' || errName === 'SecurityError') {
          msg = 'Camera permission was denied. Please allow camera access and try again.';
        } else if (errName === 'NotFoundError' || errName === 'DevicesNotFoundError') {
          msg = 'No camera was detected. Please connect a webcam.';
        } else if (errName === 'NotReadableError' || errName === 'TrackStartError') {
          msg = 'The camera is currently being used by another application.';
        } else if (errName === 'OverconstrainedError') {
          msg = 'No camera matching the requested settings was found. Please connect a webcam and try again.';
        } else if (errName === 'AbortError') {
          msg = 'Camera access was aborted. Please try again.';
        } else if (errName === 'TypeError') {
          msg = 'Camera request was invalid. Please refresh the page and try again.';
        }

        $('#scannerErrorText').text(msg);
        $err.show();
        $overlay.find('p').text('Waiting for camera access...');
        $frame.hide();
      }
    }

    async function stopCamera() {
      if (html5QrCode) {
        try {
          if (scanActive) {
            await html5QrCode.stop();
            await html5QrCode.clear();
          }
        } catch (e) { console.warn('stopCamera error:', e); }
        html5QrCode = null;
        scanActive = false;
      }
      $('#startCameraBtn').show();
      $('#stopCameraBtn').hide();
      $('#qrScannerOverlay').show();
      $('#qrScanFrame').hide();
    }

    function onQrScanSuccess(decoded, scanMethod) {
      // Stop the camera stream first, then process the QR data.
      stopCamera().then(function () {
        processQrData(decoded, scanMethod);
      });
    }

    function onQrScanError(err) {
      // Non-fatal — often just a frame without a QR
    }

    function processQrData(qrData, scanMethod) {
      const payload = typeof qrData === 'string' ? qrData.trim() : '';
      if (!payload || payload.length < 10) {
        showResult({ valid: false, message: 'The QR code could not be read. Try another scan.' }, 'danger');
        return;
      }

      showResult({ message: 'Verifying QR…', loading: true }, 'info');

      const endpoint = APP + '/api/qr_codes.php';
      const data = { action: 'verify', qr_data: payload };
      if (MODE === 'check_in') data.action = 'verify';
      if (scanMethod) data.scan_method = scanMethod;

      $.post(endpoint, data, function (res) {
        if (!res.success) {
          showResult({ valid: false, message: res.message || 'Verification failed.' }, 'danger');
          return;
        }
        handleVerifiedQr(res.data, payload, scanMethod);
      }, 'json').fail(function () {
        showResult({ valid: false, message: 'Network error. Please try again.' }, 'danger');
      });
    }

    function handleVerifiedQr(data, payload, scanMethod) {
      const student = data.student || {};
      const name = (student.first_name || '') + ' ' + (student.last_name || '');
      const details = [student.student_number || '', student.course || '', student.year_level || '']
        .filter(Boolean).join(' · ');

      if (!data.valid) {
        showResult({ valid: false, message: 'Invalid QR: ' + (data.reason || 'Verification failed') }, 'danger');
        return;
      }

      if (MODE === 'smart') {
        handleSmartScan(data, payload, scanMethod);
        return;
      }

      let html = '';
      html += '<div class="d-flex align-items-start">';
      html += '<div class="bg-white border rounded-3 p-2 me-3" style="min-width:80px">';
      html += '<canvas id="verifiedQrImg"></canvas>';
      html += '</div>';
      html += '<div class="flex-grow-1">';
      html += '<span class="badge bg-success mb-1">Valid Student QR</span>';
      html += '<div class="fw-bold mb-1">' + escapeHtml(name) + '</div>';
      html += '<div class="text-muted small">' + escapeHtml(details) + '</div>';
      html += '</div></div>';

      const extra = {
        valid: true,
        student: student,
        payload: payload,
        mode: MODE
      };

      if (MODE === 'check_in') {
        const visitType = $('#visitTypeSelect').val();
        html += '<hr class="my-2">';
        html += '<button class="btn btn-sm btn-success w-100" id="doCheckinBtn">';
        html += '<i class="bi bi-clipboard-check me-1"></i> Issue Queue & Check-In';
        html += '</button>';
        html += '<div id="checkinProgress" class="text-info small mt-2" style="display:none;">Processing…</div>';
      } else if (MODE === 'certificate') {
        html += '<hr class="my-2">';
        html += '<button class="btn btn-sm btn-primary w-100" id="genDynamicBtn">';
        html += '<i class="bi bi-qr-code me-1"></i> Generate Certificate Verification QR';
        html += '</button>';
        html += '<div id="dynamicQrResult" class="small mt-2"></div>';
      }

      showResult({ html: html, extra: extra }, 'success');
      renderQrImage(payload, 'verifiedQrImg');

      if (MODE === 'check_in') {
        $('#doCheckinBtn').off('click').on('click', function () {
          performCheckin(payload, visitType || 'walk-in', scanMethod);
        });
      }
      if (MODE === 'certificate') {
        $('#genDynamicBtn').off('click').on('click', function () {
          generateCertificateDynamicQr(student.id);
        });
      }
    }

    function handleSmartScan(data, payload, scanMethod) {
      const student = data.student || {};
      const name = (student.first_name || '') + ' ' + (student.last_name || '');
      const details = [student.student_number || '', student.course || '', student.year_level || '']
        .filter(Boolean).join(' · ');

      $.post(APP + '/api/qr_codes.php', {
        action: 'smart_scan',
        qr_data: payload,
        perform_checkin: false,
        location: 'Smart QR Scanner',
        scan_method: scanMethod || 'unknown',
        csrf_token: csrfToken
      }, function (res) {
        if (!res.success) {
          showResult({ valid: false, message: res.message || 'Smart scan failed.' }, 'danger');
          return;
        }

        const wf = res.workflow || {};
        const scenario = wf.scenario || 'walk_in';
        const appt = wf.today_appointment || null;

        let scenarioHtml = '';
        if (scenario === 'appointment') {
          scenarioHtml = '<div class="alert alert-success py-2 small mb-2">' +
            '<i class="bi bi-calendar-check me-1"></i> <strong>Appointment Detected</strong><br>' +
            escapeHtml(wf.reason || '') + '</div>';
        } else {
          scenarioHtml = '<div class="alert alert-warning py-2 small mb-2">' +
            '<i class="bi bi-person-plus me-1"></i> <strong>Walk-in</strong> — ' +
            escapeHtml(wf.reason || 'No appointment found today.') + '</div>';
        }

        let emergencyStrip = '';
        if (wf.allergies || wf.blood_type || (wf.emergency_contact && wf.emergency_contact.name)) {
          emergencyStrip = '<div class="alert alert-danger py-2 small mb-2">' +
            '<i class="bi bi-shield-exclamation me-1"></i> <strong>Emergency Info</strong><br>' +
            (wf.emergency_contact && wf.emergency_contact.name
              ? 'Emergency Contact: ' + escapeHtml(wf.emergency_contact.name) + ' (' + escapeHtml(wf.emergency_contact.number || '') + ')<br>' : '') +
            (wf.allergies ? 'Allergies: ' + escapeHtml(wf.allergies) + '<br>' : '') +
            (wf.blood_type ? 'Blood Type: ' + escapeHtml(wf.blood_type) : '') +
            '</div>';
        }

        let html = '<div class="d-flex align-items-start mb-2">';
        html += '<div class="bg-white border rounded-3 p-2 me-3" style="min-width:70px">';
        html += '<canvas id="verifiedQrImg"></canvas>';
        html += '</div>';
        html += '<div class="flex-grow-1">';
        html += '<span class="badge bg-success mb-1">Smart QR Verified</span>';
        html += '<div class="fw-bold mb-1">' + escapeHtml(name) + '</div>';
        html += '<div class="text-muted small">' + escapeHtml(details) + '</div>';
        html += '</div></div>';

        html += scenarioHtml;
        html += emergencyStrip;

        html += '<hr class="my-2">';
        html += '<div class="d-grid gap-2">';

        if (scenario === 'appointment') {
          html += '<button class="btn btn-sm btn-success w-100" id="smartCheckinBtn">';
          html += '<i class="bi bi-clipboard-check me-1"></i> Mark Arrived & Issue Queue';
          html += '</button>';
          html += '<div class="row g-2">';
          html += '<div class="col"><button class="btn btn-sm btn-outline-primary w-100" id="smartApptBtn">';
          html += '<i class="bi bi-calendar me-1"></i> Appointments</button></div>';
          html += '<div class="col"><button class="btn btn-sm btn-outline-danger w-100" id="smartEmergencyBtn">';
          html += '<i class="bi bi-shield-plus me-1"></i> Emergency</button></div>';
          html += '</div>';
        } else {
          html += '<button class="btn btn-sm btn-success w-100" id="smartCheckinBtn">';
          html += '<i class="bi bi-person-plus me-1"></i> Register as Walk-in & Issue Queue';
          html += '</button>';
          html += '<div class="row g-2">';
          html += '<div class="col-6"><button class="btn btn-sm btn-outline-primary w-100" id="smartMedicineBtn">';
          html += '<i class="bi bi-capsule me-1"></i> Dispense</button></div>';
          html += '<div class="col-6"><button class="btn btn-sm btn-outline-danger w-100" id="smartEmergencyBtn">';
          html += '<i class="bi bi-shield-plus me-1"></i> Emergency</button></div>';
          html += '</div>';
        }

        html += '</div>';
        html += '<div id="smartProgress" class="text-info small mt-2" style="display:none;">Processing…</div>';
        html += '<input type="hidden" id="smartPayload" value="' + escapeHtml(payload) + '">';
        html += '<input type="hidden" id="smartStudentId" value="' + (student.id || '') + '">';
        html += '<input type="hidden" id="smartApptId" value="' + (appt ? appt.id : '') + '">';
        html += '<input type="hidden" id="smartScanMethod" value="' + escapeHtml(scanMethod || 'unknown') + '">';

        showResult({ html: html }, 'success');
        renderQrImage(payload, 'verifiedQrImg');
        bindSmartActions();
      }, 'json').fail(function () {
        showResult({ valid: false, message: 'Network error during smart scan.' }, 'danger');
      });
    }

    function bindSmartActions() {
      $('#smartCheckinBtn').off('click').on('click', function () {
        const payload = $('#smartPayload').val();
        const scanMethod = $('#smartScanMethod').val() || 'unknown';
        $('#smartProgress').show();
        const $btn = $(this);
        $btn.prop('disabled', true);

        $.post(APP + '/api/qr_codes.php', {
          action: 'smart_scan',
          qr_data: payload,
          perform_checkin: true,
          location: 'Smart QR Scanner',
          scan_method: scanMethod,
          csrf_token: csrfToken
        }, function (res) {
          $('#smartProgress').hide();
          $btn.prop('disabled', false);

          if (!res.success) {
            showResult({ valid: false, message: res.message || 'Check-in failed.' }, 'danger');
            return;
          }

          const d = res;
          $('#queueNumberDisplay').text(d.queue_number);
          $('#queueStudentName').html('<strong>' + escapeHtml(d.student.name) + '</strong>');
          $('#queueStudentDetails').text([d.student.student_number || '', d.student.course || ''].filter(Boolean).join(' · '));

          if (d.mica_greeting) {
            $('#queueStudentDetails').append('<div class="small text-success mt-2"><i class="bi bi-robot me-1"></i>' + escapeHtml(d.mica_greeting) + '</div>');
          }

          $('#queueResultModal').modal('show');
          handleSmartScan({ student: d.student }, payload);
        }, 'json').fail(function () {
          $('#smartProgress').hide();
          $btn.prop('disabled', false);
          showResult({ valid: false, message: 'Network error during check-in.' }, 'danger');
        });
      });

      $('#smartMedicineBtn').off('click').on('click', function () {
        const sid = $('#smartStudentId').val();
        if (sid) window.location.href = APP + '/pages/nurse/dispensing.php?student_id=' + sid;
      });

      $('#smartEmergencyBtn').off('click').on('click', function () {
        const sid = $('#smartStudentId').val();
        if (sid) window.location.href = APP + '/pages/nurse/patients_today.php?student_id=' + sid;
      });

      $('#smartApptBtn').off('click').on('click', function () {
        const sid = $('#smartStudentId').val();
        if (sid) window.location.href = APP + '/pages/nurse/appointments.php?student_id=' + sid;
      });
    }

    function performCheckin(payload, visitType, scanMethod) {
      $('#checkinProgress').show();
      const location = get_qr_setting('scan_location_default', 'Clinic Entrance');

      $.post(APP + '/api/qr_codes.php', {
        action: 'check_in',
        qr_data: payload,
        visit_type: visitType,
        location: location || 'Clinic Entrance',
        scan_method: scanMethod || 'unknown',
        csrf_token: csrfToken
      }, function (res) {
        $('#checkinProgress').hide();

        if (res.success) {
          const d = res.data;
          $('#queueNumberDisplay').text(d.queue_number);
          $('#queueStudentName').html('<strong>' + escapeHtml(d.student.name) + '</strong>');
          $('#queueStudentDetails').text([d.student.student_number || '', d.student.course || ''].filter(Boolean).join(' · '));
          $('#queueResultModal').modal('show');
        } else {
          showResult({ valid: false, message: res.message || 'Check-in failed.' }, 'danger');
        }
      }, 'json').fail(function () {
        $('#checkinProgress').hide();
        showResult({ valid: false, message: 'Network error during check-in.' }, 'danger');
      });
    }

    function generateCertificateDynamicQr(studentId) {
      const $el = $('#dynamicQrResult');
      $el.html('<div class="text-info">Generating…</div>');

      $.post(APP + '/api/qr_codes.php', {
        action: 'generate_dynamic',
        purpose: 'certificate_verification',
        reference_type: 'students',
        reference_id: studentId,
        student_id: studentId,
        expires_in: 0
      }, function (res) {
        if (!res.success) {
          $el.html('<div class="text-danger">' + escapeHtml(res.message) + '</div>');
          return;
        }
        const qrData = res.data.qr_data;
        $el.html('<div class="small text-success mb-1">Generated</div><div id="dynCertQr" class="d-inline-block p-2 bg-white border rounded"></div>');
        renderQrImage(qrData, 'dynCertQr', 120);
      }, 'json');
    }

    function renderQrImage(data, containerId, size) {
      const qrSize = size || 80;
      const el = document.getElementById(containerId);
      if (!el) return;
      el.innerHTML = '';
      if (!el.id) el.setAttribute('id', containerId);
      if (window.QRCode) {
        new QRCode(el, { text: data, width: qrSize, height: qrSize, correctLevel: QRCode.CorrectLevel.M });
      }
    }

    function showResult(opts, type) {
      const $panel = $('#qrScannerResult');
      if (opts.loading) {
        $panel.html('<div class="d-flex align-items-center"><div class="spinner-border spinner-border-sm text-info me-2"></div>' +
          '<span class="text-info">' + escapeHtml(opts.message || '') + '</span></div>');
        return;
      }
      if (opts.html) {
        $panel.html(opts.html);
      } else {
        const cls = type === 'success' ? 'success' : type === 'danger' ? 'danger' : type === 'info' ? 'info' : 'warning';
        $panel.html('<div class="d-flex align-items-center"><i class="bi bi-' +
          (type === 'success' ? 'check-circle' : type === 'danger' ? 'x-circle' : type === 'info' ? 'info-circle' : 'exclamation-triangle') +
          ' me-2 text-' + cls + '"></i><span>' + escapeHtml(opts.message || '') + '</span></div>');
      }
    }

    function get_qr_setting(key, def) {
      if (key === 'scan_location_default') return '<?= e($qrScanLocation) ?>';
      return def;
    }

    function escapeHtml(str) {
      return String(str || '').replace(/[&<>"']/g, function (c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
      });
    }

    $('#startCameraBtn').off('click').on('click', startCamera);
    $('#stopCameraBtn').off('click').on('click', stopCamera);

    if (!window.Html5Qrcode) {
      const script = document.createElement('script');
      script.src = '<?= app_url('assets/js/html5-qrcode.min.js') ?>';
      script.onload = function () {
        $('#startCameraBtn').prop('disabled', false);
      };
      script.onerror = function () {
        $('#startCameraBtn').prop('disabled', true);
        $('#scannerErrorText').text('Failed to load QR scanner library. Please refresh the page or contact support.');
        $('#scannerError').show();
      };
      $('#startCameraBtn').prop('disabled', true);
      document.head.appendChild(script);
    }

    $(window).on('pagehide', function () {
      stopCamera();
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initQrScanner);
  } else {
    initQrScanner();
  }
})();
</script>
