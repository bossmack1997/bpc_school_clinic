<?php
/**
 * pages/queue_display.php
 * =====================================================================
 * Standalone Live Now Serving Display for Waiting Room TV / Monitors
 * AI-Enhanced School Clinic Management System for Bulacan Polytechnic College Healthcare Service Improvement
 * =====================================================================
 */
require_once __DIR__ . '/../config/config.php';
$db = getDB();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Now Serving — BPC School Clinic</title>
  <link rel="icon" type="image/png" href="<?= APP_URL ?>/assets/images/bpc_logo.png">
<!-- Local assets only: this display runs offline on the clinic TV, so a CDN
     dependency would leave it unstyled without internet. -->
<link rel="stylesheet" href="<?= asset_url('css/bootstrap.min.css') ?>">
<link rel="stylesheet" href="<?= asset_url('css/bootstrap-icons.min.css') ?>">
<link rel="stylesheet" href="<?= asset_url('css/design-system.css') ?>">
<style>
    /* This is a wall-mounted TV display rather than an operator console, so it
       keeps a dark canvas for legibility at distance. It still uses the shared
       brand green and type family so it reads as the same product. */
    :root {
      --primary: #0E7A3E;
      --primary-dark: #095C2E;
      --primary-light: #EAF6EF;
      --accent: #22C55E;
      --bg-dark: #0F172A;
      --card-bg: #1E293B;
      --card-border: #334155;
      --text-main: #F8FAFC;
      --text-muted: #94A3B8;
      --gold: #F59E0B;
      --red: #EF4444;
      --blue: #3B82F6;
    }

    body.queue-display {
      font-family: var(--font-sans);
    }

    * {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
    }

    body.queue-display {
      font-family: var(--font-sans);
      background: linear-gradient(135deg, #091E14 0%, #0F172A 100%);
      color: var(--text-main);
      min-height: 100vh;
      display: flex;
      flex-direction: column;
      overflow-x: hidden;
      user-select: none;
    }

    /* Top Navigation / Brand Header */
    .display-header {
      background: rgba(11, 107, 58, 0.95);
      backdrop-filter: blur(10px);
      border-bottom: 2px solid rgba(255, 255, 255, 0.15);
      padding: 1rem 2rem;
      display: flex;
      justify-content: space-between;
      align-items: center;
      box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
    }

    .brand-section {
      display: flex;
      align-items: center;
      gap: 1.25rem;
    }

    .brand-logo {
      width: 56px;
      height: 56px;
      background: #FFFFFF;
      border-radius: 14px;
      padding: 4px;
      display: flex;
      align-items: center;
      justify-content: center;
      box-shadow: 0 4px 12px rgba(0,0,0,0.2);
    }

    .brand-logo img {
      max-width: 100%;
      max-height: 100%;
      object-fit: contain;
    }

    .brand-title {
      font-size: 1.35rem;
      font-weight: 700;
      color: #FFFFFF;
      line-height: 1.2;
      letter-spacing: -0.02em;
    }

    .brand-subtitle {
      font-size: 0.82rem;
      color: rgba(255, 255, 255, 0.85);
      font-weight: 400;
      letter-spacing: 0.01em;
    }

    .clock-section {
      text-align: right;
      display: flex;
      align-items: center;
      gap: 1.5rem;
    }

    .live-time {
      font-size: 1.85rem;
      font-weight: 800;
      color: #FFFFFF;
      font-variant-numeric: tabular-nums;
      line-height: 1;
      text-shadow: 0 2px 8px rgba(0,0,0,0.3);
    }

    .live-date {
      font-size: 0.85rem;
      color: rgba(255, 255, 255, 0.85);
      font-weight: 500;
      margin-top: 2px;
    }

    .header-controls {
      display: flex;
      gap: 0.5rem;
    }

    .ctrl-btn {
      background: rgba(255, 255, 255, 0.15);
      border: 1px solid rgba(255, 255, 255, 0.25);
      color: #FFFFFF;
      border-radius: 10px;
      padding: 0.45rem 0.85rem;
      font-size: 0.85rem;
      font-weight: 600;
      cursor: pointer;
      transition: all 0.2s ease;
      display: inline-flex;
      align-items: center;
      gap: 0.35rem;
      text-decoration: none;
    }

    .ctrl-btn:hover {
      background: rgba(255, 255, 255, 0.25);
      color: #FFFFFF;
      transform: translateY(-1px);
    }

    /* Main Grid */
    .display-body {
      flex: 1;
      padding: 1.5rem 2rem;
      display: grid;
      grid-template-columns: 1.15fr 0.85fr;
      gap: 1.75rem;
      align-items: stretch;
    }

    /* Hero Card - Now Serving */
    .hero-card {
      background: linear-gradient(165deg, rgba(30, 41, 59, 0.95) 0%, rgba(15, 23, 42, 0.95) 100%);
      border: 2px solid rgba(34, 197, 94, 0.35);
      border-radius: 24px;
      padding: 2.25rem 2rem;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      box-shadow: 0 20px 45px rgba(0, 0, 0, 0.4), 0 0 35px rgba(11, 107, 58, 0.2);
      position: relative;
      overflow: hidden;
    }

    .hero-card::before {
      content: '';
      position: absolute;
      top: -50%;
      left: -50%;
      width: 200%;
      height: 200%;
      background: radial-gradient(circle, rgba(34, 197, 94, 0.08) 0%, transparent 60%);
      pointer-events: none;
    }

    .hero-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      z-index: 1;
    }

    .hero-badge {
      display: inline-flex;
      align-items: center;
      gap: 0.5rem;
      background: rgba(34, 197, 94, 0.15);
      border: 1px solid rgba(34, 197, 94, 0.4);
      color: #4ADE80;
      padding: 0.5rem 1.25rem;
      border-radius: 30px;
      font-size: 1.05rem;
      font-weight: 700;
      letter-spacing: 0.05em;
      text-transform: uppercase;
    }

    .hero-badge .pulse-dot {
      width: 10px;
      height: 10px;
      background: #4ADE80;
      border-radius: 50%;
      box-shadow: 0 0 10px #4ADE80;
      animation: pulse 1.5s infinite;
    }

    @keyframes pulse {
      0% { transform: scale(0.95); opacity: 0.8; box-shadow: 0 0 0 0 rgba(74, 222, 128, 0.7); }
      70% { transform: scale(1.15); opacity: 1; box-shadow: 0 0 0 10px rgba(74, 222, 128, 0); }
      100% { transform: scale(0.95); opacity: 0.8; box-shadow: 0 0 0 0 rgba(74, 222, 128, 0); }
    }

    .station-badge {
      background: rgba(59, 130, 246, 0.15);
      border: 1px solid rgba(59, 130, 246, 0.4);
      color: #60A5FA;
      padding: 0.5rem 1.15rem;
      border-radius: 30px;
      font-size: 0.95rem;
      font-weight: 600;
    }

    .hero-number-wrapper {
      text-align: center;
      margin: 1.5rem 0;
      z-index: 1;
    }

    .hero-number {
      font-size: 7.5rem;
      font-weight: 900;
      color: #FFFFFF;
      letter-spacing: 0.02em;
      line-height: 1;
      text-shadow: 0 4px 30px rgba(34, 197, 94, 0.4);
      transition: all 0.3s ease;
      font-variant-numeric: tabular-nums;
    }

    .hero-subinfo {
      display: flex;
      justify-content: center;
      gap: 1rem;
      margin-top: 1rem;
    }

    .patient-type-pill {
      background: rgba(255, 255, 255, 0.1);
      border: 1px solid rgba(255, 255, 255, 0.2);
      color: #F8FAFC;
      padding: 0.4rem 1.25rem;
      border-radius: 20px;
      font-size: 1rem;
      font-weight: 600;
      text-transform: capitalize;
    }

    .priority-pill {
      padding: 0.4rem 1.25rem;
      border-radius: 20px;
      font-size: 1rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.03em;
    }

    .priority-pill.emergency {
      background: rgba(239, 68, 68, 0.2);
      border: 1px solid #EF4444;
      color: #FCA5A5;
      animation: flash-red 1s infinite alternate;
    }

    .priority-pill.urgent {
      background: rgba(245, 158, 11, 0.2);
      border: 1px solid #F59E0B;
      color: #FCD34D;
    }

    .priority-pill.normal {
      background: rgba(34, 197, 94, 0.15);
      border: 1px solid #22C55E;
      color: #86EFAC;
    }

    @keyframes flash-red {
      from { background: rgba(239, 68, 68, 0.15); }
      to { background: rgba(239, 68, 68, 0.4); }
    }

    .hero-footer {
      background: rgba(0, 0, 0, 0.25);
      border-radius: 16px;
      padding: 1rem 1.5rem;
      display: flex;
      justify-content: space-between;
      align-items: center;
      z-index: 1;
      border: 1px solid rgba(255, 255, 255, 0.05);
    }

    .hero-instruction {
      font-size: 1.05rem;
      font-weight: 600;
      color: #F1F5F9;
      display: flex;
      align-items: center;
      gap: 0.6rem;
    }

    .hero-instruction i {
      color: #4ADE80;
      font-size: 1.25rem;
    }

    /* Flash highlight when new ticket called */
    .flash-call {
      animation: callPulse 2.5s ease-out;
    }

    @keyframes callPulse {
      0% { transform: scale(1); box-shadow: 0 0 60px rgba(34, 197, 94, 0.9); }
      50% { transform: scale(1.02); box-shadow: 0 0 90px rgba(34, 197, 94, 1); }
      100% { transform: scale(1); box-shadow: 0 20px 45px rgba(0, 0, 0, 0.4); }
    }

    /* Right Column - Next in line & metrics */
    .side-panel {
      display: flex;
      flex-direction: column;
      gap: 1.25rem;
    }

    .side-card {
      background: var(--card-bg);
      border: 1px solid var(--card-border);
      border-radius: 20px;
      padding: 1.25rem 1.5rem;
      box-shadow: 0 10px 25px rgba(0, 0, 0, 0.25);
    }

    .side-card-title {
      font-size: 1rem;
      font-weight: 700;
      color: #CBD5E1;
      text-transform: uppercase;
      letter-spacing: 0.05em;
      margin-bottom: 1rem;
      display: flex;
      align-items: center;
      gap: 0.5rem;
    }

    .side-card-title i {
      color: #38BDF8;
    }

    /* Next in line grid */
    .next-grid {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 0.85rem;
    }

    .next-ticket {
      background: rgba(15, 23, 42, 0.6);
      border: 1px solid rgba(255, 255, 255, 0.08);
      border-radius: 14px;
      padding: 0.85rem 0.5rem;
      text-align: center;
      transition: transform 0.2s ease;
    }

    .next-ticket:hover {
      transform: translateY(-2px);
      border-color: rgba(34, 197, 94, 0.4);
    }

    .next-number {
      font-size: 1.65rem;
      font-weight: 800;
      color: #F8FAFC;
      line-height: 1.1;
    }

    .next-label {
      font-size: 0.72rem;
      color: #94A3B8;
      font-weight: 600;
      margin-top: 0.25rem;
      text-transform: uppercase;
    }

    /* Stats row */
    .metrics-grid {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 0.85rem;
    }

    .metric-box {
      background: rgba(15, 23, 42, 0.7);
      border: 1px solid rgba(255, 255, 255, 0.06);
      border-radius: 14px;
      padding: 0.85rem;
      text-align: center;
    }

    .metric-val {
      font-size: 1.6rem;
      font-weight: 800;
      line-height: 1;
      color: #FFFFFF;
    }

    .metric-val.green { color: #4ADE80; }
    .metric-val.blue { color: #60A5FA; }
    .metric-val.gold { color: #FBBF24; }

    .metric-lbl {
      font-size: 0.72rem;
      color: #94A3B8;
      font-weight: 600;
      text-transform: uppercase;
      margin-top: 0.35rem;
    }

    /* Recent completed tags */
    .recent-list {
      display: flex;
      flex-wrap: wrap;
      gap: 0.5rem;
    }

    .recent-tag {
      background: rgba(255, 255, 255, 0.06);
      border: 1px solid rgba(255, 255, 255, 0.1);
      border-radius: 8px;
      padding: 0.35rem 0.75rem;
      font-size: 0.85rem;
      font-weight: 600;
      color: #94A3B8;
    }

    /* Bottom Ticker */
    .display-footer {
      background: #0B6B3A;
      color: #FFFFFF;
      padding: 0.65rem 2rem;
      display: flex;
      align-items: center;
      gap: 1rem;
      font-size: 0.95rem;
      font-weight: 500;
      box-shadow: 0 -4px 20px rgba(0, 0, 0, 0.2);
    }

    .ticker-label {
      background: rgba(0, 0, 0, 0.25);
      padding: 0.2rem 0.75rem;
      border-radius: 6px;
      font-weight: 700;
      font-size: 0.8rem;
      text-transform: uppercase;
      letter-spacing: 0.05em;
      white-space: nowrap;
    }

    .ticker-content {
      flex: 1;
      overflow: hidden;
      white-space: nowrap;
    }

    .ticker-text {
      display: inline-block;
      padding-left: 100%;
      animation: ticker 25s linear infinite;
    }

    @keyframes ticker {
      0% { transform: translate3d(0, 0, 0); }
      100% { transform: translate3d(-100%, 0, 0); }
    }

    /* Audio Enable Overlay Modal (for browser gesture unlock) */
    #audioPromptOverlay {
      position: fixed;
      top: 0;
      left: 0;
      right: 0;
      bottom: 0;
      background: rgba(15, 23, 42, 0.85);
      backdrop-filter: blur(8px);
      z-index: 9999;
      display: flex;
      align-items: center;
      justify-content: center;
      cursor: pointer;
    }

    .audio-card {
      background: #1E293B;
      border: 2px solid #22C55E;
      border-radius: 24px;
      padding: 2.5rem;
      text-align: center;
      max-width: 480px;
      box-shadow: 0 25px 50px rgba(0,0,0,0.5);
    }

    @media (max-width: 992px) {
      .display-body {
        grid-template-columns: 1fr;
      }
      .hero-number {
        font-size: 5rem;
      }
    }
  </style>
  <link rel="stylesheet" href="<?= asset_url('css/theme.css') ?>">
</head>
<body class="queue-display">

  <!-- Audio Unlock Overlay -->
  <div id="audioPromptOverlay">
    <div class="audio-card">
      <div style="font-size: 3.5rem; color: #4ADE80; margin-bottom: 1rem;">
        <i class="bi bi-volume-up-fill"></i>
      </div>
      <h3 style="font-weight: 700; color: #FFFFFF; margin-bottom: 0.75rem;">Enable Live Audio Chime</h3>
      <p style="color: #94A3B8; font-size: 0.95rem; margin-bottom: 1.75rem;">
        Click anywhere to start the queue display with automated audio announcements when tickets are called.
      </p>
      <button class="btn btn-success btn-lg px-4 py-2" style="border-radius: 12px; font-weight: 700;">
        <i class="bi bi-play-circle-fill me-1"></i> Start Live Display
      </button>
    </div>
  </div>

  <!-- Header -->
  <header class="display-header">
    <div class="brand-section">
      <div class="brand-logo">
        <img src="<?= APP_URL ?>/assets/images/bpc_logo.png" alt="BPC Logo">
      </div>
      <div>
        <h1 class="brand-title">BPC School Clinic — Now Serving</h1>
        <div class="brand-subtitle">AI-Enhanced School Clinic Management System for Bulacan Polytechnic College Healthcare Service Improvement</div>
      </div>
    </div>

    <div class="clock-section">
      <div>
        <div class="live-time" id="liveTime">--:--:-- --</div>
        <div class="live-date" id="liveDate">Loading date...</div>
      </div>
      <div class="header-controls">
        <button class="ctrl-btn" id="soundToggleBtn" onclick="toggleSound()">
          <i class="bi bi-volume-up-fill" id="soundIcon"></i> <span id="soundText">Sound: ON</span>
        </button>
        <button class="ctrl-btn" onclick="toggleFullscreen()">
          <i class="bi bi-arrows-fullscreen"></i> Fullscreen
        </button>
        <a href="<?= APP_URL ?>/pages/nurse/queue.php" class="ctrl-btn">
          <i class="bi bi-box-arrow-in-right"></i> Nurse Desk
        </a>
      </div>
    </div>
  </header>

  <!-- Body -->
  <main class="display-body">
    <!-- Left Hero: Now Serving -->
    <div class="hero-card" id="heroCard">
      <div class="hero-header">
        <div class="hero-badge">
          <span class="pulse-dot"></span> NOW SERVING
        </div>
        <div class="station-badge">
          <i class="bi bi-hospital me-1"></i> Station 1 (Consultation Room)
        </div>
      </div>

      <div class="hero-number-wrapper">
        <div class="hero-number" id="nowServingNumber">---</div>
        <div class="hero-subinfo" id="nowServingSub">
          <span class="patient-type-pill" id="patientTypePill">Please Wait</span>
          <span class="priority-pill normal" id="priorityPill">Regular</span>
        </div>
      </div>

      <div class="hero-footer">
        <div class="hero-instruction">
          <i class="bi bi-arrow-right-circle-fill"></i>
          <span id="heroInstructionText">Please proceed to the Nurse Station when your number is displayed.</span>
        </div>
        <div style="font-size: 0.85rem; color: #94A3B8;">
          <span id="elapsedTimer"></span>
        </div>
      </div>
    </div>

    <!-- Right Column -->
    <div class="side-panel">
      <!-- Next in line -->
      <div class="side-card">
        <div class="side-card-title">
          <i class="bi bi-person-lines-fill"></i> Next In Line
        </div>
        <div class="next-grid" id="nextInLineGrid">
          <div class="next-ticket"><div class="next-number">---</div><div class="next-label">Waiting</div></div>
          <div class="next-ticket"><div class="next-number">---</div><div class="next-label">Waiting</div></div>
          <div class="next-ticket"><div class="next-number">---</div><div class="next-label">Waiting</div></div>
        </div>
      </div>

      <!-- Queue Metrics -->
      <div class="side-card">
        <div class="side-card-title">
          <i class="bi bi-speedometer2"></i> Clinic Queue Status
        </div>
        <div class="metrics-grid">
          <div class="metric-box">
            <div class="metric-val green" id="metricWaiting">0</div>
            <div class="metric-lbl">Waiting</div>
          </div>
          <div class="metric-box">
            <div class="metric-val blue" id="metricWaitTime">0m</div>
            <div class="metric-lbl">Est. Wait</div>
          </div>
          <div class="metric-box">
            <div class="metric-val gold" id="metricServed">0</div>
            <div class="metric-lbl">Served Today</div>
          </div>
        </div>
      </div>

      <!-- Recently Completed -->
      <div class="side-card">
        <div class="side-card-title">
          <i class="bi bi-check-circle-fill" style="color: #22C55E;"></i> Recently Served
        </div>
        <div class="recent-list" id="recentCompletedList">
          <span class="recent-tag">None yet</span>
        </div>
      </div>
    </div>
  </main>

  <!-- Footer Ticker -->
  <footer class="display-footer">
    <div class="ticker-label"><i class="bi bi-megaphone-fill me-1"></i> Notice</div>
    <div class="ticker-content">
      <div class="ticker-text" id="tickerText">
        Welcome to Bulacan Polytechnic College School Clinic. • Please prepare your Student ID or QR Pass when called. • Emergency cases are attended with top priority. • Consultations and first aid medicines are free for all students and faculty.
      </div>
    </div>
  </footer>

  <script>
    // Sound & Synthesizer Engine (Web Audio API)
    let audioCtx = null;
    let soundEnabled = true;
    let lastCalledTicketId = null;
    let lastCalledTime = null;

    function initAudio() {
      if (!audioCtx) {
        audioCtx = new (window.AudioContext || window.webkitAudioContext)();
      }
      if (audioCtx.state === 'suspended') {
        audioCtx.resume();
      }
    }

    // Play a gentle 3-tone hospital chime (C5 -> E5 -> G5)
    function playChime() {
      if (!soundEnabled) return;
      try {
        initAudio();
        const now = audioCtx.currentTime;
        const notes = [523.25, 659.25, 783.99]; // C5, E5, G5
        notes.forEach((freq, idx) => {
          const osc = audioCtx.createOscillator();
          const gain = audioCtx.createGain();

          osc.type = 'sine';
          osc.frequency.setValueAtTime(freq, now + idx * 0.22);

          gain.gain.setValueAtTime(0.001, now + idx * 0.22);
          gain.gain.exponentialRampToValueAtTime(0.3, now + idx * 0.22 + 0.04);
          gain.gain.exponentialRampToValueAtTime(0.001, now + idx * 0.22 + 0.65);

          osc.connect(gain);
          gain.connect(audioCtx.destination);

          osc.start(now + idx * 0.22);
          osc.stop(now + idx * 0.22 + 0.7);
        });
      } catch (err) {
        console.warn('Audio playback error:', err);
      }
    }

    function toggleSound() {
      soundEnabled = !soundEnabled;
      const icon = document.getElementById('soundIcon');
      const text = document.getElementById('soundText');
      if (soundEnabled) {
        initAudio();
        icon.className = 'bi bi-volume-up-fill';
        text.innerText = 'Sound: ON';
        playChime();
      } else {
        icon.className = 'bi bi-volume-mute-fill';
        text.innerText = 'Sound: OFF';
      }
    }

    // Fullscreen toggle
    function toggleFullscreen() {
      if (!document.fullscreenElement) {
        document.documentElement.requestFullscreen().catch(() => {});
      } else {
        if (document.exitFullscreen) document.exitFullscreen();
      }
    }

    // Clock
    function updateClock() {
      const now = new Date();
      const timeStr = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true });
      const dateStr = now.toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
      document.getElementById('liveTime').innerText = timeStr;
      document.getElementById('liveDate').innerText = dateStr;
    }
    setInterval(updateClock, 1000);
    updateClock();

    // Data Polling with in-flight lock
    let isDisplayFetching = false;
    async function fetchQueueStatus() {
      if (isDisplayFetching) return;
      isDisplayFetching = true;
      try {
        const res = await fetch('<?= APP_URL ?>/api/queue.php?action=public_display');
        const json = await res.json();
        if (!json.success || !json.data) return;

        const d = json.data;
        renderNowServing(d.now_serving);
        renderNextInLine(d.next_in_line);
        renderRecentCompleted(d.recent_completed);

        document.getElementById('metricWaiting').innerText = d.total_waiting ?? 0;
        document.getElementById('metricWaitTime').innerText = (d.estimated_wait_min ?? 0) + 'm';
        document.getElementById('metricServed').innerText = d.total_served ?? 0;
      } catch (err) {
        console.error('Queue poll error:', err);
      } finally {
        isDisplayFetching = false;
      }
    }

    function renderNowServing(ticket) {
      const numEl = document.getElementById('nowServingNumber');
      const typePill = document.getElementById('patientTypePill');
      const prioPill = document.getElementById('priorityPill');
      const heroCard = document.getElementById('heroCard');
      const instr = document.getElementById('heroInstructionText');

      if (!ticket) {
        numEl.innerText = '---';
        typePill.innerText = 'Waiting for Patient';
        prioPill.className = 'priority-pill normal';
        prioPill.innerText = 'STANDBY';
        instr.innerText = 'Clinic is open. Please wait for your number to be called.';
        lastCalledTicketId = null;
        lastCalledTime = null;
        return;
      }

      const isNewCall = (lastCalledTicketId !== ticket.id) || (lastCalledTime !== ticket.called_at);
      if (isNewCall) {
        lastCalledTicketId = ticket.id;
        lastCalledTime = ticket.called_at;

        // Flash animation & chime
        heroCard.classList.remove('flash-call');
        void heroCard.offsetWidth; // trigger reflow
        heroCard.classList.add('flash-call');
        playChime();
      }

      numEl.innerText = ticket.queue_number;
      typePill.innerText = (ticket.patient_type === 'teacher' ? 'Faculty / Staff' : 'Student') + ' Patient';

      const prio = ticket.priority || 'normal';
      prioPill.className = 'priority-pill ' + prio;
      prioPill.innerText = prio.toUpperCase();

      if (ticket.status === 'called') {
        instr.innerText = 'Now Calling Ticket ' + ticket.queue_number + '! Please enter the consultation room.';
      } else {
        instr.innerText = 'Consultation in progress. Next patients please stay nearby.';
      }
    }

    function renderNextInLine(items) {
      const grid = document.getElementById('nextInLineGrid');
      if (!items || items.length === 0) {
        grid.innerHTML = `
          <div class="next-ticket"><div class="next-number">---</div><div class="next-label">No queue</div></div>
          <div class="next-ticket"><div class="next-number">---</div><div class="next-label">Standby</div></div>
          <div class="next-ticket"><div class="next-number">---</div><div class="next-label">Standby</div></div>
        `;
        return;
      }

      let html = '';
      items.slice(0, 6).forEach((item) => {
        const pBadge = item.priority === 'emergency' ? 'text-danger' : (item.priority === 'urgent' ? 'text-warning' : 'text-success');
        html += `
          <div class="next-ticket">
            <div class="next-number ${pBadge}">${item.queue_number}</div>
            <div class="next-label">${item.patient_type === 'teacher' ? 'Staff' : 'Student'}</div>
          </div>
        `;
      });
      grid.innerHTML = html;
    }

    function renderRecentCompleted(items) {
      const list = document.getElementById('recentCompletedList');
      if (!items || items.length === 0) {
        list.innerHTML = '<span class="recent-tag">No completed visits yet today</span>';
        return;
      }
      let html = '';
      items.forEach((item) => {
        html += `<span class="recent-tag"><i class="bi bi-check2 text-success me-1"></i> ${item.queue_number}</span>`;
      });
      list.innerHTML = html;
    }

    // Audio Unlock overlay click
    document.getElementById('audioPromptOverlay').addEventListener('click', function() {
      this.style.display = 'none';
      initAudio();
      playChime();
    });

    // Start Polling every 3.5 seconds
    fetchQueueStatus();
    setInterval(fetchQueueStatus, 3500);
  </script>
</body>
</html>

