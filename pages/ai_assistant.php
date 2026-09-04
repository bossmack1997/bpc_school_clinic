<?php
/**
 * pages/ai_assistant.php
 * ------------------------------------------------------------------
 * SINGLE role-aware MICA page for ALL roles (admin / nurse / student).
 * Replaces pages/student/ai_assistant.php and pages/nurse/ai_assistant.php.
 *
 * The floating MICA widget is available on every authenticated page via
 * components/footer.php → components/mica.php + assets/js/mica.js.
 * This page is an optional full-screen showcase that reuses the SAME
 * backend (api/ai_assistant.php) and the SAME global MICA instance.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../config/config.php';
require_role(['admin', 'nurse', 'student', 'adviser']);
$db   = getDB();
$user = current_user();
$role = $user['role'] ?? 'student';

$pageTitle = 'MICA - Medical Intelligent Clinic Assistant';

// Per-role quick actions
$quickActions = match ($role) {
    'nurse' => [
        ['📅', 'Today\'s Appointments', 'Show today\'s appointments', 'text-primary'],
        ['⚠️', 'Low Stock Alerts', 'Low stock medicines', 'text-danger'],
        ['📄', 'Patient Records', 'Recent patient records', 'text-info'],
        ['🩺', 'Health Data', 'Health monitoring data', 'text-danger'],
        ['⏳', 'Pending Approvals', 'Pending appointments', 'text-warning'],
        ['📢', 'Announcements', 'Clinic announcements', 'text-warning'],
        ['💊', 'Inventory', 'Medicine inventory', 'text-success'],
        ['🎤', 'Talk to MICA', '__open__', 'text-primary'],
    ],
    'admin' => [
        ['📊', 'Dashboard Stats', 'Show dashboard analytics', 'text-primary'],
        ['📅', 'Appointments', 'Show all appointments', 'text-primary'],
        ['📄', 'Medical Records', 'Show medical records', 'text-info'],
        ['💊', 'Medicine Inventory', 'Medicine inventory status', 'text-success'],
        ['📢', 'Announcements', 'Latest clinic announcements', 'text-warning'],
        ['👥', 'Student Overview', 'Show student statistics', 'text-info'],
        ['🚨', 'SOS Status', 'Show active SOS emergencies', 'text-danger'],
        ['🎤', 'Talk to MICA', '__open__', 'text-primary'],
    ],
    'adviser' => [
        ['👥', 'My Advisory Class', 'Show my advisory class', 'text-primary'],
        ['🚨', 'Emergency Reports', 'Show emergency reports', 'text-danger'],
        ['📢', 'Announcements', 'Latest clinic announcements', 'text-warning'],
        ['📋', 'My Consultations', 'Show my clinic consultations', 'text-info'],
        ['🎤', 'Talk to MICA', '__open__', 'text-primary'],
    ],
    default => [
        ['📅', 'Book Appointment', 'Book an appointment', 'text-primary'],
        ['❤️', 'Health Monitoring', 'Check my health monitoring data', 'text-danger'],
        ['📄', 'Medical Records', 'Show my medical records', 'text-info'],
        ['💊', 'Medicine Availability', 'What medicines are available?', 'text-success'],
        ['📢', 'Announcements', 'Latest clinic announcements', 'text-warning'],
        ['💉', 'Vaccinations', 'What vaccines do I have?', 'text-purple'],
        ['📊', 'BMI', 'What is my BMI?', 'text-secondary'],
        ['🎤', 'Talk to MICA', '__open__', 'text-primary'],
    ],
};
?>
<!DOCTYPE html>
<html lang="en">
<head><?php include __DIR__ . '/../includes/head.php'; ?></head>
<body>
<div class="app-wrapper">
  <?php include __DIR__ . '/../components/sidebar.php'; ?>
  <div class="main-content">
    <?php include __DIR__ . '/../components/navbar.php'; ?>
    <div class="content-area page-enter">

      <!-- Hero banner -->
      <div class="alert alert-success d-flex align-items-center justify-content-between flex-wrap gap-2 rounded-4 shadow-sm mb-4 py-3 px-4" data-aos="fade-down">
        <div class="d-flex align-items-center gap-2">
          <i class="bi bi-mic fs-5"></i>
          <span class="small">
            <strong>🤖 MICA</strong> — Your Medical Intelligent Clinic Assistant is available on every page.
            Click the <strong>🤖 MICA</strong> floating button (bottom-right) to chat anytime!
          </span>
        </div>
        <div class="d-flex align-items-center gap-2">
          <button class="btn btn-primary btn-sm" onclick="MICA.open()">
            <i class="bi bi-chat-dots-fill me-1"></i> Open MICA
          </button>
          <button class="btn btn-outline-primary btn-sm" onclick="MICA.testVoice()">
            <i class="bi bi-volume-up-fill me-1"></i> Test Voice
          </button>
        </div>
      </div>

      <!-- Quick Actions Grid -->
      <h6 class="section-title mb-3" data-aos="fade-up"><i class="bi bi-lightning-fill"></i> Quick Actions</h6>
      <div class="row g-3 mb-4" data-aos="fade-up" data-aos-delay="50">
        <?php foreach ($quickActions as $qa): ?>
        <div class="col-6 col-md-4 col-lg-3">
          <div class="card border-0 shadow-sm h-100 text-center p-3 quick-action-card"
               onclick="<?= $qa[2] === '__open__' ? 'MICA.open()' : "MICA.send('" . addslashes($qa[2]) . "')" ?>">
            <div class="qa-icon mx-auto mb-2"><span class="fs-3 <?= $qa[3] ?>"><?= $qa[0] ?></span></div>
            <h6 class="mb-1 small fw-semibold"><?= e($qa[1]) ?></h6>
          </div>
        </div>
        <?php endforeach; ?>
      </div>

      <!-- MICA Info Card -->
      <div class="card border-0 rounded-4 shadow-sm overflow-hidden" data-aos="fade-up" data-aos-delay="150">
        <div class="row g-0">
          <div class="col-lg-4 d-flex align-items-center justify-content-center p-4" style="background: linear-gradient(135deg, var(--mica-primary,#0B6B3A) 0%, #22C55E 100%);">
            <div class="text-center text-white py-4">
              <div class="display-4 mb-3">🤖</div>
              <h4 class="fw-bold text-white mb-1">MICA</h4>
              <p class="mb-0 small opacity-75">Medical Intelligent<br>Clinic Assistant</p>
            </div>
          </div>
          <div class="col-lg-8 p-4">
            <h5 class="fw-semibold mb-3">How can MICA help you?</h5>
            <div class="row g-2">
              <div class="col-6"><div class="d-flex align-items-center gap-2 p-2 rounded-3 bg-light"><span>💬</span><span class="small">Chat naturally</span></div></div>
              <div class="col-6"><div class="d-flex align-items-center gap-2 p-2 rounded-3 bg-light"><span>🎤</span><span class="small">Use voice</span></div></div>
              <div class="col-6"><div class="d-flex align-items-center gap-2 p-2 rounded-3 bg-light"><span>🏥</span><span class="small">Clinic info</span></div></div>
              <div class="col-6"><div class="d-flex align-items-center gap-2 p-2 rounded-3 bg-light"><span>🚨</span><span class="small">SOS Emergency</span></div></div>
              <div class="col-6"><div class="d-flex align-items-center gap-2 p-2 rounded-3 bg-light"><span>📋</span><span class="small">Health records</span></div></div>
              <div class="col-6"><div class="d-flex align-items-center gap-2 p-2 rounded-3 bg-light"><span>📅</span><span class="small">Appointments</span></div></div>
            </div>
            <div class="mt-3">
              <button class="btn btn-primary w-100" onclick="MICA.open()">
                <i class="bi bi-chat-dots-fill me-1"></i> Chat with MICA
              </button>
            </div>
          </div>
        </div>
      </div>

    </div>
  </div>
</div>

<?php include __DIR__ . '/../components/footer.php'; ?>

<style>
.quick-action-card { cursor: pointer; transition: all 0.3s ease; border-radius: 14px !important; }
.quick-action-card:hover { transform: translateY(-4px); box-shadow: 0 8px 24px rgba(0,0,0,0.1) !important; border-color: var(--primary) !important; }
.quick-action-card .qa-icon { width: 48px; height: 48px; border-radius: 14px; background: var(--primary-light); display: flex; align-items: center; justify-content: center; transition: all 0.3s; }
.quick-action-card:hover .qa-icon { transform: scale(1.1); }
</style>
</body>
</html>

