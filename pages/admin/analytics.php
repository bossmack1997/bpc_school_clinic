<?php
require_once __DIR__ . '/../../config/config.php';
require_role('admin');
$db = getDB();

$ageDistribution = $db->query("
  SELECT CASE
    WHEN TIMESTAMPDIFF(YEAR, s.birthdate, CURDATE()) BETWEEN 15 AND 17 THEN '15-17'
    WHEN TIMESTAMPDIFF(YEAR, s.birthdate, CURDATE()) BETWEEN 18 AND 20 THEN '18-20'
    WHEN TIMESTAMPDIFF(YEAR, s.birthdate, CURDATE()) BETWEEN 21 AND 24 THEN '21-24'
    ELSE '25+' END AS age_group, COUNT(*) cnt
  FROM students s WHERE s.birthdate IS NOT NULL GROUP BY age_group
")->fetchAll();

$genderDist = $db->query("SELECT gender, COUNT(*) cnt FROM students WHERE gender IS NOT NULL GROUP BY gender")->fetchAll();

$yearLevelDist = $db->query("SELECT year_level, COUNT(*) cnt FROM students WHERE year_level IS NOT NULL GROUP BY year_level")->fetchAll();

$courseDist = $db->query("SELECT course, COUNT(*) cnt FROM students WHERE course IS NOT NULL GROUP BY course ORDER BY cnt DESC LIMIT 8")->fetchAll();

$apptStatus = $db->query("SELECT status, COUNT(*) cnt FROM appointments GROUP BY status")->fetchAll();

$bmiDist = $db->query("SELECT bmi_status, COUNT(*) cnt FROM health_monitoring WHERE bmi_status IS NOT NULL GROUP BY bmi_status")->fetchAll();

$pageTitle = 'Health Analytics';
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
        <div class="col-xl-4">
          <div class="card p-3 h-100">
            <h6 class="section-title mb-3">Student Age Distribution</h6>
            <canvas id="ageChart" height="180"></canvas>
          </div>
        </div>
        <div class="col-xl-4">
          <div class="card p-3 h-100">
            <h6 class="section-title mb-3">Gender Distribution</h6>
            <canvas id="genderChart" height="180"></canvas>
          </div>
        </div>
        <div class="col-xl-4">
          <div class="card p-3 h-100">
            <h6 class="section-title mb-3">Year Level Distribution</h6>
            <canvas id="yearChart" height="180"></canvas>
          </div>
        </div>
        <div class="col-xl-6">
          <div class="card p-3 h-100">
            <h6 class="section-title mb-3">Top Courses</h6>
            <canvas id="courseChart" height="180"></canvas>
          </div>
        </div>
        <div class="col-xl-3">
          <div class="card p-3 h-100">
            <h6 class="section-title mb-3">Appointment Status</h6>
            <canvas id="apptChart" height="180"></canvas>
          </div>
        </div>
        <div class="col-xl-3">
          <div class="card p-3 h-100">
            <h6 class="section-title mb-3">BMI Distribution</h6>
            <canvas id="bmiChart" height="180"></canvas>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../../components/footer.php'; ?>
<script>
const ageData = <?= json_encode($ageDistribution) ?>;
const genderData = <?= json_encode($genderDist) ?>;
const yearData = <?= json_encode($yearLevelDist) ?>;
const courseData = <?= json_encode($courseDist) ?>;
const apptData = <?= json_encode($apptStatus) ?>;
const bmiData = <?= json_encode($bmiDist) ?>;
const green = '#0E7A3E';

new Chart(document.getElementById('ageChart'), {
  type: 'bar', data: { labels: ageData.map(v=>v.age_group), datasets: [{ label:'Students', data: ageData.map(v=>v.cnt), backgroundColor: green, borderRadius:6 }] },
  options: { plugins:{legend:{display:false}}, scales:{y:{beginAtZero:true}} }
});
new Chart(document.getElementById('genderChart'), {
  type: 'doughnut', data: { labels: genderData.map(v=>v.gender), datasets: [{ data: genderData.map(v=>v.cnt), backgroundColor:['#0E7A3E','#2E86AB','#E0A527'] }] },
  options: { plugins:{legend:{position:'bottom'}} }
});
new Chart(document.getElementById('yearChart'), {
  type: 'bar', data: { labels: yearData.map(v=>v.year_level), datasets: [{ label:'Students', data: yearData.map(v=>v.cnt), backgroundColor: '#2E86AB', borderRadius:6 }] },
  options: { plugins:{legend:{display:false}}, scales:{y:{beginAtZero:true}} }
});
new Chart(document.getElementById('courseChart'), {
  type: 'bar', data: { labels: courseData.map(v=>v.course), datasets: [{ label:'Students', data: courseData.map(v=>v.cnt), backgroundColor: '#0E7A3E', borderRadius:6 }] },
  options: { plugins:{legend:{display:false}}, indexAxis:'y', scales:{x:{beginAtZero:true}} }
});
new Chart(document.getElementById('apptChart'), {
  type: 'doughnut', data: { labels: apptData.map(v=>v.status), datasets: [{ data: apptData.map(v=>v.cnt), backgroundColor:['#E0A527','#0E7A3E','#2E86AB','#D64545','#6C5CE7'] }] },
  options: { plugins:{legend:{position:'bottom'}} }
});
new Chart(document.getElementById('bmiChart'), {
  type: 'doughnut', data: { labels: bmiData.map(v=>v.bmi_status), datasets: [{ data: bmiData.map(v=>v.cnt), backgroundColor:['#0E7A3E','#2E86AB','#E0A527','#D64545'] }] },
  options: { plugins:{legend:{position:'bottom'}} }
});
</script>
</body>
</html>
