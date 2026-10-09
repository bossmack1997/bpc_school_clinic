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
        <div id="qrVideoContainer" class="position-relative border rounded-3 overflow-hidden bg-dark" style="min-height: 300px; height: 300px;">
          <div id="qrReader" class="app-qr-reader"></div>
          <div id="qrScannerOverlay"
               class="position-absolute top-0 start-0 w-100 h-100 d-flex align-items-center justify-content-center text-center text-white"
               style="background: rgba(0,0,0,0.7); z-index: 10;">
            <div>
              <i class="bi bi-camera-video" style="font-size: 2rem;"></i>
              <p class="mb-0 mt-2 small">Waiting for camera access...</p>
            </div>
          </div>
        </div>
        <div id="cameraSelectContainer" class="mt-2" style="display: none;">
          <label class="form-label small text-muted mb-1"><i class="bi bi-camera-video me-1"></i> Camera Device</label>
          <select id="cameraSelect" class="form-select form-select-sm"></select>
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
<div class="modal fade" id="queueResultModal" tabindex="-1" aria-labelledby="queueResultModalTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h6 class="modal-title" id="queueResultModalTitle"><?= bpc_icon('check-circle') ?> Check-In Successful</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body text-center py-4">
        <div class="app-muted mb-2">Queue Number</div>
        <div id="queueNumberDisplay" style="font-size:3rem;font-weight:700;line-height:1;color:var(--primary);letter-spacing:-0.03em;">&mdash;</div>
        <div class="mt-3" style="font-weight:600;color:var(--text-primary);" id="queueStudentName">&mdash;</div>
        <div class="app-muted" id="queueStudentDetails">&mdash;</div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
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
    let availableCameras = [];
    let isProcessingScan = false;

    async function initCameraDevices() {
      if (!window.Html5Qrcode || !Html5Qrcode.getCameras) return;
      try {
        console.log('[QR] Initializing scanner...');
        console.log('[QR] Requesting camera permission...');
        const devices = await Html5Qrcode.getCameras();
        if (devices && devices.length > 0) {
          availableCameras = devices;
          const $select = $('#cameraSelect');
          const $container = $('#cameraSelectContainer');
          const curVal = $select.val();
          $select.empty();
          devices.forEach((dev, idx) => {
            const label = dev.label || `Camera ${idx + 1}`;
            $select.append(`<option value="${escapeHtml(dev.id)}">${escapeHtml(label)}</option>`);
          });
          if (curVal && devices.some(d => d.id === curVal)) {
            $select.val(curVal);
          }
          if (devices.length > 1) {
            $container.show();
          } else {
            $container.hide();
          }
        }
      } catch (err) {
        console.warn('[QR] getCameras notice:', err);
      }
    }

    async function startCamera() {
      const $err = $('#scannerError');
      const $overlay = $('#qrScannerOverlay');

      if (scanActive) return;
      $err.hide();

      if (!window.Html5Qrcode) {
        console.error('[QR] QR scanner initialization failed: Html5Qrcode library not found.');
        $('#scannerErrorText').text('QR scanner library not loaded. Please refresh the page.');
        $err.show();
        return;
      }

      $overlay.find('i').attr('class', 'spinner-border spinner-border-sm text-light').css('font-size', '1.5rem');
      $overlay.find('p').text('Starting camera...');
      $overlay.show();

      console.log('[QR] Initializing scanner...');
      console.log('[QR] Requesting camera permission...');

      try {
        if (!availableCameras || availableCameras.length === 0) {
          try {
            availableCameras = await Html5Qrcode.getCameras();
          } catch (camErr) {
            console.warn('[QR] getCameras during startCamera:', camErr);
          }
        }

        const selectedId = $('#cameraSelect').val();
        let cameraConfig = null;

        if (selectedId) {
          cameraConfig = selectedId;
        } else if (availableCameras && availableCameras.length > 0) {
          cameraConfig = availableCameras[0].id;
        } else {
          cameraConfig = { facingMode: "user" };
        }

        if (html5QrCode) {
          try {
            if (html5QrCode.isScanning) {
              await html5QrCode.stop();
            }
            await html5QrCode.clear();
          } catch (e) {
            console.warn('[QR] clearing previous instance:', e);
          }
        }

        html5QrCode = new Html5Qrcode('qrReader');

        const scanConfig = {
          fps: 15,
          qrbox: { width: 220, height: 220 },
          aspectRatio: 1.0
        };

        console.log('[QR] Camera stream starting with config:', cameraConfig);

        await html5QrCode.start(
          cameraConfig,
          scanConfig,
          function (decodedText) {
            if (!scanActive || isProcessingScan) return;
            console.log('[QR] QR code detected.');
            console.log('[QR] QR value:', decodedText);
            onQrScanSuccess(decodedText, 'camera');
          },
          function (scanError) {
            // Normal scan frame without QR
          }
        );

        scanActive = true;
        isProcessingScan = false;
        console.log('[QR] Camera stream started.');
        console.log('[QR] Video ready.');
        console.log('[QR] QR scanner initialized.');
        console.log('[QR] Scanning started.');

        $overlay.hide();
        $('#startCameraBtn').hide();
        $('#stopCameraBtn').show();

        initCameraDevices();

      } catch (err) {
        console.error('[QR] Camera initialization failed:', err);
        handleCameraError(err);
      }
    }

    async function stopCamera() {
      scanActive = false;
      isProcessingScan = false;
      if (html5QrCode) {
        try {
          if (html5QrCode.isScanning) {
            await html5QrCode.stop();
          }
          await html5QrCode.clear();
        } catch (e) {
          console.warn('[QR] stopCamera warning:', e);
        }
      }
      $('#startCameraBtn').show();
      $('#stopCameraBtn').hide();
      $('#qrScannerOverlay').find('i').attr('class', 'bi bi-camera-video').css('font-size', '2rem');
      $('#qrScannerOverlay').find('p').text('Camera stopped. Click Start Camera to resume.');
      $('#qrScannerOverlay').show();
      return Promise.resolve();
    }

    function onQrScanSuccess(decodedText, scanMethod) {
      if (isProcessingScan) return;
      isProcessingScan = true;

      // Stop camera feed while processing student record
      stopCamera().then(function () {
        processQrData(decodedText, scanMethod);
      });
    }

    function handleCameraError(err) {
      const $err = $('#scannerError');
      const $overlay = $('#qrScannerOverlay');

      let msg = 'Unable to access camera: ' + ((err && err.message) || err || 'Unknown error');
      const errStr = (err && (err.name || err.message || err.toString())) || '';

      if (errStr.includes('NotAllowedError') || errStr.includes('PermissionDeniedError') || errStr.includes('Permission denied')) {
        msg = 'Camera permission was denied. Please allow camera access in your browser settings.';
      } else if (errStr.includes('NotFoundError') || errStr.includes('DevicesNotFoundError') || errStr.includes('No camera')) {
        msg = 'No camera was detected on this device.';
      } else if (errStr.includes('NotReadableError') || errStr.includes('TrackStartError')) {
        msg = 'The camera is currently in use by another application. Please close Zoom/Teams/Camera app and try again.';
      } else if (errStr.includes('OverconstrainedError')) {
        msg = 'The requested camera settings are not supported by your webcam.';
      } else if (errStr.includes('AbortError')) {
        msg = 'Camera access was aborted. Please try again.';
      }

      $('#scannerErrorText').text(msg);
      $err.show();

      $overlay.find('i').attr('class', 'bi bi-camera-video-off').css('font-size', '2rem');
      $overlay.find('p').text('Camera unavailable.');
      $overlay.show();
      $('#startCameraBtn').show().prop('disabled', false);
      $('#stopCameraBtn').hide();
    }

    // Switch camera if user selects a different device from dropdown
    $('#cameraSelect').on('change', async function () {
      if (scanActive) {
        await stopCamera();
        await startCamera();
      }
    });

    function processQrData(qrData, scanMethod) {
      const payload = typeof qrData === 'string' ? qrData.trim() : '';
      if (!payload || payload.length < 10) {
        console.warn('[QR] QR detection error: Payload too short or invalid', payload);
        showResult({ valid: false, message: 'Invalid QR code or student record not found.' }, 'danger');
        return;
      }

      console.log('[QR] Fetching student record...');
      showResult({ message: 'Fetching student record…', loading: true }, 'info');

      if (MODE === 'smart') {
        $.post(APP + '/api/qr_codes.php', {
          action: 'smart_scan',
          qr_data: payload,
          perform_checkin: false,
          location: 'Smart QR Scanner',
          scan_method: scanMethod || 'camera',
          csrf_token: csrfToken
        }, function (res) {
          if (!res || !res.success) {
            console.error('[QR] Student lookup failed:', res && res.message);
            showResult({ valid: false, message: (res && res.message) || 'Invalid QR code or student record not found.' }, 'danger');
            return;
          }
          console.log('[QR] Student found.');
          renderSmartScanResult(res, payload, scanMethod);
        }, 'json').fail(function (xhr, status, err) {
          console.error('[QR] Student lookup failed (network):', err || status);
          showResult({ valid: false, message: 'Network error. Please try again.' }, 'danger');
        });
        return;
      }

      // Check-in or verify mode
      const endpoint = APP + '/api/qr_codes.php';
      const data = { action: 'verify', qr_data: payload, csrf_token: csrfToken };
      if (scanMethod) data.scan_method = scanMethod;

      $.post(endpoint, data, function (res) {
        if (!res || !res.success) {
          console.error('[QR] Student lookup failed:', res && res.message);
          showResult({ valid: false, message: (res && res.message) || 'Invalid QR code or student record not found.' }, 'danger');
          return;
        }
        console.log('[QR] Student found.');
        renderVerifiedQrResult(res, payload, scanMethod);
      }, 'json').fail(function () {
        console.error('[QR] Student lookup failed (network)');
        showResult({ valid: false, message: 'Network error. Please try again.' }, 'danger');
      });
    }

    function renderVerifiedQrResult(res, payload, scanMethod) {
      const student = res.student || (res.data && res.data.student) || {};
      const name = (student.first_name || '') + ' ' + (student.last_name || '');
      const details = [student.student_number || '', student.course || '', student.year_level || '', student.section || '']
        .filter(Boolean).join(' · ');

      let html = '';
      html += '<div class="d-flex align-items-start">';
      html += '<div class="bg-white border rounded-3 p-2 me-3" style="min-width:80px">';
      html += '<div id="verifiedQrImg"></div>';
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
        const visitType = $('#visitTypeSelect').val() || 'walk-in';
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
          performCheckin(payload, $('#visitTypeSelect').val() || 'walk-in', scanMethod);
        });
      }
      if (MODE === 'certificate') {
        $('#genDynamicBtn').off('click').on('click', function () {
          generateCertificateDynamicQr(student.id);
        });
      }
    }

    function renderSmartScanResult(res, payload, scanMethod) {
      const student = res.student || {};
      const name = (student.first_name || '') + ' ' + (student.last_name || '');
      const details = [student.student_number || '', student.course || '', student.year_level || '', student.section || '']
        .filter(Boolean).join(' · ');

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
      html += '<div id="verifiedQrImg"></div>';
      html += '</div>';
      html += '<div class="flex-grow-1">';
      html += '<span class="badge bg-success mb-1">Smart QR Verified</span>';
      html += '<div class="fw-bold mb-1">' + escapeHtml(name) + '</div>';
      html += '<div class="text-muted small">' + escapeHtml(details) + '</div>';
      html += '</div></div>';

      html += scenarioHtml;
      html += emergencyStrip;

      if (res.latest_self_bmi) {
        const b = res.latest_self_bmi;
        html += '<div class="alert alert-info py-2 small mb-2">' +
          '<i class="bi bi-activity me-1"></i> <strong>Latest Self-Recorded BMI</strong><br>' +
          'BMI: ' + escapeHtml(b.bmi || '—') + ' (' + escapeHtml(b.bmi_status || '—') + ')<br>' +
          'Height: ' + escapeHtml(b.height_cm || '—') + ' cm · Weight: ' + escapeHtml(b.weight_kg || '—') + ' kg<br>' +
          '<span class="text-muted">Recorded: ' + escapeHtml(b.recorded_at || '') + ' by Student</span>' +
          '</div>';
      }

      if (res.latest_nurse_assessment) {
        const b = res.latest_nurse_assessment;
        html += '<div class="alert alert-success py-2 small mb-2">' +
          '<i class="bi bi-heart-pulse me-1"></i> <strong>Latest Nurse Assessment</strong><br>' +
          'BMI: ' + escapeHtml(b.bmi || '—') + ' (' + escapeHtml(b.bmi_status || '—') + ')<br>' +
          'Height: ' + escapeHtml(b.height_cm || '—') + ' cm · Weight: ' + escapeHtml(b.weight_kg || '—') + ' kg<br>' +
          (b.blood_pressure ? 'Blood Pressure: ' + escapeHtml(b.blood_pressure) + ' mmHg<br>' : '') +
          (b.heart_rate ? 'Heart Rate: ' + escapeHtml(b.heart_rate) + ' bpm<br>' : '') +
          '<span class="text-muted">Recorded: ' + escapeHtml(b.recorded_at || '') + ' by Nurse</span>' +
          '</div>';
      }

      if (!res.latest_self_bmi && !res.latest_nurse_assessment) {
        html += '<div class="alert alert-secondary py-2 small mb-2">No BMI records available.</div>';
      }

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
          $('#queueStudentName').html('<strong>' + escapeHtml((d.student && d.student.name) || '') + '</strong>');
          $('#queueStudentDetails').text([(d.student && d.student.student_number) || '', (d.student && d.student.course) || ''].filter(Boolean).join(' · '));

          if (d.mica_greeting) {
            $('#queueStudentDetails').append('<div class="small text-success mt-2"><i class="bi bi-robot me-1"></i>' + escapeHtml(d.mica_greeting) + '</div>');
          }

          $('#queueResultModal').modal('show');
          processQrData(payload, scanMethod);
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
        if (sid) window.location.href = APP + '/pages/nurse/queue.php?priority=urgent&student_id=' + sid;
      });

      $('#smartApptBtn').off('click').on('click', function () {
        const sid = $('#smartStudentId').val();
        if (sid) window.location.href = APP + '/pages/nurse/walkin.php?student_id=' + sid;
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

        if (res && res.success) {
          const d = res.data || res;
          const student = d.student || {};
          const studentName = student.name || ((student.first_name || '') + ' ' + (student.last_name || '')) || 'Student';
          const studentDetails = [student.student_number || '', student.course || ''].filter(Boolean).join(' · ');

          $('#queueNumberDisplay').text(d.queue_number || '—');
          $('#queueStudentName').html('<strong>' + escapeHtml(studentName) + '</strong>');
          $('#queueStudentDetails').text(studentDetails);
          $('#queueResultModal').modal('show');
        } else {
          showResult({ valid: false, message: (res && res.message) || 'Check-in failed.' }, 'danger');
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

    if (window.Html5Qrcode) {
      initCameraDevices().then(function () {
        setTimeout(startCamera, 300);
      });
    } else {
      const script = document.createElement('script');
      script.src = '<?= app_url('assets/js/html5-qrcode.min.js') ?>';
      script.onload = function () {
        $('#startCameraBtn').prop('disabled', false);
        initCameraDevices().then(function () {
          setTimeout(startCamera, 300);
        });
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
