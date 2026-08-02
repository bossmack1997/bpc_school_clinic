<?php
require_once __DIR__ . '/../../config/config.php';
require_role('nurse');
$pageTitle = 'Patient History';
?>
<!DOCTYPE html>
<html lang="en">
<head><?php include __DIR__ . '/../../includes/head.php'; ?></head>
<body>
<div class="app-wrapper">
  <?php include __DIR__ . '/../../components/sidebar.php'; ?>
  <div class="main-content">
    <?php include __DIR__ . '/../../components/navbar.php'; ?>
    <div class="content-area">
      <div class="row g-3">
        <div class="col-lg-4">
          <div class="card p-3">
<h6 class="section-title mb-3"><i class="bi bi-clock-history text-success me-1"></i> Search Patient</h6>
            <div class="mb-3">
              <label class="form-label small fw-semibold">Search Student</label>
              <input type="text" id="studentSearch" class="form-control" placeholder="Name or student number...">
              <div id="searchResults" class="list-group mt-1"></div>
            </div>
            <div id="patientInfo" class="d-none">
              <hr>
              <h6 class="small fw-semibold">Patient Info</h6>
              <p class="mb-1" id="pName"></p>
              <p class="mb-1" id="pNumber"></p>
              <p class="mb-1" id="pCourse"></p>
            </div>
          </div>
        </div>
        <div class="col-lg-8">
          <div class="card p-3 mb-3" id="consultCard" style="display:none">
            <h6 class="section-title mb-3">Consultation History</h6>
            <div class="table-responsive">
              <table class="table table-hover align-middle" id="consultTable" style="width:100%">
                <thead><tr><th>Date</th><th>Type</th><th>Complaint</th><th>Diagnosis</th><th>Treatment</th></tr></thead>
                <tbody></tbody>
              </table>
            </div>
          </div>
          <div class="card p-3 mb-3" id="healthCard" style="display:none">
            <h6 class="section-title mb-3">Health Monitoring</h6>
            <div class="table-responsive">
              <table class="table table-hover align-middle" id="healthTable" style="width:100%">
                <thead><tr><th>Date</th><th>BMI</th><th>Status</th><th>BP</th><th>Heart Rate</th><th>Temp</th></tr></thead>
                <tbody></tbody>
              </table>
            </div>
          </div>
          <div class="card p-3" id="vacCard" style="display:none">
            <h6 class="section-title mb-3">Vaccination Record</h6>
            <div class="table-responsive">
              <table class="table table-hover align-middle" id="vacTable" style="width:100%">
                <thead><tr><th>Vaccine</th><th>Dose</th><th>Date Administered</th><th>Next Dose</th></tr></thead>
                <tbody></tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../../components/footer.php'; ?>
<script>
$('#studentSearch').on('keyup', function () {
  const q = $(this).val();
  if (q.length < 2) { $('#searchResults').empty(); return; }
  $.get(APP_URL + '/api/students_api.php', { action: 'search', q }, function (res) {
    const $r = $('#searchResults').empty();
    res.data.forEach(s => {
      $r.append(`<button type="button" class="list-group-item list-group-item-action" data-id="${s.id}" data-name="${s.first_name} ${s.last_name} (${s.student_number})" data-course="${s.course||''}" data-number="${s.student_number||''}">${s.first_name} ${s.last_name} — ${s.student_number}</button>`);
    });
  }, 'json');
});

$(document).on('click', '#searchResults button', function () {
  const id = $(this).data('id');
  $('#pName').text('Name: ' + $(this).data('name'));
  $('#pNumber').text('Student No: ' + $(this).data('number'));
  $('#pCourse').text('Course: ' + $(this).data('course'));
  $('#patientInfo').removeClass('d-none');
  $('#searchResults').empty();
  $('#studentSearch').val('');
  loadPatientHistory(id);
});

function loadPatientHistory(id) {
  $.get(APP_URL + '/api/students_api.php', { action: 'patient_history', student_id: id }, function (res) {
    if (!res.success) return;

    if (res.consultations.length) {
      $('#consultCard').show();
      const rows = res.consultations.map(c => [c.consultation_date, c.visit_type, c.chief_complaint, c.diagnosis || '—', c.treatment || '—']);
      $('#consultTable').DataTable({ data: rows, columns:[{},{},{},{},{}], destroy:true, pageLength:5 });
    } else { $('#consultCard').hide(); }

    if (res.health_monitoring.length) {
      $('#healthCard').show();
      const rows = res.health_monitoring.map(h => [h.recorded_at, h.bmi || '—', h.bmi_status || '—', h.blood_pressure || '—', h.heart_rate || '—', h.temperature || '—']);
      $('#healthTable').DataTable({ data: rows, columns:[{},{},{},{},{},{}], destroy:true, pageLength:5 });
    } else { $('#healthCard').hide(); }

    if (res.vaccinations.length) {
      $('#vacCard').show();
      const rows = res.vaccinations.map(v => [v.vaccine_name, v.dose_number || '—', v.date_administered, v.next_dose_date || '—']);
      $('#vacTable').DataTable({ data: rows, columns:[{},{},{},{}], destroy:true, pageLength:5 });
    } else { $('#vacCard').hide(); }
  }, 'json');
}
</script>
</body>
</html>
