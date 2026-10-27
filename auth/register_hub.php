<?php
/**
 * auth/register_hub.php
 * ------------------------------------------------------------------
 * Unified Registration Hub — medical account type selection.
 * Supports public self-registration for:
 *   1. Student
 *   2. Teacher
 *   3. Nurse
 *   4. Administrator
 * ------------------------------------------------------------------
 */
require_once __DIR__ . '/../config/config.php';
require __DIR__.'/../includes/public_registration_closed.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');

if (is_logged_in()) {
    redirect(dashboard_url_for(current_user()['role']));
}

$pageTitle = 'Create an Account';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php include __DIR__ . '/../includes/head.php'; ?>
</head>
<body>
<div class="auth-wrapper">
  <div class="auth-card" style="max-width:1040px;" data-aos="fade-up" data-aos-duration="700">
    <div class="auth-side">
      <div class="auth-icon"><img src="<?= APP_URL ?>/assets/images/bpc_logo.png" alt="BPC Clinic Logo"></div>
      <h1 class="mb-2">AI-Enhanced School Clinic Management System for Bulacan Polytechnic College Healthcare Service Improvement</h1>
      <p class="mb-3">Choose the account type that applies to you. Each role has a dedicated medical workflow, registration form, and clinic dashboard.</p>
      <ul class="list-unstyled small mb-0" style="opacity:.85;">
        <li class="mb-2"><i class="bi bi-check-circle-fill me-2"></i><strong>Students:</strong> Access personal health records, consult via messaging &amp; use QR clinic check-in.</li>
        <li class="mb-2"><i class="bi bi-check-circle-fill me-2"></i><strong>Teachers:</strong> Submit authorized clinic referrals and emergency reports while preserving student privacy.</li>
        <li class="mb-2"><i class="bi bi-check-circle-fill me-2"></i><strong>Nurses:</strong> Operate clinic workstation, triage walk-ins, records &amp; medicine inventory.</li>
        <li><i class="bi bi-check-circle-fill me-2"></i><strong>Administrators:</strong> Full clinic system administration, user accounts, analytics &amp; reporting.</li>
      </ul>
    </div>
    <div class="auth-form-side">
      <h4 class="mb-1">Create Your Account</h4>
      <p class="text-muted mb-4">Choose your medical account type to open the dedicated registration form:</p>

      <?php if ($flash = get_flash()): ?>
        <div class="alert alert-<?= e($flash['type']) ?> py-2 small d-flex align-items-center gap-2 mb-3">
          <i class="bi bi-<?= $flash['type'] === 'success' ? 'check-circle-fill' : 'info-circle-fill' ?>"></i>
          <span><?= e($flash['message']) ?></span>
        </div>
      <?php endif; ?>

      <div class="row g-3">
        <div class="col-sm-6" data-aos="fade-up" data-aos-delay="50">
          <a href="register.php?role=student" class="app-choice">
            <div class="app-choice__icon"><i class="bi bi-mortarboard-fill"></i></div>
            <div class="app-choice__title">Student</div>
            <div class="app-choice__desc">Personal health records, clinic messaging, medicine availability, and QR clinic check-in.</div>
            <span class="btn btn-sm btn-outline-primary mt-auto">Register as Student <i class="bi bi-arrow-right ms-1"></i></span>
          </a>
        </div>
        <div class="col-sm-6" data-aos="fade-up" data-aos-delay="100">
          <a href="register.php?role=teacher" class="app-choice">
            <div class="app-choice__icon"><i class="bi bi-person-workspace"></i></div>
            <div class="app-choice__title">Teacher</div>
            <div class="app-choice__desc">Submit authorized clinic referrals and emergency reports for students under your care.</div>
            <span class="btn btn-sm btn-outline-info mt-auto">Register as Teacher <i class="bi bi-arrow-right ms-1"></i></span>
          </a>
        </div>
        <div class="col-sm-6" data-aos="fade-up" data-aos-delay="150">
          <a href="register.php?role=nurse" class="app-choice">
            <div class="app-choice__icon"><i class="bi bi-person-badge-fill"></i></div>
            <div class="app-choice__title">Nurse</div>
            <div class="app-choice__desc">Clinic workstation, walk-in triage, vital signs, patient queue, and medicine inventory.</div>
            <span class="btn btn-sm btn-outline-success mt-auto">Register as Nurse <i class="bi bi-arrow-right ms-1"></i></span>
          </a>
        </div>
        <div class="col-sm-6"><div class="app-choice"><div class="app-choice__icon"><i class="bi bi-shield-lock-fill"></i></div><div class="app-choice__title">Administration</div><div class="app-choice__desc">Accounts are provisioned by the Primary Administrator through the restricted administration workspace.</div><span class="small text-muted mt-auto">Public registration unavailable</span></div></div>
      </div>

      <p class="text-center small text-muted mt-4 mb-0">
        Already have an account? <a href="login.php" class="fw-medium">Sign in</a>
      </p>
    </div>
  </div>
</div>
<script>AOS.init({ once: true, duration: 700 });</script>
<?php include __DIR__ . '/../components/footer.php'; ?>
</body>
</html>
