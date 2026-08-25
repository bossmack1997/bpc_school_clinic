<?php
require_once __DIR__ . '/../../config/config.php';
require_role('adviser');
$db = getDB();
$u = current_user();

$assignments = get_adviser_active_assignment($db, $u['id']);
$sections = array_map(fn($a) => $a['section_name'], $assignments);
$students = get_adviser_students($db, $u['id']);


$pageTitle = 'My Advisory Class';
?>
<!DOCTYPE html>
<html lang="en">
<head><?php include __DIR__ . '/../../includes/head.php'; ?></head>
<body>
<div class="app-wrapper">
  <?php include __DIR__ . '/../../components/sidebar.php'; ?>
  <div class="main-content">
    <?php include __DIR__ . '/../../components/navbar.php'; ?>
    <div class="content-area page-enter">

      <div class="alert alert-light border d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3" role="alert">
        <div class="d-flex align-items-center gap-2">
          <i class="bi bi-info-circle-fill text-primary"></i>
          <div class="small">
            <?php if ($sections): ?>
              Viewing advisory section(s): <strong><?= implode(', ', array_map('e', $sections)) ?></strong>. Access is limited to your assigned advisory section only.
            <?php else: ?>
              You have not assigned an advisory class yet. Select your assigned course, year level, and section to view your students.
            <?php endif; ?>
          </div>
        </div>
        <a href="my_advisory.php" class="btn btn-sm btn-outline-primary"><i class="bi bi-gear me-1"></i> Manage Advisory Class</a>
      </div>

      <div class="card">
        <div class="card-header d-flex align-items-center gap-2">
          <i class="bi bi-people-fill text-primary"></i><span>My Advisory Class (<?= count($students) ?>)</span>
        </div>
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
              <thead><tr><th>Student</th><th>Student No.</th><th>Course</th><th>Year</th><th>Section</th><th></th></tr></thead>
              <tbody>
                <?php foreach ($students as $s): ?>
                <tr>
                  <td class="fw-medium"><?= e($s['first_name'].' '.$s['last_name']) ?></td>
                  <td><?= e($s['student_number']) ?></td>
                  <td><?= e($s['course'] ?: '—') ?></td>
                  <td><?= e($s['year_level'] ?: '—') ?></td>
                  <td><span class="badge bg-light text-dark border fw-medium"><?= e($s['assigned_section_name'] ?: $s['section']) ?></span></td>
                  <td><a href="student_detail.php?id=<?= (int)$s['id'] ?>" class="btn btn-sm btn-outline-primary">View</a></td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$students): ?>
                <tr>
                  <td colspan="6" class="text-center py-4">
                    <div class="p-4 my-2">
                      <div class="mb-3 text-muted">
                        <div class="d-inline-flex align-items-center justify-content-center bg-light rounded-circle p-3" style="width: 64px; height: 64px;">
                          <i class="bi bi-people fs-2 text-secondary"></i>
                        </div>
                      </div>
                      <?php if ($sections): ?>
                        <h5 class="fw-bold text-dark mb-1">No Students Yet</h5>
                        <p class="text-muted small mb-0" style="max-width: 480px; margin: 0 auto;">
                          There are currently no students enrolled in your advisory section, <strong><?= e(implode(', ', $sections)) ?></strong>.<br>
                          Once students are enrolled in this section, they will appear here.
                        </p>
                      <?php else: ?>
                        <h5 class="fw-bold text-dark mb-1">No Advisory Class Assigned</h5>
                        <p class="text-muted small mb-3">You have not selected your advisory class yet. Select your assigned course, year level, and section to view your students.</p>
                        <a href="my_advisory.php" class="btn btn-success btn-sm fw-semibold"><i class="bi bi-plus-circle me-1"></i> Set Up My Advisory Class</a>
                      <?php endif; ?>
                    </div>
                  </td>
                </tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

    </div>
  </div>
</div>
<?php include __DIR__ . '/../../components/footer.php'; ?>
</body>
</html>
