<?php
/**
 * components/voice_enrollment.php
 * --------------------------------------------------------------
 * SINGLE reusable Voice Enrollment card for all roles.
 * Included from student/nurse/admin profile pages (Account Settings).
 *
 * Features:
 *  - Glassmorphism card with BPC branding (green/white theme).
 *  - Enrollment status badge (Enrolled / Not Enrolled / Provider down).
 *  - Reads the verification phrase with the user's real first name.
 *  - Register Voice, Re-register Voice, Test Voice, Delete Voice.
 *  - Microphone status + recording animation + verification indicator.
 *  - Communicates with the SINGLE api/voice.php endpoint via the global
 *    MICA_VOICE API (no duplicate AJAX / no duplicate audio capture).
 *
 * SECURITY: Only renders for an authenticated user. All actions are
 *           CSRF-protected server-side and scoped to the session user.
 *
 * NOTE: This inline script runs in the middle of <body>, BEFORE jQuery /
 *       SweetAlert2 / MICA are loaded (those load at the bottom of the page
 *       in components/footer.php). So we poll for those globals before
 *       initializing the card.
 */

if (!is_logged_in()) return;

$vUser = current_user();
$vFirstName = e($vUser['first_name'] ?? 'there');
$vPhrase = "My name is {$vFirstName}. This is my Bulacan Polytechnic College School Clinic voice verification.";
?>
<!-- ================================================================ -->
<!-- Voice Enrollment Card (reusable across student/nurse/admin)      -->
<!-- ================================================================ -->
<div class="card voice-card glass-card mb-3" id="voiceEnrollCard">
  <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
    <div class="d-flex align-items-center gap-2">
      <i class="bi bi-mic-fill voice-card-icon"></i>
      <span class="fw-semibold">Voice Enrollment</span>
    </div>
    <span class="voice-status-badge" id="voiceStatusBadge">
      <span class="spinner-border spinner-border-sm me-1"></span> Checking...
    </span>
  </div>

  <div class="card-body">
    <!-- Instructions -->
    <div class="voice-instructions mb-3">
      <div class="d-flex align-items-center gap-2 mb-2">
        <i class="bi bi-shield-lock-fill text-success"></i>
        <span class="fw-semibold small">Voice Mode requires Voice Verification</span>
      </div>
      <p class="small text-muted mb-2">
        Voice Mode is only available to the authenticated account owner. To enroll,
        read the phrase below for about <strong>5–10 seconds</strong> in a quiet place.
        Only a secure voice reference is stored — raw audio is never saved.
      </p>
      <div class="voice-phrase-box">
        <i class="bi bi-quote me-1"></i>
        <span id="voicePhraseText"><?= e($vPhrase) ?></span>
      </div>
    </div>

    <!-- Microphone Status / Recording -->
    <div class="voice-mic-status d-none" id="voiceMicStatus">
      <div class="voice-mic-pulse"><i class="bi bi-mic-fill"></i></div>
      <span id="voiceMicText">Recording... Speak the phrase clearly.</span>
    </div>

    <!-- Verification indicator -->
    <div class="voice-verify-indicator d-none" id="voiceVerifyIndicator">
      <i class="bi bi-fingerprint me-1"></i>
      <span id="voiceVerifyText">Verifying voice...</span>
    </div>

    <!-- Actions -->
    <div class="d-flex flex-wrap gap-2 mt-3" id="voiceActions">
      <button class="btn btn-success btn-sm" id="voiceEnrollBtn" type="button">
        <i class="bi bi-person-plus-fill me-1"></i> Register Voice
      </button>
      <button class="btn btn-outline-success btn-sm d-none" id="voiceReEnrollBtn" type="button">
        <i class="bi bi-arrow-counterclockwise me-1"></i> Re-register Voice
      </button>
      <button class="btn btn-outline-primary btn-sm d-none" id="voiceTestBtn" type="button">
        <i class="bi bi-check2-circle me-1"></i> Test Voice Verification
      </button>
      <button class="btn btn-outline-danger btn-sm d-none" id="voiceDeleteBtn" type="button">
        <i class="bi bi-trash me-1"></i> Delete Voice Profile
      </button>
    </div>

    <div class="text-muted small mt-3" id="voiceProviderInfo"></div>
  </div>
</div>

<script>
(function () {
  'use strict';

  var csrfToken = (document.querySelector('meta[name="csrf-token"]')
    ? document.querySelector('meta[name="csrf-token"]').getAttribute('content') : '');
  var APP_URL = (typeof APP_URL !== 'undefined' && APP_URL) ? APP_URL : window.location.origin + '/bpc_clinic_system';

  // The component's inline script runs BEFORE jQuery / SweetAlert2 / MICA
  // are loaded (they load at the bottom of the page via components/footer.php).
  // Poll until the required globals exist, then initialize the card once.
  var maxRetries = 300;
  var retryDelay = 50;

  function tryInit(attempt) {
    if (typeof window.jQuery === 'undefined' ||
        typeof window.Swal === 'undefined' ||
        typeof window.MICA_VOICE === 'undefined' ||
        typeof window.MICA_VOICE.captureSignature !== 'function') {
      if (attempt >= maxRetries) {
        // Fail-safe: still allow status to load once jQuery is available,
        // even if MICA hasn't finished initializing.
        if (typeof window.jQuery !== 'undefined') {
          initVoiceCard();
        }
        return;
      }
      setTimeout(function () { tryInit(attempt + 1); }, retryDelay);
      return;
    }
    initVoiceCard();
  }

  function initVoiceCard() {
    var $ = window.jQuery;
    var $card = $('#voiceEnrollCard');
    if (!$card.length) return;

    var $badge = $('#voiceStatusBadge');
    var $enrollBtn = $('#voiceEnrollBtn');
    var $reEnrollBtn = $('#voiceReEnrollBtn');
    var $testBtn = $('#voiceTestBtn');
    var $deleteBtn = $('#voiceDeleteBtn');
    var $micStatus = $('#voiceMicStatus');
    var $micText = $('#voiceMicText');
    var $verifyIndicator = $('#voiceVerifyIndicator');
    var $verifyText = $('#voiceVerifyText');
    var $providerInfo = $('#voiceProviderInfo');

    function setBadge(html, cls) {
      $badge.attr('class', 'voice-status-badge ' + (cls || ''));
      $badge.html(html);
    }

    function setBusy(busy) {
      $enrollBtn.prop('disabled', busy);
      $reEnrollBtn.prop('disabled', busy);
      $testBtn.prop('disabled', busy);
      $deleteBtn.prop('disabled', busy);
    }

    function refreshStatus() {
      $.get(APP_URL + '/api/voice.php', { action: 'status' }, function (res) {
        if (!res.success) {
          setBadge('<i class="bi bi-exclamation-triangle me-1"></i> Error', 'badge-warn');
          return;
        }

        if (!res.available || !res.provider_available) {
          setBadge('<i class="bi bi-slash-circle me-1"></i> Unavailable', 'badge-down');
          $providerInfo.text('Voice verification service is temporarily unavailable. You may continue using Text Chat.');
          $enrollBtn.addClass('d-none');
          return;
        }

        $providerInfo.text('Provider: ' + (res.provider || 'hybrid').toUpperCase());

        if (res.enrolled) {
          setBadge('<i class="bi bi-check-circle-fill me-1"></i> Enrolled', 'badge-ok');
          $enrollBtn.addClass('d-none');
          $reEnrollBtn.removeClass('d-none');
          $testBtn.removeClass('d-none');
          $deleteBtn.removeClass('d-none');
        } else {
          setBadge('<i class="bi bi-dash-circle me-1"></i> Not Enrolled', 'badge-idle');
          $enrollBtn.removeClass('d-none');
          $reEnrollBtn.addClass('d-none');
          $testBtn.addClass('d-none');
          $deleteBtn.addClass('d-none');
        }
      }, 'json').fail(function () {
        setBadge('<i class="bi bi-exclamation-triangle me-1"></i> Offline', 'badge-warn');
      });
    }

    function showMic(text) {
      $micText.text(text || 'Recording... Speak the phrase clearly.');
      $micStatus.removeClass('d-none');
      $verifyIndicator.addClass('d-none');
    }

    function hideMic() {
      $micStatus.addClass('d-none');
    }

    function showVerify(text) {
      $verifyText.text(text || 'Verifying voice...');
      $verifyIndicator.removeClass('d-none');
      $micStatus.addClass('d-none');
    }

    function hideVerify() {
      $verifyIndicator.addClass('d-none');
    }

    function captureAndSend(action, duration, label) {
      if (typeof window.MICA_VOICE === 'undefined' || typeof window.MICA_VOICE.captureSignature !== 'function') {
        Swal.fire({ icon: 'error', title: 'Voice Capture Unavailable', text: 'MICA voice capture is not available on this page yet. Please wait a moment and try again.' });
        return;
      }

      setBusy(true);
      showMic(label || 'Recording...');

      window.MICA_VOICE.captureSignature(duration).then(function (payload) {
        hideMic();
        if (!payload) {
          setBusy(false);
          Swal.fire({ icon: 'error', title: 'Capture Failed', text: 'Could not capture your voice. Please check your microphone and try again.' });
          return;
        }

        showVerify(action === 'delete' ? 'Deleting voice profile...' : 'Verifying voice...');

        var formData = new FormData();
        formData.append('action', action);
        formData.append('csrf_token', csrfToken);
        formData.append('audio[sample_rate]', payload.sample_rate);
        // Send a single JSON field (avoids PHP max_input_vars truncation).
        var downsampled = [];
        payload.frames.forEach(function (f, i) {
          if (i % 8 === 0) downsampled.push(f); // downsampled by captureSignature
        });
        formData.append('audio_json', JSON.stringify(downsampled));

        $.ajax({
          url: APP_URL + '/api/voice.php',
          type: 'POST',
          data: formData,
          processData: false,
          contentType: false,
          dataType: 'json',
        }).done(function (res) {
          hideVerify();
          setBusy(false);
          if (res.success) {
            Swal.fire({ icon: 'success', title: 'Success', text: res.message || 'Done.', timer: 2500, showConfirmButton: false });
            refreshStatus();
          } else {
            Swal.fire({ icon: 'error', title: 'Failed', text: res.message || 'Please try again.' });
          }
        }).fail(function () {
          hideVerify();
          setBusy(false);
          Swal.fire({ icon: 'error', title: 'Error', text: 'Network error. Please try again.' });
        });
      }).catch(function (err) {
        hideMic();
        hideVerify();
        setBusy(false);
        Swal.fire({ icon: 'error', title: 'Capture Error', text: (err && err.message) || 'Could not capture your voice. Please check microphone permissions.' });
      });
    }

    $enrollBtn.on('click', function () {
      captureAndSend('enroll', 8, 'Recording enrollment sample (5–10s)... Speak the phrase clearly.');
    });

    $reEnrollBtn.on('click', function () {
      Swal.fire({
        title: 'Re-register Voice?',
        text: 'This will replace your existing voice profile.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#0B6B3A',
        cancelButtonColor: '#8A94A0',
        confirmButtonText: 'Yes, re-register',
      }).then(function (result) {
        if (result.isConfirmed) {
          captureAndSend('re_enroll', 8, 'Recording new sample (5–10s)... Speak the phrase clearly.');
        }
      });
    });

    $testBtn.on('click', function () {
      captureAndSend('test', 3, 'Recording verification sample (2–3s)... Speak the phrase.');
    });

    $deleteBtn.on('click', function () {
      Swal.fire({
        title: 'Delete Voice Profile?',
        text: 'Voice Mode will be disabled until you re-enroll.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#D64545',
        cancelButtonColor: '#8A94A0',
        confirmButtonText: 'Delete',
      }).then(function (result) {
        if (!result.isConfirmed) return;
        setBusy(true);
        var formData = new FormData();
        formData.append('action', 'delete');
        formData.append('csrf_token', csrfToken);
        $.post(APP_URL + '/api/voice.php', formData, function (res) {
          setBusy(false);
          if (res.success) {
            Swal.fire({ icon: 'success', title: 'Deleted', text: res.message || 'Voice profile deleted.', timer: 2000, showConfirmButton: false });
            refreshStatus();
          } else {
            Swal.fire({ icon: 'error', title: 'Error', text: res.message || 'Delete failed.' });
          }
        }, 'json').fail(function () {
          setBusy(false);
          Swal.fire({ icon: 'error', title: 'Error', text: 'Network error.' });
        });
      });
    });

    refreshStatus();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { tryInit(0); });
  } else {
    tryInit(0);
  }
})();
</script>

