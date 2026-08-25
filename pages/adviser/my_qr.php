<?php
require_once __DIR__ . '/../../config/config.php';
require_role('adviser');
$u = current_user();
$pageTitle = 'My Clinic QR';
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

      <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <h5 class="section-title mb-0"><i class="bi bi-qr-code-scan text-success me-1"></i> My Clinic QR</h5>
        <div class="d-flex flex-wrap gap-2">
          <button class="btn btn-sm btn-outline-success" id="downloadQrBtn"><i class="bi bi-download me-1"></i> Download</button>
          <button class="btn btn-sm btn-outline-success" id="printQrBtn"><i class="bi bi-printer me-1"></i> Print</button>
        </div>
      </div>

      <div class="row g-3">
        <div class="col-lg-4">
          <div class="card p-4 text-center shadow-sm h-100">
            <div class="mb-2"><img src="<?= APP_URL ?>/assets/images/bpc_logo.png" alt="BPC Logo" style="height:48px;"></div>
            <div id="qrCardInfo" class="mb-2 small text-muted">Loading…</div>
            <div id="qrCanvas" class="d-inline-block p-3 bg-white border rounded-3 mx-auto"></div>
            <div class="small text-muted mt-3">Present this QR to the clinic for check-in and consultation.</div>
            <div class="small text-muted">Patient Type: <span class="badge bg-info text-dark">Teacher / Staff</span></div>
          </div>
        </div>
        <div class="col-lg-8">
          <div class="card p-4 shadow-sm h-100">
            <h6 class="section-title mb-3"><i class="bi bi-person-badge me-1"></i> Staff Information</h6>
            <div class="row g-2 small">
              <div class="col-md-6"><div class="text-muted">Name</div><div class="fw-semibold" id="tName">—</div></div>
              <div class="col-md-6"><div class="text-muted">Employee ID</div><div class="fw-semibold" id="tEmp">—</div></div>
              <div class="col-md-6"><div class="text-muted">Department</div><div class="fw-semibold" id="tDept">—</div></div>
              <div class="col-md-6"><div class="text-muted">Email</div><div class="fw-semibold" id="tEmail">—</div></div>
              <div class="col-md-6"><div class="text-muted">QR Status</div><div class="fw-semibold" id="tStatus">—</div></div>
            </div>
            <h6 class="section-title mt-4 mb-2"><i class="bi bi-clock-history me-1"></i> Scan History</h6>
            <div class="table-responsive">
              <table class="table table-sm table-hover align-middle mb-0" id="scanTable" style="width:100%">
                <thead><tr><th>Time</th><th>Purpose</th><th>Scanner</th><th>Status</th></tr></thead>
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
$(function () {
  $.get(APP_URL + '/api/qr_codes.php', { action: 'teacher_qr' }, function (res) {
    if (!res.success) { toastError(res.message); return; }
    const t = res.teacher || {};
    const qr = res.qr || {};
    $('#tName').text((t.first_name || '') + ' ' + (t.last_name || ''));
    $('#tEmp').text(t.employee_id || '—');
    $('#tDept').text(t.department || '—');
    $('#tEmail').text(t.email || '—');
    $('#tStatus').text(qr.status ? (qr.status.charAt(0).toUpperCase() + qr.status.slice(1)) : '—');
    $('#qrCardInfo').html('<div class="fw-bold">' + ((t.first_name||'') + ' ' + (t.last_name||'')) + '</div><div class="text-muted">' + (t.employee_id || 'Teacher/Staff') + '</div>');

    if (window.QRCode && qr.qr_data) {
      new QRCode(document.getElementById('qrCanvas'), { text: qr.qr_data, width: 200, height: 200, correctLevel: QRCode.CorrectLevel.M });
    }

    const rows = (res.scan_history || []).map(function (h) {
      const st = h.status === 'success' ? '<span class="badge bg-success">Success</span>'
        : h.status === 'duplicate' ? '<span class="badge bg-warning text-dark">Duplicate</span>'
        : '<span class="badge bg-secondary">' + (h.status||'—') + '</span>';
      return [h.scan_time || '—', h.purpose || 'check-in', h.scanner_name || '—', st];
    });
    $('#scanTable').DataTable({ data: rows, columns: [null,null,null,null], pageLength: 10, destroy: true });
  }, 'json');

  function getQrDataUrl() {
    const c = document.querySelector('#qrCanvas canvas');
    if (c && c.toDataURL) return c.toDataURL('image/png');
    const img = $('#qrCanvas img'); return img.length ? img.attr('src') : null;
  }
  $('#downloadQrBtn').on('click', function () {
    const img = getQrDataUrl(); if (!img) return;
    const a = document.createElement('a'); a.href = img; a.download = 'BPC_Teacher_QR.png'; a.click();
  });
  $('#printQrBtn').on('click', function () {
    const img = getQrDataUrl(); if (!img) return;
    const w = window.open('', '_blank');
    w.document.write('<html><head><title>Teacher QR</title></head><body style="text-align:center;font-family:Arial;padding:30px"><img src="'+img+'"><div style="margin-top:10px">BPC School Clinic — Teacher/Staff QR</div></body></html>');
    w.document.close(); w.focus(); setTimeout(function(){ w.print(); }, 300);
  });
});
</script>
</body>
</html>
