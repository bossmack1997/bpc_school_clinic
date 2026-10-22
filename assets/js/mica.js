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
 * Voice interaction is 100% browser-based (Web Speech API + speechSynthesis).
 * No voice biometrics, no voice verification, no enrollment — the mic button
 * goes straight to Speech-to-Text and the AI response is spoken back via
 * Text-to-Speech.
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
      mode: 'idle',
      recognition: null,
      recognitionState: 'idle',  // idle | starting | listening | processing | speaking | error
      isListening: false,
      synthQueue: [],
      isPlaying: false,
      currentAudio: null,
      messages: [],
      isFirstMessage: true,
      hasInteracted: false,
      greetingSpoken: false,
      lastResponse: '',
      conversationCount: 0,
      unreadCount: 0,
      lastInputMode: 'text',
      voiceTestRunning: false,
      muted: false,
      wakeListening: false,
      activeRequestId: 0,
      continuousTimer: null,
      locationState: 'unrequested', // unrequested | prompting | granted | denied | unavailable | timeout | unsupported | insecure
      locationData: null,          // backward-compatibility pointer
      micaLocation: {
        latitude: null,
        longitude: null,
        accuracy: null,
        accuracyMeters: null,
        timestamp: null,
        available: false,
        lowAccuracy: false,
        lastAcquired: 0
      },
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
      ttsProvider: 'elevenlabs',    // 'elevenlabs' or 'browser' — SINGLE source of truth for TTS engine
      timezone: 'Asia/Manila',      // Philippine timezone for greeting
      wakeWords: ['hey mica', 'hello mica', 'hi mica'],
      continuousTimeoutMs: 8000,    // inactivity timeout for continuous mode
      minConfidence: 0.35,          // minimum transcript confidence for noise filtering
      navigationCommands: [
        { match: /(open|go to|show|navigate to)\s+(dashboard|home)/i,            url: 'dashboard.php' },
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

  // ================================================================
  // SPEECH TEXT PIPELINE
  // ----------------------------------------------------------------
  // MICA must SOUND like a natural, professional female clinic
  // assistant. The chat window keeps the original AI response; speech
  // is produced from a cleaned, human-friendly version.
  // ================================================================

  // Expand technical acronyms so they are pronounced letter-by-letter.
  // NOTE: "AM"/"PM" are intentionally NOT here — they are handled by spellTime().
  var SPEECH_ACRONYMS = {
    'BPC': 'B P C',
    'QR': 'Q R',
    'SOS': 'S O S',
    'BMI': 'B M I',
    'HTML': 'H T M L',
    'CSS': 'C S S',
    'AI': 'A I',
    'HTTP': 'H T T P',
    'URL': 'U R L',
    'ID': 'I D',
    'PH': 'P H',
  };

  // Pronunciation dictionary — fix misrecognised names before speech.
  // Brand is always displayed as "MICA" in chat UI, but spoken as "Mee-ka".
  // "Mika" (common STT error) → spoken as "Mee-ka"
  var PRONUNCIATION_DICTIONARY = {
    'MICA': 'Mee-ka',
    'Mika': 'Mee-ka',
    'mika': 'Mee-ka',
    'Mica': 'Mee-ka',
    'mica': 'Mee-ka',
    'Meeka': 'Mee-ka',
    'meeka': 'Mee-ka',
    'Me ka': 'Mee-ka',
    'me ka': 'Mee-ka',
    'Mee ka': 'Mee-ka',
    'mee ka': 'Mee-ka',
  };

  // Speech recognition normalization — applied to STT transcript BEFORE
  // sending the text to the AI.  This prevents "Mika" from ever reaching
  // the LLM and normalises spoken acronyms into their canonical form.
  var RECOGNITION_NORMALIZATION = [
    { from: /\bMika\b/gi,                    to: 'MICA' },
    { from: /\bmika\b/gi,                    to: 'MICA' },
    { from: /\bMica\b/gi,                    to: 'MICA' },
    { from: /\bmica\b/gi,                    to: 'MICA' },
    { from: /\bMeeka\b/gi,                   to: 'MICA' },
    { from: /\bmeeka\b/gi,                   to: 'MICA' },
    { from: /\bme ka\b/gi,                   to: 'MICA' },
    { from: /\bMee ka\b/gi,                  to: 'MICA' },
    { from: /\b(m|mi)c(a|ah)\b/gi,           to: 'MICA' },  // fuzzy catch
    // Normalise spoken acronyms that STT splits into separate letters.
    { from: /\bb\s+p\s+c\b/gi,               to: 'BPC' },
    { from: /\bq\s+r\b/gi,                   to: 'QR' },
    { from: /\ba\s+i\b/gi,                   to: 'AI' },
    { from: /\bs\s+o\s+s\b/gi,               to: 'SOS' },
    { from: /\bb\s+m\s+i\b/gi,               to: 'BMI' },
    { from: /\bh\s+t\s+m\s+l\b/gi,           to: 'HTML' },
    { from: /\bc\s+s\s+s\b/gi,               to: 'CSS' },
  ];

  // Map of numbers (0-99) to words for natural reading.
  var SPEECH_ONES = ['zero','one','two','three','four','five','six','seven','eight','nine','ten',
    'eleven','twelve','thirteen','fourteen','fifteen','sixteen','seventeen','eighteen','nineteen'];
  var SPEECH_TENS = ['','','twenty','thirty','forty','fifty','sixty','seventy','eighty','ninety'];

  function numberToWords(n) {
    if (n === 0) return 'zero';
    if (n < 20) return SPEECH_ONES[n];
    if (n < 100) {
      var t = Math.floor(n / 10);
      var r = n % 10;
      return SPEECH_TENS[t] + (r ? '-' + SPEECH_ONES[r] : '');
    }
    if (n < 1000) {
      var h = Math.floor(n / 100);
      var rest = n % 100;
      return SPEECH_ONES[h] + ' hundred' + (rest ? ' ' + numberToWords(rest) : '');
    }
    if (n < 1000000) {
      var th = Math.floor(n / 1000);
      var rest2 = n % 1000;
      return numberToWords(th) + ' thousand' + (rest2 ? ' ' + numberToWords(rest2) : '');
    }
    return String(n); // fallback
  }

  function numberToOrdinal(n) {
    var words = numberToWords(n).split('-');
    var last = words[words.length - 1];
    var map = {
      one: 'first', two: 'second', three: 'third', five: 'fifth',
      eight: 'eighth', nine: 'ninth', twelve: 'twelfth',
    };
    if (map[last]) words[words.length - 1] = map[last];
    else if (last.slice(-1) === 'y') words[words.length - 1] = last.slice(0, -1) + 'ieth';
    else words[words.length - 1] = last + 'th';
    return words.join(' ');
  }

  // Convert standalone numbers (integers) to their spoken word form.
  function spellNumbers(text) {
    return text.replace(/\b(\d{1,3}(?:,\d{3})*|\d+)\b/g, function (m) {
      var cleaned = m.replace(/,/g, '');
      var num = parseInt(cleaned, 10);
      if (isNaN(num) || num < 0 || num > 9999999) return m;
      return numberToWords(num);
    });
  }

  // Convert "2026" years like "two thousand twenty-six" (handled by spellNumbers),
  // but this helper ensures 4-digit numbers read as years.
  function spellYear(year) {
    var y = parseInt(year, 10);
    if (isNaN(y)) return year;
    if (y >= 2000) {
      // 2000 -> 2099 : "two thousand ..."
      var thou = Math.floor(y / 1000);
      var rest = y % 1000;
      return SPEECH_ONES[thou] + ' thousand' + (rest ? (rest < 100 ? ' ' : ' ') + numberToWords(rest) : '');
    }
    return numberToWords(y);
  }

  // Convert ISO dates "2026-08-05" -> "August fifth, two thousand twenty-six".
  function spellDate(text) {
    return text.replace(/\b(\d{4})-(\d{1,2})-(\d{1,2})\b/g, function (m, y, mo, d) {
      var months = ['','January','February','March','April','May','June','July',
        'August','September','October','November','December'];
      var monthName = months[parseInt(mo, 10)] || mo;
      var day = numberToOrdinal(parseInt(d, 10));
      var year = spellYear(y);
      return monthName + ' ' + day + ', ' + year;
    });
  }

  // Convert times "13:30", "9:00", "9:30 PM" -> "one thirty PM".
  function spellTime(text) {
    return text.replace(/\b(\d{1,2}):([0-5]\d)\b(\s?(AM|PM|am|pm))?/g, function (m, h, min, suffix) {
      var hour = parseInt(h, 10);
      var minute = parseInt(min, 10);
      var meridiem = suffix ? suffix.trim().toUpperCase() : '';
      var isPM = meridiem === 'PM';
      if (!meridiem) {
        isPM = hour >= 12;
        meridiem = isPM ? 'PM' : 'AM';
      }
      var dispHour = hour % 12;
      if (dispHour === 0) dispHour = 12;
      var hourWord = numberToWords(dispHour);
      if (minute === 0) return hourWord + ' ' + meridiem;
      return hourWord + ' ' + numberToWords(minute) + ' ' + meridiem;
    });
  }

// Remove emojis, emoticons, and invisible Unicode characters.
  function stripEmojis(text) {
    // Each range is applied separately to avoid huge single character-class
    // regexes that can trip up some parsers.
    var ranges = [
      '\\u{1F000}-\\u{1FAFF}', // misc symbols & pictographs
      '\\u{1F900}-\\u{1F9FF}', // supplemental symbols
      '\\u{1F600}-\\u{1F64F}', // emoticons
      '\\u{1F680}-\\u{1F6FF}', // transport & map
      '\\u{2600}-\\u{27BF}',   // misc symbols, dingbats
      '\\u{2B00}-\\u{2BFF}',   // arrows / misc symbols
      '\\u{2190}-\\u{21FF}',   // arrows
      '\\u{2300}-\\u{23FF}',   // technical symbols
      '\\u{2B50}-\\u{2BFF}',   // stars, marks
       '\\u{1F3FB}-\\u{1F3FF}', // emoji modifiers (skin tones)
       '\\u{FE0F}',             // variation selector (emoji color)
       '\\u{FE0E}',             // variation selector (text presentation)
       '\\u{200D}',             // zero-width joiner
       '\\u{20E3}',             // combining enclosing keycap
       '\\u{1F1E6}-\\u{1F1FF}',// regional indicators (flags)
    ];
    var out = text;
    for (var i = 0; i < ranges.length; i++) {
      out = out.replace(new RegExp('[' + ranges[i] + ']', 'gu'), '');
    }

    // Remove common Western emoticons: :-) :( :D ;) etc.
    out = out.replace(/[:=;][-^>]?[)DdpP(]/g, ' ');

    // Remove invisible / zero-width Unicode characters.
     out = out.replace(/[\u200B-\u200F\u2028-\u202F\u205F-\u206F\uFEFF\uFFFC\uFFFD\u00AD]/g, ' ');

    // Remove other decorative / technical symbols that should never be spoken.
    out = out.replace(/[\u2600-\u2605\u2606-\u26FF\u2700-\u27BF\u2B00-\u2BFF]/g, ' ');

    return out;
  }

  // Remove markdown formatting, code blocks, HTML tags, URLs, lists, symbols.
  function cleanSpeechText(raw) {
    if (!raw) return '';
    var text = String(raw);

    // 1. Remove hidden metadata / code blocks / JSON / stack traces.
    text = text.replace(/```[\s\S]*?```/g, ' ');
    text = text.replace(/`[^`]*`/g, ' ');
    text = text.replace(/<script[\s\S]*?<\/script>/gi, ' ');
    text = text.replace(/<style[\s\S]*?<\/style>/gi, ' ');
    text = text.replace(/<pre[\s\S]*?<\/pre>/gi, ' ');
    text = text.replace(/\{[\s\S]*?\n\s*\}/g, ' '); // rough JSON/object blocks
    text = text.replace(/at\s+[\w$\\.]+\s+\([^)]*\)/g, ' '); // stack-trace frames
    text = text.replace(/:\s*'[^']*'/g, ' '); // key:'value' fragments

    // 2. Remove HTML tags.
    text = text.replace(/<[^>]+>/g, ' ');

    // 3. Remove URLs.
    text = text.replace(/https?:\/\/\S+/g, ' ');
    text = text.replace(/www\.\S+/g, ' ');

    // 4. Remove markdown headers.
    text = text.replace(/^#{1,6}\s+/gm, '');

    // 5. Remove markdown emphasis / links.
    text = text.replace(/\*\*([^*]+)\*\*/g, '$1');
    text = text.replace(/(^|[^*])\*([^*\n]+)\*/g, '$1$2');
    text = text.replace(/__([^_]+)__/g, '$1');
    text = text.replace(/\[([^\]]+)\]\([^)]+\)/g, '$1');

    // 6. Remove list markers.
    text = text.replace(/(^|\n)\s*([-*+•]|\d+[.)])\s+/g, '$1 ');
    text = text.replace(/^\s*[-*+•]\s+/gm, ' ');
    text = text.replace(/^\s*\d+[.)]\s+/gm, ' ');

    // 7. Remove blockquote markers.
    text = text.replace(/^\s*>\s+/gm, ' ');

     // 8. Remove emojis and technical symbols.
    text = stripEmojis(text);
    text = text.replace(/[#*_~|>]/g, ' ');
    text = text.replace(/[@%\\]/g, ' ');
    text = text.replace(/[<>{}\[\]()]/g, ' ');
    text = text.replace(/[_`]/g, ' ');
    text = text.replace(/[;,]/g, (m) => m + ' ');
    text = text.replace(/\s*[=:]\s*/g, ' ');

    // 8b. Apply pronunciation dictionary BEFORE acronym expansion.
    // This fixes misrecognised names (Mika → Mee-ka) that would
    // otherwise be mangled by acronym expansion or left as raw text.
    Object.keys(PRONUNCIATION_DICTIONARY).forEach(function (key) {
      var re = new RegExp('\\b' + key.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '\\b', 'g');
      text = text.replace(re, PRONUNCIATION_DICTIONARY[key]);
    });

    // 9. Expand acronyms (before date/time/number conversion so letters stay).
    Object.keys(SPEECH_ACRONYMS).forEach(function (key) {
      var re = new RegExp('\\b' + key + '\\b', 'g');
      text = text.replace(re, SPEECH_ACRONYMS[key]);
    });

    // 10. Convert dates, times, then remaining numbers.
    text = spellDate(text);
    text = spellTime(text);
    text = spellNumbers(text);

    // 11. Normalize whitespace and punctuation.
    text = text.replace(/\s+/g, ' ').trim();
    text = text.replace(/\s+([.,!?])/g, '$1');
    text = text.replace(/\.{2,}/g, '.');

    return text;
  }

  // Split long full responses into safe chunks without dropping any sentences or words.
  function splitTextForTTS(text, maxLength) {
    maxLength = maxLength || 450;
    if (!text || typeof text !== 'string') return [];
    var trimmed = text.trim();
    if (trimmed.length <= maxLength) return [trimmed];

    var chunks = [];
    // Split on sentence terminators (. ! ?) or line breaks
    var sentences = trimmed.split(/(?<=[.!?])\s+|\n+/).filter(Boolean);
    var currentChunk = '';

    for (var i = 0; i < sentences.length; i++) {
      var s = sentences[i].trim();
      if (!s) continue;

      // If a single sentence is exceptionally long (> maxLength), split it by punctuation/clauses or words
      if (s.length > maxLength) {
        if (currentChunk) {
          chunks.push(currentChunk.trim());
          currentChunk = '';
        }
        var subParts = s.split(/(?<=[,;:])\s+/).filter(Boolean);
        var subChunk = '';
        for (var j = 0; j < subParts.length; j++) {
          var p = subParts[j].trim();
          if (!p) continue;
          if (p.length > maxLength) {
            // Split by words
            var words = p.split(/\s+/).filter(Boolean);
            for (var w = 0; w < words.length; w++) {
              if ((subChunk + ' ' + words[w]).trim().length > maxLength) {
                if (subChunk) chunks.push(subChunk.trim());
                subChunk = words[w];
              } else {
                subChunk = (subChunk ? subChunk + ' ' : '') + words[w];
              }
            }
          } else if ((subChunk + ' ' + p).trim().length > maxLength) {
            if (subChunk) chunks.push(subChunk.trim());
            subChunk = p;
          } else {
            subChunk = (subChunk ? subChunk + ' ' : '') + p;
          }
        }
        if (subChunk) {
          currentChunk = subChunk;
        }
        continue;
      }

      if ((currentChunk + ' ' + s).trim().length > maxLength) {
        if (currentChunk) chunks.push(currentChunk.trim());
        currentChunk = s;
      } else {
        currentChunk = (currentChunk ? currentChunk + ' ' : '') + s;
      }
    }

    if (currentChunk && currentChunk.trim()) {
      chunks.push(currentChunk.trim());
    }

    return chunks.filter(Boolean);
  }

  // Final speech-ready text used by every TTS path. Returns full text without truncation.
  function makeSpeechNatural(text) {
    var t = text;

    // Convert em dashes and curly quotes to plain ASCII before other transforms.
    t = t.replace(/—/g, '. ');
    t = t.replace(/–/g, '-');
    t = t.replace(/[""]/g, '"');
    t = t.replace(/['']/g, "'");

    // Remove any remaining bullet / arrow / math / currency / symbol characters
    // that stripEmojis might have missed (these should never be spoken).
    t = t.replace(/[\u2022\u2023\u25E6\u2020\u2021\u2190-\u21FF\u2200-\u22FF\u00A3\u20B9\u20B1\u20A6\u20B5\u2122\u00A9\u00AE\u2120]/g, ' ');

    // Convert common AI-style phrasing to conversational female speech.
    var transforms = [
      { from: /\bThe requested\b/gi,                  to: 'Your' },
      { from: /\bhas been successfully\b/gi,           to: 'was' },
      { from: /\bsuccessfully completed\b/gi,         to: 'done' },
      { from: /\. The (system|clinic|application)\b/gi, to: '. The clinic' },
      { from: /\bI am not sure\b/gi,                  to: "I'm not sure" },
      { from: /\bdoes not\b/gi,                       to: "doesn't" },
      { from: /\bcannot\b/gi,                         to: "can't" },
      { from: /\bIt is\b/gi,                          to: 'It\'s' },
      { from: /\bThat is\b/gi,                        to: 'That\'s' },
      { from: /\bThere is\b/gi,                       to: 'There\'s' },
      { from: /\bThere are\b/gi,                      to: 'There\'re' },
      { from: /\bYou should\b/gi,                     to: 'You ought to' },
      { from: /\bIn order to\b/gi,                    to: 'To' },
      { from: /\bDue to the fact that\b/gi,           to: 'Because' },
      { from: /\bAs a result\b/gi,                    to: 'So' },
      { from: /\bPlease be advised\b/gi,              to: 'Please note' },
      { from: /\bPlease note that\b/gi,               to: 'Please be aware that' },
      { from: /\bFor more information\b/gi,           to: 'For details' },
      { from: /\bIf you have any questions\b/gi,      to: 'If you need help' },
      { from: /\bFeel free to\b/gi,                   to: 'Please' },
      { from: /\bUtilization of\b/gi,                  to: 'Use of' },
      { from: /\bImplementation of\b/gi,              to: 'Using' },
      { from: /\bDiagnosis is\b/gi,                   to: 'You have' },
      { from: /\bTreatment is\b/gi,                   to: 'You should' },
      { from: /\bPlease note that\b/gi,                to: 'Note' },
      { from: /\bIt is recommended\b/gi,              to: 'I recommend' },
      { from: /\bOne should\b/gi,                     to: 'You should' },
      { from: /\bIt is important to\b/gi,             to: 'It\'s important to' },
      { from: /\bIt is advisable to\b/gi,             to: 'It\'s best to' },
      { from: /\bThe patient\b/gi,                    to: 'You' },
      { from: /\bA patient\b/gi,                      to: 'A patient' },
      { from: /\bAccording to the records\b/gi,       to: 'From your records' },
      { from: /\bAccording to the database\b/gi,      to: 'From the clinic records' },
      { from: /\bThe system\b/gi,                    to: 'The clinic' },
      { from: /\bPlease be informed\b/gi,             to: 'Just so you know' },
      { from: /\bWe would like to inform you\b/gi,    to: 'I want to let you know' },
    ];

    for (var i = 0; i < transforms.length; i++) {
      t = t.replace(transforms[i].from, transforms[i].to);
    }

    // Add natural pauses after periods (slightly longer pause).
    t = t.replace(/\.\s*/g, '. ');
    // Normalize multiple spaces.
    t = t.replace(/\s+/g, ' ').trim();

    return t;
  }

  // Final speech-ready text used by every TTS path. Returns full text without truncation.
  function prepareSpeechText(text) {
    var cleaned = cleanSpeechText(text);
    if (!cleaned) return '';
    cleaned = makeSpeechNatural(cleaned);
    return cleaned;
  }

  function getRolePath() {
    var role = (window.MICA_USER && window.MICA_USER.role) || (document.getElementById('mica-assistant') && document.getElementById('mica-assistant').getAttribute('data-mica-role')) || 'student';
    if (role === 'admin') return 'admin';
    if (role === 'nurse') return 'nurse';
    if (role === 'teacher' || role === 'adviser') return 'adviser';
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

  // Tinymark — structured markdown renderer (headings, bold, italic, numbered & bullet lists, code, links, paragraphs)
  function renderMarkdown(text) {
    if (!text) return '';
    // Strip accidental non-currency leading dollar signs before markdown processing
    var clean = String(text)
      .replace(/^\s*\$\s+/gm, '')
      .replace(/(?:^|\n)\s*\$\s*([A-Za-z0-9])/g, '\n$1');

    var lines = clean.split('\n');
    var html = [];

    for (var i = 0; i < lines.length; i++) {
      var line = lines[i];
      var trimmed = line.trim();

      // Blank line -> Paragraph spacing
      if (trimmed === '') {
        html.push('<div class="mica-p-gap"></div>');
        continue;
      }

      // Headings: ###, ##, #
      var h3Match = trimmed.match(/^###\s+(.+)$/);
      if (h3Match) {
        html.push('<h6 class="mica-h6">' + formatInline(h3Match[1]) + '</h6>');
        continue;
      }
      var h2Match = trimmed.match(/^##\s+(.+)$/);
      if (h2Match) {
        html.push('<h5 class="mica-h5">' + formatInline(h2Match[1]) + '</h5>');
        continue;
      }
      var h1Match = trimmed.match(/^#\s+(.+)$/);
      if (h1Match) {
        html.push('<h4 class="mica-h4">' + formatInline(h1Match[1]) + '</h4>');
        continue;
      }

      // Numbered list: 1. Item, 2. Item
      var numMatch = trimmed.match(/^(\d+)[.)]\s+(.+)$/);
      if (numMatch) {
        html.push('<div class="mica-list-item"><span class="mica-list-num">' + escapeHtml(numMatch[1]) + '.</span><span class="mica-list-text">' + formatInline(numMatch[2]) + '</span></div>');
        continue;
      }

      // Bullet list: - Item, * Item, • Item, + Item
      var bulletMatch = trimmed.match(/^[-*•+]\s+(.+)$/);
      if (bulletMatch) {
        html.push('<div class="mica-list-item"><span class="mica-list-bullet">&bull;</span><span class="mica-list-text">' + formatInline(bulletMatch[1]) + '</span></div>');
        continue;
      }

      // Standard text line
      html.push('<div class="mica-line">' + formatInline(line) + '</div>');
    }

    return html.join('');
  }

  function formatInline(text) {
    if (!text) return '';
    var h = escapeHtml(text);
    // code blocks / inline code
    h = h.replace(/`([^`]+)`/g, '<code>$1</code>');
    // bold
    h = h.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
    // italic
    h = h.replace(/(^|[^*])\*([^*\n]+)\*/g, '$1<em>$2</em>');
    // markdown links: [label](url)
    h = h.replace(/\[([^\]]+)\]\((https?:\/\/[^)\s]+)\)/g, '<a href="$2" target="_blank" rel="noopener">$1</a>');
    // raw URLs (if not already inside an anchor)
    h = h.replace(/(^|[^"'>])(https?:\/\/[^\s<]+)/g, '$1<a href="$2" target="_blank" rel="noopener">$2</a>');
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
    C.dom.locationIndicator = widget.querySelector('#micaLocationIndicator');
    C.dom.locationText = widget.querySelector('#micaLocationText');
  }

  function buildWidgetHTML() {
    var waveformBars = '';
    for (var i = 0; i < 12; i++) waveformBars += '<div class="mica-waveform-bar"></div>';

    var role = (window.MICA_USER && window.MICA_USER.role) || (document.getElementById('mica-assistant') && document.getElementById('mica-assistant').getAttribute('data-mica-role')) || 'student';
    var quickActions = [];

    if (role === 'nurse') {
      quickActions = [
        { icon: '', label: 'Student Records', action: 'mica_student_records' },
        { icon: '', label: 'Medicine Inventory', action: 'mica_inventory' },
        { icon: '', label: 'Certificates', action: 'mica_certificates' },
        { icon: '', label: 'Reports', action: 'mica_reports' },
      ];
    } else if (role === 'teacher' || role === 'adviser') {
      quickActions = [
        { icon: '', label: 'My Advisory', action: 'advisory_teacher' },
        { icon: '', label: 'Clinic Referrals', action: 'referrals_teacher' },
        { icon: '', label: 'Nearest Hospital', action: 'nearby_hospital' },
        { icon: '', label: 'Announcements', action: 'announcements' },
        { icon: '', label: 'SOS Protocol', action: 'sos_protocol' },
      ];
    } else if (role === 'admin') {
      quickActions = [
        { icon: '', label: 'User Overview', action: 'users_admin' },
        { icon: '', label: 'Sections', action: 'sections_admin' },
        { icon: '', label: 'Nearby Hospitals', action: 'nearby_hospital' },
        { icon: '', label: 'Analytics', action: 'analytics_admin' },
        { icon: '', label: 'Announcements', action: 'announcements' },
      ];
    } else {
      quickActions = [
        { icon: '', label: 'Clinic Information', action: 'clinic_information' },
        { icon: '', label: 'My Queue', action: 'my_queue' },
        { icon: '', label: 'My Health Record', action: 'records' },
        { icon: '', label: 'Clinic Procedures', action: 'clinic_procedures' },
        { icon: '', label: 'Emergency Help', action: 'sos' },
      ];
    }

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
              '<li><span class="dropdown-item-text small text-muted"> Volume</span></li>' +
              '<li class="px-3 pb-1"><input type="range" class="w-100" id="micaVolume" min="0" max="100" value="100" step="5"></li>' +
              '<li><span class="dropdown-item-text small text-muted"> Speed (<span id="micaSpeedVal">1.0x</span>)</span></li>' +
              '<li class="px-3 pb-1"><input type="range" class="w-100" id="micaSpeed" min="50" max="200" value="100" step="10"></li>' +
              '<li><span class="dropdown-item-text small text-muted"> Continuous</span></li>' +
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

      '<div class="mica-location-indicator" id="micaLocationIndicator" style="display:none;" title="Browser GPS Status (Click to refresh)">' +
        '<i class="bi bi-geo-alt-fill"></i><span id="micaLocationText">Location available</span>' +
      '</div>' +

      '<div class="mica-chips" id="micaChips">' +
        '<span class="mica-chip">Find the nearest hospital</span>' +
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
          '<button class="mica-mic-btn" id="micaMicBtn" type="button" aria-label="Voice input"><i class="bi bi-mic"></i></button>' +
          '<button class="mica-send-btn" id="micaSendBtn" type="button" aria-label="Send message"><i class="bi bi-send-fill"></i></button>' +
        '</div>' +
      '</div>'
    );
  }

  // ================================================================
  // Draggable FAB + Widget
  // ================================================================
  function makeDraggable(el, handle) {
    var isDown = false;
    var startX = 0, startY = 0;
    var offsetX = 0, offsetY = 0;

    function onDown(e) {
      isDown = true;
      startX = e.clientX;
      startY = e.clientY;
      var rect = el.getBoundingClientRect();
      offsetX = e.clientX - rect.left;
      offsetY = e.clientY - rect.top;
    }

    function onMove(e) {
      if (!isDown) return;
      var dist = Math.hypot(e.clientX - startX, e.clientY - startY);
      if (dist > 5) {
        C.state.isDragging = true;
        el.classList.add('dragging');
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
    }

    function onUp() {
      isDown = false;
      setTimeout(function () { C.state.isDragging = false; el.classList.remove('dragging'); }, 100);
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

    // Ensure branding consistency: "Mika/mica" → "MICA" in the displayed chat.
    // The speech pipeline receives the raw content (via speakQueued) so it
    // can apply its own cleaning + pronunciation dictionary.
    var displayContent = role === 'ai' ? normalizeForDisplay(content) : content;

    var msgDiv = document.createElement('div');
    msgDiv.className = 'mica-message mica-' + role;

    var speakingIndicator = (role === 'ai' && C.state.synthQueue.length > 0)
      ? '<div class="mica-speaking-indicator"><span></span><span></span><span></span></div>'
      : '';

    var speakBtn = role === 'ai'
      ? '<button class="mica-msg-speak-btn" type="button" title="Speak this response" aria-label="Speak response"><i class="bi bi-volume-up"></i></button>'
      : '';

    msgDiv.innerHTML =
      '<div class="mica-bubble">' +
        '<div>' + renderMarkdown(displayContent) + speakingIndicator + '</div>' +
        '<div class="d-flex justify-content-between align-items-center mt-1">' +
          '<div class="mica-bubble-time">' + time + '</div>' +
          speakBtn +
        '</div>' +
      '</div>';

    C.dom.micaBody.appendChild(msgDiv);
    scrollToBottom();
    C.state.messages.push({ role: role, content: displayContent, time: time });
  }

// Return the time-of-day greeting based on PHILIPPINE time (Asia/Manila),
  // regardless of the browser's local timezone.
  function getPhilippineGreeting() {
    var hour = new Date().getHours();
    var tz = C.config.timezone || 'Asia/Manila';
    try {
      var parts = new Intl.DateTimeFormat('en-US', { hour: '2-digit', hour12: false, timeZone: tz }).formatToParts(new Date());
      var hp = parts.find(function (p) { return p.type === 'hour'; });
      if (hp) hour = parseInt(hp.value, 10);
    } catch (e) { /* fall back to browser hour */ }
    if (hour >= 5 && hour < 12) return 'Good morning';
    if (hour >= 12 && hour < 18) return 'Good afternoon';
    return 'Good evening';
  }

  function showPersonalizedGreeting() {
    var timeOfDay = getPhilippineGreeting();
    var firstName = (window.MICA_USER && window.MICA_USER.firstName) || 'there';
    var role = (window.MICA_USER && window.MICA_USER.role) || (document.getElementById('mica-assistant') && document.getElementById('mica-assistant').getAttribute('data-mica-role')) || 'student';

    var roleScope = '';
    if (role === 'nurse') {
      roleScope = 'I can assist you with <strong>today\'s appointments</strong>, <strong>medicine inventory stock</strong>, <strong>emergency SOS alerts</strong>, <strong>dispensing logs</strong>, and <strong>medical certificates</strong>.<br><br>';
    } else if (role === 'teacher' || role === 'adviser') {
      roleScope = 'I can assist you with <strong>advisory class health tracking</strong>, <strong>clinic coordination</strong>, <strong>clinic announcements</strong>, and <strong>emergency SOS guidelines</strong>.<br><br>';
    } else if (role === 'admin') {
      roleScope = 'I can assist you with <strong>user management overviews</strong>, <strong>course & section setup</strong>, <strong>system configuration</strong>, and <strong>clinic analytics & reports</strong>.<br><br>';
    } else {
      roleScope = 'Ask me about your <strong>medical records</strong>, <strong>appointments</strong>, <strong>BMI & vitals</strong>, <strong>vaccinations</strong>, <strong>medicine availability</strong>, or <strong>SOS emergency</strong> help.<br><br>';
    }

    var div = document.createElement('div');
    div.className = 'mica-message mica-ai';
    div.innerHTML =
      '<div class="mica-bubble">' +
        '<div><strong> ' + timeOfDay + ', ' + escapeHtml(firstName) + '!</strong><br><br>' +
        'I\'m <strong>MICA</strong> — your <strong>Medical Intelligent Clinic Assistant</strong><br>' +
        'from Bulacan Polytechnic College.<br><br>' +
        roleScope +
        'You can <strong>type</strong> your question or tap the <strong>microphone</strong> to speak.<br>' +
        'I\'ll listen, understand, and respond in real time.<br><br>' +
        'How can I help you today?</div>' +
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
    var role = (window.MICA_USER && window.MICA_USER.role) || (document.getElementById('mica-assistant') && document.getElementById('mica-assistant').getAttribute('data-mica-role')) || 'student';
    var questions = [];

    if (role === 'nurse') {
      questions = ['Today\'s appointments', 'Medicine inventory stock', 'Recent emergency alerts', 'Clinic announcements'];
    } else if (role === 'teacher' || role === 'adviser') {
      questions = ['My advisory students', 'Clinic referral process', 'Clinic announcements', 'Emergency SOS guidelines'];
    } else if (role === 'admin') {
      questions = ['System user overview', 'Course & section list', 'Recent activity summary', 'Clinic announcements'];
    } else {
      questions = ['Find the nearest hospital', 'Book an appointment', 'What is my BMI?', 'Medicine availability'];
    }

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
  // Geolocation helpers: real-time browser location access
  // ================================================================
  function isLocationQuery(text) {
    if (!text) return false;
    var lower = String(text).toLowerCase();
    var kws = [
      'hospital near me', 'nearest hospital', 'nearby hospital', 'where is the nearest hospital',
      'find the nearest hospital', 'find nearby hospital', 'find hospital', 'nearest emergency room',
      'emergency room near me', 'where is the nearest emergency room', 'medical help nearby',
      'nearest clinic', 'nearby clinic', 'malapit na ospital', 'saan may ospital', 'pinakamalapit na ospital',
      'emergency room malapit', 'nearest health center', 'nearby emergency facilities', 'nearest medical center',
      'nearest doctor', 'nearby doctor', 'san may ospital', 'saan may clinic', 'pinakamalapit na clinic',
      'where is the nearest clinic', 'closest hospital', 'closest clinic', 'closest emergency room',
      'find emergency room', 'er near me', 'nearest er', 'nearby er', 'nearby healthcare', 'nearby facilities'
    ];
    for (var i = 0; i < kws.length; i++) {
      if (lower.indexOf(kws[i]) !== -1) return true;
    }
    return false;
  }

  function isLocationStale() {
    if (!C.state.micaLocation || !C.state.micaLocation.available) return true;
    var age = Date.now() - (C.state.micaLocation.lastAcquired || 0);
    return age > 600000; // 10 minutes
  }

  function requestLocation(forceFresh, retryCount) {
    retryCount = retryCount || 0;
    return new Promise(function (resolve) {
      var isSecure = window.isSecureContext || window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1';
      if (!isSecure && window.location.protocol !== 'https:') {
        C.state.locationState = 'insecure';
        C.state.micaLocation.available = false;
        C.state.locationData = null;
        updateLocationBadge();
        resolve(null);
        return;
      }

      if (!navigator.geolocation) {
        C.state.locationState = 'unsupported';
        C.state.micaLocation.available = false;
        C.state.locationData = null;
        updateLocationBadge();
        resolve(null);
        return;
      }

      // If already available, fresh (< 10 minutes), and not forced, return cached location
      if (!forceFresh && C.state.micaLocation && C.state.micaLocation.available && !isLocationStale()) {
        resolve(C.state.micaLocation);
        return;
      }

      C.state.locationState = 'prompting';
      updateLocationBadge();

      navigator.geolocation.getCurrentPosition(
        function (pos) {
          var lat = pos.coords.latitude;
          var lng = pos.coords.longitude;
          var rawAcc = pos.coords.accuracy || 0;
          var time = new Date(pos.timestamp || Date.now()).toISOString().replace('T', ' ').substring(0, 19);

          // If accuracy is poor (> 1000m) and we haven't retried yet, retry once with maximumAge: 0
          if (rawAcc > 1000 && retryCount < 1) {
            console.log('[MICA Geolocation] Low accuracy detected (' + Math.round(rawAcc) + 'm). Retrying for GPS lock... (attempt ' + (retryCount + 1) + ')');
            requestLocation(true, retryCount + 1).then(resolve);
            return;
          }

          var isLow = (rawAcc > 1000);
          var accFormatted = rawAcc >= 1000 ? (Math.round(rawAcc / 1000) + 'km') : (Math.round(rawAcc) + 'm');

          C.state.locationState = 'granted';
          C.state.micaLocation = {
            latitude: lat,
            longitude: lng,
            accuracy: accFormatted,
            accuracyMeters: Math.round(rawAcc),
            timestamp: time,
            available: true,
            lowAccuracy: isLow,
            lastAcquired: Date.now()
          };

          C.state.locationData = {
            lat: lat,
            lng: lng,
            accuracy: accFormatted,
            accuracyMeters: Math.round(rawAcc),
            isLowAccuracy: isLow,
            timestamp: time,
            lastUpdated: Date.now()
          };

          // Synchronize global SOS location cache
          window.__MICA_SOS_LOCATION__ = { lat: lat, lng: lng, accuracy: accFormatted, rawAccuracy: rawAcc };
          window.__MICA_SOS_LOC_TIME__ = Date.now();

          updateLocationBadge();


          console.log('[MICA Geolocation] Location granted:', lat, lng, accFormatted, (isLow ? '(Low accuracy)' : '(Precise)'));
          resolve(C.state.micaLocation);
        },
        function (err) {
          console.warn('[MICA Geolocation] Error:', err.code, err.message);
          if (err.code === 1) { // PERMISSION_DENIED
            C.state.locationState = 'denied';
          } else if (err.code === 3) { // TIMEOUT
            C.state.locationState = 'timeout';
          } else { // POSITION_UNAVAILABLE
            C.state.locationState = 'unavailable';
          }
          C.state.micaLocation.available = false;
          C.state.locationData = null;
          updateLocationBadge();
          resolve(null);
        },
        { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 }
      );
    });
  }

  function updateLocationBadge() {
    if (!C.dom.locationIndicator || !C.dom.locationText) return;
    var st = C.state.locationState;
    if (st === 'granted' && C.state.micaLocation && C.state.micaLocation.available) {
      if (C.state.micaLocation.lowAccuracy) {
        C.dom.locationIndicator.className = 'mica-location-indicator unavailable';
        C.dom.locationText.textContent = 'Coarse Location (±' + (C.state.micaLocation.accuracy || '50km') + ') • Tap to refine';
      } else {
        C.dom.locationIndicator.className = 'mica-location-indicator active';
        C.dom.locationText.textContent = 'Location available • Accuracy: ±' + (C.state.micaLocation.accuracy || '20m');
      }
      C.dom.locationIndicator.style.display = 'flex';
    } else if (st === 'prompting') {
      C.dom.locationIndicator.className = 'mica-location-indicator';
      C.dom.locationText.textContent = 'Requesting location permission...';
      C.dom.locationIndicator.style.display = 'flex';
    } else if (st === 'denied') {
      C.dom.locationIndicator.className = 'mica-location-indicator denied';
      C.dom.locationText.textContent = 'Location blocked in browser (Tap to allow)';
      C.dom.locationIndicator.style.display = 'flex';
    } else if (st === 'timeout' || st === 'unavailable') {
      C.dom.locationIndicator.className = 'mica-location-indicator unavailable';
      C.dom.locationText.textContent = ' GPS unavailable (Tap to retry)';
      C.dom.locationIndicator.style.display = 'flex';
    } else {
      C.dom.locationIndicator.style.display = 'none';
    }
  }

  // ================================================================
  // Quick Actions handler
  // ================================================================
  function handleQuickAction(action) {
    if (!action) return;
    if (action === 'sos') {
      showSOSPrompt();
      return;
    }
    if (action === 'nearby_hospital' || action === 'hospital') {
      requestLocation(true).then(function () {
        sendMessage('Find the nearest hospital');
      });
      return;
    }
    if (action === 'bmi') {
      sendMessage('What is my BMI?');
      return;
    }
    if (action === 'appointments' || action === 'appointments_nurse') {
      sendMessage('Check my scheduled appointments');
      return;
    }
    if (action === 'records') {
      sendMessage('Show my medical records');
      return;
    }
    if (action === 'medicines') {
      sendMessage('Check medicine availability');
      return;
    }
    if (action === 'vaccines') {
      sendMessage('Show my vaccination records');
      return;
    }
    if (action === 'announcements') {
      sendMessage('Show latest clinic announcements');
      return;
    }
    if (action === 'emergencies_nurse') {
      sendMessage('Show recent emergency alerts and SOS requests');
      return;
    }
    if (action === 'advisory_teacher') {
      sendMessage('Show my advisory class health summary');
      return;
    }
    if (action === 'users_admin') {
      sendMessage('Show system user management overview');
      return;
    }
    sendMessage(action.replace(/_/g, ' '));
  }

  // ================================================================
  // Chat: send message via AJAX
  // mode: 'text' (default) — never speaks. 'voice' — speaks response.
  // ================================================================
  function sendMessage(text, mode) {
    if (!text || text.trim() === '' || (C.dom.sendBtn && C.dom.sendBtn.disabled)) return;

    if(window.MICAInventory && window.MICAInventory.command(text,C.dom.micaBody)){if(C.dom.micaInput)C.dom.micaInput.value='';return;}
    mode = mode || 'text';
    var message = text.trim().substring(0, C.config.maxTextLength);
    if (C.dom.micaInput) C.dom.micaInput.value = '';
    if (C.dom.micaInput) C.dom.micaInput.disabled = true;
    if (C.dom.sendBtn) C.dom.sendBtn.disabled = true;

    C.state.hasInteracted = true;

    // Stop any in-progress speech when the user submits a new message.
    stopSpeaking();

    // Record the input mode BEFORE awaiting the response so the AI reply
    // knows whether to be spoken (voice) or shown only (text).
    C.state.lastInputMode = mode;

    addMessage('user', message);
    hideChips();

    function dispatchChatFetch() {
      setMode('thinking');
      C.state.conversationCount++;

      var formData = new FormData();
      formData.append('action', 'mica_chat');
      formData.append('message', message);
      formData.append('first_message', C.state.isFirstMessage ? '1' : '0');
      formData.append('csrf_token', C.config.csrfToken);
      formData.append('page', window.MICA_CURRENT_PAGE || '');

      // Real-time browser location metadata
      var loc = C.state.micaLocation || {};
      var hasCoords = (loc.latitude !== null && loc.latitude !== undefined && loc.longitude !== null && loc.longitude !== undefined);
      var isGranted = (C.state.locationState === 'granted' || loc.available === true);


if (hasCoords && isGranted) {
        formData.append('latitude', loc.latitude);
        formData.append('longitude', loc.longitude);
        formData.append('accuracy', loc.accuracy || '');
        formData.append('accuracy_meters', loc.accuracyMeters || '');
        formData.append('is_low_accuracy', loc.lowAccuracy ? '1' : '0');
        formData.append('location_status', 'granted');
        formData.append('location_timestamp', loc.timestamp || '');
      } else {
        formData.append('location_status', C.state.locationState || 'unrequested');
      }

      C.state.isFirstMessage = false;

      fetch(C.config.appUrl + '/api/ai_assistant.php', {
        method: 'POST',
        body: formData,
      })
        .then(function (res) {
          console.log('[MICA] Chat request — HTTP ' + res.status + ' for url: ' + C.config.appUrl + '/api/ai_assistant.php');
          if (res.status === 401) {
            // Session expired — show message and redirect to login
            addMessage('ai', 'Your session has expired. Redirecting to login...');
            if (!C.state.isPlaying) setMode('idle');
            setTimeout(function () {
              window.location.href = C.config.appUrl + '/auth/login.php';
            }, 1500);
            return null;
          }
          if (!res.ok) {
            return res.json().catch(function () { return null; }).then(function (errData) {
              var detail = (errData && errData.message) ? errData.message : 'HTTP ' + res.status;
              throw new Error(detail);
            });
          }
          return res.json();
        })
        .then(function (data) {
          if (!data) return; // 401 handled above
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
            addMessage('ai', data.message || 'Sorry, I am having trouble connecting right now. Please try again.');
            if (!C.state.isPlaying) setMode('idle');
          }
        })
        .catch(function (err) {
          console.warn('[MICA] Chat request failed.');
          if(C.dom.micaInput && !C.dom.micaInput.value)C.dom.micaInput.value=message;
          addMessage('ai', 'Sorry, I am having trouble connecting right now. Please try again.');
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

    // Always dispatch directly without non-gesture async geolocation requests
    dispatchChatFetch();
  }

  // ================================================================
  // Stop any current speech output (used before replay & mode switch)
  // ================================================================
  function stopSpeaking() {
    if (C.state.currentAudio) {
      try { C.state.currentAudio.pause(); C.state.currentAudio = null; } catch (e) { /* ignore */ }
    }
    if ('speechSynthesis' in window) {
      try { window.speechSynthesis.cancel(); } catch (e) { /* ignore */ }
    }
    C.state.isPlaying = false;
    C.state.synthQueue = [];
    C.state.synthTotalChunks = 0;
    C.state.synthCurrentChunk = 0;
  }

  // ================================================================
  // Smart navigation
  // ================================================================
  function handleNavigation(text) {
    if (text.match(/(log|sign)?\s*(out|logout|sign out)/i) && !text.match(/about|without|login|sign in/i)) {
      addMessage('ai', 'Logging you out now... ');
      setTimeout(function () { window.location.href = C.config.appUrl + '/auth/logout.php'; }, 800);
      return true;
    }

    for (var i = 0; i < C.config.navigationCommands.length; i++) {
      var cmd = C.config.navigationCommands[i];
      var match = text.match(cmd.match);
      if (match) {
        var pageName = match[2] || 'that page';
        addMessage('ai', 'Navigating to ' + pageName + '... ');
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
      title: ' EMERGENCY DETECTED',
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
          // Reuse the single instance so repeat SOS activations
          // never stack backdrops or duplicate listeners.
          var bsModal = bootstrap.Modal.getOrCreateInstance(modalEl);
          bsModal.show();
          // GPS location is requested AFTER the student confirms & submits
          // the SOS request (handled inside components/mica.php submitSOS).
        }
      }
    });
  }

  function activateSOS(type, description, lat, lng) {
    var proceed = function (coords) {
      var finalLat = (coords && coords.lat !== undefined) ? coords.lat : lat;
      var finalLng = (coords && coords.lng !== undefined) ? coords.lng : lng;
      var finalAcc = (coords && coords.accuracy !== undefined) ? coords.accuracy : null;

      var formData = new FormData();
      formData.append('action', 'sos_emergency');
      formData.append('emergency_type', type);
      formData.append('description', description || type + ' - Emergency via MICA');
      formData.append('csrf_token', C.config.csrfToken);
      if (finalLat !== null && finalLat !== undefined) formData.append('latitude', finalLat);
      if (finalLng !== null && finalLng !== undefined) formData.append('longitude', finalLng);
      if (finalAcc !== null && finalAcc !== undefined) formData.append('accuracy', finalAcc);

      return fetch(C.config.appUrl + '/api/ai_assistant.php', {
        method: 'POST',
        body: formData,
      }).then(function (r) { return r.json(); }).then(function (res) {
        if (res && res.success && res.emergency_id && typeof window.startSosLocationTracking === 'function') {
          window.startSosLocationTracking(res.emergency_id, finalLat, finalLng, finalAcc);
        }
        return res;
      });
    };

    if ((lat === undefined || lat === null) && typeof window.getLocation === 'function') {
      return window.getLocation().then(proceed);
    }
    return proceed({ lat: lat, lng: lng });
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
    }).then(function (r) { console.log('[MICA] ClearHistory request — HTTP ' + r.status); return r.json(); })
      .then(function (data) {
        if (data.success) {
          C.state.messages = [];
          C.config.initialGreetingShown = false;
          C.state.isFirstMessage = true;
          if (C.dom.micaBody) C.dom.micaBody.innerHTML = '';
          showPersonalizedGreeting();
        } else {
          console.warn('[MICA] ClearHistory failed:', data.message || 'unknown error');
        }
      })
      .catch(function (err) { console.error('[MICA] ClearHistory error:', err); });
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
    }).then(function (r) { console.log('[MICA] History request — HTTP ' + r.status); return r.json(); })
      .then(function (data) {
        if (!data.success) { console.warn('[MICA] History fetch returned success:false:', data.message); return; }
        // The API returns { success, message, history, stats } — history is
        // at the top level, NOT nested under data. Guard against both shapes
        // so an empty/invalid response never throws an uncaught error.
        var historyData = (data && (data.history || data.messages)) || (data && data.data && data.data.history) || [];
        if (!Array.isArray(historyData) || historyData.length === 0) {
          showPersonalizedGreeting();
          return;
        }
        // Render last 20 messages
        var history = historyData.slice(-20);
        history.forEach(function (entry) {
          addMessage(entry.role === 'user' ? 'user' : 'ai', entry.content);
        });
        C.state.isFirstMessage = false;
        C.config.initialGreetingShown = true;
        hideChips();
      })
      .catch(function (err) { console.error('[MICA] History load error:', err); showPersonalizedGreeting(); });
  }

  // ================================================================
  // Voice: Speech Recognition (built-in)
  // ================================================================
  var SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
  var supportsSpeechRecognition = !!SpeechRecognition;

  // Diagnostic flags — test physical microphone independently from SpeechRecognition
  var micAccessibilityUnknown = true;
  var micAccessible = false;

  // Diagnostic: test physical microphone access
  function testMicrophoneAccess() {
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      console.warn('[MICA] MediaDevices not available');
      micAccessibilityUnknown = false;
      micAccessible = false;
      return Promise.reject(new Error('MediaDevices not available'));
    }
    console.log('[MICA] Testing microphone access...');
    return navigator.mediaDevices.getUserMedia({ audio: true })
      .then(function (stream) {
        console.log('[MICA] getUserMedia success');
        micAccessible = true;
        micAccessibilityUnknown = false;
        stream.getTracks().forEach(function (t) { t.stop(); });
        return true;
      })
      .catch(function (err) {
        console.warn('[MICA] getUserMedia failed:', err.name, err.message);
        micAccessibilityUnknown = false;
        micAccessible = false;
        return Promise.reject(err);
      });
  }

  // Reliable recognition state variables to eliminate all race conditions
  var isRecognitionRunning = false;
  var isStartingRecognition = false;

  // Initialize recognition ONCE — never create duplicate instances
  function initRecognition() {
    if (!supportsSpeechRecognition) {
      console.warn('[MICA] SpeechRecognition not supported in this browser');
      return;
    }
    if (C.state.recognition) return;

    console.log('[MICA] Creating SpeechRecognition instance');
    var recognition = new SpeechRecognition();
    recognition.continuous = false;
    recognition.interimResults = true;
    recognition.lang = 'en-US';

    // onstart confirms browser-side recognition is live
    recognition.onstart = function () {
      console.log('[MICA] Recognition started');
      isRecognitionRunning = true;
      isStartingRecognition = false;
      C.state.recognitionState = 'listening';
      C.state.isListening = true;
      if (C.dom.micaInput) C.dom.micaInput.placeholder = 'Listening...';
      showStatus('Listening...');
    };

    recognition.onresult = function (event) {
      var finalTranscript = '';
      var interimTranscript = '';
      for (var i = event.resultIndex; i < event.results.length; i++) {
        var r = event.results[i];
        if (r.isFinal) finalTranscript += r[0].transcript;
        else interimTranscript += r[0].transcript;
      }
      if (interimTranscript && C.dom.micaInput) {
        C.dom.micaInput.value = interimTranscript;
        showStatus(' ' + interimTranscript);
      }
      if (finalTranscript) {
        console.log('[MICA] Final transcript:', finalTranscript.trim());
        handleVoiceInput(finalTranscript.trim());
      }
    };

    recognition.onerror = function (event) {
      console.warn('[MICA] Speech recognition error:', event.error);
      isRecognitionRunning = false;
      isStartingRecognition = false;
      C.state.isListening = false;
      C.state.recognitionState = 'error';

      if (event.error === 'not-allowed') {
        showStatus('Microphone permission was denied. Please allow microphone access in your browser settings.');
        setMode('idle');
      } else if (event.error === 'audio-capture') {
        showStatus('Could not access your microphone. Please check your audio hardware and try again.');
        setMode('idle');
      } else if (event.error === 'network') {
        showStatus('Voice recognition requires an active internet connection.');
        setMode('idle');
      } else if (event.error === 'no-speech') {
        if (C.config.continuousMode && C.state.isOpen) {
          setTimeout(function () {
            if (C.config.continuousMode && C.state.isOpen && !C.state.isPlaying && !isRecognitionRunning && !isStartingRecognition) {
              startRecognition();
            }
          }, 400);
        } else {
          showStatus('No speech detected. Tap the mic to try again.');
          setMode('idle');
        }
      } else if (event.error === 'aborted') {
        setMode('idle');
      } else {
        showStatus('Voice input error: ' + event.error + '. Try typing instead.');
        setMode('idle');
      }
    };

    recognition.onend = function () {
      console.log('[MICA] Recognition ended');
      isRecognitionRunning = false;
      isStartingRecognition = false;
      C.state.isListening = false;
      C.state.recognitionState = 'idle';
      if (C.state.mode === 'listening') {
        if (C.config.continuousMode && C.state.isOpen && !C.state.isPlaying) {
          setTimeout(function () {
            if (C.config.continuousMode && C.state.isOpen && !C.state.isPlaying && !isRecognitionRunning && !isStartingRecognition) {
              startRecognition();
            }
          }, 300);
        } else {
          setMode('idle');
          if (C.dom.micaInput) C.dom.micaInput.placeholder = 'Type a message...';
        }
      }
    };

    C.state.recognition = recognition;
    C.state.recognitionState = 'idle';
    console.log('[MICA] SpeechRecognition initialized');
  }

  // Single centralized function responsible for starting recognition
  function startRecognition() {
    if (!supportsSpeechRecognition) {
      showStatus('Voice recognition is not supported in this browser. Please use Google Chrome or Microsoft Edge.');
      if (C.dom.micaInput) C.dom.micaInput.focus();
      return;
    }

    if (isRecognitionRunning || isStartingRecognition) {
      console.log('[MICA] Recognition already starting/running — ignoring duplicate start');
      return;
    }

    // SELF-HEARING PREVENTION: Stop speaking if MICA is currently outputting audio
    if (C.state.isPlaying || ('speechSynthesis' in window && window.speechSynthesis.speaking)) {
      console.log('[MICA] MICA is currently speaking. Pausing speech before listening.');
      stopSpeaking();
    }

    initRecognition();
    if (!C.state.recognition) {
      showStatus('Voice input is not supported by this browser.');
      setMode('idle');
      return;
    }

    isStartingRecognition = true;
    setMode('listening');
    if (C.dom.micaInput) C.dom.micaInput.placeholder = 'Listening...';
    showStatus('Listening...');
    C.state.recognitionState = 'starting';

    try {
      C.state.recognition.start();
      console.log('[MICA] Starting browser SpeechRecognition instance');
    } catch (error) {
      if (error.name === 'InvalidStateError') {
        console.warn('[MICA] Recognition is already running');
        isRecognitionRunning = true;
      } else {
        console.error('[MICA] Recognition start error:', error);
        C.state.isListening = false;
        C.state.recognitionState = 'idle';
        setMode('idle');
      }
    } finally {
      isStartingRecognition = false;
    }
  }

  // Safely restart recognition after it ends (with state protection)
  function tryRestartRecognition() {
    if (!C.state.recognition) return;
    if (isRecognitionRunning || isStartingRecognition) return;
    startRecognition();
  }

  // User gesture handler for microphone button click
  function handleMicBtnClick() {
    if (isRecognitionRunning || isStartingRecognition || C.state.isListening || C.state.recognitionState === 'listening' || C.state.recognitionState === 'starting') {
      stopListening();
      return;
    }
    // GESTURE-BASED GEOLOCATION REQUEST:
    // Prompt for browser geolocation directly inside the user gesture handler.
    // If location is unrequested, not yet available, or stale (> 10 mins), request it now!
    if (navigator.geolocation && (C.state.locationState === 'unrequested' || !C.state.micaLocation.available || isLocationStale())) {
      requestLocation(false).catch(function () {});
    }
    startRecognition();
  }

  function startListening() {
    startRecognition();
  }

  function stopListening() {
    console.log('[MICA] stopListening called');
    if (C.state.recognition) {
      try {
        if (isStartingRecognition || C.state.recognitionState === 'starting') {
          C.state.recognition.abort();
        } else {
          C.state.recognition.stop();
        }
      } catch (e) {
        console.warn('[MICA] stop failed:', e.message);
      }
    }
    isRecognitionRunning = false;
    isStartingRecognition = false;
    C.state.isListening = false;
    C.state.recognitionState = 'idle';
    if (C.state.mode === 'listening') setMode('idle');
    if (C.dom.micaInput) C.dom.micaInput.placeholder = 'Type a message...';
  }

  function normalizeSpeechTranscript(text) {
    if (!text) return text;
    var out = String(text);
    for (var i = 0; i < RECOGNITION_NORMALIZATION.length; i++) {
      out = out.replace(RECOGNITION_NORMALIZATION[i].from, RECOGNITION_NORMALIZATION[i].to);
    }
    return out.replace(/\s+/g, ' ').trim();
  }

  // Ensure the assistant name is always displayed as "MICA" in the UI.
  function normalizeForDisplay(text) {
    if (!text) return text;
    var out = String(text);
    // Strip accidental non-currency '$' artifacts
    out = out.replace(/^\s*\$\s+/gm, '');
    out = out.replace(/(?:^|\n)\s*\$\s*([A-Za-z0-9])/gm, '\n$1');
    var neverDisplay = ['Mika', 'mika', 'Mica', 'mica', 'Meeka', 'meeka'];
    for (var i = 0; i < neverDisplay.length; i++) {
      out = out.replace(new RegExp('\\b' + neverDisplay[i] + '\\b', 'g'), 'MICA');
    }
    return out;
  }

  function handleVoiceInput(transcript) {
    C.state.isListening = false;
    C.state.recognitionState = 'processing';
    if (C.state.recognition) { try { C.state.recognition.stop(); } catch (e) { /* ignore */ } }
    if (!transcript || transcript.trim() === '') {
      showStatus("I didn't catch that. Please try again.");
      C.state.recognitionState = 'idle';
      setMode('idle');
      return;
    }

    console.log('[MICA] Transcript:', transcript.trim());

    // Normalize speech recognition BEFORE sending to the AI.
    // Fixes "Mika" → "MICA", "b p c" → "BPC", etc.
    transcript = normalizeSpeechTranscript(transcript);

    // Ensure branding consistency in the input field.
    if (C.dom.micaInput) C.dom.micaInput.value = transcript.trim();
    transcript = normalizeForDisplay(transcript.trim());

    // VOICE MODE: mark the input as 'voice' so the reply is spoken,
    // then the state machine returns to Idle (no continuous listening).
    if (handleNavigation(transcript)) return;
    sendMessage(transcript, 'voice');
  }

  // ================================================================
  // Voice: TTS + audio queue
  // ================================================================
  function speakQueued(text) {
    if (!text || text.length < 2) return;
    var fullClean = prepareSpeechText(text);
    if (!fullClean) return;

    var chunks = splitTextForTTS(fullClean, 450);
    if (!chunks.length) return;

    console.log('[MICA TTS] Full response length:', text.length);
    console.log('[MICA TTS] Full response:', text);
    console.log('[MICA TTS] Number of chunks:', chunks.length);

    C.state.synthQueue = chunks.slice();
    C.state.synthTotalChunks = chunks.length;
    C.state.synthCurrentChunk = 0;

    processSynthQueue();
  }

  function processSynthQueue() {
    // SELF-HEARING PREVENTION: Always stop microphone before MICA speaks!
    stopListening();

    if (!C.state.synthQueue || C.state.synthQueue.length === 0) {
      console.log('[MICA TTS] COMPLETE — entire response spoken');
      C.state.isPlaying = false;
      C.state.currentAudio = null;
      C.state.synthTotalChunks = 0;
      C.state.synthCurrentChunk = 0;
      if (C.config.continuousMode && C.state.isOpen) {
        setMode('idle');
        showStatus('Listening...');
        setTimeout(function () {
          if (C.config.continuousMode && C.state.isOpen && !C.state.isPlaying) {
            startListening();
          }
        }, 500);
      } else {
        setMode('idle');
      }
      return;
    }

    var chunk = C.state.synthQueue.shift();
    C.state.synthCurrentChunk = (C.state.synthCurrentChunk || 0) + 1;
    var currentIdx = C.state.synthCurrentChunk;
    var total = C.state.synthTotalChunks || 1;
    console.log('[MICA TTS] Speaking chunk ' + currentIdx + '/' + total);

    speakSingleChunk(chunk)
      .then(function () {
        processSynthQueue();
      })
      .catch(function (err) {
        console.warn('[MICA TTS] Chunk ' + currentIdx + ' playback failed, proceeding to next:', err);
        processSynthQueue();
      });
  }

  function speakSingleChunk(chunkText) {
    return new Promise(function (resolve) {
      if (!chunkText || !chunkText.trim()) { resolve(); return; }

      if (C.state.currentAudio) {
        try { C.state.currentAudio.pause(); C.state.currentAudio = null; } catch (e) { /* ignore */ }
      }
      if ('speechSynthesis' in window) {
        try { window.speechSynthesis.cancel(); } catch (e) { /* ignore */ }
      }

      var provider = C.config.ttsProvider || 'elevenlabs';
      if (provider === 'elevenlabs') {
        ttsViaServer(chunkText).then(resolve).catch(function (err) {
          console.warn('[MICA TTS] ElevenLabs chunk failed (' + (err && err.message ? err.message : err) + '), using browser speech fallback.');
          if ('speechSynthesis' in window) {
            try { window.speechSynthesis.cancel(); } catch (e) { /* ignore */ }
          }
          speakWithNativeTTS(chunkText).then(resolve);
        });
      } else {
        speakWithNativeTTS(chunkText).then(resolve);
      }
    });
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
      var clean = text ? text.trim() : '';
      if (!clean) { resolve(); return; }

      var utter = new SpeechSynthesisUtterance(clean);
      utter.rate = C.config.voiceSpeed;
      utter.volume = C.config.voiceVolume;
      var fv = pickFemaleVoice();
      if (fv) utter.voice = fv;

      utter.onend = function () {
        C.state.isPlaying = false;
        resolve();
      };
      utter.onerror = function () {
        C.state.isPlaying = false;
        resolve();
      };

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
    showStatus('Testing voice...');
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
      var sosBanner = document.getElementById('studentActiveSosBanner');
      if (sosBanner) sosBanner.classList.add('mica-open-dock');
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
      if (window.MICAInventory) window.MICAInventory.show(C.dom.micaBody, false, true);

      // HYBRID RULE: NEVER auto-start listening when the widget opens.
      // The user must explicitly press the microphone button.
    } else {
      C.dom.micaWidget.classList.remove('open');
      var sosBanner2 = document.getElementById('studentActiveSosBanner');
      if (sosBanner2) sosBanner2.classList.remove('mica-open-dock');
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
      appointments_nurse: 'What appointments are scheduled today?',
      records: 'Show my medical records',
      clinic_information: 'What are the clinic hours and services?',
      my_queue: 'Show my current clinic queue status',
      clinic_procedures: 'Explain the clinic procedures for students',
      medicines: 'What medicines are available in the clinic?',
      vaccines: 'What vaccines do I have on record?',
      vaccination: 'What vaccines do I have on record?',
      announcements: 'Latest clinic announcements',
      health: 'Show my health monitoring data',
      emergencies_nurse: 'Are there any active emergency alerts or SOS requests?',
      certificates_nurse: 'How do I issue or verify a medical certificate?',
      advisory_teacher: 'How do I check my advisory students in the clinic system?',
      referrals_teacher: 'How do I coordinate a student visit to the school clinic?',
      sos_protocol: 'What is the emergency SOS protocol for teachers?',
      wellness_tips: 'What student wellness support techniques can be recommended?',
      users_admin: 'Provide a summary of user roles and accounts in the system',
      sections_admin: 'How are courses and sections configured in the clinic system?',
      analytics_admin: 'What clinic reports and analytics are available?',
      sos: '__SOS__',
      navigate_dashboard: 'Open Dashboard',
      navigate_appointments: 'Open Appointments',
      navigate_reports: 'Open Reports',
      navigate_notifications: 'Open Notifications',
      navigate_settings: 'Open Settings',
      navigate_profile: 'Open Profile',
      logout: 'Logout',
    };

    if (action === 'mica_student_records') { renderStudentRecordsPanel(); return; }
    if (action === 'mica_inventory') { renderInventoryPanel(); return; }
    if (action === 'mica_certificates') { renderCertificatesPanel(); return; }
    if (action === 'mica_reports') { renderReportsPanel(); return; }

    var msg = messages[action] || messages[action.toLowerCase()] || 'Help me with clinic information';

    if (action === 'sos') { showSOSPrompt(); return; }

    if (msg.match(/(open|logout)/i)) {
      if (handleNavigation(msg.toLowerCase())) { return; }
    }

    if (C.dom.micaInput) C.dom.micaInput.value = msg;
    sendMessage(msg);
    hideChips();
  }

  var _lastMicClick = 0;
  function handleMicBtnClick() {
    var now = Date.now();
    if (now - _lastMicClick < 350) return;
    _lastMicClick = now;

    console.log('[MICA] Microphone button activated, current mode:', C.state.mode);

    // If currently speaking, stop first then start listening
    if (C.state.isPlaying || C.state.mode === 'speaking') {
      console.log('[MICA] Interrupting speech to start listening');
      stopSpeaking();
      setMode('idle');
      setTimeout(function () { startListening(); }, 150);
      return;
    }
    // If thinking, stop and allow fresh start
    if (C.state.mode === 'thinking') {
      setMode('idle');
      return;
    }
    // Toggle: stop if listening, start if idle
    if (C.state.isListening) {
      stopListening();
      return;
    }
    startListening();
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

    if (C.dom.micBtn) {
      C.dom.micBtn.type = 'button';
      if(!supportsSpeechRecognition){C.dom.micBtn.disabled=true;C.dom.micBtn.title='Speech recognition is unavailable in this browser. Type your message instead.';}
      C.dom.micBtn.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        handleMicBtnClick();
      });
    }

    if (C.dom.micaBody) {
      C.dom.micaBody.addEventListener('click', function (e) {
        var speakBtn = e.target.closest('.mica-msg-speak-btn');
        if (speakBtn) {
          e.preventDefault();
          var bubble = speakBtn.closest('.mica-bubble');
          if (bubble) {
            var textEl = bubble.querySelector('div:first-child');
            var rawText = textEl ? textEl.innerText.replace(/MICA is speaking\.\.\./g, '') : '';
            if (rawText.trim()) {
              stopSpeaking();
              speakQueued(rawText.trim());
            }
          }
        }
      });
    }

    if (C.dom.replayBtn) {
      C.dom.replayBtn.addEventListener('click', function () {
        if (C.state.lastResponse) {
          stopSpeaking();
          addMessage('ai', 'Replaying...');
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

    if (C.dom.locationIndicator) {
      C.dom.locationIndicator.addEventListener('click', function () {
        requestLocation(true).then(function (loc) {
          if (loc) {
            showStatus('Location updated: ±' + (loc.accuracy || '20m'));
          } else if (C.state.locationState === 'denied') {
            showStatus('Location blocked in browser settings.');
          } else {
            showStatus('Unable to obtain GPS coordinates.');
          }
        });
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
      if (window.__MICA_INITIALIZED__ || C.dom.micaFab) return; // Prevent double init
      window.__MICA_INITIALIZED__ = true;

      C.config.csrfToken = document.querySelector('meta[name="csrf-token"]')
        ? document.querySelector('meta[name="csrf-token"]').getAttribute('content') : '';
      C.config.appUrl = (typeof APP_URL !== 'undefined' && APP_URL) ? APP_URL : '';
      if (!C.config.appUrl) C.config.appUrl = window.location.origin + '/bpc_clinic_system';

      // Pull server-provided MICA_CONFIG (timezone, wake words, greeting, TTS provider).
      if (typeof window.MICA_CONFIG !== 'undefined' && window.MICA_CONFIG) {
        if (window.MICA_CONFIG.timezone) C.config.timezone = window.MICA_CONFIG.timezone;
        if (window.MICA_CONFIG.wakeWords) C.config.wakeWords = window.MICA_CONFIG.wakeWords;
        if (window.MICA_CONFIG.continuousTimeoutMs) C.config.continuousTimeoutMs = window.MICA_CONFIG.continuousTimeoutMs;
        if (window.MICA_CONFIG.minConfidence) C.config.minConfidence = window.MICA_CONFIG.minConfidence;
        if (window.MICA_CONFIG.greeting) C.config.greeting = window.MICA_CONFIG.greeting;
        if (window.MICA_CONFIG.ttsProvider) C.config.ttsProvider = window.MICA_CONFIG.ttsProvider;
      }

      buildUI();
      bindEvents();

      // Diagnostic: log browser capabilities
      console.log('[MICA] SpeechRecognition supported:', supportsSpeechRecognition);
      console.log('[MICA] MediaDevices available:', !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia));
      console.log('[MICA] Secure context:', !!window.isSecureContext);
      console.log('[MICA] Origin:', window.location.origin);

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

    stopSpeaking: function () {
      stopSpeaking();
      setMode('idle');
    },

    replay: function () {
      if (C.state.lastResponse) {
        stopSpeaking();
        speakQueued(C.state.lastResponse);
      }
    },

    startListening: function () { startListening(); },
    stopListening: function () { stopListening(); },
    setVolume: function (v) { setVolume(v); },
    setSpeed: function (s) { setSpeed(s); },

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

    speak: function (text) {
      stopSpeaking();
      speakQueued(text);
    },

    isListening: function () {
      return C.state.isListening;
    },

    // Browser Geolocation API integration
    requestLocation: function (force) {
      return requestLocation(force);
    },

    getLocationState: function () {
      return {
        state: C.state.locationState,
        data: C.state.locationData
      };
    },

    isLocationAvailable: function () {
      return C.state.locationState === 'granted' && !!C.state.locationData;
    },

    // Voice test — exposed for a "Test Voice" button
    testVoice: function () { testVoice(); },
  };

  // Voice sub-API — browser Speech Synthesis + Speech Recognition helpers
  window.MICA_VOICE = {
    initRecognition: initRecognition,
    startListening: startListening,
    stopListening: stopListening,
    handleVoiceInput: handleVoiceInput,
    prepareSpeechText: prepareSpeechText,
    splitTextForTTS: splitTextForTTS,
    speakQueued: speakQueued,
    stopSpeaking: stopSpeaking,
    speakText: function (text) {
      stopSpeaking();
      speakQueued(text);
      return Promise.resolve();
    },
    interruptSpeaking: function () {
      stopSpeaking();
      setMode('listening');
    },
    processSynthQueue: processSynthQueue,
    setVolume: setVolume,
    setSpeed: setSpeed,
    getState: function () { return C.state; },
    isSupported: supportsSpeechRecognition,
    isListening: function () { return C.state.isListening; },
  };

  // TTS via server proxy (api/mica_tts.php) — key never exposed
  function ttsViaServer(text) {
    return new Promise(function (resolve, reject) {
      if (!text || text.length < 2) { resolve(); return; }
      var cleanText = text.trim();
      if (!cleanText) { resolve(); return; }

      var cacheKey = cleanText.substring(0, 100);
      if (C.config.audioCache[cacheKey]) {
        playBlob(C.config.audioCache[cacheKey]).then(resolve).catch(reject);
        return;
      }

      var formData = new FormData();
      formData.append('action', 'synthesize');
      formData.append('text', cleanText.substring(0, 2000));
      formData.append('csrf_token', C.config.csrfToken);

      fetch(C.config.appUrl + '/api/mica_tts.php', { method: 'POST', body: formData })
        .then(function (response) {
          var contentType = (response.headers && typeof response.headers.get === 'function') ? (response.headers.get('Content-Type') || '') : '';
          if (contentType.includes('application/json')) {
            return response.json().then(function (data) {
              throw new Error((data && data.error) || 'TTS server fallback');
            });
          }
          if (!response.ok) {
            throw new Error('TTS HTTP ' + response.status);
          }
          return response.blob();
        })
        .then(function (blob) {
          if (blob.size < 100) throw new Error('TTS returned empty audio');
          C.config.audioCache[cacheKey] = blob;
          return playBlob(blob);
        })
        .then(resolve)
        .catch(reject);
    });
  }

  function playBlob(blob) {
    return new Promise(function (resolve, reject) {
      if (!blob || !(blob instanceof Blob)) {
        reject(new Error('Invalid audio Blob'));
        return;
      }
      var audioBlob = (blob.type && blob.type.indexOf('audio') !== -1) ? blob : new Blob([blob], { type: 'audio/mpeg' });
      var url = URL.createObjectURL(audioBlob);
      var audio = new Audio();
      C.state.currentAudio = audio;
      audio.src = url;
      audio.volume = C.config.voiceVolume || 1.0;
      audio.playbackRate = C.config.voiceSpeed || 1.0;

      audio.onended = function () {
        URL.revokeObjectURL(url);
        C.state.isPlaying = false;
        C.state.currentAudio = null;
        resolve();
      };

      audio.onerror = function () {
        URL.revokeObjectURL(url);
        try { audio.pause(); } catch (e) { /* ignore */ }
        C.state.isPlaying = false;
        C.state.currentAudio = null;
        reject(new Error('Audio playback error'));
      };

      C.state.isPlaying = true;
      setMode('speaking');

      try {
        audio.load();
      } catch (e) { /* ignore */ }

      audio.play().catch(function (err) {
        console.warn('[MICA] Audio play error:', err.message);
        try { URL.revokeObjectURL(url); } catch (e) { /* ignore */ }
        C.state.isPlaying = false;
        C.state.currentAudio = null;
        reject(new Error('Audio play failed: ' + err.message));
      });
    });
  }

  // ================================================================
  // MICA Structured Clinic Panels (Nurse)
  // Deterministic, role-scoped interfaces backed by
  // api/mica_clinic_tools.php. No raw SQL is executed from the client.
  // ================================================================
  function setPanelMode(active, title) {
    if (!C.dom.micaBody) return;
    C.dom.micaBody.innerHTML = '';
    if (active) {
      var header = document.createElement('div');
      header.className = 'mica-panel-header d-flex align-items-center justify-content-between mb-3';
      header.innerHTML =
        '<button type="button" class="btn btn-outline-secondary btn-sm mica-panel-back" aria-label="Back to chat"><i class="bi bi-arrow-left me-1"></i> ' + escapeHtml(title || 'MICA') + '</button>' +
        '<span class="mica-panel-loading d-none"><span class="spinner-border spinner-border-sm me-1"></span> Loading...</span>';
      C.dom.micaBody.appendChild(header);
      var container = document.createElement('div');
      container.className = 'mica-panel-content';
      C.dom.micaBody.appendChild(container);
      header.querySelector('.mica-panel-back').onclick = function () {
        C.dom.micaBody.innerHTML = '';
        if (C.state.messages.length === 0 && !C.config.initialGreetingShown) showPersonalizedGreeting();
        else if (C.state.messages.length > 0) {
          C.state.messages.forEach(function (m) { addMessage(m.role, m.content); });
        }
      };
    }
  }
  function micaPanelContainer() { return C.dom.micaBody ? C.dom.micaBody.querySelector('.mica-panel-content') : null; }
  function micaPanelLoading(show) {
    var el = C.dom.micaBody ? C.dom.micaBody.querySelector('.mica-panel-loading') : null;
    if (el) el.classList.toggle('d-none', !show);
  }
  function micaApi(action, params) {
    var url = C.config.appUrl + '/api/mica_clinic_tools.php?action=' + encodeURIComponent(action);
    if (params) Object.keys(params).forEach(function (k) { url += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(params[k]); });
    return fetch(url, { credentials: 'same-origin' }).then(function (r) {
      if (!r.ok) throw new Error('HTTP ' + r.status);
      return r.json();
    });
  }
  function micaDownload(action, data) {
    var fd = new FormData();
    fd.append('action', action);
    fd.append('csrf_token', C.config.csrfToken || $('meta[name="csrf-token"]').attr('content') || '');
    if (data) Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
    micaPanelLoading(true);
    var ext = (data && data.format) ? data.format : 'bin';
    fetch(C.config.appUrl + '/api/mica_clinic_tools.php', { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) {
        var ct = (r.headers && typeof r.headers.get === 'function') ? (r.headers.get('Content-Type') || '') : '';
        if (ct.indexOf('application/json') !== -1) return r.json().then(function (j) { throw new Error(j.message || 'Export failed.'); });
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.blob();
      })
      .then(function (blob) {
        var url = URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url;
        a.download = 'BPC_' + action + '_' + new Date().toISOString().slice(0, 10) + '.' + ext;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
      })
      .catch(function (err) {
        micaPanelLoading(false);
        showStatus('Export failed: ' + err.message);
        addMessage('ai', 'Download failed: ' + escapeHtml(err.message));
      })
      .finally(function () { micaPanelLoading(false); });
  }

  function renderStudentRecordsPanel() {
    setPanelMode(true, 'Student Records');
    var con = micaPanelContainer();
    con.innerHTML =
      '<div class="row g-2 mb-3"><div class="col-12"><div class="input-group"><span class="input-group-text"><i class="bi bi-search"></i></span><input type="search" class="form-control" id="micaSrSearch" placeholder="Search student by name or ID"></div></div></div>' +
      '<div id="micaSrResults"></div>';
    var searchIn = document.getElementById('micaSrSearch');
    var results = document.getElementById('micaSrResults');
    var timer = null;
    function load(q) {
      micaPanelLoading(true);
      results.innerHTML = '<div class="text-muted p-3">Searching authorized student records...</div>';
      micaApi('student_search', { q: q || '' }).then(function (res) {
        var data = res.data || [];
        results.innerHTML = '';
        if (!data.length) { results.innerHTML = '<div class="text-muted p-3">No matching students found.</div>'; micaPanelLoading(false); return; }
        data.forEach(function (s) {
          var card = document.createElement('div');
          card.className = 'card border-0 mb-2 shadow-sm';
          card.innerHTML =
            '<div class="card-body p-3"><div class="d-flex justify-content-between align-items-start"><div><div class="fw-bold">' + escapeHtml(s.name) + '</div><div class="small text-muted">' + escapeHtml(s.student_number) + ' &middot; ' + escapeHtml(s.section) + ' &middot; ' + escapeHtml(s.course) + '</div></div><button class="btn btn-sm btn-outline-primary mica-view-student" data-id="' + s.id + '">View Record</button></div></div>';
          results.appendChild(card);
        });
        micaPanelLoading(false);
      }).catch(function (err) {
        results.innerHTML = '<div class="text-danger p-3">Unable to load student records.</div>';
        micaPanelLoading(false);
      });
    }
    searchIn.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(function () { load(searchIn.value.trim()); }, 300); });
    load('');
    con.addEventListener('click', function (e) {
      var btn = e.target.closest('.mica-view-student');
      if (!btn) return;
      var id = btn.dataset.id;
      micaPanelLoading(true);
      micaApi('student_detail', { id: id }).then(function (res) {
        var d = res.data || {};
        var stu = d.student || {};
        var consultations = d.consultations || [];
        var visits = d.visits || [];
        var fullName = escapeHtml((stu.first_name || '') + ' ' + (stu.last_name || ''));
        var html = '<div class="mb-3"><div class="d-flex justify-content-between align-items-center mb-2"><div><h6>' + fullName + '</h6><div class="small text-muted">' + escapeHtml(stu.student_number || '') + ' &middot; ' + escapeHtml(stu.course || '') + ' ' + escapeHtml(stu.year_level || '') + ' &middot; ' + escapeHtml(stu.section || '') + '</div></div><button class="btn btn-sm btn-outline-secondary mica-back-to-list"><i class="bi bi-arrow-left me-1"></i> Back</button></div></div>' +
          '<div class="row g-3"><div class="col-md-6"><div class="card border-0 shadow-sm"><div class="card-body"><h7 class="card-title small text-uppercase text-muted">Profile</h7><table class="table table-borderless small"><tr><th style="width:40%">Student ID</th><td>' + escapeHtml(stu.student_number || '') + '</td></tr><tr><th>Course / Year</th><td>' + escapeHtml(stu.course || '') + ' ' + escapeHtml(stu.year_level || '') + '</td></tr><tr><th>Section</th><td>' + escapeHtml(stu.section || '') + '</td></tr><tr><th>Blood Type</th><td>' + escapeHtml(stu.blood_type || '') + '</td></tr><tr><th>Allergies</th><td>' + escapeHtml(stu.allergies || 'None recorded') + '</td></tr></table></div></div></div><div class="col-md-6"><div class="card border-0 shadow-sm"><div class="card-body"><h7 class="card-title small text-uppercase text-muted">Consultations (' + consultations.length + ')</h7>';
        if (!consultations.length) html += '<p class="text-muted small">No consultation records.</p>';
        consultations.forEach(function (c) { html += '<div class="small border-bottom pb-2 mb-2">' + escapeHtml(c.chief_complaint || 'Visit') + ' <span class="text-muted">' + (c.consultation_date ? escapeHtml(c.consultation_date) : '') + '</span></div>'; });
        html += '</div></div></div></div>';
        if (visits.length) {
          html += '<div class="col-12"><div class="card border-0 shadow-sm mt-3"><div class="card-body"><h7 class="card-title small text-uppercase text-muted">Visit History (' + visits.length + ')</h7>';
          visits.forEach(function (v) { html += '<div class="small border-bottom pb-2 mb-2">' + escapeHtml(v.chief_complaint || 'Visit') + ' <span class="text-muted">' + (v.visit_date ? escapeHtml(v.visit_date) : '') + '</span></div>'; });
          html += '</div></div></div>';
        }
        html += '<div class="mt-3"><button class="btn btn-sm btn-outline-primary" onclick="window.MICA.send(\'Show the medical history of student ' + fullName + '\')">Ask MICA about this student</button></div>';
        con.innerHTML = html;
        micaPanelLoading(false);
        con.querySelector('.mica-back-to-list').onclick = function () { renderStudentRecordsPanel(); };
      }).catch(function (err) {
        con.innerHTML = '<div class="text-danger p-3">Unable to load student record.</div>';
        micaPanelLoading(false);
      });
    });
  }

  function renderInventoryPanel() {
    setPanelMode(true, 'Medicine Inventory');
    var con = micaPanelContainer();
    con.innerHTML =
      '<div class="row g-2 mb-3"><div class="col-md-5"><div class="input-group"><span class="input-group-text"><i class="bi bi-search"></i></span><input type="search" class="form-control" id="micaInvSearch" placeholder="Search medicine by name"></div></div>' +
      '<div class="col-md-3"><select class="form-select form-select-sm" id="micaInvFilter"><option value="all">All</option><option value="low">Low Stock</option><option value="available">Available</option><option value="expiring">Expiring Soon</option></select></div>' +
      '<div class="col-md-4"><div class="d-flex gap-2"><button class="btn btn-success btn-sm w-100" id="micaInvExportPdf">PDF</button><button class="btn btn-success btn-sm w-100" id="micaInvExportXlsx">XLSX</button><button class="btn btn-success btn-sm w-100" id="micaInvExportCsv">CSV</button></div></div></div>' +
      '<div id="micaInvResults"></div><div id="micaInvSummary" class="mt-2 small text-muted"></div>';
    var searchIn = document.getElementById('micaInvSearch');
    var filter = document.getElementById('micaInvFilter');
    var results = document.getElementById('micaInvResults');
    var summary = document.getElementById('micaInvSummary');
    var timer = null;
    function statusBadge(m) {
      var st = m.computed_status || m.status || 'Available';
      var cls = 'bg-secondary';
      if (st === 'Available') cls = 'bg-success';
      else if (st === 'Low Stock') cls = 'bg-warning text-dark';
      else if (st === 'Out of Stock') cls = 'bg-danger';
      else if (st === 'Expiring Soon' || st === 'Expired') cls = 'bg-info text-dark';
      return '<span class="badge ' + cls + '">' + escapeHtml(st) + '</span>';
    }
    function load() {
      micaPanelLoading(true);
      results.innerHTML = '<div class="text-muted p-3">Loading medicine inventory...</div>';
      summary.innerHTML = '';
      micaApi('inventory_list', { q: searchIn.value.trim(), filter: filter.value }).then(function (res) {
        var data = res.data || [];
        results.innerHTML = '';
        if (!data.length) { results.innerHTML = '<div class="text-muted p-3">No medicines found.</div>'; micaPanelLoading(false); return; }
        var low = 0, exp = 0;
        data.forEach(function (m) {
          var st = m.computed_status || m.status || 'Available';
          if (st === 'Low Stock' || st === 'Out of Stock') low++;
          if (st === 'Expiring Soon' || st === 'Expired') exp++;
          var row = document.createElement('div');
          row.className = 'card border-0 mb-2 shadow-sm';
          row.innerHTML =
            '<div class="card-body p-3"><div class="d-flex justify-content-between align-items-start"><div><div class="fw-bold">' + escapeHtml(m.medicine_name) + '</div><div class="small text-muted">' + escapeHtml(m.category || '') + ' &middot; ' + escapeHtml(m.unit || 'pcs') + ' &middot; Batch ' + escapeHtml(m.batch_number || '—') + '</div></div><div class="text-end">' + statusBadge(m) + '<div class="small mt-1">' + (m.quantity != null ? m.quantity : '0') + ' <span class="text-muted">' + escapeHtml(m.unit || 'pcs') + '</span></div></div></div>' +
            '<div class="mt-2 small"><span class="badge bg-light text-dark me-1">Reorder: ' + (m.reorder_level != null ? m.reorder_level : '—') + '</span><span class="badge bg-light text-dark">Expires: ' + (m.expiration_date ? escapeHtml(m.expiration_date) : '—') + '</span></div></div>';
          results.appendChild(row);
        });
        summary.innerHTML = data.length + ' medicine(s) &middot; <strong class="text-danger">' + low + '</strong> low/out of stock &middot; <strong class="text-warning">' + exp + '</strong> expiring soon.';
        micaPanelLoading(false);
      }).catch(function (err) {
        results.innerHTML = '<div class="text-danger p-3">Unable to load inventory.</div>';
        micaPanelLoading(false);
      });
    }
    searchIn.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(load, 300); });
    filter.addEventListener('change', load);
    load();
    function doExport(fmt) { micaDownload('inventory_export', { from: '', to: '', format: fmt }); }
    document.getElementById('micaInvExportPdf').onclick = function () { doExport('pdf'); };
    document.getElementById('micaInvExportXlsx').onclick = function () { doExport('xlsx'); };
    document.getElementById('micaInvExportCsv').onclick = function () { doExport('csv'); };
  }

  function renderCertificatesPanel() {
    setPanelMode(true, 'Certificates');
    var con = micaPanelContainer();
    con.innerHTML =
      '<div class="row g-2 mb-3"><div class="col-12"><div class="input-group"><span class="input-group-text"><i class="bi bi-search"></i></span><input type="search" class="form-control" id="micaCertSearch" placeholder="Search certificate by number or student"></div></div></div>' +
      '<div id="micaCertResults"></div>';
    var searchIn = document.getElementById('micaCertSearch');
    var results = document.getElementById('micaCertResults');
    var timer = null;
    function load(q) {
      micaPanelLoading(true);
      results.innerHTML = '<div class="text-muted p-3">Searching certificates...</div>';
      micaApi('certificate_list', { q: q || '' }).then(function (res) {
        var data = res.data || [];
        results.innerHTML = '';
        if (!data.length) { results.innerHTML = '<div class="text-muted p-3">No certificates found.</div>'; micaPanelLoading(false); return; }
        data.forEach(function (c) {
          var row = document.createElement('div');
          row.className = 'card border-0 mb-2 shadow-sm';
          row.innerHTML =
            '<div class="card-body p-3"><div class="d-flex justify-content-between align-items-start"><div><div class="fw-bold">' + escapeHtml(c.certificate_number || '—') + '</div><div class="small text-muted">' + escapeHtml(c.student_name || '') + ' &middot; ' + escapeHtml(c.student_number || '') + '</div></div><div class="d-flex gap-1"><button class="btn btn-sm btn-outline-primary mica-view-cert" data-id="' + c.id + '">View</button><button class="btn btn-sm btn-success mica-download-cert" data-id="' + c.id + '"><i class="bi bi-download me-1"></i></button></div></div>' +
            '<div class="mt-2 small"><span class="badge bg-light text-dark me-1">' + escapeHtml(c.certificate_type || 'Medical Certificate') + '</span><span class="badge bg-light text-dark">Issued: ' + (c.date_issued ? escapeHtml(c.date_issued) : '—') + '</span></div>' +
            (c.purpose ? '<div class="mt-1 small">' + escapeHtml(c.purpose) + '</div>' : '') +
            '</div>';
          results.appendChild(row);
        });
        micaPanelLoading(false);
      }).catch(function (err) {
        results.innerHTML = '<div class="text-danger p-3">Unable to load certificates.</div>';
        micaPanelLoading(false);
      });
    }
    searchIn.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(function () { load(searchIn.value.trim()); }, 300); });
    load('');
    con.addEventListener('click', function (e) {
      var dl = e.target.closest('.mica-download-cert');
      if (dl) { window.location = C.config.appUrl + '/api/mica_clinic_tools.php?action=certificate_pdf&id=' + dl.dataset.id; return; }
      var btn = e.target.closest('.mica-view-cert');
      if (btn) { window.location = C.config.appUrl + '/api/mica_clinic_tools.php?action=certificate_pdf&id=' + btn.dataset.id; }
    });
  }

  function renderReportsPanel() {
    setPanelMode(true, 'Reports');
    var con = micaPanelContainer();
    con.innerHTML =
      '<div class="row g-2 mb-3"><div class="col-md-3"><select class="form-select form-select-sm" id="micaRepType"><option value="daily_visits">Daily Clinic Visits</option><option value="clinic_activity">Clinic Activity</option><option value="emergencies">Emergency Incidents</option><option value="inventory">Medicine Inventory</option></select></div>' +
      '<div class="col-md-3"><input type="date" class="form-control form-control-sm" id="micaRepFrom"></div>' +
      '<div class="col-md-3"><input type="date" class="form-control form-control-sm" id="micaRepTo"></div>' +
      '<div class="col-md-3"><button class="btn btn-primary btn-sm w-100" id="micaRepGenerate">Generate</button></div></div>' +
      '<div id="micaRepResults"></div>';
    var fromIn = document.getElementById('micaRepFrom');
    var toIn = document.getElementById('micaRepTo');
    var today = new Date().toISOString().slice(0, 10);
    var monthStart = new Date(); monthStart.setDate(1);
    fromIn.value = monthStart.toISOString().slice(0, 10);
    toIn.value = today;
    var results = document.getElementById('micaRepResults');
    function doGenerate() {
      micaPanelLoading(true);
      results.innerHTML = '<div class="text-muted p-3">Generating report...</div>';
      micaApi('report_preview', { type: document.getElementById('micaRepType').value, from: fromIn.value, to: toIn.value }).then(function (res) {
        var report = res.data || {};
        var rows = report.rows || [];
        var html = '<div class="mb-3"><h6 class="fw-bold">' + escapeHtml(report.title || 'Clinic Report') + '</h6><div class="small text-muted">Period: ' + (report.from || '') + ' to ' + (report.to || '') + ' &middot; Generated: ' + new Date().toLocaleString() + ' &middot; Records: ' + rows.length + '</div></div>';
        if (rows.length) {
          html += '<div class="table-responsive"><table class="table table-bordered table-sm align-middle"><thead><tr>';
          Object.keys(rows[0]).forEach(function (h) { html += '<th>' + escapeHtml(h) + '</th>'; });
          html += '</tr></thead><tbody>';
          rows.forEach(function (row) {
            html += '<tr>';
            Object.values(row).forEach(function (v) { html += '<td>' + escapeHtml(v == null ? '' : v) + '</td>'; });
            html += '</tr>';
          });
          html += '</tbody></table></div>';
        } else {
          html += '<div class="text-muted p-3">No records found for this period.</div>';
        }
        html += '<div class="mt-3 d-flex gap-2"><button class="btn btn-outline-primary btn-sm mica-rep-export" data-fmt="pdf">PDF</button><button class="btn btn-outline-primary btn-sm mica-rep-export" data-fmt="xlsx">XLSX</button><button class="btn btn-outline-primary btn-sm mica-rep-export" data-fmt="csv">CSV</button></div>';
        results.innerHTML = html;
        micaPanelLoading(false);
      }).catch(function (err) {
        results.innerHTML = '<div class="text-danger p-3">Unable to generate report.</div>';
        micaPanelLoading(false);
      });
    }
    document.getElementById('micaRepGenerate').onclick = doGenerate;
    con.addEventListener('click', function (e) {
      var btn = e.target.closest('.mica-rep-export');
      if (!btn) return;
      micaDownload('report_export', { type: document.getElementById('micaRepType').value, from: fromIn.value, to: toIn.value, format: btn.dataset.fmt });
    });
    doGenerate();
  }
})();




