<?php
/**
 * auth/register_hub.php
 * ------------------------------------------------------------------
 * Unified Registration Hub — account type selection.
 * Supports: Student | Nurse | Teacher | Guidance Counselor
 * Admin is NOT offered publicly (internal creation only).
 * ------------------------------------------------------------------
 */
require_once __DIR__ . '/../config/config.php';

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
<style>
  .reg-type-card {
    display: flex; flex-direction: column; align-items: center; text-align: center;
    gap: 10px; padding: 24px 16px; height: 100%;
    border: 2px solid var(--border, #e5e7eb); border-radius: 18px;
    background: var(--card, #fff); cursor: pointer;
    transition: transform .18s ease, box-shadow .18s ease, border-color .18s ease;
    text-decoration: none; color: inherit;
  }
  .reg-type-card:hover {
    transform: translateY(-4px);
    border-color: var(--primary);
    box-shadow: 0 14px 30px rgba(11, 107, 58, 0.15);
    color: inherit;
  }
  .reg-type-icon {
    width: 56px; height: 56px; border-radius: 50%;
    background: var(--primary-light, #EAF7EF); color: var(--primary);
    display: flex; align-items: center; justify-content: center;
    font-size: 1.5rem; transition: all .18s ease;
  }
  .reg-type-card:hover .reg-type-icon { background: var(--primary); color: #fff; }
  .reg-type-title { font-weight: 700; font-size: 0.98rem; }
  .reg-type-desc { font-size: .80rem; color: var(--text-secondary, #6b7280); line-height: 1.45; }
</style>
</head>
<body>
<div class="auth-wrapper">
  <div class="auth-card" style="max-width:1040px;" data-aos="fade-up" data-aos-duration="700">
    <div class="auth-side">
      <div class="auth-icon"><img src="<?= APP_URL ?>/assets/images/bpc_logo.png" alt="BPC Clinic Logo"></div>
      <h2 class="mb-2">Join BPC School Clinic</h2>
      <p class="mb-3">Choose the account type that applies to you. Each registration is reviewed by the clinic administrator before activation.</p>
      <ul class="list-unstyled small mb-0" style="opacity:.85;">
        <li class="mb-2"><i class="bi bi-check-circle-fill me-2"></i>Students get health records, appointments &amp; QR check-in.</li>
        <li class="mb-2"><i class="bi bi-check-circle-fill me-2"></i>Nurses manage consultations, records &amp; inventory.</li>
        <li class="mb-2"><i class="bi bi-check-circle-fill me-2"></i>Teachers set their advisory class &amp; monitor students.</li>
        <li><i class="bi bi-check-circle-fill me-2"></i>Guidance counselors handle student support referrals.</li>
      </ul>
    </div>
    <div class="auth-form-side">
      <h4 class="mb-1">Create an Account</h4>
      <p class="text-muted mb-4">Select your account type to continue</p>

      <?php if ($flash = get_flash()): ?>
        <div class="alert alert-<?= e($flash['type']) ?> py-2 small d-flex align-items-center gap-2 mb-3">
          <i class="bi bi-<?= $flash['type'] === 'success' ? 'check-circle-fill' : 'info-circle-fill' ?>"></i>
          <span><?= e($flash['message']) ?></span>
        </div>
      <?php endif; ?>

      <div class="row g-3">
        <div class="col-6 col-md-3" data-aos="fade-up" data-aos-delay="50">
          <a href="register.php?role=student" class="reg-type-card">
            <div class="reg-type-icon"><i class="bi bi-mortarboard-fill"></i></div>
            <div class="reg-type-title">Student</div>
            <div class="reg-type-desc">Enrolled BPC students. Appointments, health records &amp; QR code.</div>
            <span class="btn btn-sm btn-outline-primary mt-auto">Select <i class="bi bi-arrow-right ms-1"></i></span>
          </a>
        </div>
        <div class="col-6 col-md-3" data-aos="fade-up" data-aos-delay="100">
          <a href="register.php?role=nurse" class="reg-type-card">
            <div class="reg-type-icon"><i class="bi bi-person-badge-fill"></i></div>
            <div class="reg-type-title">Nurse</div>
            <div class="reg-type-desc">School nurses. Manage patient consultations and clinic operations.</div>
            <span class="btn btn-sm btn-outline-primary mt-auto">Select <i class="bi bi-arrow-right ms-1"></i></span>
          </a>
        </div>
        <div class="col-6 col-md-3" data-aos="fade-up" data-aos-delay="150">
          <a href="register.php?role=teacher" class="reg-type-card">
            <div class="reg-type-icon"><i class="bi bi-person-video3"></i></div>
            <div class="reg-type-title">Teacher</div>
            <div class="reg-type-desc">Faculty members. Set advisory class and monitor students.</div>
            <span class="btn btn-sm btn-outline-primary mt-auto">Select <i class="bi bi-arrow-right ms-1"></i></span>
          </a>
        </div>
        <div class="col-6 col-md-3" data-aos="fade-up" data-aos-delay="200">
          <a href="register.php?role=guidance_counselor" class="reg-type-card">
            <div class="reg-type-icon"><i class="bi bi-heart-pulse-fill"></i></div>
            <div class="reg-type-title">Counselor</div>
            <div class="reg-type-desc">Guidance office staff. Handle student support referrals.</div>
            <span class="btn btn-sm btn-outline-primary mt-auto">Select <i class="bi bi-arrow-right ms-1"></i></span>
          </a>
        </div>
      </div>

      <hr class="my-4">
      <p class="text-center small text-muted mb-0">
        Already have an account? <a href="login.php" class="fw-medium">Sign in</a>
      </p>
      <p class="text-center small text-muted mb-0 mt-1">
        <i class="bi bi-shield-lock me-1"></i>Administrator accounts are strictly created internally by the school.
      </p>
    </div>
  </div>
</div>
<script>AOS.init({ once: true, duration: 700 });</script>
<?php include __DIR__ . '/../components/footer.php'; ?>
</body>
</html>
