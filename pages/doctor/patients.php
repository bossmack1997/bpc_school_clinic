<?php
require_once __DIR__ . '/../../config/config.php';
require_role('doctor');
$db = getDB();
$u = current_user();

$search = trim($_GET['search'] ?? '');
$params = [];
$where = '';
if ($search !== '') {
    $where = "WHERE (s.student_number LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ? OR s.course LIKE ?)";
    $like = '%' . $search . '%';
    $params = [$like, $like, $like, $like];
}

$stmt = $db->prepare("
    SELECT s.*, u.first_name, u.middle_name, u.last_name, u.email, u.phone, u.gender, u.date_of_birth, u.profile_picture
    FROM students s
    JOIN users u ON u.id = s.user_id
    $where
    ORDER BY u.last_name ASC, u.first_name ASC
    LIMIT 100
");
$stmt->execute($params);
$patients = $stmt->fetchAll();

$pageTitle = 'Patient Records';
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
      
      <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
          <h3 class="fw-bold mb-1"><i class="bi bi-people-fill text-primary me-2"></i>Patient Health Records</h3>
          <p class="text-muted mb-0">Search and review student clinical histories, vital signs, and consultation logs.</p>
        </div>
        <form method="GET" class="d-flex gap-2">
          <input type="text" name="search" class="form-control" placeholder="Search by name, ID, or course" value="<?= e($search) ?>" style="min-width: 250px;">
          <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i> Search</button>
          <?php if ($search): ?><a href="patients.php" class="btn btn-outline-secondary">Reset</a><?php endif; ?>
        </form>
      </div>

      <div class="card border-0 shadow-sm rounded-4">
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 datatable-responsive">
              <thead class="table-light text-muted small">
                <tr>
                  <th class="ps-4">Student</th>
                  <th>Student Number</th>
                  <th>Course &amp; Section</th>
                  <th>Gender / DOB</th>
                  <th>Contact</th>
                  <th class="text-end pe-4">Clinical Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($patients)): ?>
                  <tr><td colspan="6" class="text-center py-5 text-muted">No patient records found.</td></tr>
                <?php else: ?>
                  <?php foreach ($patients as $p): ?>
                    <tr>
                      <td class="ps-4">
                        <div class="d-flex align-items-center gap-3">
                          <img src="<?= APP_URL ?>/<?= e($p['profile_picture'] ?: 'assets/images/default-avatar.png') ?>" class="rounded-circle" style="width:40px;height:40px;object-fit:cover;">
                          <div>
                            <div class="fw-semibold text-dark"><?= e($p['last_name'] . ', ' . $p['first_name'] . ' ' . $p['middle_name']) ?></div>
                            <small class="text-muted"><?= e($p['email']) ?></small>
                          </div>
                        </div>
                      </td>
                      <td><span class="badge bg-light text-dark border"><?= e($p['student_number']) ?></span></td>
                      <td><?= e($p['course'] . ' ' . $p['year_level'] . '-' . $p['section']) ?></td>
                      <td><?= e($p['gender'] ?: '—') ?> &bull; <small class="text-muted"><?= e($p['date_of_birth'] ?: '—') ?></small></td>
                      <td><?= e($p['phone'] ?: '—') ?></td>
                      <td class="text-end pe-4">
                        <a href="consultations.php?student_id=<?= (int)$p['id'] ?>" class="btn btn-sm btn-primary rounded-pill px-3 me-1">
                          <i class="bi bi-stethoscope"></i> Consult
                        </a>
                        <a href="medical_records.php?student_id=<?= (int)$p['id'] ?>" class="btn btn-sm btn-outline-secondary rounded-pill px-3">
                          <i class="bi bi-file-medical"></i> History
                        </a>
                      </td>
                    </tr>
                  <?php endforeach; ?>
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