  /**
 * =====================================================================
 * MICA (Medical Intelligent Clinic Assistant) — Single Consolidated Bundle
 * =====================================================================
 *
 * This file REPLACES the previous 3-file split:
 *   - assets/js/mica.js       (orchestrator / FAB / widget UI)
 *   - assets/js/mica_chat.js  (chat, navigation, SOS, quick actions)
 *   - assets/js/mica_voice.js (speech recognition + TTS + queue)
 *
 * Everything is now in ONE self-contained IIFE. There is exactly ONE
 * MICA instance per page. The component include is `components/mica.php`,
 * loaded from `components/footer.php` on authenticated pages only.
 *
 * Backend:
 *   - api/ai_assistant.php   (mica_chat, clear_history, history, sos_emergency)
 *   - api/mica_tts.php       (ElevenLabs TTS proxy — key never exposed)
 *
 * Highlights of this reconstruction:
 *   • FAB z-index raised to 2147483000 (above ALL Bootstrap modals) so it
 *     is NEVER hidden behind a modal and is ALWAYS clickable.
 *   • Draggable FAB (pointer events, bounded to viewport).
 *   • Minimize / Maximize / Close controls on the widget.
 *   • Typing + thinking animations, auto-scroll, markdown rendering.
 *   • Persistent chat history (restored from php session via api/history).
 *   • Clear conversation + new conversation actions.
 *   • Voice: Speech-to-Text, Text-to-Speech, waveform, replay, volume,
 *     speed, continuous conversation.
 * =====================================================================
 */
(function () {
  'use strict';

  // ================================================================
  // Shared state + config
  // ================================================================
  var C = {
    state: {
      isOpen: false,
      isDragging: false,
      isMinimized: false,
      mode: 'idle',                 // idle | listening | speaking | thinking
      recognition: null,
      isListening: false,
      synthQueue: [],
      isPlaying: false,
      currentAudio: null,
      messages: [],
      isFirstMessage: true,
      lastResponse: '',
      conversationCount: 0,
      unreadCount: 0,
      lastInputMode: 'text',        // 'text' | 'voice' — voice input speaks, text never speaks
      voiceTestRunning: false,
    },
    config: {
      csrfToken: '',
      appUrl: '',
      maxTextLength: 1000,
      audioCache: {},
      continuousMode: false,        // Push-to-talk: NEVER auto-listen or auto-speak
      voiceVolume: 1.0,
      voiceSpeed: 1.0,
      preferredFemaleVoice: null,   // Auto-detected female voice for native TTS
      initialGreetingShown: false,
      navigationCommands: [
        { match: /(open|go to|show|navigate to)\s+(dashboard|home)/i,            url: 'dashboard.php' },
        { match: /(open|go to|show|navigate to)\s+(appointments?|booking)/i,     url: 'appointments.php' },
        { match: /(open|go to|show|navigate to)\s+(medical\s*record|health\s*record)/i, url: 'medical_record.php' },
        { match: /(open|go to|show|navigate to)\s+(health monitoring|vitals)/i,  url: 'health_monitoring.php' },
        { match: /(open|go to|show|navigate to)\s+(vaccination|vaccines?|shots)/i, url: 'vaccination.php' },
        { match: /(open|go to|show|navigate to)\s+(certificates?|med cert)/i,    url: 'certificates.php' },
        { match: /(open|go to|show|navigate to)\s+(announcements?|news)/i,       url: 'announcements.php' },
        { match: /(open|go to|show|navigate to)\s+(profile|settings?|account)/i, url: 'profile.php' },
        { match: /(open|go to|show|navigate to)\s+(notifications?|alerts)/i,     url: 'notifications.php' },
        { match: /(open|go to|show|navigate to)\s+(reports?|analytics)/i,        url: 'reports.php' },
        { match: /(open|go to|show)\s+(walk-?in|patients)/i,                     url: 'walkin.php' },
        { match: /(open|go to|show)\s+(inventory|medicine stock)/i,              url: 'inventory.php' },
        { match: /(open|go to|show)\s+(announcements)\s*(page)?/i,               url: 'announcements.php' },
      ],
    },
    dom: {},
  };

  // ================================================================
  // Small helpers
  // ================================================================
  function escapeHtml(text) {
    if (!text) return '';
    var div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
  }

  function getRolePath() {
    var role = (window.MICA_USER && window.MICA_USER.role) || 'student';
    if (role === 'admin') return 'admin';
    if (role === 'nurse') return 'nurse';
    return 'student';
  }

  function setMode(mode) {
    C.state.mode = mode;

    if (C.dom.micaFab) {
      C.dom.micaFab.className = 'mica-fab';
      if (mode === 'listening') C.dom.micaFab.classList.add('listening');
      else if (mode === 'speaking') C.dom.micaFab.classList.add('speaking');
      else if (mode === 'thinking') C.dom.micaFab.classList.add('thinking');
    }

    if (C.dom.micaWidget) {
      C.dom.micaWidget.className = 'mica-widget' + (C.state.isOpen ? ' open' : '') + ' mica-state-' + mode;
    }

    if (C.dom.micBtn) {
      C.dom.micBtn.className = 'mica-mic-btn' + (mode === 'listening' ? ' listening' : '');
      var mi = C.dom.micBtn.querySelector('i');
      if (mi) {
        mi.className = mode === 'listening' ? 'bi bi-mic-fill'
          : mode === 'speaking' ? 'bi bi-volume-up-fill'
          : mode === 'thinking' ? 'bi bi-arrow-repeat'
          : 'bi bi-mic';
      }
    }

    var statusTexts = {
      idle: '<span class="mica-status-dot idle"></span> Tap mic or type',
      listening: '<span class="mica-status-dot listening"></span> Listening...',
      speaking: '<span class="mica-status-dot speaking"></span> Speaking...',
      thinking: '<span class="mica-status-dot thinking"></span> Thinking...',
    };
    if (C.dom.micaStatus) C.dom.micaStatus.innerHTML = statusTexts[mode] || statusTexts.idle;

    if (C.dom.micaTyping) C.dom.micaTyping.style.display = mode === 'thinking' ? 'flex' : 'none';

    if (C.dom.continuousIndicator) {
      C.dom.continuousIndicator.style.display = (C.config.continuousMode && C.state.isOpen) ? 'flex' : 'none';
    }
  }

  function showStatus(text) {
    if (C.dom.micaStatus) C.dom.micaStatus.innerHTML = text;
  }

  function scrollToBottom() {
    if (C.dom.micaBody) C.dom.micaBody.scrollTop = C.dom.micaBody.scrollHeight;
  }

  // Tinymark — tiny markdown renderer (bold, italic, lists, code, links, line breaks)
  function renderMarkdown(text) {
    var h = escapeHtml(text);
    // code blocks
    h = h.replace(/```([\s\S]*?)```/g, function (m, c) {
      return '<pre class="mica-code">' + c + '</pre>';
    });
    // inline code
    h = h.replace(/`([^`]+)`/g, '<code>$1</code>');
    // bold
    h = h.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
    // italic
    h = h.replace(/(^|[^*])\*([^*\n]+)\*/g, '$1<em>$2</em>');
    // links
    h = h.replace(/\[([^\]]+)\]\((https?:\/\/[^)\s]+)\)/g, '<a href="$2" target="_blank" rel="noopener">$1</a>');
    h = h.replace(/(https?:\/\/[^\s<]+)/g, '<a href="$1" target="_blank" rel="noopener">$1</a>');
    // line breaks
    h = h.replace(/\n/g, '<br>');
    // lists (simple)
    h = h.replace(/(?:^|<br>)\s*[•*]\s+/g, '$1&bull; ');
    return h;
  }

  // ================================================================
  // DOM Builder
  // ================================================================
  function buildUI() {
    if (C.dom.micaFab) return;

    var fab = document.createElement('button');
    fab.className = 'mica-fab';
    fab.id = 'micaFab';
    fab.setAttribute('aria-label', 'Toggle MICA Voice Assistant');
    fab.innerHTML =
      '<span class="mica-fab-icon"><i class="bi bi-robot"></i></span>' +
      '<span class="mica-fab-text">MICA</span>' +
      '<span class="mica-notif-badge" id="micaNotifBadge" style="display:none;">0</span>';
    document.body.appendChild(fab);

    var widget = document.createElement('div');
    widget.className = 'mica-widget';
    widget.id = 'micaWidget';
    widget.innerHTML = buildWidgetHTML();
    document.body.appendChild(widget);

    C.dom.micaFab = fab;
    C.dom.micaWidget = widget;
    C.dom.micaBody = widget.querySelector('#micaBody');
    C.dom.micaInput = widget.querySelector('#micaInput');
    C.dom.micaStatus = widget.querySelector('#micaStatus');
    C.dom.micaTyping = widget.querySelector('#micaTyping');
    C.dom.micBtn = widget.querySelector('#micaMicBtn');
    C.dom.sendBtn = widget.querySelector('#micaSendBtn');
    C.dom.closeBtn = widget.querySelector('#micaClose');
    C.dom.minBtn = widget.querySelector('#micaMinimize');
    C.dom.replayBtn = widget.querySelector('#micaReplayBtn');
    C.dom.notifBadge = fab.querySelector('#micaNotifBadge');
    C.dom.quickActions = widget.querySelector('#micaQuickActions');
    C.dom.micaChips = widget.querySelector('#micaChips');
    C.dom.continuousIndicator = widget.querySelector('#micaContinuousIndicator');
    C.dom.volumeControl = widget.querySelector('#micaVolume');
    C.dom.speedControl = widget.querySelector('#micaSpeed');
    C.dom.speedVal = widget.querySelector('#micaSpeedVal');
    C.dom.clearHistoryBtn = widget.querySelector('#micaClearHistory');
    C.dom.newConvBtn = widget.querySelector('#micaNewConv');
    C.dom.testVoiceBtn = widget.querySelector('#micaTestVoice');
  }

  function buildWidgetHTML() {
    var waveformBars = '';
    for (var i = 0; i < 12; i++) waveformBars += '<div class="mica-waveform-bar"></div>';

    var quickActions = [
      { icon: '📊', label: 'BMI', action: 'bmi' },
      { icon: '📅', label: 'Appointments', action: 'appointments' },
      { icon: '📄', label: 'Records', action: 'records' },
      { icon: '💊', label: 'Medicines', action: 'medicines' },
      { icon: '💉', label: 'Vaccines', action: 'vaccines' },
      { icon: '🚨', label: 'SOS', action: 'sos' },
    ];

    var qaHtml = quickActions.map(function (a) {
      var cls = a.action === 'sos' ? 'mica-qa-btn mica-qa-btn-danger' : 'mica-qa-btn';
      return '<button class="' + cls + '" data-action="' + a.action + '" title="' + a.label + '">' +
        '<span>' + a.icon + '</span> ' + a.label + '</button>';
    }).join('');

    return (
      '<div class="mica-widget-header" id="micaWidgetHeader">' +
        '<div class="mica-widget-header-left">' +
          '<div class="mica-header-avatar"><i class="bi bi-robot"></i></div>' +
          '<div>' +
            '<div class="mica-widget-title">MICA</div>' +
            '<div class="mica-widget-subtitle">Medical Intelligent Clinic Assistant</div>' +
          '</div>' +
        '</div>' +
        '<div class="d-flex align-items-center gap-1">' +
          '<div class="dropdown">' +
            '<button class="mica-header-btn" type="button" id="micaSettingsBtn" data-bs-toggle="dropdown" aria-expanded="false" title="Settings"><i class="bi bi-gear-fill"></i></button>' +
            '<ul class="dropdown-menu dropdown-menu-end shadow" style="min-width:210px;">' +
              '<li><span class="dropdown-item-text small text-muted">🔊 Volume</span></li>' +
              '<li class="px-3 pb-1"><input type="range" class="w-100" id="micaVolume" min="0" max="100" value="100" step="5"></li>' +
              '<li><span class="dropdown-item-text small text-muted">⚡ Speed (<span id="micaSpeedVal">1.0x</span>)</span></li>' +
              '<li class="px-3 pb-1"><input type="range" class="w-100" id="micaSpeed" min="50" max="200" value="100" step="10"></li>' +
              '<li><span class="dropdown-item-text small text-muted">🎙 Continuous</span></li>' +
              '<li class="px-3 pb-1"><div class="form-check form-switch m-0"><input class="form-check-input" type="checkbox" id="micaContinuous"></div></li>' +
              '<li><button class="dropdown-item small" id="micaTestVoice"><i class="bi bi-volume-up me-1"></i>Test Voice</button></li>' +
              '<li><hr class="dropdown-divider"></li>' +
              '<li><button class="dropdown-item small" id="micaNewConv"><i class="bi bi-plus-square me-1"></i>New Conversation</button></li>' +
              '<li><button class="dropdown-item small" id="micaClearHistory"><i class="bi bi-trash me-1"></i>Clear Chat History</button></li>' +
            '</ul>' +
          '</div>' +
          '<button class="mica-header-btn" id="micaMinimize" title="Minimize" aria-label="Minimize"><i class="bi bi-dash-lg"></i></button>' +
          '<button class="mica-header-btn" id="micaReplayBtn" title="Replay last response" aria-label="Replay"><i class="bi bi-arrow-counterclockwise"></i></button>' +
          '<button class="mica-header-btn" id="micaClose" title="Close" aria-label="Close MICA"><i class="bi bi-x-lg"></i></button>' +
        '</div>' +
      '</div>' +

      '<div class="mica-chips" id="micaChips">' +
        '<span class="mica-chip">Book an appointment</span>' +
        '<span class="mica-chip">What is my BMI?</span>' +
        '<span class="mica-chip">Show my medical records</span>' +
        '<span class="mica-chip">Medicine availability</span>' +
      '</div>' +

      '<div class="mica-continuous-indicator" id="micaContinuousIndicator" style="display:none;">' +
        '<i class="bi bi-soundwave"></i><span>Continuous listening is active</span>' +
      '</div>' +

      '<div class="mica-widget-body" id="micaBody">' +
        '<div class="mica-avatar-container">' +
          '<div class="mica-avatar"><i class="mica-avatar-icon bi bi-robot"></i></div>' +
          '<div class="mica-waveform" id="micaWaveform">' + waveformBars + '</div>' +
          '<div class="mica-typing" id="micaTyping" style="display:none;">' +
            '<div class="mica-typing-dots"><span></span><span></span><span></span></div>' +
            '<span class="mica-typing-text">MICA is thinking...</span>' +
          '</div>' +
        '</div>' +
      '</div>' +

      '<div class="mica-quick-actions" id="micaQuickActions">' + qaHtml + '</div>' +

      '<div class="mica-widget-footer">' +
        '<div class="mica-input-wrapper">' +
          '<input type="text" class="mica-input" id="micaInput" placeholder="Type a message..." autocomplete="off" maxlength="' + C.config.maxTextLength + '">' +
          '<button class="mica-mic-btn" id="micaMicBtn" aria-label="Voice input"><i class="bi bi-mic"></i></button>' +
          '<button class="mica-send-btn" id="micaSendBtn" aria-label="Send message"><i class="bi bi-send-fill"></i></button>' +
        '</div>' +
      '</div>'
    );
  }

  // ================================================================
  // Draggable FAB + Widget
  // ================================================================
  function makeDraggable(el, handle) {
    var isDown = false;
    var offsetX = 0;
    var offsetY = 0;

    function onDown(e) {
      if (e.target.closest('.mica-fab-icon') || e.target.closest('.mica-notif-badge')) {
        // allow click-through to toggle for icon/notif badge? we still allow drag from text/background
      }
      isDown = true;
      C.state.isDragging = true;
      var rect = el.getBoundingClientRect();
      offsetX = e.clientX - rect.left;
      offsetY = e.clientY - rect.top;
      e.preventDefault();
    }

    function onMove(e) {
      if (!isDown) return;
      var x = e.clientX - offsetX;
      var y = e.clientY - offsetY;
      var maxX = window.innerWidth - el.offsetWidth - 8;
      var maxY = window.innerHeight - el.offsetHeight - 8;
      x = Math.max(8, Math.min(x, maxX));
      y = Math.max(8, Math.min(y, maxY));
      el.style.left = x + 'px';
      el.style.top = y + 'px';
      el.style.right = 'auto';
      el.style.bottom = 'auto';
    }

    function onUp() {
      isDown = false;
      C.state.isDragging = false;
    }

    (handle || el).addEventListener('pointerdown', onDown);
    window.addEventListener('pointermove', onMove);
    window.addEventListener('pointerup', onUp);
  }

  // ================================================================
  // Chat: messages
  // ================================================================
  function addMessage(role, content) {
    var now = new Date();
    var time = now.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });

    var msgDiv = document.createElement('div');
    msgDiv.className = 'mica-message mica-' + role;

    var speakingIndicator = (role === 'ai' && C.state.synthQueue.length > 0)
      ? '<div class="mica-speaking-indicator"><span></span><span></span><span></span></div>'
      : '';

    msgDiv.innerHTML =
      '<div class="mica-bubble">' +
        '<div>' + renderMarkdown(content) + speakingIndicator + '</div>' +
        '<div class="mica-bubble-time">' + time + '</div>' +
      '</div>';

    C.dom.micaBody.appendChild(msgDiv);
    scrollToBottom();
    C.state.messages.push({ role: role, content: content, time: time });
  }

  function showPersonalizedGreeting() {
    var timeOfDay = (function () {
      var h = new Date().getHours();
      return h < 12 ? 'Good morning' : h < 18 ? 'Good afternoon' : 'Good evening';
    })();
    var firstName = (window.MICA_USER && window.MICA_USER.firstName) || 'there';

    var div = document.createElement('div');
    div.className = 'mica-message mica-ai';
    div.innerHTML =
      '<div class="mica-bubble">' +
        '<div><strong>👋 ' + timeOfDay + ', ' + escapeHtml(firstName) + '!</strong><br>' +
        'I\'m <strong>MICA</strong> — your <strong>Medical Intelligent Clinic Assistant</strong>.<br>' +
        'I can help you with:<br>' +
        '• 🏥 <strong>Health information</strong> &amp; wellness tips<br>' +
        '• 📅 <strong>Appointment booking</strong> guidance<br>' +
        '• 📋 Accessing your <strong>medical records</strong><br>' +
        '• ⚖️ <strong>BMI</strong> &amp; health monitoring<br>' +
        '• 💊 <strong>Medicine &amp; vaccination</strong> info<br>' +
        '• 🚨 <strong>SOS Emergency</strong> alerts<br>' +
        'Try a suggestion below or just start typing! 🎤</div>' +
        '<div class="mica-bubble-time">Just now</div>' +
      '</div>';
    C.dom.micaBody.appendChild(div);
    scrollToBottom();
    C.state.lastResponse = '';
    C.state.isFirstMessage = false;
    C.config.initialGreetingShown = true;
    showChips();
  }

  function showChips() {
    if (!C.dom.micaChips) return;
    var questions = ['Book an appointment', 'What is my BMI?', 'Show my medical records', 'Medicine availability'];
    C.dom.micaChips.innerHTML = '';
    questions.forEach(function (q) {
      var chip = document.createElement('span');
      chip.className = 'mica-chip';
      chip.textContent = q;
      C.dom.micaChips.appendChild(chip);
    });
    C.dom.micaChips.style.display = 'flex';
  }

  function hideChips() {
    if (C.dom.micaChips) C.dom.micaChips.style.display = 'none';
  }

  // ================================================================
  // Chat: send message via AJAX
  // mode: 'text' (default) — never speaks. 'voice' — speaks response.
  // ================================================================
  function sendMessage(text, mode) {
    if (!text || text.trim() === '') return;

    mode = mode || 'text';
    var message = text.trim().substring(0, C.config.maxTextLength);
    if (C.dom.micaInput) C.dom.micaInput.value = '';
    if (C.dom.micaInput) C.dom.micaInput.disabled = true;
    if (C.dom.sendBtn) C.dom.sendBtn.disabled = true;

    // Record the input mode BEFORE awaiting the response so the AI reply
    // knows whether to be spoken (voice) or shown only (text).
    C.state.lastInputMode = mode;

    addMessage('user', message);
    hideChips();

    setMode('thinking');
    C.state.conversationCount++;

    var formData = new FormData();
    formData.append('action', 'mica_chat');
    formData.append('message', message);
    formData.append('first_message', C.state.isFirstMessage ? '1' : '0');
    formData.append('csrf_token', C.config.csrfToken);
    C.state.isFirstMessage = false;

    fetch(C.config.appUrl + '/api/ai_assistant.php', {
      method: 'POST',
      body: formData,
    })
      .then(function (res) {
        if (!res.ok) throw new Error('HTTP ' + res.status);
        return res.json();
      })
      .then(function (data) {
        if (data.success) {
          var reply = data.reply || 'I\'m not sure how to respond. Could you ask me something else?';
          addMessage('ai', reply);
          C.state.lastResponse = reply;

          if (data.sos_required || data.emergency) {
            showSOSPrompt();
          }

          // HYBRID RULE: only VOICE input activates Text-to-Speech.
          // Typed conversations display text ONLY — never speak.
          if (C.state.lastInputMode === 'voice') {
            stopSpeaking();
            speakQueued(reply);
          } else {
            setMode('idle');
          }
        } else {
          addMessage('ai', data.message || 'I encountered an error. Please try again.');
          if (!C.state.isPlaying) setMode('idle');
        }
      })
      .catch(function (err) {
        console.error('[MICA] Chat error:', err);
        addMessage('ai', 'Connection error. Please check your network and try again. The clinic AI is temporarily unavailable.');
        if (!C.state.isPlaying) setMode('idle');
      })
      .finally(function () {
        if (C.dom.micaInput) C.dom.micaInput.disabled = false;
        if (C.dom.sendBtn) C.dom.sendBtn.disabled = false;
        if (!C.state.isPlaying && C.state.mode === 'thinking') {
          setMode('idle');
        }
      });
  }

  // ================================================================
  // Stop any current speech output (used before replay & mode switch)
  // ================================================================
  function stopSpeaking() {
    if (C.state.currentAudio) {
      try { C.state.currentAudio.pause(); C.state.currentAudio = null; } catch (e) { /* ignore */ }
    }
    if ('speechSynthesis' in window) window.speechSynthesis.cancel();
    C.state.isPlaying = false;
    C.state.synthQueue = [];
  }

  // ================================================================
  // Smart navigation
  // ================================================================
  function handleNavigation(text) {
    if (text.match(/(log|sign)?\s*(out|logout|sign out)/i) && !text.match(/about|without|login|sign in/i)) {
      addMessage('ai', 'Logging you out now... 👋');
      setTimeout(function () { window.location.href = C.config.appUrl + '/auth/logout.php'; }, 800);
      return true;
    }

    for (var i = 0; i < C.config.navigationCommands.length; i++) {
      var cmd = C.config.navigationCommands[i];
      var match = text.match(cmd.match);
      if (match) {
        var pageName = match[2] || 'that page';
        addMessage('ai', 'Navigating to ' + pageName + '... 🚀');
        setTimeout(function () {
          window.location.href = C.config.appUrl + '/pages/' + getRolePath() + '/' + cmd.url;
        }, 600);
        return true;
      }
    }
    return false;
  }

  // ================================================================
  // SOS
  // ================================================================
  function showSOSPrompt() {
    if (typeof Swal === 'undefined') return;
    Swal.fire({
      title: '🚨 EMERGENCY DETECTED',
      html: '<p>The AI detected a possible medical emergency.</p><p class="fw-bold text-danger">Do you need to activate the SOS Emergency System?</p>',
      icon: 'warning',
      showCancelButton: true,
      confirmButtonColor: '#DC3545',
      cancelButtonColor: '#6C757D',
      confirmButtonText: '<i class="bi bi-exclamation-triangle-fill me-1"></i> Activate SOS',
      cancelButtonText: 'No, I\'m fine',
      reverseButtons: true,
    }).then(function (result) {
      if (result.isConfirmed) {
        var modalEl = document.getElementById('sosModal');
        if (modalEl) {
          var bsModal = new bootstrap.Modal(modalEl);
          bsModal.show();
          // GPS location is requested AFTER the student confirms & submits
          // the SOS request (handled inside components/mica.php submitSOS).
        }
      }
    });
  }

  function activateSOS(type, description, lat, lng) {
    var formData = new FormData();
    formData.append('action', 'sos_emergency');
    formData.append('emergency_type', type);
    formData.append('description', description || type + ' - Emergency via MICA');
    formData.append('csrf_token', C.config.csrfToken);
    if (lat) formData.append('latitude', lat);
    if (lng) formData.append('longitude', lng);

    return fetch(C.config.appUrl + '/api/ai_assistant.php', {
      method: 'POST',
      body: formData,
    }).then(function (r) { return r.json(); });
  }

  // ================================================================
  // Persistence: history
  // ================================================================
  function clearHistory() {
    var formData = new FormData();
    formData.append('action', 'clear_history');
    formData.append('csrf_token', C.config.csrfToken);

    fetch(C.config.appUrl + '/api/ai_assistant.php', {
      method: 'POST',
      body: formData,
    }).then(function (r) { return r.json(); })
      .then(function (data) {
        if (data.success) {
          C.state.messages = [];
          C.config.initialGreetingShown = false;
          C.state.isFirstMessage = true;
          if (C.dom.micaBody) C.dom.micaBody.innerHTML = '';
          showPersonalizedGreeting();
        }
      });
  }

  function newConversation() {
    C.state.messages = [];
    C.config.initialGreetingShown = false;
    C.state.isFirstMessage = true;
    if (C.dom.micaBody) C.dom.micaBody.innerHTML = '';
    showPersonalizedGreeting();
  }

  function loadHistory() {
    fetch(C.config.appUrl + '/api/ai_assistant.php?action=history', {
      method: 'GET',
    }).then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data.success) return;
        if (!data.data.history || data.data.history.length === 0) {
          showPersonalizedGreeting();
          return;
        }
        // Render last 20 messages
        var history = data.data.history.slice(-20);
        history.forEach(function (entry) {
          addMessage(entry.role === 'user' ? 'user' : 'ai', entry.content);
        });
        C.state.isFirstMessage = false;
        C.config.initialGreetingShown = true;
        hideChips();
      })
      .catch(function () { showPersonalizedGreeting(); });
  }

  // ================================================================
  // Voice: Speech Recognition (built-in)
  // ================================================================
  var SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
  var supportsSpeechRecognition = !!SpeechRecognition;

  function initRecognition() {
    if (!supportsSpeechRecognition || C.state.recognition) return;

    var recognition = new SpeechRecognition();
    recognition.continuous = true;
    recognition.interimResults = true;
    recognition.lang = 'en-PH';

    recognition.onresult = function (event) {
      var finalTranscript = '';
      var interimTranscript = '';
      for (var i = event.resultIndex; i < event.results.length; i++) {
        var r = event.results[i];
        if (r.isFinal) finalTranscript += r[0].transcript;
        else interimTranscript += r[0].transcript;
      }
      if (interimTranscript && C.dom.micaInput) C.dom.micaInput.value = interimTranscript;
      if (finalTranscript) handleVoiceInput(finalTranscript.trim());
    };

    recognition.onerror = function (event) {
      console.warn('[MICA] Speech recognition error:', event.error);
      C.state.isListening = false;
      if (event.error === 'not-allowed') {
        showStatus('🎤 Microphone access denied. Please allow microphone permissions.');
        setMode('idle');
      } else if (event.error === 'no-speech') {
        if (C.state.mode === 'listening' && C.config.continuousMode) {
          setTimeout(function () {
            if (C.state.mode === 'listening' && !C.state.isListening) startListening();
          }, 500);
        } else {
          showStatus('No speech detected. Try again.');
          setMode('idle');
        }
      } else if (event.error === 'aborted') {
        setMode('idle');
      } else {
        showStatus('Voice input error. Try typing instead.');
        setMode('idle');
      }
    };

    recognition.onend = function () {
      C.state.isListening = false;
    };

    recognition.onsoundstart = function () {
      if (C.state.mode === 'speaking' && C.state.currentAudio) {
        interruptSpeaking();
      }
    };

    C.state.recognition = recognition;
  }

  function startListening() {
    if (!supportsSpeechRecognition) {
      showStatus('Voice input not supported. Please type.');
      if (C.dom.micaInput) C.dom.micaInput.focus();
      return;
    }
    if (C.state.isListening) { stopListening(); return; }

    initRecognition();
    try {
      C.state.isListening = true;
      C.state.recognition.start();
      setMode('listening');
      if (C.dom.micaInput) C.dom.micaInput.placeholder = '🎤 Listening...';
      showStatus('🎤 Listening...');
    } catch (e) {
      console.warn('[MICA] Recognition start failed:', e);
      C.state.isListening = false;
      setMode('idle');
      showStatus('Could not start voice input. Try typing.');
    }
  }

  function stopListening() {
    if (C.state.recognition && C.state.isListening) {
      try { C.state.recognition.stop(); } catch (e) { /* ignore */ }
    }
    C.state.isListening = false;
    if (C.state.mode === 'listening') setMode('idle');
    if (C.dom.micaInput) C.dom.micaInput.placeholder = 'Type a message...';
  }

  function handleVoiceInput(transcript) {
    C.state.isListening = false;
    if (C.state.recognition) { try { C.state.recognition.stop(); } catch (e) { /* ignore */ } }
    if (!transcript || transcript.trim() === '') {
      showStatus("I didn't catch that. Please try again.");
      setMode('idle');
      return;
    }
    if (C.dom.micaInput) C.dom.micaInput.value = transcript.trim();

    // VOICE MODE: mark the input as 'voice' so the reply is spoken,
    // then the state machine returns to Idle (no continuous listening).
    if (handleNavigation(transcript)) return;
    sendMessage(transcript.trim(), 'voice');
  }

  // ================================================================
  // Voice Verification Gate
  // Voice Mode requires the authenticated account owner's voice profile.
  // Flow: mic pressed → logged in? → has profile? → capture 2-3s → verify.
  // Text chat is unaffected; SOS is unaffected.
  // ================================================================
  function requestVoiceMode() {
    if (!supportsSpeechRecognition) {
      showStatus('Voice input not supported. Please type.');
      return;
    }
    // Check provider availability + enrollment status first.
    $.get(C.config.appUrl + '/api/voice.php', { action: 'status' }, function (res) {
      if (!res.success || !res.available || !res.provider_available) {
        showStatus('Voice verification service is temporarily unavailable. You may continue using Text Chat.');
        if (typeof Swal !== 'undefined') {
          Swal.fire({ icon: 'warning', title: 'Voice Mode Unavailable', text: 'Voice verification service is temporarily unavailable. You may continue using Text Chat.' });
        }
        return;
      }
      if (!res.enrolled) {
        showStatus('No voice profile found. Please complete Voice Enrollment before using Voice Mode.');
        if (typeof Swal !== 'undefined') {
          Swal.fire({ icon: 'warning', title: 'Voice Enrollment Required', text: 'No voice profile found.\nPlease complete Voice Enrollment before using Voice Mode.' });
        }
        return;
      }
      // Enrolled → capture a short sample and verify before starting STT.
      verifyThenListen();
    }, 'json').fail(function () {
      showStatus('Voice verification service is temporarily unavailable. You may continue using Text Chat.');
      if (typeof Swal !== 'undefined') {
        Swal.fire({ icon: 'warning', title: 'Voice Mode Unavailable', text: 'Voice verification service is temporarily unavailable. You may continue using Text Chat.' });
      }
    });
  }

  function verifyThenListen() {
    showStatus('🎤 Verify your voice... speak now (2-3 seconds).');
    captureSignature(3).then(function (payload) {
      if (!payload) {
        showStatus('Voice capture failed. Please check your microphone.');
        return;
      }
      var formData = new FormData();
      formData.append('action', 'verify');
      formData.append('csrf_token', C.config.csrfToken);
      formData.append('audio[sample_rate]', payload.sample_rate);
      // Downsample: send every 8th frame as ONE JSON field
      // (avoids PHP max_input_vars truncation of thousands of fields).
      var downsampled = [];
      for (var i = 0; i < payload.frames.length; i += 8) {
        downsampled.push(payload.frames[i]);
      }
      formData.append('audio_json', JSON.stringify(downsampled));
      fetch(C.config.appUrl + '/api/voice.php', { method: 'POST', body: formData })
        .then(function (r) { return r.json(); })
        .then(function (res) {
          if (res.success && res.verified) {
            showStatus('✅ Voice verified. Listening...');
            startListening();
          } else {
            showStatus('Voice verification failed. Only the authenticated account owner may use MICA Voice Assistant.');
            if (typeof Swal !== 'undefined') {
              Swal.fire({ icon: 'error', title: 'Voice Verification Failed', text: 'Voice verification failed. Only the authenticated account owner may use MICA Voice Assistant.' });
            }
          }
        })
        .catch(function () {
          showStatus('Voice verification service is temporarily unavailable. You may continue using Text Chat.');
        });
    }).catch(function () {
      showStatus('Voice capture cancelled.');
    });
  }

  // Capture N seconds of audio via getUserMedia + AudioContext,
  // returning a Promise of { sample_rate, frames:array } (16kHz mono).
  function captureSignature(durationSeconds) {
    return new Promise(function (resolve, reject) {
      if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        reject(new Error('Microphone not supported'));
        return;
      }
      durationSeconds = durationSeconds || 3;
      navigator.mediaDevices.getUserMedia({ audio: true })
        .then(function (stream) {
          var AudioCtx = window.AudioContext || window.webkitAudioContext;
          if (!AudioCtx) {
            stream.getTracks().forEach(function (t) { t.stop(); });
            reject(new Error('Audio context not supported'));
            return;
          }
          var ctx = new AudioCtx();
          var source = ctx.createMediaStreamSource(stream);
          var sampleRate = ctx.sampleRate || 16000;
          var targetRate = 16000;
          var frames = [];
          var recorder = ctx.createScriptProcessor(4096, 1, 1);
          source.connect(recorder);
          recorder.connect(ctx.destination);
          var done = false;
          function stopAndResolve() {
            if (done) return;
            done = true;
            try { recorder.disconnect(); } catch (e) {}
            try { source.disconnect(); } catch (e) {}
            stream.getTracks().forEach(function (t) { t.stop(); });
            try { ctx.close(); } catch (e) {}
            resolve({ sample_rate: targetRate, frames: frames });
          }
          recorder.onaudioprocess = function (e) {
            if (done) return;
            var ch = e.inputBuffer.getChannelData(0);
            var step = Math.max(1, Math.round(sampleRate / targetRate));
            for (var i = 0; i < ch.length; i += step) {
              frames.push(ch[i]);
            }
            if (frames.length >= targetRate * durationSeconds) {
              stopAndResolve();
            }
          };
          setTimeout(stopAndResolve, (durationSeconds + 1) * 1000);
        })
        .catch(function (err) {
          reject(err);
        });
    });
  }

  // ================================================================
  // Voice: TTS + audio queue
  // ================================================================
  function speakQueued(text) {
    if (!text || text.length < 3) return;
    C.state.synthQueue.push(text);
    processSynthQueue();
  }

  function processSynthQueue() {
    if (C.state.synthQueue.length === 0) {
      // HYBRID RULE: after finishing speech, ALWAYS return to Idle.
      // Never auto-relisten, never continue speaking without user action.
      C.state.isPlaying = false;
      C.state.currentAudio = null;
      setMode('idle');
      return;
    }

    var next = C.state.synthQueue.shift();
    if (typeof window.MICA_VOICE !== 'undefined' && window.MICA_VOICE && window.MICA_VOICE.speakText) {
      window.MICA_VOICE.speakText(next).then(processSynthQueue).catch(processSynthQueue);
    } else {
      // Use built-in speechSynthesis as fallback
      speakWithNativeTTS(next).then(processSynthQueue).catch(processSynthQueue);
    }
  }

  // ================================================================
  // Female voice auto-detection — picks the best professional female
  // voice available in the browser, falling back to the closest match.
  // ================================================================
  function pickFemaleVoice() {
    if (C.config.preferredFemaleVoice) return C.config.preferredFemaleVoice;
    if (!('speechSynthesis' in window)) return null;

    var voices = window.speechSynthesis.getVoices() || [];
    if (!voices.length) return null;

    var femaleHints = [
      'female', 'woman', 'samantha', 'victoria', 'karen', 'moira', 'tessa',
      'zira', 'ava', 'susan', 'fiona', 'kate', 'siri', 'allison', 'amelie',
      'google us english', 'google uk english female', 'google hindi',
      'female en-us', 'en-us female',
    ];

    var sorted = voices.slice().sort(function (a, b) {
      var score = function (v) {
        var s = 0;
        var n = (v.name || '') + ' ' + (v.lang || '');
        femaleHints.forEach(function (kw) {
          if (n.toLowerCase().indexOf(kw.toLowerCase()) !== -1) s++;
        });
        if (/en(-|_)?(US|GB|AU|PH|IN)/i.test(v.lang || '')) s += 2; // prefer English
        return s;
      };
      return score(b) - score(a);
    });

    C.config.preferredFemaleVoice = sorted[0] || null;
    return C.config.preferredFemaleVoice;
  }

  function speakWithNativeTTS(text) {
    return new Promise(function (resolve) {
      if (!('speechSynthesis' in window)) { resolve(); return; }
      var clean = text
        .replace(/\*\*(.*?)\*\*/g, '$1')
        .replace(/\*(.*?)\*/g, '$1')
        .replace(/\[([^\]]+)\]\([^)]+\)/g, '$1')
        .replace(/[#*_~`>|:-]/g, '')
        .replace(/\n+/g, ' ')
        .replace(/\s+/g, ' ')
        .trim();
      if (!clean) { resolve(); return; }

      var utter = new SpeechSynthesisUtterance(clean);
      utter.rate = C.config.voiceSpeed;
      utter.volume = C.config.voiceVolume;
      var fv = pickFemaleVoice();
      if (fv) utter.voice = fv;
      utter.onend = function () { C.state.isPlaying = false; C.state.currentAudio = null; resolve(); };
      utter.onerror = function () { C.state.isPlaying = false; C.state.currentAudio = null; resolve(); };
      C.state.isPlaying = true;
      setMode('speaking');
      window.speechSynthesis.speak(utter);
    });
  }

  // ================================================================
  // Voice test — speaks a short sample to verify the female voice.
  // ================================================================
  function testVoice() {
    if (C.state.voiceTestRunning) return;
    C.state.voiceTestRunning = true;
    stopSpeaking();
    showStatus('🔊 Testing voice...');
    var sample = "Hello! I'm MICA, your Medical Intelligent Clinic Assistant from Bulacan Polytechnic College.";
    speakQueued(sample);
    setTimeout(function () { C.state.voiceTestRunning = false; }, 5000);
  }

  function interruptSpeaking() {
    if (C.state.currentAudio) {
      try { C.state.currentAudio.pause(); C.state.currentAudio = null; } catch (e) { /* ignore */ }
    }
    if ('speechSynthesis' in window) window.speechSynthesis.cancel();
    C.state.isPlaying = false;
    C.state.synthQueue = [];
    setMode('listening');
  }

  function setVolume(level) {
    C.config.voiceVolume = Math.max(0, Math.min(1, level));
    if (C.state.currentAudio) C.state.currentAudio.volume = C.config.voiceVolume;
  }

  function setSpeed(rate) {
    C.config.voiceSpeed = rate;
    if (C.state.currentAudio && C.state.currentAudio.playbackRate !== undefined) {
      C.state.currentAudio.playbackRate = C.config.voiceSpeed;
    }
  }

  // ================================================================
  // Toggle widget (open/close/minimize/base)
  // ================================================================
  function toggleWidget() {
    if (C.state.isDragging) return;
    C.state.isMinimized = false;
    setOpen(!C.state.isOpen);
  }

  function setOpen(open) {
    C.state.isOpen = open;

    if (open) {
      C.dom.micaWidget.classList.add('open');
      var fabIcon = C.dom.micaFab.querySelector('.mica-fab-icon');
      if (fabIcon) fabIcon.innerHTML = '<i class="bi bi-x-lg"></i>';
      var fabText = C.dom.micaFab.querySelector('.mica-fab-text');
      if (fabText) fabText.style.display = 'none';

      scrollToBottom();
      if (C.state.messages.length === 0 && !C.config.initialGreetingShown) {
        showPersonalizedGreeting();
      }
      if (C.state.messages.length === 0 && C.config.initialGreetingShown) {
        // history was loaded but body could be empty in edge cases
      }
      clearNotifBadge();

      // HYBRID RULE: NEVER auto-start listening when the widget opens.
      // The user must explicitly press the microphone button.
    } else {
      C.dom.micaWidget.classList.remove('open');
      var fabIcon2 = C.dom.micaFab.querySelector('.mica-fab-icon');
      if (fabIcon2) fabIcon2.innerHTML = '<i class="bi bi-robot"></i>';
      var fabText2 = C.dom.micaFab.querySelector('.mica-fab-text');
      if (fabText2) fabText2.style.display = '';
      stopListening();
      interruptSpeaking();
      setMode('idle');
    }
  }

  function minimizeWidget() {
    C.state.isMinimized = true;
    setOpen(false);
  }

  function clearNotifBadge() {
    if (C.dom.notifBadge) C.dom.notifBadge.style.display = 'none';
  }

  // ================================================================
  // Quick actions
  // ================================================================
  function handleQuickAction(action) {
    var messages = {
      bmi: 'What is my BMI? How can I check it?',
      appointments: 'How do I book an appointment?',
      records: 'Show my medical records',
      medicines: 'What medicines are available in the clinic?',
      vaccines: 'What vaccines do I have on record?',
      vaccination: 'What vaccines do I have on record?',
      announcements: 'Latest clinic announcements',
      health: 'Show my health monitoring data',
      sos: '__SOS__',
      navigate_dashboard: 'Open Dashboard',
      navigate_appointments: 'Open Appointments',
      navigate_reports: 'Open Reports',
      navigate_notifications: 'Open Notifications',
      navigate_settings: 'Open Settings',
      navigate_profile: 'Open Profile',
      logout: 'Logout',
    };

    var msg = messages[action] || messages[action.toLowerCase()] || 'Help me with clinic information';

    if (action === 'sos') { showSOSPrompt(); return; }

    if (msg.match(/(open|logout)/i)) {
      if (handleNavigation(msg.toLowerCase())) { return; }
    }

    if (C.dom.micaInput) C.dom.micaInput.value = msg;
    sendMessage(msg);
    hideChips();
  }

  // ================================================================
  // Event binding
  // ================================================================
  function bindEvents() {
    C.dom.micaFab.addEventListener('click', toggleWidget);

    if (C.dom.closeBtn) C.dom.closeBtn.addEventListener('click', toggleWidget);
    if (C.dom.minBtn) C.dom.minBtn.addEventListener('click', minimizeWidget);

    C.dom.sendBtn.addEventListener('click', function () {
      sendMessage(C.dom.micaInput.value);
    });

    C.dom.micaInput.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        sendMessage(C.dom.micaInput.value);
      }
    });

    C.dom.micBtn.addEventListener('click', function () {
      if (C.state.isListening) stopListening();
      else requestVoiceMode();
    });
    if (C.dom.replayBtn) {
      C.dom.replayBtn.addEventListener('click', function () {
        if (C.state.lastResponse) {
          addMessage('ai', '🔄 Replaying...');
          speakQueued(C.state.lastResponse);
        } else {
          showStatus('No previous response to replay');
        }
      });
    }

    if (C.dom.clearHistoryBtn) {
      C.dom.clearHistoryBtn.addEventListener('click', function () { clearHistory(); });
    }
    if (C.dom.newConvBtn) {
      C.dom.newConvBtn.addEventListener('click', function () { newConversation(); });
    }

    if (C.dom.testVoiceBtn) {
      C.dom.testVoiceBtn.addEventListener('click', function () { testVoice(); });
    }

    if (C.dom.volumeControl) {
      C.dom.volumeControl.addEventListener('input', function (e) {
        setVolume(parseInt(e.target.value, 10) / 100);
      });
    }

    if (C.dom.speedControl) {
      C.dom.speedControl.addEventListener('input', function (e) {
        var v = parseInt(e.target.value, 10) / 100;
        setSpeed(v);
        if (C.dom.speedVal) C.dom.speedVal.textContent = v.toFixed(1) + 'x';
      });
    }

    var contToggle = C.dom.micaWidget.querySelector('#micaContinuous');
    if (contToggle) {
      contToggle.checked = C.config.continuousMode;
      contToggle.addEventListener('change', function (e) {
        C.config.continuousMode = e.target.checked;
        if (C.dom.continuousIndicator) {
          C.dom.continuousIndicator.style.display = C.config.continuousMode && C.state.isOpen ? 'flex' : 'none';
        }
      });
    }

    if (C.dom.micaChips) {
      C.dom.micaChips.addEventListener('click', function (e) {
        var chip = e.target.closest('.mica-chip');
        if (!chip) return;
        hideChips();
        sendMessage(chip.textContent.trim());
      });
    }

    if (C.dom.quickActions) {
      C.dom.quickActions.addEventListener('click', function (e) {
        var btn = e.target.closest('.mica-qa-btn');
        if (!btn) return;
        handleQuickAction(btn.dataset.action);
      });
    }

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && C.state.isOpen) setOpen(false);
    });

    // Drag FAB
    makeDraggable(C.dom.micaFab, C.dom.micaFab);
  }

  // ================================================================
  // Public API
  // ================================================================
  window.MICA = {
    init: function () {
      if (C.dom.micaFab) return; // Prevent double init

      C.config.csrfToken = document.querySelector('meta[name="csrf-token"]')
        ? document.querySelector('meta[name="csrf-token"]').getAttribute('content') : '';
      C.config.appUrl = (typeof APP_URL !== 'undefined' && APP_URL) ? APP_URL : '';
      if (!C.config.appUrl) C.config.appUrl = window.location.origin + '/bpc_clinic_system';

      buildUI();
      bindEvents();

      try { initRecognition(); } catch (e) { console.warn('[MICA] Recognition init failed:', e); }

      // Restore history (async) — fallback to greeting
      try { loadHistory(); } catch (e) { console.warn('[MICA] History load failed:', e); }

      console.log('[MICA] Voice AI Assistant initialized');
    },

    open: function () { setOpen(true); },
    close: function () { setOpen(false); },
    minimize: function () { minimizeWidget(); },

    send: function (text) {
      if (C.state.isOpen) {
        if (C.dom.micaInput) C.dom.micaInput.value = text;
        sendMessage(text);
      } else {
        setOpen(true);
        setTimeout(function () {
          if (C.dom.micaInput) C.dom.micaInput.value = text;
          sendMessage(text);
        }, 500);
      }
    },

    getMode: function () { return C.state.mode; },

    setContinuousMode: function (enabled) {
      C.config.continuousMode = !!enabled;
      if (C.dom.continuousIndicator) {
        C.dom.continuousIndicator.style.display = C.config.continuousMode && C.state.isOpen ? 'flex' : 'none';
      }
    },

    sos: function (type, description, lat, lng) {
      return activateSOS(type, description, lat, lng);
    },

    toggle: function () { toggleWidget(); },

    // Voice test — exposed for a "Test Voice" button
    testVoice: function () { testVoice(); },
  };

  // Voice sub-API (kept for backward compat with any page code referring to MICA_VOICE)
  window.MICA_VOICE = {
    initRecognition: initRecognition,
    startListening: startListening,
    stopListening: stopListening,
    handleVoiceInput: handleVoiceInput,
    speakText: function (text) {
      return new Promise(function (resolve) {
        // Try server TTS first, fall back to native
        ttsViaServer(text).then(resolve).catch(function () { speakWithNativeTTS(text).then(resolve); });
      });
    },
    interruptSpeaking: interruptSpeaking,
    processSynthQueue: processSynthQueue,
    setVolume: setVolume,
    setSpeed: setSpeed,
    isSupported: supportsSpeechRecognition,
    isListening: function () { return C.state.isListening; },

    // ---- Voice Verification API (single source used by MICA + enrollment card) ----
    requestVoiceMode: requestVoiceMode,
    captureSignature: captureSignature,
    getVoiceStatus: function () {
      return new Promise(function (resolve) {
        $.get(C.config.appUrl + '/api/voice.php', { action: 'status' }, function (res) {
          resolve(res || { success: false });
        }, 'json').fail(function () { resolve({ success: false }); });
      });
    },
  };

  // TTS via server proxy (api/mica_tts.php) — key never exposed
  function ttsViaServer(text) {
    return new Promise(function (resolve, reject) {
      if (!text || text.length < 3) { resolve(); return; }
      var cleanText = text
        .replace(/\*\*(.*?)\*\*/g, '$1')
        .replace(/\*(.*?)\*/g, '$1')
        .replace(/\[([^\]]+)\]\([^)]+\)/g, '$1')
        .replace(/[#*_~`>|:-]/g, '')
        .replace(/\n+/g, ' ')
        .replace(/\s+/g, ' ')
        .trim();
      if (!cleanText) { resolve(); return; }

      var cacheKey = cleanText.substring(0, 100);
      if (C.config.audioCache[cacheKey]) {
        playBlob(C.config.audioCache[cacheKey], resolve, reject);
        return;
      }

      var formData = new FormData();
      formData.append('action', 'synthesize');
      formData.append('text', cleanText.substring(0, 2000));
      formData.append('csrf_token', C.config.csrfToken);

      fetch(C.config.appUrl + '/api/mica_tts.php', { method: 'POST', body: formData })
        .then(function (response) {
          if (!response.ok) throw new Error('TTS HTTP ' + response.status);
          var contentType = response.headers.get('Content-Type') || '';
          if (contentType.includes('application/json')) {
            return response.json().then(function (data) { throw new Error(data.error || 'TTS failed'); });
          }
          return response.blob();
        })
        .then(function (blob) {
          if (blob.size < 100) throw new Error('TTS returned empty audio');
          C.config.audioCache[cacheKey] = blob;
          playBlob(blob, resolve, reject);
        })
        .catch(function (err) {
          console.warn('[MICA] TTS server error:', err.message);
          reject(err);
        });
    });
  }

  function playBlob(blob, resolve, reject) {
    var url = URL.createObjectURL(blob);
    var audio = new Audio(url);
    C.state.currentAudio = audio;
    audio.volume = C.config.voiceVolume || 1.0;

    audio.onended = function () {
      URL.revokeObjectURL(url);
      C.state.isPlaying = false;
      C.state.currentAudio = null;
      processSynthQueue();
      resolve();
    };

    audio.onerror = function () {
      URL.revokeObjectURL(url);
      C.state.isPlaying = false;
      C.state.currentAudio = null;
      processSynthQueue();
      reject(new Error('Audio playback failed'));
    };

    C.state.isPlaying = true;
    setMode('speaking');

    audio.play().catch(function (err) {
      console.warn('[MICA] Audio play error:', err.message);
      C.state.isPlaying = false;
      C.state.currentAudio = null;
      setMode('idle');
      resolve();
    });
  }
})();

