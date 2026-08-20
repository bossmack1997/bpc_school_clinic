<?php
require_once __DIR__ . '/../../config/config.php';
require_role('nurse');
$pageTitle = 'Health Monitoring';
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
        <div class="col-lg-5">
          <div class="card p-3">
            <h6 class="section-title mb-3"><i class="bi bi-heart-pulse text-success me-1"></i> Record Vitals</h6>
            <div class="mb-3">
              <label class="form-label small fw-semibold">Search Student</label>
              <input type="text" id="studentSearch" class="form-control" placeholder="Name or student number...">
              <div id="searchResults" class="list-group mt-1"></div>
            </div>
             <form id="vitalsForm" class="d-none">
               <input type="hidden" name="student_id" id="student_id">
               <div class="alert alert-success py-2 small" id="selectedStudent"></div>
               <div class="row g-2">
                 <div class="col-6"><label class="form-label small">Height (cm)</label><input type="number" step="0.1" name="height_cm" class="form-control" id="nurseHeight"></div>
                 <div class="col-6"><label class="form-label small">Weight (kg)</label><input type="number" step="0.1" name="weight_kg" class="form-control" id="nurseWeight"></div>
                 <div class="col-6">
                   <label class="form-label small">BMI (auto)</label>
                   <input type="text" class="form-control" id="nurseBmi" readonly placeholder="—">
                 </div>
                 <div class="col-6"><label class="form-label small">BMI Status</label>
                   <input type="text" class="form-control" id="nurseBmiStatus" readonly placeholder="—">
                 </div>
                 <div class="col-6"><label class="form-label small">Blood Pressure</label><input type="text" name="blood_pressure" class="form-control" placeholder="120/80"></div>
                 <div class="col-6"><label class="form-label small">Heart Rate</label><input type="number" name="heart_rate" class="form-control"></div>
                 <div class="col-6"><label class="form-label small">Pulse Rate</label><input type="number" name="pulse_rate" class="form-control"></div>
                 <div class="col-6"><label class="form-label small">Temperature (°C)</label><input type="number" step="0.1" name="temperature" class="form-control"></div>
                 <div class="col-6"><label class="form-label small">Oxygen Saturation (%)</label><input type="number" step="0.1" name="oxygen_saturation" class="form-control"></div>
                 <div class="col-6"><label class="form-label small">Vision</label><input type="text" name="vision" class="form-control"></div>
                 <div class="col-12"><label class="form-label small">Hearing</label><input type="text" name="hearing" class="form-control"></div>
               </div>
               <button class="btn btn-primary w-100 mt-3" type="submit">Save Health Record</button>
             </form>
          </div>
        </div>
        <div class="col-lg-7">
          <div class="card p-3" id="historyCard" style="display:none">
            <h6 class="section-title mb-3">History</h6>
            <div class="table-responsive">
              <table class="table table-hover align-middle" id="histTable" style="width:100%">
                 <thead><tr><th>Date</th><th>BMI</th><th>Status</th><th>Record Type</th><th>BP</th><th>HR</th><th>Temp</th></tr></thead>
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
const csrfToken = $('meta[name="csrf-token"]').attr('content');
let selectedId = null, histTable;

$('#studentSearch').on('keyup', function () {
  const q = $(this).val();
  if (q.length < 2) { $('#searchResults').empty(); return; }
  $.get(APP_URL + '/api/health_monitoring.php', { action: 'search_students', q }, function (res) {
    const $r = $('#searchResults').empty();
    res.data.forEach(s => {
      $r.append(`<button type="button" class="list-group-item list-group-item-action" data-id="${s.id}" data-name="${s.first_name} ${s.last_name} (${s.student_number})">${s.first_name} ${s.last_name} — ${s.student_number}</button>`);
    });
  }, 'json');
});

$(document).on('click', '#searchResults button', function () {
  selectedId = $(this).data('id');
  $('#student_id').val(selectedId);
  $('#selectedStudent').text('Selected: ' + $(this).data('name'));
  $('#vitalsForm').removeClass('d-none');
  $('#searchResults').empty();
  $('#studentSearch').val('');
  loadHistory();
});

function loadHistory() {
  $.get(APP_URL + '/api/health_monitoring.php', { action: 'history', student_id: selectedId }, function (res) {
    if (!res.success) return;
    $('#historyCard').show();
    if (histTable) histTable.destroy();
    const rows = res.data.map(d => {
      const typeLabel = d.record_type === 'nurse_assessment' ? 'Nurse Assessment' : 'Self-Recorded BMI';
      return [d.recorded_at, d.bmi ?? '—', d.bmi_status ?? '—', typeLabel, d.blood_pressure ?? '—', d.heart_rate ?? '—', d.temperature ?? '—'];
    });
    histTable = $('#histTable').DataTable({ data: rows, columns:[{},{},{},{},{},{},{}], destroy:true, pageLength:5 });
  }, 'json');
}

function calculateNurseBmi() {
  const h = parseFloat($('#nurseHeight').val());
  const w = parseFloat($('#nurseWeight').val());
  if (h && w && h > 0 && w > 0) {
    const bmi = parseFloat((w / Math.pow(h / 100, 2)).toFixed(2));
    const status = bmi < 18.5 ? 'Underweight' : bmi < 25 ? 'Normal' : bmi < 30 ? 'Overweight' : 'Obese';
    $('#nurseBmi').val(bmi);
    $('#nurseBmiStatus').val(status);
  } else {
    $('#nurseBmi').val('—');
    $('#nurseBmiStatus').val('—');
  }
}

$('#nurseHeight, #nurseWeight').on('input', calculateNurseBmi);

$('#vitalsForm').on('submit', function (e) {
  e.preventDefault();
  const data = $(this).serialize() + '&action=record&csrf_token=' + csrfToken;
  $.post(APP_URL + '/api/health_monitoring.php', data, function (res) {
    if (res.success) { toastSuccess(res.message); loadHistory(); }
    else toastError(res.message);
  }, 'json');
});
</script>
</body>
</html>
