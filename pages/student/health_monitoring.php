<?php
require_once __DIR__ . '/../../config/config.php';
require_role('student');
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
    <div class="content-area page-enter">
      <div class="row g-3">
        <div class="col-xl-6">
          <div class="card p-3 h-100">
            <h6 class="section-title mb-3">BMI Trend</h6>
            <canvas id="bmiChart" height="200"></canvas>
          </div>
        </div>
        <div class="col-xl-6">
          <div class="card p-3 h-100">
            <h6 class="section-title mb-3">Vital Signs Trend</h6>
            <canvas id="vitalsChart" height="200"></canvas>
          </div>
        </div>
      </div>

      <div class="card p-3 mt-3">
        <h6 class="section-title mb-3">BMI Calculator</h6>
        <div class="row g-3 align-items-end">
          <div class="col-sm-4">
            <label class="form-label">Height</label>
            <div class="input-group">
              <input type="number" class="form-control" id="bmiHeight" placeholder="170" min="1" step="0.1">
              <span class="input-group-text">cm</span>
            </div>
          </div>
          <div class="col-sm-4">
            <label class="form-label">Weight</label>
            <div class="input-group">
              <input type="number" class="form-control" id="bmiWeight" placeholder="65" min="1" step="0.1">
              <span class="input-group-text">kg</span>
            </div>
          </div>
          <div class="col-sm-4">
            <button class="btn btn-primary w-100" id="calculateBmiBtn">
              <i class="bi bi-calculator me-1"></i> Calculate BMI
            </button>
          </div>
        </div>
        <div class="row g-3 mt-2" id="bmiResult" style="display:none;">
          <div class="col-sm-4">
            <div class="border rounded-3 p-3 text-center h-100">
              <div class="text-muted small">BMI</div>
              <div class="display-6 fw-bold" id="bmiValue">—</div>
            </div>
          </div>
          <div class="col-sm-4">
            <div class="border rounded-3 p-3 text-center h-100">
              <div class="text-muted small">Category</div>
              <div class="display-6 fw-bold" id="bmiCategory">—</div>
            </div>
          </div>
          <div class="col-sm-4 d-flex align-items-center">
            <button class="btn btn-success w-100" id="saveBmiBtn">
              <i class="bi bi-save me-1"></i> Save BMI
            </button>
          </div>
        </div>
      </div>

      <div class="card p-3 mt-3">
        <h6 class="section-title mb-3">Health Monitoring History</h6>
        <div class="table-responsive">
          <table class="table table-hover align-middle" id="histTable" style="width:100%">
            <thead><tr><th>Date</th><th>Height</th><th>Weight</th><th>BMI</th><th>Status</th><th>Record Type</th><th>BP</th><th>Heart Rate</th><th>Temp</th><th>O2 Sat</th></tr></thead>
            <tbody></tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../../components/footer.php'; ?>
<script>
const csrfToken = $('meta[name="csrf-token"]').attr('content');

function getBmiStatusClass(status) {
  if (!status) return 'secondary';
  if (status === 'Normal') return 'success';
  if (status === 'Underweight') return 'warning text-dark';
  if (status === 'Overweight') return 'warning';
  if (status === 'Obese') return 'danger';
  return 'secondary';
}

$('#calculateBmiBtn').on('click', function () {
  const height = parseFloat($('#bmiHeight').val());
  const weight = parseFloat($('#bmiWeight').val());

  if (!height || height <= 0) {
    toastError('Please enter a valid height.');
    return;
  }
  if (!weight || weight <= 0) {
    toastError('Please enter a valid weight.');
    return;
  }

  const heightM = height / 100;
  const bmi = parseFloat((weight / (heightM * heightM)).toFixed(2));
  const status = bmi < 18.5 ? 'Underweight' : bmi < 25 ? 'Normal' : bmi < 30 ? 'Overweight' : 'Obese';

  $('#bmiValue').text(bmi);
  $('#bmiCategory').text(status);
  $('#bmiCategory').removeClass('text-secondary text-success text-warning text-danger').addClass('text-' + getBmiStatusClass(status));
  $('#bmiResult').show();
});

$('#saveBmiBtn').on('click', function () {
  const height = parseFloat($('#bmiHeight').val());
  const weight = parseFloat($('#bmiWeight').val());

  if (!height || height <= 0) {
    toastError('Please enter a valid height.');
    return;
  }
  if (!weight || weight <= 0) {
    toastError('Please enter a valid weight.');
    return;
  }

  $.post(APP_URL + '/api/health_monitoring.php', {
    action: 'student_record',
    height_cm: height,
    weight_kg: weight,
    csrf_token: csrfToken
  }, function (res) {
    if (res.success) {
      toastSuccess(res.message || 'BMI saved successfully.');
      loadHistory();
      $('#bmiResult').hide();
      $('#bmiHeight').val('');
      $('#bmiWeight').val('');
    } else {
      toastError(res.message || 'Failed to save BMI.');
    }
  }, 'json').fail(function () {
    toastError('Network error. Please try again.');
  });
});

function loadHistory() {
  $.get(APP_URL + '/api/health_monitoring.php', { action: 'history' }, function (res) {
    if (!res.success) return;
    const data = res.data.slice().reverse();
    new Chart(document.getElementById('bmiChart'), {
      type:'line',
      data:{ labels: data.map(d=>d.recorded_at.split(' ')[0]), datasets:[{ label:'BMI', data:data.map(d=>d.bmi), borderColor:'#0E7A3E', backgroundColor:'rgba(14,122,62,.12)', fill:true, tension:.35 }]},
      options:{ plugins:{legend:{display:false}} }
    });
    new Chart(document.getElementById('vitalsChart'), {
      type:'line',
      data:{ labels: data.map(d=>d.recorded_at.split(' ')[0]), datasets:[
        { label:'Heart Rate', data:data.map(d=>d.heart_rate), borderColor:'#D64545', tension:.35 },
        { label:'Temp (°C)', data:data.map(d=>d.temperature), borderColor:'#E0A527', tension:.35 },
        { label:'O2 Sat (%)', data:data.map(d=>d.oxygen_saturation), borderColor:'#2E86AB', tension:.35 },
      ]},
      options:{ plugins:{legend:{position:'bottom'}} }
    });

    const rows = res.data.map(d => {
      const typeLabel = d.record_type === 'nurse_assessment' ? 'Nurse Assessment' : 'Self-Recorded BMI';
      return [
        d.recorded_at.split(' ')[0], (d.height_cm ?? '—') + ' cm', (d.weight_kg ?? '—') + ' kg',
        d.bmi ?? '—', d.bmi_status ? '<span class=\"badge bg-' + getBmiStatusClass(d.bmi_status) + '\">' + d.bmi_status + '</span>' : '—',
        typeLabel, d.blood_pressure ?? '—', d.heart_rate ?? '—', d.temperature ?? '—', d.oxygen_saturation ?? '—'
      ];
    });
    $('#histTable').DataTable({ data: rows, columns:[{},{},{},{},{},{},{},{},{},{}], pageLength:8 });
  }, 'json');
}

loadHistory();
</script>
</body>
</html>
