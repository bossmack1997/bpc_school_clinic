<?php
require_once __DIR__.'/../../config/config.php';
require_role('student');
require_once __DIR__.'/../../includes/clinic_availability.php';
$db=getDB();$u=current_user();
$stmt=$db->prepare("SELECT id,student_number,course,year_level,section FROM students WHERE user_id=? LIMIT 1");
$stmt->execute([$u['id']]);
$student=$stmt->fetch()?:[];
$clinic=clinic_availability($db);
$ticket=null;
if(!empty($student['id'])){
    $stmt=$db->prepare("SELECT queue_number,status FROM queue_numbers WHERE student_id=? AND created_at>=CURDATE() AND status IN ('waiting','called','serving') ORDER BY id DESC LIMIT 1");
    $stmt->execute([$student['id']]);
    $ticket=$stmt->fetch()?:null;
}
$pageTitle='Student Dashboard';
$studentName=trim(($u['first_name']??'').' '.($u['last_name']??''));
?>
<!doctype html><html lang="en"><head><?php include __DIR__.'/../../includes/head.php'; ?></head><body><div class="app-wrapper"><?php include __DIR__.'/../../components/sidebar.php'; ?><main class="main-content"><?php include __DIR__.'/../../components/navbar.php'; ?>
<div class="content-area page-enter" id="mainContent">

  <!-- Section A — Unified Welcome Banner -->
  <?= dbg_welcome_banner(['name' => $studentName, 'clinic' => $clinic]) ?>

  <!-- Section B — My Clinic Services -->
  <h3 class="h5 mb-3">My Clinic Services</h3>
  <div class="row g-4 mb-4">
    <div class="col-md-6">
      <div class="card border shadow-sm rounded-4 h-100">
        <div class="card-body d-flex align-items-center gap-3">
          <div class="dbg-service-icon"><i class="bi bi-ticket-perforated"></i></div>
          <div>
            <h4 class="h5 mb-1">Clinic Queue</h4>
            <p class="text-muted small mb-2"><?= $ticket?'Current ticket '.e($ticket['queue_number']).' is '.e(ucfirst($ticket['status'])).'.':'Join the clinic queue and follow your current visit status.' ?></p>
            <a class="btn btn-success" href="queue.php"><?= $ticket?'View Queue Status':'Join Clinic Queue' ?></a>
          </div>
        </div>
      </div>
    </div>
    <div class="col-md-6">
      <div class="card border shadow-sm rounded-4 h-100">
        <div class="card-body d-flex align-items-center gap-3">
          <div class="dbg-service-icon"><i class="bi bi-file-earmark-medical"></i></div>
          <div>
            <h4 class="h5 mb-1">My Health Record</h4>
            <p class="text-muted small mb-2">Review your authorized medical records and previous clinic consultations.</p>
            <a class="btn btn-outline-success" href="medical_record.php">View Health Record</a>
          </div>
        </div>
      </div>
    </div>
  </div>

  <?php include __DIR__.'/../../components/announcement_carousel.php'; ?>

</div></main></div><?php include __DIR__.'/../../components/footer.php'; ?></body></html>
