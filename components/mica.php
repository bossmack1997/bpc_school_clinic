<?php
/**
 * components/mica.php
 * ------------------------------------------------------------------
 * SINGLE MICA (Medical Intelligent Clinic Assistant) component.
 * Replaces includes/mica_widget.php — provides:
 *   • SOS Emergency Modal (GPS + type selection)
 *   • User context as JavaScript (first_name, role, full name)
 *   • Personalized MICA greeting
 *   • Only EVER rendered ONCE per page (guarded)
 * ------------------------------------------------------------------
 */
if (defined('MICA_COMPONENT_LOADED')) return;
define('MICA_COMPONENT_LOADED', true);

$micaUser = current_user();
if (!$micaUser) return;

$micaFirstName = e($micaUser['first_name'] ?? 'there');
$micaFullName  = e(($micaUser['first_name'] ?? '') . ' ' . ($micaUser['last_name'] ?? ''));
$micaRole      = e($micaUser['role'] ?? 'student');
?>
<!-- ================================================================ -->
<!-- SOS Emergency Modal (Global - used by MICA on every page)        -->
<!-- ================================================================ -->
<div class="modal fade" id="sosModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content border-0 rounded-4 shadow-lg">
      <div class="modal-header border-0 bg-danger text-white rounded-top-4">
        <h5 class="modal-title"><i class="bi bi-exclamation-triangle-fill me-2"></i>SOS Emergency</h5>
        <button class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form id="sosForm" novalidate>
        <div class="modal-body">
          <div class="text-center mb-3">
            <i class="bi bi-shield-exclamation text-danger" style="font-size:3rem;"></i>
            <p class="mt-2 mb-0 fw-semibold">Are you experiencing a medical emergency?</p>
            <small class="text-muted">Help will be sent to the clinic immediately.</small>
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold" for="emergencyType">Emergency Type</label>
            <select class="form-select" name="emergency_type" id="emergencyType" required>
              <option value="">Select type...</option>
              <option value="Chest Pain">Chest Pain</option>
              <option value="Difficulty Breathing">Difficulty Breathing</option>
              <option value="Severe Bleeding">Severe Bleeding</option>
              <option value="Unconscious">Unconscious</option>
              <option value="Seizure">Seizure</option>
              <option value="Heart Attack">Heart Attack</option>
              <option value="Stroke">Stroke</option>
              <option value="Choking">Choking</option>
              <option value="Other">Other Emergency</option>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold" for="emergencyDesc">Description <small class="text-muted">(optional)</small></label>
            <textarea class="form-control" name="description" id="emergencyDesc" rows="2" placeholder="Briefly describe what happened..."></textarea>
          </div>
          <div id="locationStatus" class="alert alert-info small py-2 d-none">
            <i class="bi bi-geo-alt-fill me-1"></i> <span id="locationText">Fetching your location...</span>
          </div>
        </div>
        <div class="modal-footer border-0 justify-content-center gap-2">
          <button class="btn btn-secondary px-4" type="button" data-bs-dismiss="modal">Cancel</button>
          <button class="btn btn-danger px-4" type="submit" id="sosSubmitBtn">
            <i class="bi bi-exclamation-triangle-fill me-1"></i> Activate SOS
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ================================================================ -->
<!-- MICA User Data (server-rendered, consumed by assets/js/mica.js)  -->
<!-- ================================================================ -->
<script>
window.MICA_USER = {
  firstName: '<?= $micaFirstName ?>',
  fullName: '<?= $micaFullName ?>',
  role: '<?= $micaRole ?>',
};

window.MICA_GREETING = 'Hello, <?= $micaFirstName ?>. Welcome back to the BPC School Clinic. I\'m MICA, your Medical Intelligent Clinic Assistant. How may I assist you today?';

window.__MICA_SOS_LOCATION__ = null;

// Requests the browser GPS location AFTER the student confirms & submits
// the SOS request. Resolves with {lat, lng} on success, or with null when
// permission is denied / unsupported / timeout — the SOS is NEVER blocked.
// Returns a Promise so callers can await the result before sending.
window.getLocation = function () {
  return new Promise(function (resolve) {
    var statusBox = document.getElementById('locationStatus');
    var statusText = document.getElementById('locationText');
    if (statusBox) statusBox.classList.remove('d-none');

    if (!navigator.geolocation) {
      window.__MICA_SOS_LOCATION__ = null;
      if (statusText) {
        statusText.textContent = 'Location not supported by this browser.';
        statusBox.className = 'alert alert-warning small py-2';
      }
      resolve(null);
      return;
    }

    if (statusText) {
      statusText.textContent = 'Fetching your location...';
      statusBox.className = 'alert alert-info small py-2';
    }

    var settled = false;
    function finish(val) {
      if (settled) return;
      settled = true;
      clearTimeout(timeout);
      window.__MICA_SOS_LOCATION__ = val;
      resolve(val);
    }

    var timeout = setTimeout(function () {
      if (statusText) {
        statusText.textContent = 'Location request timed out. You can still send SOS without location.';
        statusBox.className = 'alert alert-warning small py-2';
      }
      finish(null);
    }, 8000);

    navigator.geolocation.getCurrentPosition(
      function (pos) {
        var lat = pos.coords.latitude;
        var lng = pos.coords.longitude;
        if (statusText) {
          statusText.textContent = '✅ Location acquired: ' + lat.toFixed(5) + ', ' + lng.toFixed(5);
          statusBox.className = 'alert alert-success small py-2';
        }
        finish({ lat: lat, lng: lng });
      },
      function () {
        if (statusText) {
          statusText.textContent = 'Location access denied. You can still send SOS.';
          statusBox.className = 'alert alert-warning small py-2';
        }
        finish(null);
      },
      { enableHighAccuracy: true, timeout: 6000 }
    );
  });
};

(function () {
  function submitSOS(e) {
    e.preventDefault();
    var type = document.getElementById('emergencyType').value;
    var desc = document.getElementById('emergencyDesc').value.trim() || type + ' - Emergency via MICA';

    if (!type) {
      Swal.fire({ icon: 'warning', title: 'Select Emergency Type', text: 'Please select an emergency type before submitting.' });
      return;
    }

    var submitBtn = document.getElementById('sosSubmitBtn');
    var originalText = submitBtn.innerHTML;
    submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Sending SOS...';
    submitBtn.disabled = true;

    // 1) Request GPS location AFTER the SOS is confirmed (never blocks).
    // 2) Send the SOS with location when granted, without it when denied.
    window.getLocation().then(function (loc) {
      var lat = loc ? loc.lat : null;
      var lng = loc ? loc.lng : null;

      if (typeof MICA === 'undefined' || typeof MICA.sos !== 'function') {
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
        Swal.fire({ icon: 'error', title: 'Error', text: 'SOS service is unavailable. Please contact the clinic directly.' });
        return;
      }

      return MICA.sos(type, desc, lat, lng).then(function (res) {
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;

        var modalEl = document.getElementById('sosModal');
        if (modalEl) {
          var bsModal = bootstrap.Modal.getInstance(modalEl);
          if (bsModal) bsModal.hide();
        }

        if (res && res.success) {
          Swal.fire({ icon: 'success', title: 'SOS Activated!', html: res.message || 'Your emergency request has been successfully sent to the School Clinic. Please remain where you are while waiting for assistance.', timer: 6000, timerProgressBar: true });
        } else {
          Swal.fire({ icon: 'error', title: 'SOS Failed', text: (res && res.message) || 'Failed to send SOS. Please try again.' });
        }
        window.__MICA_SOS_LOCATION__ = null;
      }).catch(function () {
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
        Swal.fire({ icon: 'error', title: 'Error', text: 'Failed to send SOS. Please try again.' });
      });
    });
  }

  var form = document.getElementById('sosForm');
  if (form) form.addEventListener('submit', submitSOS);
})();
</script>

