<?php
/**
 * components/mica.php
 * ------------------------------------------------------------------
 * SINGLE MICA (Medical Intelligent Clinic Assistant) component.
 * Replaces includes/mica_widget.php — provides:
 *   • SOS Emergency Modal (GPS + type selection)
 *   • Active Emergency Real-Time GPS Tracking Card
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

$micaTz = defined('MICA_TIMEZONE') ? MICA_TIMEZONE : 'Asia/Manila';
$micaHour = (int)date('H');
try {
    $dt = new DateTime('now', new DateTimeZone($micaTz));
    $micaHour = (int)$dt->format('H');
} catch (Exception $e) { /* fallback */ }
if ($micaHour >= 5 && $micaHour < 12) $micaTimeOfDay = 'Good morning';
elseif ($micaHour >= 12 && $micaHour < 18) $micaTimeOfDay = 'Good afternoon';
else $micaTimeOfDay = 'Good evening';
$micaGreeting = $micaTimeOfDay . ', ' . $micaFirstName . '. I am MICA, your Medical Intelligent Clinic Assistant from BPC. How can I help you today?';
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
            <small class="text-muted">Location access is required to send your current location to the school clinic during an emergency.</small>
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
            <i class="bi bi-geo-alt-fill me-1"></i> <span id="locationText">Acquiring high-accuracy GPS coordinates...</span>
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
<!-- Student Active SOS Live Tracking Widget (Compact & Expandable)  -->
<!-- ================================================================ -->
<div id="studentActiveSosBanner" class="sos-floating-widget collapsed" style="display:none;" aria-live="assertive">
  <!-- Collapsed Bar (Default Minimal State) -->
  <div class="sos-widget-bar" id="sosWidgetBar">
    <div class="sos-widget-left" id="sosToggleArea">
      <span class="sos-pulse-dot"></span>
      <span class="sos-widget-title">
        <i class="bi bi-shield-fill-exclamation text-danger me-1"></i>
        <strong>Emergency Active</strong>
        <span class="sos-badge-pill" id="sosStatusPill"><i class="bi bi-broadcast me-1"></i>Location Sharing Active</span>
      </span>
    </div>
    <div class="sos-widget-actions">
      <button type="button" class="btn btn-sm btn-outline-danger sos-quick-cancel-btn" id="btnCancelActiveSosQuick" title="Cancel Emergency">
        <i class="bi bi-x-circle me-1"></i> Cancel
      </button>
      <button type="button" class="sos-expand-toggle-btn" id="sosExpandToggle" aria-label="Toggle Details" title="Expand / Collapse Details">
        <i class="bi bi-chevron-up" id="sosExpandIcon"></i>
      </button>
    </div>
  </div>

  <!-- Expandable Details Body -->
  <div class="sos-widget-details" id="sosWidgetDetails">
    <div class="sos-details-inner">
      <div class="small text-dark fw-medium mb-2" id="sosWidgetSubTitle">
        <i class="bi bi-geo-alt-fill text-danger me-1"></i> Real-time location is shared with the School Clinic:
      </div>
      <div class="sos-coords-box p-2 rounded bg-light border mb-2">
        <div class="d-flex align-items-center gap-2 small">
          <span class="text-danger fw-bold">GPS:</span>
          <span id="sosCurrentCoords" class="fw-semibold text-dark">Detecting coordinates...</span>
        </div>
        <div class="text-muted small mt-1" style="font-size: 0.75rem;">
          <i class="bi bi-clock me-1"></i> Last updated: <span id="sosLastUpdatedText">Just now</span>
        </div>
      </div>
      <button type="button" class="btn btn-danger btn-sm w-100 fw-semibold" id="btnCancelActiveSos">
        <i class="bi bi-x-circle-fill me-1"></i> Cancel Emergency SOS
      </button>
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

window.MICA_CONFIG = {
  timezone: '<?= defined('MICA_TIMEZONE') ? e(MICA_TIMEZONE) : 'Asia/Manila' ?>',
  wakeWords: <?= json_encode(array_values(array_filter(array_map('trim', explode(',', defined('MICA_WAKE_WORDS') ? MICA_WAKE_WORDS : 'hey mica, hello mica, hi mica'))))) ?>,
  continuousTimeoutMs: <?= defined('MICA_CONTINUOUS_TIMEOUT_MS') ? (int)MICA_CONTINUOUS_TIMEOUT_MS : 8000 ?>,
  minConfidence: <?= defined('MICA_MIN_CONFIDENCE') ? (float)MICA_MIN_CONFIDENCE : 0.35 ?>,
  greeting: '<?= $micaGreeting ?>',
  ttsProvider: '<?= defined('MICA_TTS_PROVIDER') ? MICA_TTS_PROVIDER : 'browser' ?>',
};

// Backward-compatible alias
window.MICA_GREETING = window.MICA_CONFIG.greeting;
window.__MICA_SOS_LOCATION__ = null;
window.__ACTIVE_SOS_ID__ = null;
window.__SOS_WATCH_ID__ = null;
window.__SOS_LAST_UPDATE_TIME__ = 0;
window.__SOS_LAST_LAT__ = null;
window.__SOS_LAST_LNG__ = null;
window.__MICA_SOS_LOC_TIME__ = 0;

// Requests device GPS location with robust permission checking, high-accuracy preference, and truthful fallbacks.
window.getLocation = function (forceFresh) {
  return new Promise(function (resolve) {
    var statusBox = document.getElementById('locationStatus');
    var statusText = document.getElementById('locationText');
    if (statusBox) statusBox.classList.remove('d-none');

    // Use recently acquired fresh location (< 15s) if available and not forced
    if (!forceFresh && window.__MICA_SOS_LOCATION__ && (Date.now() - window.__MICA_SOS_LOC_TIME__ < 15000)) {
      if (statusText && window.__MICA_SOS_LOCATION__.lat) {
        statusText.innerHTML = '📍 GPS ready: <strong>' + window.__MICA_SOS_LOCATION__.lat.toFixed(5) + ', ' + window.__MICA_SOS_LOCATION__.lng.toFixed(5) + '</strong>';
        if (statusBox) statusBox.className = 'alert alert-success small py-2';
      }
      resolve(window.__MICA_SOS_LOCATION__);
      return;
    }

    if (!navigator.geolocation) {
      console.warn('[SOS Geolocation] navigator.geolocation not supported in this browser.');
      window.__MICA_SOS_LOCATION__ = null;
      if (statusText) {
        statusText.innerHTML = '<i class="bi bi-geo-alt-slash me-1"></i> Geolocation is not supported by this browser. Your emergency request will still be sent.';
        if (statusBox) statusBox.className = 'alert alert-warning small py-2';
      }
      resolve(null);
      return;
    }

    var settled = false;
    function finish(val) {
      if (settled) return;
      settled = true;
      window.__MICA_SOS_LOCATION__ = val;
      window.__MICA_SOS_LOC_TIME__ = val ? Date.now() : 0;
      resolve(val);
    }

    function doGeolocationRequest() {
      if (statusText) {
        statusText.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Acquiring high-accuracy GPS coordinates...';
        if (statusBox) statusBox.className = 'alert alert-info small py-2';
      }
      console.log('[SOS Geolocation] Starting geolocation request (highAccuracy: true)...');

      navigator.geolocation.getCurrentPosition(
        function (pos) {
          var lat = pos.coords.latitude;
          var lng = pos.coords.longitude;
          var acc = pos.coords.accuracy ? (Math.round(pos.coords.accuracy) + 'm') : null;
          console.log('[SOS Geolocation] Success: lat=' + lat + ', lng=' + lng + ', accuracy=' + acc);

          if (statusText) {
            statusText.innerHTML = '📍 GPS acquired: <strong>' + lat.toFixed(5) + ', ' + lng.toFixed(5) + '</strong>' + (acc ? ' (±' + acc + ')' : '');
            if (statusBox) statusBox.className = 'alert alert-success small py-2';
          }
          finish({ lat: lat, lng: lng, accuracy: acc });
        },
        function (err) {
          var errCode = err ? err.code : 0;
          var errMsg = err ? err.message : 'Unknown error';
          var codeName = errCode === 1 ? 'PERMISSION_DENIED' : (errCode === 2 ? 'POSITION_UNAVAILABLE' : (errCode === 3 ? 'TIMEOUT' : 'UNKNOWN'));
          console.warn('[SOS Geolocation] Error: code=' + errCode + ' (' + codeName + ') - ' + errMsg);

          // Code 1: Permission Denied
          if (errCode === 1) {
            if (statusText) {
              statusText.innerHTML = '<i class="bi bi-shield-lock me-1"></i> Location permission was not allowed. Your emergency request will still be sent, but without GPS coordinates.';
              if (statusBox) statusBox.className = 'alert alert-warning small py-2';
            }
            finish(null);
            return;
          }

          // Fallback: Try standard accuracy if high-accuracy timed out or unavailable
          console.log('[SOS Geolocation] High-accuracy failed (' + codeName + '). Attempting standard accuracy fallback...');
          if (statusText) {
            statusText.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Refining position via standard network location...';
          }

          navigator.geolocation.getCurrentPosition(
            function (pos2) {
              var lat2 = pos2.coords.latitude;
              var lng2 = pos2.coords.longitude;
              var acc2 = pos2.coords.accuracy ? (Math.round(pos2.coords.accuracy) + 'm') : null;
              console.log('[SOS Geolocation] Fallback Success: lat=' + lat2 + ', lng=' + lng2 + ', accuracy=' + acc2);

              if (statusText) {
                statusText.innerHTML = '📍 Location acquired: <strong>' + lat2.toFixed(5) + ', ' + lng2.toFixed(5) + '</strong>' + (acc2 ? ' (±' + acc2 + ')' : '');
                if (statusBox) statusBox.className = 'alert alert-success small py-2';
              }
              finish({ lat: lat2, lng: lng2, accuracy: acc2 });
            },
            function (err2) {
              var fbCode = err2 ? err2.code : 0;
              var fbName = fbCode === 1 ? 'PERMISSION_DENIED' : (fbCode === 2 ? 'POSITION_UNAVAILABLE' : (fbCode === 3 ? 'TIMEOUT' : 'UNKNOWN'));
              console.warn('[SOS Geolocation] Fallback error: code=' + fbCode + ' (' + fbName + ') - ' + (err2 ? err2.message : ''));

              if (statusText) {
                statusText.innerHTML = '<i class="bi bi-geo-alt-slash me-1"></i> Current location could not be obtained. Your emergency request will still be sent to the clinic.';
                if (statusBox) statusBox.className = 'alert alert-warning small py-2';
              }
              finish(null);
            },
            { enableHighAccuracy: false, timeout: 6000, maximumAge: 0 }
          );
        },
        { enableHighAccuracy: true, timeout: 8000, maximumAge: 0 }
      );
    }

    // Inspect Permissions API if available
    if (navigator.permissions && navigator.permissions.query) {
      navigator.permissions.query({ name: 'geolocation' }).then(function (p) {
        console.log('[SOS Geolocation] Permission state: ' + p.state);
        if (p.state === 'denied') {
          console.warn('[SOS Geolocation] Location permission is blocked in browser settings.');
          if (statusText) {
            statusText.innerHTML = '<i class="bi bi-shield-lock me-1"></i> Location permission is blocked. Your emergency request will still be sent, but your current location could not be obtained.';
            if (statusBox) statusBox.className = 'alert alert-warning small py-2';
          }
          finish(null);
          return;
        }
        doGeolocationRequest();
      }).catch(function () {
        doGeolocationRequest();
      });
    } else {
      doGeolocationRequest();
    }
  });
};

// Auto pre-fetch GPS whenever SOS modal is opened
$(document).on('shown.bs.modal', '#sosModal', function () {
  window.getLocation(true);
});

// Start Continuous Real-Time Tracking for active SOS
window.startSosLocationTracking = function (emergencyId, initialLat, initialLng, initialAcc) {
  window.__ACTIVE_SOS_ID__ = emergencyId;
  window.__SOS_LAST_UPDATE_TIME__ = Date.now();
  window.__SOS_LAST_LAT__ = initialLat;
  window.__SOS_LAST_LNG__ = initialLng;

  var $banner = $('#studentActiveSosBanner');
  if ($banner.length) {
    $banner.fadeIn(300);
    updateBannerCoords(initialLat, initialLng, initialAcc);
  }

  if (!navigator.geolocation) return;

  // Clear existing watch if any
  if (window.__SOS_WATCH_ID__ !== null) {
    navigator.geolocation.clearWatch(window.__SOS_WATCH_ID__);
  }

  window.__SOS_WATCH_ID__ = navigator.geolocation.watchPosition(
    function (pos) {
      var lat = pos.coords.latitude;
      var lng = pos.coords.longitude;
      var acc = pos.coords.accuracy ? (Math.round(pos.coords.accuracy) + 'm') : null;

      // Throttle: Send update if moved > 5m or at least 8 seconds elapsed
      var now = Date.now();
      var timeDiff = (now - window.__SOS_LAST_UPDATE_TIME__) / 1000;
      var moved = true;
      if (window.__SOS_LAST_LAT__ !== null && window.__SOS_LAST_LNG__ !== null) {
        var dLat = Math.abs(lat - window.__SOS_LAST_LAT__);
        var dLng = Math.abs(lng - window.__SOS_LAST_LNG__);
        if (dLat < 0.00005 && dLng < 0.00005 && timeDiff < 8) {
          moved = false;
        }
      }

      if (moved && window.__ACTIVE_SOS_ID__) {
        window.__SOS_LAST_UPDATE_TIME__ = now;
        window.__SOS_LAST_LAT__ = lat;
        window.__SOS_LAST_LNG__ = lng;
        updateBannerCoords(lat, lng, acc);

        var csrf = $('meta[name="csrf-token"]').attr('content') || (window.C && window.C.config && window.C.config.csrfToken);
        $.post(APP_URL + '/api/emergencies.php', {
          action: 'update_location',
          emergency_id: window.__ACTIVE_SOS_ID__,
          latitude: lat,
          longitude: lng,
          accuracy: acc,
          csrf_token: csrf
        }, function (res) {
          if (res && res.success) {
            if (res.tracking_active === false) {
              window.stopSosLocationTracking('Emergency Resolved', 'Clinic staff has resolved this emergency. Location sharing has stopped.');
            }
          }
        }, 'json');
      }
    },
    function (err) {
      console.warn('[SOS GPS Watch Error]', err.message);
    },
    { enableHighAccuracy: true, maximumAge: 3000, timeout: 15000 }
  );
};

// Stop Continuous Real-Time Tracking
window.stopSosLocationTracking = function (title, msg) {
  if (window.__SOS_WATCH_ID__ !== null) {
    navigator.geolocation.clearWatch(window.__SOS_WATCH_ID__);
    window.__SOS_WATCH_ID__ = null;
  }
  window.__ACTIVE_SOS_ID__ = null;
  $('#studentActiveSosBanner').fadeOut(300);
  if (title && msg && typeof Swal !== 'undefined') {
    Swal.fire({ icon: 'info', title: title, text: msg });
  }
};

function updateBannerCoords(lat, lng, acc) {
  var hasCoords = (lat !== null && lat !== undefined && lng !== null && lng !== undefined && isFinite(lat) && isFinite(lng));
  var $pill = $('#sosStatusPill');
  var $subtitle = $('#sosWidgetSubTitle');
  var $coords = $('#sosCurrentCoords');

  if (hasCoords) {
    if ($pill.length) {
      $pill.removeClass('sos-badge-unavailable').html('<i class="bi bi-broadcast me-1"></i>Location Sharing Active');
    }
    if ($subtitle.length) {
      $subtitle.html('<i class="bi bi-geo-alt-fill text-danger me-1"></i> Real-time location is shared with the School Clinic:');
    }
    if ($coords.length) {
      $coords.html('<strong>' + Number(lat).toFixed(6) + ', ' + Number(lng).toFixed(6) + '</strong>' + (acc ? ' (±' + acc + ')' : ''));
    }
  } else {
    if ($pill.length) {
      $pill.addClass('sos-badge-unavailable').html('<i class="bi bi-geo-alt-slash me-1"></i>Location Unavailable');
    }
    if ($subtitle.length) {
      $subtitle.html('<i class="bi bi-exclamation-triangle text-warning me-1"></i> Emergency reported without GPS coordinates.');
    }
    if ($coords.length) {
      $coords.html('<span class="text-muted"><i class="bi bi-geo-alt-slash me-1"></i>No GPS coordinates available</span>');
    }
  }
  $('#sosLastUpdatedText').text('Just now');
}

// Auto-check active SOS status on student pages
function checkStudentActiveSos() {
  if (window.MICA_USER && window.MICA_USER.role === 'student') {
    $.get(APP_URL + '/api/emergencies.php', { action: 'active_sos' }, function (res) {
      if (res && res.success && res.has_active_sos && res.emergency) {
        var emg = res.emergency;
        window.startSosLocationTracking(emg.id, emg.latitude, emg.longitude, emg.location_accuracy);
      }
    }, 'json');
  }
}

// Toggle expand/collapse for Floating Emergency Widget
$(document).on('click', '#sosExpandToggle, #sosToggleArea', function (e) {
  e.stopPropagation();
  var $widget = $('#studentActiveSosBanner');
  var isExpanded = $widget.hasClass('expanded');
  if (isExpanded) {
    $widget.removeClass('expanded').addClass('collapsed');
    $('#sosExpandIcon').removeClass('bi-chevron-down').addClass('bi-chevron-up');
  } else {
    $widget.removeClass('collapsed').addClass('expanded');
    $('#sosExpandIcon').removeClass('bi-chevron-up').addClass('bi-chevron-down');
  }
});

// Cancel Active SOS handler (shared by quick-cancel and full cancel buttons)
$(document).on('click', '#btnCancelActiveSos, #btnCancelActiveSosQuick', function (e) {
  e.stopPropagation();
  if (!window.__ACTIVE_SOS_ID__) return;
  var emgId = window.__ACTIVE_SOS_ID__;
  var csrf = $('meta[name="csrf-token"]').attr('content');

  Swal.fire({
    title: 'Cancel Emergency?',
    text: 'Are you sure you want to cancel this emergency request? Location sharing will stop immediately.',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#dc3545',
    confirmButtonText: 'Yes, Cancel Emergency'
  }).then(function (result) {
    if (result.isConfirmed) {
      $.post(APP_URL + '/api/emergencies.php', {
        action: 'cancel_sos',
        emergency_id: emgId,
        csrf_token: csrf
      }, function (res) {
        if (res && res.success) {
          window.stopSosLocationTracking('Emergency Cancelled', 'Your emergency request has been cancelled. Location sharing has stopped.');
        } else {
          Swal.fire('Error', (res && res.message) || 'Failed to cancel emergency.', 'error');
        }
      }, 'json');
    }
  });
});

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
    submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Acquiring GPS & Sending...';
    submitBtn.disabled = true;

    // 1) Obtain device GPS location
    window.getLocation().then(function (loc) {
      var lat = loc ? loc.lat : null;
      var lng = loc ? loc.lng : null;
      var acc = loc ? loc.accuracy : null;
      var csrf = $('meta[name="csrf-token"]').attr('content');

      // Post directly to api/emergencies.php
      $.post(APP_URL + '/api/emergencies.php', {
        action: 'sos',
        emergency_type: type,
        description: desc,
        latitude: lat,
        longitude: lng,
        accuracy: acc,
        csrf_token: csrf
      }, function (res) {
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;

        var modalEl = document.getElementById('sosModal');
        if (modalEl) {
          var bsModal = bootstrap.Modal.getInstance(modalEl);
          if (bsModal) bsModal.hide();
        }

        if (res && res.success) {
          var emgId = res.emergency_id;
          Swal.fire({
            icon: 'success',
            title: '🚨 SOS Activated!',
            html: (res.message || 'Your emergency request has been sent to the School Clinic.') +
                  (lat ? '<br><small class="text-success mt-2 d-block">📍 GPS Coordinates transmitted. Live tracking started.</small>' : '<br><small class="text-warning mt-2 d-block">⚠️ Sent without exact GPS location.</small>'),
            timer: 5000,
            timerProgressBar: true
          });

          // Start continuous real-time location tracking
          window.startSosLocationTracking(emgId, lat, lng, acc);
        } else {
          Swal.fire({ icon: 'error', title: 'SOS Failed', text: (res && res.message) || 'Failed to send SOS. Please try again.' });
        }
        window.__MICA_SOS_LOCATION__ = null;
      }, 'json').fail(function () {
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
        Swal.fire({ icon: 'error', title: 'Connection Error', text: 'Failed to send SOS. Please contact the clinic directly.' });
      });
    });
  }

  var form = document.getElementById('sosForm');
  if (form) form.addEventListener('submit', submitSOS);

  $(document).ready(function () {
    checkStudentActiveSos();
  });
})();
</script>
