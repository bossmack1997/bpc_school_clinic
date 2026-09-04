<?php
/**
 * auth/register_hub.php
 * ------------------------------------------------------------------
 * Unified Registration Hub — account type selection.
 * Supports public self-registration for:
 *   - Student
 *   - Teacher / Adviser
 *   - Administrator
 *
 * Nurse accounts are managed/provisioned directly by the Clinic Administrator.
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
  .reg-type-card.card-admin:hover {
    border-color: #0f172a;
    box-shadow: 0 14px 30px rgba(15, 23, 42, 0.2);
  }
  .reg-type-icon {
    width: 56px; height: 56px; border-radius: 50%;
    background: var(--primary-light, #EAF7EF); color: var(--primary);
    display: flex; align-items: center; justify-content: center;
    font-size: 1.5rem; transition: all .18s ease;
  }
  .reg-type-card:hover .reg-type-icon { background: var(--primary); color: #fff; }
  .reg-type-card.card-admin:hover .reg-type-icon { background: #0f172a; color: #fff; }
  .reg-type-title { font-weight: 700; font-size: 0.98rem; }
  .reg-type-desc { font-size: .80rem; color: var(--text-secondary, #6b7280); line-height: 1.45; }
</style>
</head>
<body>
<div class="auth-wrapper">
  <div class="auth-card" style="max-width:1040px;" data-aos="fade-up" data-aos-duration="700">
    <div class="auth-side">
      <div class="auth-icon"><img src="<?= APP_URL ?>/assets/images/bpc_logo.png" alt="BPC Clinic Logo"></div>
      <h2 class="mb-2">AI-Enhanced School Clinic Management System for Bulacan Polytechnic College Healthcare Service Improvement</h2>
      <p class="mb-3">Choose the account type that applies to you. Each role has a dedicated workflow, registration form, and dashboard.</p>
      <ul class="list-unstyled small mb-0" style="opacity:.85;">
        <li class="mb-2"><i class="bi bi-check-circle-fill me-2"></i><strong>Students:</strong> Access personal health records, book appointments &amp; use QR check-in.</li>
        <li class="mb-2"><i class="bi bi-check-circle-fill me-2"></i><strong>Teachers / Advisers:</strong> Manage advisory classes &amp; coordinate student care.</li>
        <li class="mb-2"><i class="bi bi-check-circle-fill me-2"></i><strong>Nurses:</strong> Operate clinic workstation, consultations, records &amp; medicine inventory.</li>
        <li><i class="bi bi-check-circle-fill me-2"></i><strong>Administrators:</strong> Oversee users, system settings, courses, sections &amp; reporting.</li>
      </ul>
    </div>
    <div class="auth-form-side">
      <h4 class="mb-1">Create Your Account</h4>
      <p class="text-muted mb-4">Choose your account type to open the dedicated registration form:</p>

      <?php if ($flash = get_flash()): ?>
        <div class="alert alert-<?= e($flash['type']) ?> py-2 small d-flex align-items-center gap-2 mb-3">
          <i class="bi bi-<?= $flash['type'] === 'success' ? 'check-circle-fill' : 'info-circle-fill' ?>"></i>
          <span><?= e($flash['message']) ?></span>
        </div>
      <?php endif; ?>

      <div class="row g-3">
        <div class="col-sm-6" data-aos="fade-up" data-aos-delay="50">
          <a href="register_student.php" class="reg-type-card">
            <div class="reg-type-icon"><i class="bi bi-mortarboard-fill"></i></div>
            <div class="reg-type-title">Student</div>
            <div class="reg-type-desc">Personal health records, appointments, medicine availability, and QR clinic check-in.</div>
            <span class="btn btn-sm btn-outline-primary mt-auto">Register as Student <i class="bi bi-arrow-right ms-1"></i></span>
          </a>
        </div>
        <div class="col-sm-6" data-aos="fade-up" data-aos-delay="100">
          <a href="register_teacher.php" class="reg-type-card">
            <div class="reg-type-icon"><i class="bi bi-person-video3"></i></div>
            <div class="reg-type-title">Teacher / Adviser</div>
            <div class="reg-type-desc">Advisory section health profiles, clinic coordination, and classroom emergencies.</div>
            <span class="btn btn-sm btn-outline-primary mt-auto">Register as Teacher <i class="bi bi-arrow-right ms-1"></i></span>
          </a>
        </div>
        <div class="col-sm-6" data-aos="fade-up" data-aos-delay="150">
          <a href="register_nurse.php" class="reg-type-card">
            <div class="reg-type-icon"><i class="bi bi-person-badge-fill"></i></div>
            <div class="reg-type-title">Nurse</div>
            <div class="reg-type-desc">Clinic workstation, medical records, patient visits, QR scanner, and medicine inventory.</div>
            <span class="btn btn-sm btn-outline-success mt-auto">Register as Nurse <i class="bi bi-arrow-right ms-1"></i></span>
          </a>
        </div>
        <div class="col-sm-6" data-aos="fade-up" data-aos-delay="200">
          <a href="register_admin.php" class="reg-type-card card-admin">
            <div class="reg-type-icon"><i class="bi bi-shield-lock-fill"></i></div>
            <div class="reg-type-title">Administrator</div>
            <div class="reg-type-desc">System administration, user management, course &amp; section settings, and analytics.</div>
            <span class="btn btn-sm btn-outline-dark mt-auto">Register as Admin <i class="bi bi-arrow-right ms-1"></i></span>
          </a>
        </div>
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