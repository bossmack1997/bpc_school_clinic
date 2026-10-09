<?php
/**
 * includes/qr_management_ui.php
 * Shared QR Management UI partial (tabs: Student QR, Scan Logs).
 * Renders the QR table with View / Download / Print / Regenerate /
 * Deactivate actions + a modal preview powered by qrcodejs + SheetJS-free.
 */
$roleDir = $roleDir ?? 'nurse';
$isAdmin = (current_user()['role'] ?? '') === 'admin';
?>
<div class="page-enter fade-in">
  <!-- Header -->
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h6 class="section-title mb-0"><i class="bi bi-qr-code-scan text-success me-1"></i> QR Management</h6>
    <div class="d-flex flex-wrap gap-2">
      <div class="dropdown">
        <button class="btn btn-sm btn-outline-success dropdown-toggle" type="button" data-bs-toggle="dropdown">
          <i class="bi bi-download me-1"></i> Export Logs
        </button>
        <ul class="dropdown-menu dropdown-menu-end" id="qrExportMenu">
          <li><button class="dropdown-item" data-type="qr_scan_logs"><i class="bi bi-receipt me-2"></i> QR Scan Logs</button></li>
          <li><button class="dropdown-item" data-type="daily_checkins"><i class="bi bi-calendar-check me-2"></i> Daily Check-ins</button></li>
          <li><button class="dropdown-item" data-type="walkin_statistics"><i class="bi bi-person-plus me-2"></i> Walk-in Statistics</button></li>
          <li><button class="dropdown-item" data-type="appointment_attendance"><i class="bi bi-clipboard-data me-2"></i> Appointment Attendance</button></li>
          <li><button class="dropdown-item" data-type="queue_report"><i class="bi bi-list-ol me-2"></i> Queue Report</button></li>
        </ul>
      </div>
      <button class="btn btn-sm btn-outline-primary" id="qrRefreshBtn"><i class="bi bi-arrow-clockwise me-1"></i> Refresh</button>
    </div>
  </div>

  <!-- Export Date Range (hidden until an export type is chosen) -->
  <div class="card p-3 mb-3" id="qrExportBar" style="display:none;">
    <div class="row g-2 align-items-end">
      <div class="col-md-3">
        <label class="form-label small fw-semibold mb-1">From</label>
        <input type="date" class="form-control form-control-sm" id="qrExportFrom" value="<?= date('Y-m-01') ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label small fw-semibold mb-1">To</label>
        <input type="date" class="form-control form-control-sm" id="qrExportTo" value="<?= date('Y-m-d') ?>">
      </div>
      <div class="col-md-4">
        <label class="form-label small fw-semibold mb-1">Search (optional)</label>
        <input type="text" class="form-control form-control-sm" id="qrExportSearch" placeholder="Student, purpose...">
      </div>
      <div class="col-md-4">
        <div class="d-grid gap-2">
          <button class="btn btn-sm btn-success" id="qrExportRunBtn"><i class="bi bi-file-earmark-arrow-down me-1"></i> Export CSV</button>
          <button class="btn btn-sm btn-outline-success" id="qrExportExcelBtn"><i class="bi bi-file-earmark-excel me-1"></i> Export Excel</button>
        </div>
      </div>
    </div>
  </div>

  <!-- Tabs -->
  <ul class="nav nav-tabs mb-3" id="qrTabs" role="tablist">
    <li class="nav-item" role="presentation">
      <button class="nav-link active" id="qrStudentsTab" data-bs-toggle="tab" data-bs-target="#qrStudentsPane" type="button" role="tab">
        <i class="bi bi-people-fill me-1"></i> Student QR Codes
      </button>
    </li>
    <li class="nav-item" role="presentation">
      <button class="nav-link" id="qrScanLogsTab" data-bs-toggle="tab" data-bs-target="#qrScanLogsPane" type="button" role="tab">
        <i class="bi bi-receipt-cutoff me-1"></i> Scan Logs
      </button>
    </li>
  </ul>

  <!-- ==================== STUDENT QR TAB ==================== -->
  <div class="tab-content">
    <div class="tab-pane fade show active" id="qrStudentsPane" role="tabpanel">
      <div class="card p-3 mb-3">
        <div class="row g-2 align-items-center">
          <div class="col-md-4">
            <div class="input-group input-group-sm">
              <span class="input-group-text bg-transparent"><i class="bi bi-search"></i></span>
              <input type="text" id="qrSearch" class="form-control" placeholder="Search name or student number...">
            </div>
          </div>
<div class="col-md-3">
            <select id="qrStatusFilter" class="form-select form-select-sm">
              <option value="">All Status</option>
              <option value="active">Active</option>
              <option value="inactive">Inactive</option>
              <option value="revoked">Revoked</option>
              <option value="regenerated">Regenerated</option>
              <option value="deactivated">Deactivated</option>
              <option value="without">Without QR</option>
            </select>
          </div>
          <div class="col-md-3">
            <span class="text-muted small" id="qrCount"></span>
          </div>
        </div>
      </div>

      <div class="card p-3">
        <div class="table-responsive">
          <table class="table table-hover align-middle" id="qrTable" style="width:100%">
            <thead>
              <tr>
                <th>Student</th>
                <th>Student No.</th>
                <th>Course / Year</th>
                <th>Status</th>
                <th>Date Generated</th>
                <th>Last Printed</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody></tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- ==================== SCAN LOGS TAB ==================== -->
    <div class="tab-pane fade" id="qrScanLogsPane" role="tabpanel">
      <div class="card p-3 mb-3">
        <div class="row g-2 align-items-center">
          <div class="col-md-4">
            <div class="input-group input-group-sm">
              <span class="input-group-text bg-transparent"><i class="bi bi-search"></i></span>
              <input type="text" id="scanLogSearch" class="form-control" placeholder="Search student or purpose...">
            </div>
          </div>
          <div class="col-md-3">
            <span class="text-muted small" id="scanLogCount"></span>
          </div>
        </div>
      </div>
      <div class="card p-3">
        <div class="table-responsive">
          <table class="table table-hover align-middle" id="scanLogTable" style="width:100%">
            <thead>
              <tr>
                <th>Student</th>
                <th>Date / Time</th>
                <th>Purpose</th>
                <th>Scanner</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody></tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ============================================================ -->
<!-- QR Preview / Print Modal                                     -->
<!-- ============================================================ -->
<div class="modal fade" id="qrPreviewModal" tabindex="-1" aria-hidden="true" aria-labelledby="qrPreviewModalTitle">
  <div class="modal-dialog modal-dialog-centered" style="max-width:420px;">
    <div class="modal-content">
      <div class="modal-header modal-header--success">
      <h5 class="modal-title" id="qrPreviewModalTitle"><?= bpc_icon('qr') ?> Student QR Code</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body text-center" id="qrPreviewBody">
        <div class="text-muted py-4">Loading...</div>
      </div>
      <div class="modal-footer border-0 justify-content-center gap-2" id="qrPreviewFooter" style="display:none;">
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal"><i class="bi bi-x-lg me-1"></i> Close</button>
        <button type="button" class="btn btn-primary btn-sm" id="qrDownloadBtn"><i class="bi bi-download me-1"></i> Download</button>
        <button type="button" class="btn btn-success btn-sm" id="qrPrintBtn"><i class="bi bi-printer me-1"></i> Print</button>
      </div>
    </div>
  </div>
</div>

<script>
window.__initQrManagementUI = function () {
  (function () {
  'use strict';
  const APP = window.APP_URL || '';
  const csrf = $('meta[name="csrf-token"]').attr('content');
  const IS_ADMIN = <?= $isAdmin ? 'true' : 'false' ?>;
  let qrDt = null, scanDt = null;
  let currentQrStudent = null;

function statusBadge(status) {
    if (status === 'active') return '<span class="badge bg-success">Active</span>';
    if (status === 'inactive') return '<span class="badge bg-secondary">Inactive</span>';
    if (status === 'revoked') return '<span class="badge bg-danger">Revoked</span>';
    if (status === 'regenerated') return '<span class="badge bg-info">Regenerated</span>';
    if (status === 'deactivated') return '<span class="badge bg-danger">Deactivated</span>';
    return '<span class="badge bg-secondary">No QR</span>';
  }

  // ============================================================
  // Load student QR list
  // ============================================================
  function loadQrList() {
    const params = {
      action: 'list',
      search: $('#qrSearch').val(),
      status: $('#qrStatusFilter').val(),
      limit: 100,
    };
    $.get(APP + '/api/qr_codes.php', params, function (res) {
      if (!res.success) return;
      $('#qrCount').text(res.total + ' student(s)');

      const rows = res.data.map(function (s) {
        const name = escapeHtml(s.first_name + ' ' + s.last_name);
        const hasQR = s.qr_id ? true : false;
        // Nurses: View / Print / Deactivate / Reactivate only.
        // Admins: also Regenerate (admin-only) and Revoke (admin-only).
        const adminBtns = IS_ADMIN
          ? `<button class="btn btn-sm btn-outline-warning qr-regener" data-id="${s.student_id}" title="Regenerate"><i class="bi bi-arrow-repeat"></i></button>`
          : '';
        const revokeBtn = IS_ADMIN && hasQR
          ? `<button class="btn btn-sm btn-outline-danger qr-revoke" data-id="${s.student_id}" title="Revoke"><i class="bi bi-x-octagon"></i></button>`
          : '';
        const actions = hasQR
          ? `<div class="d-flex gap-1 flex-wrap">
               <button class="btn btn-sm btn-outline-primary qr-view" data-student='${JSON.stringify(s).replace(/'/g, '&#39;')}' title="View"><i class="bi bi-eye"></i></button>
               <button class="btn btn-sm btn-outline-secondary qr-print" data-student='${JSON.stringify(s).replace(/'/g, '&#39;')}' title="Print"><i class="bi bi-printer"></i></button>
               ${adminBtns}
               ${revokeBtn}
               ${s.qr_status === 'active'
                 ? `<button class="btn btn-sm btn-outline-danger qr-deact" data-id="${s.student_id}" title="Deactivate"><i class="bi bi-slash-circle"></i></button>`
                 : `<button class="btn btn-sm btn-outline-success qr-react" data-id="${s.student_id}" title="Reactivate"><i class="bi bi-check-circle"></i></button>`}
             </div>`
          : (IS_ADMIN
              ? `<button class="btn btn-sm btn-outline-success qr-gen" data-id="${s.student_id}" title="Generate QR"><i class="bi bi-qr-code me-1"></i> Generate</button>`
              : '<span class="text-muted small">No QR</span>');

        return [
          `<div class="d-flex align-items-center gap-2">
             ${name} ${s.qr_status === 'deactivated' ? '<span class="badge bg-danger">Deactivated</span>' : ''}
           </div>`,
          `<span class="small">${escapeHtml(s.student_number)}</span>`,
          `<span class="small">${escapeHtml(s.course || '—')} / ${escapeHtml(s.year_level || '—')}</span>`,
          statusBadge(s.qr_status),
          `<span class="small text-muted">${escapeHtml(s.generated_at || '—')}</span>`,
          `<span class="small text-muted">${escapeHtml(s.last_printed_at || '—')}</span>`,
          actions,
        ];
      });

      if (qrDt) qrDt.destroy();
      qrDt = $('#qrTable').DataTable({
        data: rows,
        columns: [null, null, null, null, null, null, { orderable: false, searchable: false }],
        pageLength: 10,
        order: [],
      });
    }, 'json');
  }

  $('#qrSearch').on('keyup', function () {
    clearTimeout(window.__qrSearchT);
    window.__qrSearchT = setTimeout(loadQrList, 300);
  });
  $('#qrStatusFilter').on('change', loadQrList);
  $('#qrRefreshBtn').on('click', function () {
    loadQrList();
    loadScanLogs();
    toastSuccess('Refreshed.');
  });

  // ============================================================
  // Load scan logs
  // ============================================================
  function loadScanLogs() {
    const params = { action: 'scan_logs', search: $('#scanLogSearch').val(), limit: 100 };
    $.get(APP + '/api/qr_codes.php', params, function (res) {
      if (!res.success) return;
      $('#scanLogCount').text(res.total + ' scan(s)');
      const rows = res.data.map(function (l) {
        const st = l.status === 'success'
          ? '<span class="badge bg-success">Success</span>'
          : l.status === 'duplicate'
            ? '<span class="badge bg-warning text-dark">Duplicate</span>'
            : l.status === 'invalid'
              ? '<span class="badge bg-danger">Invalid</span>'
              : '<span class="badge bg-secondary">' + escapeHtml(l.status) + '</span>';
        return [
          `<span class="small">${escapeHtml([l.first_name, l.last_name].filter(Boolean).join(' ')) || '—'}<br><span class="text-muted">${escapeHtml(l.student_number || '')}</span></span>`,
          `<span class="small">${escapeHtml(l.scan_time || '—')}</span>`,
          `<span class="small text-capitalize">${escapeHtml(l.purpose || 'check-in')}</span>`,
          `<span class="small">${escapeHtml(l.scanner_name || '—')}</span>`,
          st,
        ];
      });
      if (scanDt) scanDt.destroy();
      scanDt = $('#scanLogTable').DataTable({ data: rows, columns: [null, null, null, null, null], pageLength: 10, order: [] });
    }, 'json');
  }
  $('#scanLogSearch').on('keyup', function () {
    clearTimeout(window.__scanT);
    window.__scanT = setTimeout(loadScanLogs, 300);
  });

  // ============================================================
  // Generate a QR for a student who doesn't have one yet
  // ============================================================
  $(document).on('click', '.qr-gen', function () {
    const id = $(this).data('id');
    const btn = $(this);
    btn.html('<span class="spinner-border spinner-border-sm"></span>');
    $.post(APP + '/api/qr_codes.php', { action: 'regenerate', student_id: id, csrf_token: csrf }, function (res) {
      if (res.success) {
        toastSuccess('QR code generated.');
        loadQrList();
      } else {
        toastError(res.message);
        const el = $(btn);
        setTimeout(function () { el.html('<i class="bi bi-qr-code me-1"></i> Generate'); }, 100);
      }
    }, 'json');
  });

  // ============================================================
  // View QR preview (opens modal with qrcodejs)
  // ============================================================
  $(document).on('click', '.qr-view, .qr-print', function () {
    const data = $(this).attr('data-student');
    let s;
    try { s = JSON.parse(data); } catch (e) { s = null; }
    if (!s) { toastError('Invalid QR data.'); return; }
    openQrPreview(s, $(this).hasClass('qr-print'));
  });

  function openQrPreview(s, autoPrint) {
    currentQrStudent = s;
    // Fetch the latest payload for accuracy
    $.get(APP + '/api/qr_codes.php', { action: 'data', student_id: s.student_id }, function (res) {
      if (!res.success) { toastError(res.message); return; }
      const student = res.data.student;
      const qrData = res.data.qr_data;

      $('#qrPreviewBody').html(
        `<div class="mb-2">
           <div class="fw-bold">${escapeHtml(student.first_name + ' ' + student.last_name)}</div>
           <div class="text-muted small">${escapeHtml(student.student_number)}</div>
           <div class="text-muted small">${escapeHtml([student.course, student.year_level].filter(Boolean).join(' · '))}</div>
         </div>
         <div id="qrCanvas" class="d-inline-block p-3 bg-white border rounded-3"></div>
         <div class="small text-muted mt-2">BPC School Clinic • Student ID QR</div>`
      );

      if (window.QRCode) {
        $('#qrCanvas').empty();
        new QRCode(document.getElementById('qrCanvas'), {
          text: qrData,
          width: 220,
          height: 220,
          correctLevel: QRCode.CorrectLevel.M,
        });
      } else {
        $('#qrCanvas').html('<div class="text-danger">QR library not loaded.</div>');
      }

      $('#qrPreviewFooter').show();
      const modalEl = document.getElementById('qrPreviewModal');
      const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
      modal.show();

      // Mark as printed
      $.post(APP + '/api/qr_codes.php', { action: 'mark_printed', student_id: student.id, csrf_token: csrf }, function () {}, 'json');

      if (autoPrint) {
        setTimeout(function () { printQr(); }, 600);
      }
    }, 'json');
  }

  // Print QR
  function printQr() {
    const student = currentQrStudent;
    if (!student) return;
    const content = $('#qrPreviewBody')[0].outerHTML;
    const w = window.open('', '_blank');
    w.document.write(`
      <html><head><title>BPC Student QR</title>
      <style>body{font-family:Arial,sans-serif;text-align:center;padding:30px}
      img{max-width:300px}.muted{color:#777;font-size:12px;margin-top:8px}.brand{font-weight:bold;font-size:16px;margin-bottom:4px}</style>
      </head><body>
      <div class="brand">BPC School Clinic</div>
      <div class="muted">Bulacan Polytechnic College</div>
      <hr>
      <div>${$('#qrPreviewBody .fw-bold').text()}</div>
      <div class="muted">${$('#qrPreviewBody .text-muted').text()}</div>
      <div style="margin-top:15px">${$('#qrCanvas').find('img').length ? '<img src="' + $('#qrCanvas img').attr('src') + '">' : $('#qrCanvas').text()}</div>
      <div class="muted" style="margin-top:10px">Present this QR to the school clinic nurse for check-in, appointments, and medical records verification.</div>
      </body></html>`);
    w.document.close();
    w.focus();
    setTimeout(function () { w.print(); }, 400);
  }

  // Download QR
  $(document).on('click', '#qrDownloadBtn', function () {
    const img = $('#qrCanvas img').attr('src');
    if (!img) { toastError('No QR image.'); return; }
    const a = document.createElement('a');
    a.href = img;
    a.download = 'BPC_QR_' + (currentQrStudent ? currentQrStudent.student_number : 'student') + '.png';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
  });

  $('#qrPrintBtn').on('click', printQr);

  // ============================================================
  // Regenerate / Deactivate / Reactivate
  // ============================================================
  $(document).on('click', '.qr-regener', function () {
    const id = $(this).data('id');
    confirmAction('This will invalidate the student\'s current QR code. Continue?', function () {
      $.post(APP + '/api/qr_codes.php', { action: 'regenerate', student_id: id, csrf_token: csrf }, function (res) {
        if (res.success) { toastSuccess(res.message); loadQrList(); }
        else toastError(res.message);
      }, 'json');
    });
  });

  $(document).on('click', '.qr-deact', function () {
    const id = $(this).data('id');
    confirmAction('Deactivate this student\'s QR code? It will no longer work for check-in.', function () {
      $.post(APP + '/api/qr_codes.php', { action: 'deactivate', student_id: id, csrf_token: csrf }, function (res) {
        if (res.success) { toastSuccess(res.message); loadQrList(); }
        else toastError(res.message);
      }, 'json');
    });
  });

$(document).on('click', '.qr-react', function () {
    const id = $(this).data('id');
    $.post(APP + '/api/qr_codes.php', { action: 'reactivate', student_id: id, csrf_token: csrf }, function (res) {
      if (res.success) { toastSuccess(res.message); loadQrList(); }
      else toastError(res.message);
    }, 'json');
  });

  // ============================================================
  // Revoke (admin-only) — prompt for reason
  // ============================================================
  $(document).on('click', '.qr-revoke', function () {
    const id = $(this).data('id');
    Swal.fire({
      title: 'Revoke QR Code?',
      text: 'This will permanently invalidate the student\'s QR code. They will need to contact the clinic for a new one.',
      icon: 'warning',
      input: 'textarea',
      inputPlaceholder: 'Reason for revoking...',
      inputAttributes: { required: true },
      showCancelButton: true,
      confirmButtonColor: '#d33',
      confirmButtonText: 'Yes, revoke it',
      cancelButtonText: 'Cancel',
      preConfirm: (reason) => {
        if (!reason) { Swal.showValidationMessage('Please enter a reason'); }
        return reason;
      }
    }).then(function (result) {
      if (!result.isConfirmed) return;
      $.post(APP + '/api/qr_codes.php', {
        action: 'revoke',
        student_id: id,
        reason: result.value,
        csrf_token: csrf
      }, function (res) {
        if (res.success) { toastSuccess(res.message); loadQrList(); }
        else toastError(res.message);
      }, 'json');
    });
  });

// ============================================================
  // Initial load
  // ============================================================
  loadQrList();
  loadScanLogs();

  // ============================================================
  // Export Logs — CSV export of QR-related data
  // ============================================================
  let exportType = '';
  $('#qrExportMenu .dropdown-item').on('click', function () {
    exportType = $(this).data('type');
    $('#qrExportBar').show();
    toastSuccess('Select a date range and export.');
  });

  $('#qrExportRunBtn').on('click', function () {
    if (!exportType) { toastError('Select an export type first.'); return; }
    const from = $('#qrExportFrom').val();
    const to = $('#qrExportTo').val();
    const search = $('#qrExportSearch').val() || '';
    const url = APP + '/api/qr_codes.php?action=qr_export&type=' + exportType +
      '&from_date=' + from + '&to_date=' + to + '&search=' + encodeURIComponent(search);
    window.open(url, '_blank');
  });

  // ============================================================
  // Export Logs — Excel (client-side SheetJS from JSON rows)
  // ============================================================
  $('#qrExportExcelBtn').on('click', function () {
    if (!exportType) { toastError('Select an export type first.'); return; }
    if (!window.XLSX) { toastError('Excel library not loaded.'); return; }

    const from = $('#qrExportFrom').val();
    const to = $('#qrExportTo').val();
    const search = $('#qrExportSearch').val() || '';
    const btn = $(this);
    const original = btn.html();
    btn.html('<span class="spinner-border spinner-border-sm me-1"></span> Preparing Excel...');

    $.get(APP + '/api/qr_codes.php', {
      action: 'qr_export',
      type: exportType,
      format: 'json',
      from_date: from,
      to_date: to,
      search: search
    }, function (res) {
      btn.html(original);
      if (!res.success) { toastError(res.message || 'Export failed.'); return; }

      const rows = res.data || [];
      if (!rows.length) { toastError('No data found for the selected range.'); return; }

      const ws = XLSX.utils.json_to_sheet(rows);
      const wb = XLSX.utils.book_new();
      XLSX.utils.book_append_sheet(wb, ws, (res.type || exportType).slice(0, 31));
      const filename = res.filename || ('bpc_qr_' + exportType + '.xlsx');
      XLSX.writeFile(wb, filename);
      toastSuccess('Excel export downloaded.');
    }, 'json').fail(function () {
      btn.html(original);
      toastError('Network error during export.');
    });
  });
  })();
};
</script>


