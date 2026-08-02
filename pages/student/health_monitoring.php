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
    <div class="content-area">
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
        <h6 class="section-title mb-3">Health Monitoring History</h6>
        <div class="table-responsive">
          <table class="table table-hover align-middle" id="histTable" style="width:100%">
            <thead><tr><th>Date</th><th>Height</th><th>Weight</th><th>BMI</th><th>Status</th><th>BP</th><th>Heart Rate</th><th>Temp</th><th>O2 Sat</th></tr></thead>
            <tbody></tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../../components/footer.php'; ?>
<script>
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

  const rows = res.data.map(d => [
    d.recorded_at.split(' ')[0], (d.height_cm ?? '—') + ' cm', (d.weight_kg ?? '—') + ' kg',
    d.bmi ?? '—', d.bmi_status ? `<span class="badge bg-success">${d.bmi_status}</span>` : '—',
    d.blood_pressure ?? '—', d.heart_rate ?? '—', d.temperature ?? '—', d.oxygen_saturation ?? '—'
  ]);
  $('#histTable').DataTable({ data: rows, columns:[{},{},{},{},{},{},{},{},{}], pageLength:8 });
}, 'json');
</script>
</body>
</html>
