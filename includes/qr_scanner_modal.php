<?php
/**
 * includes/qr_scanner_modal.php
 * =====================================================================
 * Reusable Camera QR Scanner Modal
 * Uses html5-qrcode to scan patient QR codes and trigger the
 * Centralized Patient Workspace.
 * =====================================================================
 */
?>
<!-- QR Scanner Camera Modal -->
<div class="modal fade" id="patientQrScannerModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static" aria-labelledby="patientQrScannerModalTitle">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
      <div class="modal-header bg-success text-white py-3">
        <h5 id="patientQrScannerModalTitle" class="modal-title fw-bold"><i class="bi bi-qr-code-scan me-2"></i> Scan Patient QR</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" onclick="stopQrScannerCamera()"></button>
      </div>
      <div class="modal-body p-4 text-center bg-light">
        <div id="modalQrVideoContainer" class="position-relative border rounded-3 overflow-hidden bg-dark mx-auto" style="width: 100%; max-width: 380px; height: 280px;">
          <div id="modalQrReader" style="width: 100%; height: 100%;"></div>
          <div id="modalQrOverlay" class="position-absolute top-0 start-0 w-100 h-100 d-flex align-items-center justify-content-center text-white" style="background: rgba(0,0,0,0.6); z-index: 5;">
            <div>
              <i class="bi bi-camera-video fs-1"></i>
              <p class="mb-0 mt-1 small">Starting camera...</p>
            </div>
          </div>
        </div>

        <div class="mt-3">
          <p class="text-muted small mb-2"><i class="bi bi-info-circle me-1"></i> Hold the student's Health Pass QR in front of the camera.</p>
          <div id="modalQrScanAlert" class="alert alert-info py-2 small mb-0" style="display: none;"></div>
        </div>
      </div>
      <div class="modal-footer bg-light border-0 justify-content-between">
        <button type="button" class="btn btn-outline-secondary btn-sm rounded-pill px-3" data-bs-dismiss="modal" onclick="stopQrScannerCamera()">Cancel</button>
        <button type="button" class="btn btn-success btn-sm rounded-pill px-3 fw-bold" id="restartScanBtn" onclick="startQrScannerCamera()" style="display: none;">
          <i class="bi bi-arrow-repeat me-1"></i> Scan Again
        </button>
      </div>
    </div>
  </div>
</div>

<script>
let modalHtml5QrCode = null;
let isModalScanning = false;

async function openQrScannerModal() {
  const modalEl = document.getElementById('patientQrScannerModal');
  if (!modalEl) return;
  const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
  modal.show();
  document.getElementById('modalQrScanAlert').style.display = 'none';
  document.getElementById('restartScanBtn').style.display = 'none';
  setTimeout(startQrScannerCamera, 300);
}

async function startQrScannerCamera() {
  if (isModalScanning) return;
  const overlay = document.getElementById('modalQrOverlay');
  if (overlay) overlay.style.display = 'flex';

  try {
    if (!window.Html5Qrcode) {
      alert('QR Scanner library not loaded.');
      return;
    }
    if (!modalHtml5QrCode) {
      modalHtml5QrCode = new Html5Qrcode('modalQrReader');
    }

    const config = { fps: 10, qrbox: { width: 220, height: 220 } };
    await modalHtml5QrCode.start(
      { facingMode: 'environment' },
      config,
      onQrCodeSuccess,
      onQrCodeError
    );
    isModalScanning = true;
    if (overlay) overlay.style.display = 'none';
  } catch (err) {
    console.error('Camera start error:', err);
    if (overlay) {
      overlay.innerHTML = `<div><i class="bi bi-exclamation-triangle text-warning fs-2"></i><p class="mb-0 mt-1 small">Camera access error or permission denied.</p></div>`;
    }
  }
}

async function stopQrScannerCamera() {
  if (modalHtml5QrCode && isModalScanning) {
    try {
      await modalHtml5QrCode.stop();
      isModalScanning = false;
    } catch(e) { console.warn(e); }
  }
}

function onQrCodeError(errorMessage) {
  // Silent scan frame errors
}

async function onQrCodeSuccess(decodedText) {
  stopQrScannerCamera();
  const alertBox = document.getElementById('modalQrScanAlert');
  alertBox.className = 'alert alert-success py-2 small mb-0';
  alertBox.textContent = 'QR Code detected! Opening patient profile...';
  alertBox.style.display = 'block';

  // Close scanner modal
  BPCModal.hide('patientQrScannerModal');

  // Open Central Patient Workspace with this QR token
  if (typeof openPatientWorkspace === 'function') {
    openPatientWorkspace(decodedText, 'qr_token');
  }
}
</script>

