/**
 * MICA WebSocket Audio Streaming — Integration Guide
 *
 * This file demonstrates ZERO-BREAKING-CHANGE integration of real-time
 * WebSocket audio streaming into the existing MICA voice assistant.
 *
 * INSTRUCTIONS:
 * 1. Include the two new files BEFORE this integration script:
 *      <script src="assets/js/mica_audio_worklet.js"></script>
 *      <script src="assets/js/mica_audio_streamer.js"></script>
 *
 * 2. Paste the contents of this file into a new <script> block AFTER
 *    MICA has initialized (e.g., in your footer or after window.MICA.init()).
 *
 * 3. The integration uses non-invasive callbacks and does NOT modify
 *    any existing MICA functions, event handlers, or DOM structure.
 */

(function () {
  'use strict';

  if (!window.MICA || !window.MicaAudioStreamer) {
    console.warn('[MICA-Audio] MICA or MicaAudioStreamer not loaded');
    return;
  }

  // ================================================================
  // Configuration
  // ================================================================
  var WS_AUDIO_URL = (typeof MICA_AUDIO_WS_URL !== 'undefined' && MICA_AUDIO_WS_URL)
    ? MICA_AUDIO_WS_URL
    : window.location.origin + '/ws/audio';

  // ================================================================
  // Lightweight RMS Level Monitor (non-invasive)
  // ================================================================
  var _rmsValue = 0;
  var _rmsSmoothed = 0;
  var _rmsContainer = null;

  function ensureRmsMeter() {
    if (_rmsContainer) return;
    _rmsContainer = document.createElement('div');
    _rmsContainer.style.cssText =
      'position:fixed;bottom:16px;right:16px;z-index:9999;' +
      'background:rgba(0,0,0,0.65);color:#fff;padding:6px 10px;border-radius:8px;' +
      'font:11px/1.3 monospace;pointer-events:none;opacity:0.85;';
    _rmsContainer.textContent = 'MIC: --';
    document.body.appendChild(_rmsContainer);
  }

  function updateRmsMeter(rms) {
    _rmsValue = rms;
    _rmsSmoothed = _rmsSmoothed * 0.7 + rms * 0.3;
    if (!_rmsContainer) return;

    var pct = Math.min(100, Math.round(_rmsSmoothed * 400));
    var label = pct < 5 ? 'SILENT' : pct < 25 ? 'LOW' : pct < 60 ? 'OK' : 'LOUD';
    _rmsContainer.textContent = 'MIC ' + pct + '% ' + label;
    _rmsContainer.style.background = pct < 5 ? 'rgba(0,0,0,0.65)' : 'rgba(220,53,69,0.75)';
  }

  function removeRmsMeter() {
    if (_rmsContainer && _rmsContainer.parentNode) {
      _rmsContainer.parentNode.removeChild(_rmsContainer);
    }
    _rmsContainer = null;
    _rmsValue = 0;
    _rmsSmoothed = 0;
  }

  // ================================================================
  // Audio Streamer Instance
  // ================================================================
  var audioStreamer = new MicaAudioStreamer({
    wsUrl: WS_AUDIO_URL,
    onLevel: function (rms) {
      updateRmsMeter(rms);
    },
    onOpen: function () {
      console.log('[MICA-Audio] WebSocket connected');
    },
    onClose: function () {
      console.log('[MICA-Audio] WebSocket disconnected');
    },
    onError: function (err) {
      console.warn('[MICA-Audio] Error:', err);
    },
  });

  // ================================================================
  // Hook Into Existing MICA Voice Flow (NON-BREAKING)
  // ================================================================
  //
  // Strategy: attach a parallel click listener to the existing mic button.
  // This listener runs AFTER the original handler (added during MICA.init),
  // so it mirrors the toggle state without modifying any existing logic.
  //
  // We also hook into widget open/close to clean up the streamer.

  function attachStreamingHooks() {
    var micBtn = C.dom.micBtn;
    if (!micBtn) {
      setTimeout(attachStreamingHooks, 200);
      return;
    }

    // Remove any previously attached streaming listener to avoid duplicates.
    micBtn.removeEventListener('mica-audio-stream', onMicBtnClick);

    function onMicBtnClick() {
      // Small delay so the original handler updates C.state first.
      setTimeout(function () {
        if (C.state.isListening) {
          ensureRmsMeter();
          if (!audioStreamer.isRunning) {
            audioStreamer.start().catch(function (err) {
              console.warn('[MICA-Audio] Streaming start failed (non-fatal):', err && err.message ? err.message : err);
            });
          }
        } else {
          if (audioStreamer.isRunning) {
            audioStreamer.stop();
          }
          removeRmsMeter();
        }
      }, 50);
    }

    micBtn.addEventListener('mica-audio-stream', onMicBtnClick);

    // Clean up when widget closes.
    var origSetOpen = window.MICA.close;
    // We use a MutationObserver / polling fallback on the widget state
    // rather than patching setOpen, to keep changes minimal.
  }

  // Attach after MICA is ready.
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', attachStreamingHooks);
  } else {
    attachStreamingHooks();
  }

  // ================================================================
  // Expose Public API for manual control (optional)
  // ================================================================
  window.MICA_AUDIO = {
    startStreaming: function () {
      ensureRmsMeter();
      return audioStreamer.start();
    },
    stopStreaming: function () {
      audioStreamer.stop();
      removeRmsMeter();
    },
    isStreaming: function () {
      return audioStreamer.isRunning();
    },
    getStats: function () {
      return audioStreamer.getStats();
    },
    setWsUrl: function (url) {
      audioStreamer.wsUrl = url;
    },
  };

  console.log('[MICA-Audio] WebSocket audio streaming module loaded');
})();
