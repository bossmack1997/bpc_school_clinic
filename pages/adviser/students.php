<?php
require_once __DIR__ . '/../../config/config.php';
require_role('adviser');
$db = getDB();
$u = current_user();

$assignments = get_adviser_active_assignment($db, $u['id']);
$allStudents = get_adviser_students($db, $u['id']);

// Build section groups based on adviser's active assignments
$sectionGroups = [];

if (!empty($assignments)) {
    foreach ($assignments as $a) {
        $secId = (int)$a['section_id'];
        $secName = trim((string)$a['section_name']);
        $secCourse = trim((string)($a['course'] ?? ''));
        $secYear = trim((string)($a['year_level'] ?? ''));

        // Filter students matching this assigned section
        $secStudents = array_values(array_filter($allStudents, function ($st) use ($a) {
            return (!empty($st['assigned_section_id']) && (int)$st['assigned_section_id'] === (int)$a['section_id']) ||
                   (!empty($st['assigned_section_name']) && strcasecmp($st['assigned_section_name'], $a['section_name']) === 0) ||
                   (!empty($st['section']) && strcasecmp($st['section'], $a['section_name']) === 0);
        }));

        $sectionGroups[] = [
            'id'          => $secId,
            'name'        => $secName,
            'course'      => $secCourse,
            'year_level'  => $secYear,
            'students'    => $secStudents,
            'count'       => count($secStudents)
        ];
    }
} else {
    // If no official assignment record, fallback to grouping any available student rows by course + section
    $groupedByCourseSec = [];
    foreach ($allStudents as $st) {
        $key = trim(($st['course'] ?: 'Unassigned') . ' ' . ($st['section'] ?: 'Section'));
        if (!isset($groupedByCourseSec[$key])) {
            $groupedByCourseSec[$key] = [
                'id'          => 0,
                'name'        => $st['section'] ?: 'General',
                'course'      => $st['course'] ?: '',
                'year_level'  => $st['year_level'] ?: '',
                'students'    => [],
                'count'       => 0
            ];
        }
        $groupedByCourseSec[$key]['students'][] = $st;
        $groupedByCourseSec[$key]['count']++;
    }
    $sectionGroups = array_values($groupedByCourseSec);
}

$totalStudents = count($allStudents);
$pageTitle = 'My Advisory Class';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php include __DIR__ . '/../../includes/head.php'; ?>
<style>
  .advisory-card {
    background: #ffffff;
    border-radius: 12px;
    border: 1px solid var(--border-color, #E2E8F0);
    overflow: hidden;
    transition: box-shadow 0.2s ease, transform 0.2s ease;
  }
  .advisory-card:hover {
    box-shadow: 0 6px 18px rgba(0, 0, 0, 0.06);
  }
  .advisory-card-header {
    background: #ffffff;
    padding: 16px 20px;
    cursor: pointer;
    user-select: none;
    transition: background-color 0.2s ease;
    border-bottom: 1px solid transparent;
  }
  .advisory-card-header:hover {
    background-color: rgba(14, 122, 62, 0.03);
  }
  .advisory-card-header[aria-expanded="true"] {
    background-color: rgba(14, 122, 62, 0.04);
    border-bottom-color: var(--border-color, #E2E8F0);
  }
  .advisory-chevron {
    transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    font-size: 1.1rem;
    color: var(--text-muted, #718096);
  }
  .advisory-card-header[aria-expanded="true"] .advisory-chevron {
    transform: rotate(180deg);
    color: var(--primary, #0E7A3E);
  }
  .advisory-badge-count {
    font-size: 0.82rem;
    font-weight: 600;
    padding: 6px 14px;
    border-radius: 20px;
  }
</style>
</head>
<body>
<div class="app-wrapper">
  <?php include __DIR__ . '/../../components/sidebar.php'; ?>
  <div class="main-content">
    <?php include __DIR__ . '/../../components/navbar.php'; ?>
    <div class="content-area page-enter">

      <!-- Header & Top Action Bar -->
      <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-3">
        <div>
          <h5 class="fw-bold mb-1"><i class="bi bi-people-fill text-success me-2"></i> My Advisory Class</h5>
          <p class="text-muted small mb-0">Manage and monitor students grouped by their assigned Course and Section.</p>
        </div>
        <div class="d-flex flex-wrap gap-2">
          <button type="button" class="btn btn-outline-secondary btn-sm" id="expandAllBtn">
            <i class="bi bi-arrows-expand me-1"></i> Expand All
          </button>
          <button type="button" class="btn btn-outline-secondary btn-sm" id="collapseAllBtn">
            <i class="bi bi-arrows-collapse me-1"></i> Collapse All
          </button>
          <a href="my_advisory.php" class="btn btn-outline-primary btn-sm">
            <i class="bi bi-gear me-1"></i> Manage Advisory Class
          </a>
        </div>
      </div>

      <!-- Scope Notice Banner -->
      <div class="alert alert-light border d-flex align-items-center justify-content-between flex-wrap gap-2 mb-4">
        <div class="d-flex align-items-center gap-2">
          <i class="bi bi-info-circle-fill text-primary fs-5"></i>
          <div class="small">
            <?php if (!empty($sectionGroups)): ?>
              Currently viewing <strong><?= count($sectionGroups) ?></strong> assigned advisory section(s) with <strong><?= $totalStudents ?></strong> total student(s). Access is securely restricted to your advisory sections.
            <?php else: ?>
              You have not assigned an advisory section yet. Set up your assigned course and section to view your students.
            <?php endif; ?>
          </div>
        </div>
        <?php if (empty($sectionGroups)): ?>
          <a href="my_advisory.php" class="btn btn-sm btn-success fw-semibold"><i class="bi bi-plus-circle me-1"></i> Set Up Advisory Class</a>
        <?php endif; ?>
      </div>

      <?php if (!empty($sectionGroups)): ?>
        <!-- Collapsible Advisory Section Cards -->
        <div class="row g-3" id="advisoryAccordion">
          <?php foreach ($sectionGroups as $idx => $group): ?>
            <?php
              $collapseId = 'collapseSection_' . $idx;
              $count = $group['count'];
              $countLabel = $count . ' ' . ($count === 1 ? 'Student' : 'Students');
              $courseLabel = !empty($group['course']) ? $group['course'] : '';
              $yearLabel = !empty($group['year_level']) ? $group['year_level'] : '';
            ?>
            <div class="col-12 advisory-section-item">
              <div class="advisory-card">
                <!-- Section Header (Clickable) -->
                <div class="advisory-card-header d-flex flex-wrap align-items-center justify-content-between gap-3 collapsed"
                     data-bs-toggle="collapse"
                     data-bs-target="#<?= $collapseId ?>"
                     aria-expanded="false"
                     aria-controls="<?= $collapseId ?>">
                  
                  <div class="d-flex align-items-center gap-3">
                    <div class="d-inline-flex align-items-center justify-content-center bg-success-light text-success rounded-circle" style="width: 44px; height: 44px; font-size: 1.25rem;">
                      <i class="bi bi-mortarboard-fill"></i>
                    </div>
                    <div>
                      <div class="d-flex align-items-center gap-2 flex-wrap">
                        <h6 class="fw-bold mb-0 text-dark fs-6"><?= e($group['name']) ?></h6>
                        <?php if ($courseLabel): ?>
                          <span class="badge bg-primary-light text-primary"><?= e($courseLabel) ?></span>
                        <?php endif; ?>
                        <?php if ($yearLabel): ?>
                          <span class="badge bg-light text-muted border"><?= e($yearLabel) ?></span>
                        <?php endif; ?>
                      </div>
                      <div class="small text-muted mt-1">
                        Course + Section · <span class="fw-semibold text-dark"><?= e($group['name']) ?></span>
                      </div>
                    </div>
                  </div>

                  <div class="d-flex align-items-center gap-2">
                    <span class="badge <?= $count > 0 ? 'bg-success-light text-success' : 'bg-light text-muted border' ?> advisory-badge-count">
                      <i class="bi <?= $count > 0 ? 'bi-person-check-fill' : 'bi-person-x' ?> me-1"></i> <?= e($countLabel) ?>
                    </span>
                    <i class="bi bi-chevron-down advisory-chevron ms-2"></i>
                  </div>
                </div>

                <!-- Collapsible Body: Student Table -->
                <div id="<?= $collapseId ?>" class="collapse advisory-collapse" data-bs-parent="#advisoryAccordionParent_disabled">
                  <div class="p-0">
                    <?php if ($count > 0): ?>
                      <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                          <thead class="bg-light text-muted small text-uppercase">
                            <tr>
                              <th class="ps-4">Student</th>
                              <th>Student No.</th>
                              <th>Course</th>
                              <th>Year Level</th>
                              <th>Section</th>
                              <th class="text-end pe-4">Action</th>
                            </tr>
                          </thead>
                          <tbody>
                            <?php foreach ($group['students'] as $s): ?>
                              <tr>
                                <td class="ps-4">
                                  <div class="d-flex align-items-center gap-2">
                                    <div class="avatar-sm rounded-circle bg-light border text-success fw-bold d-flex align-items-center justify-content-center" style="width: 36px; height: 36px; font-size: 0.85rem;">
                                      <?= strtoupper(substr($s['first_name'], 0, 1) . substr($s['last_name'], 0, 1)) ?>
                                    </div>
                                    <div>
                                      <div class="fw-bold text-dark"><?= e($s['first_name'] . ' ' . $s['last_name']) ?></div>
                                      <div class="small text-muted"><?= e($s['email']) ?></div>
                                    </div>
                                  </div>
                                </td>
                                <td><span class="font-monospace text-dark"><?= e($s['student_number']) ?></span></td>
                                <td><span class="badge bg-primary-light text-primary"><?= e($s['course'] ?: '—') ?></span></td>
                                <td><?= e($s['year_level'] ?: '—') ?></td>
                                <td><span class="badge bg-light text-dark border fw-medium"><?= e($s['assigned_section_name'] ?: $s['section']) ?></span></td>
                                <td class="text-end pe-4">
                                  <a href="student_detail.php?id=<?= (int)$s['id'] ?>" class="btn btn-sm btn-outline-primary fw-semibold">
                                    <i class="bi bi-eye me-1"></i> View Details
                                  </a>
                                </td>
                              </tr>
                            <?php endforeach; ?>
                          </tbody>
                        </table>
                      </div>
                    <?php else: ?>
                      <div class="text-center py-4 text-muted">
                        <div class="d-inline-flex align-items-center justify-content-center bg-light rounded-circle p-3 mb-2" style="width: 52px; height: 52px;">
                          <i class="bi bi-people text-secondary fs-4"></i>
                        </div>
                        <h6 class="fw-bold text-dark mb-1">No Students Enrolled</h6>
                        <p class="text-muted small mb-0">No students enrolled in this section yet.</p>
                      </div>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>

      <?php else: ?>
        <!-- No Advisory Class Assigned Empty State -->
        <div class="card p-5 text-center">
          <div class="mb-3">
            <div class="d-inline-flex align-items-center justify-content-center bg-light rounded-circle p-4" style="width: 80px; height: 80px;">
              <i class="bi bi-person-workspace text-secondary fs-1"></i>
            </div>
          </div>
          <h5 class="fw-bold text-dark mb-2">No Advisory Class Assigned</h5>
          <p class="text-muted small mb-4" style="max-width: 480px; margin: 0 auto;">
            You have not assigned an advisory section yet. Please select your assigned course, year level, and section to view and monitor your students.
          </p>
          <div>
            <a href="my_advisory.php" class="btn btn-success fw-semibold px-4">
              <i class="bi bi-plus-circle me-1"></i> Set Up My Advisory Class
            </a>
          </div>
        </div>
      <?php endif; ?>

    </div>
  </div>
</div>
<?php include __DIR__ . '/../../components/footer.php'; ?>
<script>
$(function () {
  'use strict';

  // Expand All button
  $('#expandAllBtn').on('click', function () {
    $('.advisory-collapse').collapse('show');
    $('.advisory-card-header').attr('aria-expanded', 'true').removeClass('collapsed');
  });

  // Collapse All button
  $('#collapseAllBtn').on('click', function () {
    $('.advisory-collapse').collapse('hide');
    $('.advisory-card-header').attr('aria-expanded', 'false').addClass('collapsed');
  });
});
</script>
</body>
</html>
