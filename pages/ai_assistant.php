<?php
/**
 * pages/ai_assistant.php
 * ------------------------------------------------------------------
 * AI-Enhanced School Clinic Management System for Bulacan Polytechnic College
 * Healthcare Service Improvement
 *
 * MICA Clinic Intelligence & Automation Center.
 * Unified role-aware hub featuring:
 *  - AI Action Center (Suggest -> Confirm -> Execute -> Verify -> Log)
 *  - Smart Medicine Reorders (Review -> Edit -> Approve -> Print)
 *  - Daily Clinic Intelligence Summary & Trend Insights
 *  - Natural Language Clinic Assistant Console
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../config/config.php';
require_role(['admin', 'nurse', 'student', 'teacher']);
$db   = getDB();
$user = current_user();
$role = $user['role'] ?? 'student';

$pageTitle = 'MICA - Clinic Intelligence & Automation Center';

// Per-role quick actions.
// Each entry is [icon name, label, prompt|action, tone]. Icons resolve
// through bpc_icon() so the page uses the same professional SVG set as
// the rest of the application rather than emoji glyphs.
$quickActions = match ($role) {
    'nurse', 'doctor' => [
        ['package', 'Check Low Stock', 'Which medicines are low in stock?', 'text-danger'],
        ['clock', 'Expiring Medicines', 'Which medicines expire this month?', 'text-warning'],
        ['calendar', 'Tomorrow\'s Appointments', 'Show students with appointments tomorrow', 'text-primary'],
        ['trending-up', 'Clinic Visit Trends', 'How many clinic visits happened this week?', 'text-info'],
        ['shield-alert', 'Active Emergencies', 'Show active SOS emergencies', 'text-danger'],
        ['pill', 'Inventory Intelligence', 'Medicine inventory status', 'text-success'],
        ['activity', 'Run Intelligence Scan', '__scan__', 'text-primary'],
        ['chat', 'Voice Assistant', '__open__', 'text-primary'],
    ],
    'admin' => [
        ['users', 'Pending Registrations', 'Which accounts are pending approval?', 'text-warning'],
        ['bar-chart', 'Account Statistics', 'How many active users do we have?', 'text-primary'],
        ['trending-up', 'Clinic Demand Trends', 'How many clinic visits happened this week?', 'text-info'],
        ['shield-alert', 'Emergency Status Summary', 'Show active and recent SOS emergencies', 'text-danger'],
        ['package', 'Critical Inventory Alerts', 'Which medicines are low stock or expired?', 'text-danger'],
        ['activity', 'Run Intelligence Scan', '__scan__', 'text-primary'],
        ['chat', 'Voice Assistant', '__open__', 'text-primary'],
    ],
    'adviser' => [
        ['users', 'My Advisory Class', 'Show my advisory class', 'text-primary'],
        ['megaphone', 'Clinic Announcements', 'Latest clinic announcements', 'text-warning'],
        ['hospital', 'Clinic Hours & Info', 'What are the clinic operating hours?', 'text-info'],
        ['shield-alert', 'Emergency SOS', 'How does the clinic emergency SOS work?', 'text-danger'],
        ['chat', 'Voice Assistant', '__open__', 'text-primary'],
    ],
    default => [
        ['calendar', 'My Appointments', 'When is my next appointment?', 'text-primary'],
        ['heart-pulse', 'Health Records', 'Show my clinic health records', 'text-danger'],
        ['pill', 'Medicine Availability', 'May available ba na paracetamol?', 'text-success'],
        ['megaphone', 'Announcements', 'Latest clinic announcements', 'text-warning'],
        ['clock', 'Clinic Schedule', 'What are the clinic operating hours?', 'text-info'],
        ['shield-check', 'Vaccination Info', 'What vaccines are recorded for me?', 'text-primary'],
        ['shield-alert', 'SOS Emergency Guide', 'How to use the SOS emergency button?', 'text-danger'],
        ['chat', 'Voice Assistant', '__open__', 'text-primary'],
    ],
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php include __DIR__ . '/../includes/head.php'; ?>
</head>
<body>
<div class="app-wrapper">
  <?php include __DIR__ . '/../components/sidebar.php'; ?>
  <div class="main-content">
    <?php include __DIR__ . '/../components/navbar.php'; ?>
    <div class="content-area page-enter">

      <!-- Header Banner -->
      <div class="card border-0 shadow-sm rounded-4 mb-4 bg-white overflow-hidden" data-aos="fade-down">
        <div class="card-body p-4 d-flex justify-content-between align-items-center flex-wrap gap-3">
          <div class="d-flex align-items-center gap-3">
            <div class="rounded-circle d-flex align-items-center justify-content-center text-white flex-shrink-0"
                 style="width: 58px; height: 58px; background: linear-gradient(135deg, #0A4D28 0%, #22C55E 100%);">
              <?= bpc_icon('activity', '', ['size' => 26, 'label' => 'MICA']) ?>
            </div>
            <div>
              <h5 class="fw-bold mb-1">MICA Clinic Intelligence & Automation Hub</h5>
              <p class="text-muted small mb-0">
                AI-assisted decision support, predictive stock intelligence, and automated clinic workflows for BPC.
              </p>
            </div>
          </div>
          <div class="d-flex align-items-center gap-2 flex-wrap">
            <?php if (in_array($role, ['admin', 'nurse'], true)): ?>
              <button class="btn btn-outline-primary btn-sm" id="btnRunScan" onclick="triggerAutomationScan()">
                <i class="bi bi-cpu-fill me-1"></i> Run Intelligence Scan
              </button>
            <?php endif; ?>
            <button class="btn btn-primary btn-sm" onclick="MICA.open()">
              <i class="bi bi-chat-dots-fill me-1"></i> Open Assistant Chat
            </button>
          </div>
        </div>
      </div>

      <p id="micaScanMetadata" class="small text-muted" role="status" aria-live="polite">Scan history loads with the summary. Alerts reflect their last successful verification.</p>
      <!-- Navigation Tabs -->
      <ul class="nav app-pills mb-4" id="micaTabs" role="tablist">
        <li class="nav-item" role="presentation">
          <button class="nav-link active" id="tab-actions-btn" data-bs-toggle="pill" data-bs-target="#tab-actions" type="button" role="tab">
            <i class="bi bi-lightning-charge-fill me-1 text-warning"></i> AI Action Center
          </button>
        </li>
        <?php if ($role === 'nurse'): ?>
        <li class="nav-item" role="presentation">
          <button class="nav-link" id="tab-reorders-btn" data-bs-toggle="pill" data-bs-target="#tab-reorders" type="button" role="tab">
            <i class="bi bi-box-seam-fill me-1 text-info"></i> Smart Reorder Center
          </button>
        </li>
        <?php endif; ?>
        <li class="nav-item" role="presentation">
          <button class="nav-link" id="tab-summary-btn" data-bs-toggle="pill" data-bs-target="#tab-summary" type="button" role="tab">
            <i class="bi bi-file-earmark-bar-graph-fill me-1 text-success"></i> Daily Intelligence Summary
          </button>
        </li>
        <li class="nav-item" role="presentation">
          <button class="nav-link" id="tab-chat-btn" data-bs-toggle="pill" data-bs-target="#tab-chat" type="button" role="tab">
            <i class="bi bi-chat-quote-fill me-1 text-primary"></i> Natural Language Queries
          </button>
        </li>
      </ul>

      <!-- Tab Content Area -->
      <div class="tab-content" id="micaTabContent">

        <!-- TAB 1: AI ACTION CENTER -->
        <div class="tab-pane fade show active" id="tab-actions" role="tabpanel">
          <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
            <div>
              <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-shield-check text-success me-1"></i> Recommended Actions & Alerts</h6>
              <small class="text-muted">Proactive intelligence items requiring review or confirmation (Suggest, Confirm, Execute, Log).</small>
            </div>
            <label class="small">Alert history <select id="micaAlertScope" class="form-select form-select-sm" onchange="loadRecommendedActions()"><option value="active">Active issues</option><option value="archive">Archived issues</option></select></label>
            <button class="btn btn-sm btn-outline-secondary" onclick="loadRecommendedActions()">
              <i class="bi bi-arrow-clockwise me-1"></i> Refresh
            </button>
          </div>

          <div class="row g-3" id="actionCardsContainer">
            <div class="col-12 text-center py-5 text-muted">
              <div class="spinner-border spinner-border-sm text-primary mb-2" role="status"></div>
              <div>Loading real-time AI recommendations...</div>
            </div>
          </div>
        </div>

        <!-- TAB 2: SMART REORDER CENTER -->
        <?php if ($role === 'nurse'): ?>
        <div class="tab-pane fade" id="tab-reorders" role="tabpanel">
          <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
            <div>
              <h6 class="fw-bold mb-0"><i class="bi bi-cart-check-fill text-primary me-1"></i> Medicine Reorder Requisitions</h6>
              <small class="text-muted">Verified replenishment drafts use configured minimums and recorded consumption. Nurse confirmation is required.</small>
            </div>
            <div class="btn-group btn-group-sm">
              <button class="btn btn-outline-primary active" onclick="loadReorders('all', this)">All</button>
              <button class="btn btn-outline-primary" onclick="loadReorders('for_approval', this)">For Approval</button>
              <button class="btn btn-outline-primary" onclick="loadReorders('approved', this)">Approved</button>
            </div>
          </div>

          <div class="card border-0 shadow-sm rounded-4 p-3">
            <div class="table-responsive">
              <table class="table table-hover align-middle" id="reordersTable" style="width:100%">
                <thead class="table-light">
                  <tr>
                    <th>Req #</th>
                    <th>Medicine</th>
                    <th>Actual Stock</th>
                    <th>Suggested Replenishment</th>
                    <th>Reason / Justification</th>
                    <th>Status</th>
                    <th>Date</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody id="reordersTableBody">
                  <tr><td colspan="8" class="text-center py-4 text-muted">Loading reorders...</td></tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>
        <?php endif; ?>

        <!-- TAB 3: DAILY INTELLIGENCE SUMMARY -->
        <div class="tab-pane fade" id="tab-summary" role="tabpanel">
          <div class="row g-3 mb-4" id="summaryMetricsRow">
            <div class="col-12 text-center py-4 text-muted">Loading daily intelligence metrics...</div>
          </div>

          <div class="card border-0 shadow-sm rounded-4 p-4 mb-4">
            <h6 class="fw-bold mb-3"><i class="bi bi-card-checklist text-primary me-1"></i> MICA Executive Briefing</h6>
            <div id="executiveBriefingContent" class="text-muted small">
              Generating briefing from live database metrics...
            </div>
          </div>
        </div>

        <!-- TAB 4: NATURAL LANGUAGE QUERIES & QUICK PROMPTS -->
        <div class="tab-pane fade" id="tab-chat" role="tabpanel">
          <div class="card border-0 shadow-sm rounded-4 p-4 mb-4">
            <h6 class="fw-bold mb-2"><i class="bi bi-lightning-charge-fill text-warning me-1"></i> Interactive Quick Queries</h6>
            <p class="text-muted small mb-3">Click any quick prompt to query actual clinic database facts via MICA:</p>
            
            <div class="row g-3">
              <?php foreach ($quickActions as $qa): ?>
              <div class="col-6 col-md-4 col-lg-3">
                <button type="button" class="app-action app-action--tile w-100 h-100"
                     onclick="<?= $qa[2] === '__open__' ? 'MICA.open()' : ($qa[2] === '__scan__' ? 'triggerAutomationScan()' : "askMicaDirect('" . addslashes($qa[2]) . "')") ?>">
                  <span class="app-action__icon"><?= bpc_icon($qa[0], '', ['size' => 20, 'label' => '']) ?></span>
                  <span><?= e($qa[1]) ?></span>
                </button>
              </div>
              <?php endforeach; ?>
            </div>
          </div>

          <!-- Direct Query Console -->
          <div class="card border-0 shadow-sm rounded-4 p-4">
            <h6 class="fw-bold mb-3"><i class="bi bi-terminal-fill text-primary me-1"></i> Natural Language Database Console</h6>
            <div class="input-group mb-3">
              <input type="text" id="directQueryInput" class="form-control" placeholder="Ask MICA anything (e.g. Which medicines are low in stock? or Kailan ang checkup ko?)..." onkeypress="if(event.key==='Enter') submitDirectQuery()">
              <button class="btn btn-primary px-4" onclick="submitDirectQuery()"><i class="bi bi-send-fill me-1"></i> Ask MICA</button>
            </div>
            <div id="directQueryResponse" class="p-3 bg-light rounded-3 d-none border"></div>
          </div>
        </div>

      </div>

    </div>
  </div>
</div>

<!-- Modal: Approve Reorder -->
<div class="modal fade" id="approveReorderModal" tabindex="-1" aria-hidden="true" aria-labelledby="approveReorderModalTitle">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow">
      <div class="modal-header bg-success text-white">
        <h5 id="approveReorderModalTitle" class="modal-title"><i class="bi bi-check-circle-fill me-2"></i>Approve Reorder Requisition</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body p-4">
        <input type="hidden" id="modalReorderId">
        <div class="mb-3">
          <label class="form-label small fw-bold">Medicine</label>
          <input type="text" id="modalMedName" class="form-control bg-light" readonly>
        </div>
        <div class="mb-3">
          <label class="form-label small fw-bold">Approved Quantity to Purchase *</label>
          <input type="number" id="modalAppQty" class="form-control" min="1" required>
          <div class="form-text">Adjust quantity based on clinic budget or supplier pack size.</div>
        </div>
        <div class="mb-3">
          <label class="form-label small fw-bold">Notes / Purchase Remarks</label>
          <textarea id="modalAppNotes" class="form-control" rows="2" placeholder="e.g. For immediate procurement from accredited supplier"></textarea>
        </div>
      </div>
      <div class="modal-footer bg-light">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-success" id="btnConfirmApproveReorder" onclick="submitApproveReorder()">
          <i class="bi bi-check-lg me-1"></i> Confirm & Approve
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Modal: Print Reorder Slip -->
<div class="modal fade" id="printSlipModal" tabindex="-1" aria-hidden="true" aria-labelledby="printSlipModalTitle">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content border-0 shadow">
      <div class="modal-header bg-primary text-white">
        <h5 id="printSlipModalTitle" class="modal-title"><i class="bi bi-printer-fill me-2"></i>Medicine Purchase Requisition Slip</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body p-4" id="printableSlipArea">
        <div class="text-center mb-4 border-bottom pb-3">
          <h5 class="fw-bold mb-0">BULACAN POLYTECHNIC COLLEGE</h5>
          <div class="small text-muted">School Clinic & Health Services Department</div>
          <div class="small fw-semibold text-uppercase mt-2">Official Medicine Purchase Requisition</div>
        </div>
        <div id="slipContent" class="mb-4"></div>
        <div class="row text-center mt-5">
          <div class="col-6">
            <div class="border-top pt-2 small fw-bold">Prepared By / Clinic Staff</div>
          </div>
          <div class="col-6">
            <div class="border-top pt-2 small fw-bold">Approved By / Administrator</div>
          </div>
        </div>
      </div>
      <div class="modal-footer bg-light">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
        <button type="button" class="btn btn-primary" onclick="printRequisitionSlip()"><i class="bi bi-printer me-1"></i> Print Slip</button>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../components/footer.php'; ?>

<script>
const csrfToken = $('meta[name="csrf-token"]').attr('content');
const micaReorders = new Map();
const micaUserRole = <?= json_encode($role); ?>;

$(document).ready(function() {
  if (['admin','nurse'].includes(micaUserRole)) { loadRecommendedActions(); loadDailySummary(); }
  else { $('#tab-actions-btn,#tab-summary-btn').closest('li').hide(); $('#tab-actions').removeClass('show active'); $('#tab-chat').addClass('show active'); $('#tab-chat-btn').addClass('active'); }
  if (document.getElementById('reordersTableBody')) {
    loadReorders('all');
  }
  const linkedAlert=Number(new URLSearchParams(location.search).get('alert_id'));
  if(Number.isSafeInteger(linkedAlert) && linkedAlert>0)loadAlertDetail(linkedAlert);
});

setInterval(function(){if(!document.hidden && ['admin','nurse'].includes(micaUserRole)) {loadRecommendedActions();loadDailySummary();}},60000);

const micaActionCenterLocks = {
  recommended: null,
  summary: null,
  reorder: null
};

function actionStatusLabel(status) {
  const value = (status || 'new').toString();
  if (value === 'in_progress' || value === 'in-progress') return 'Monitoring';
  if (value === 'resolved') return 'Resolved';
  return 'Active';
}

function showActionMonitoring(title, description, reason, status) {
  const summary = [
    'Status: ' + actionStatusLabel(status),
    'Details: ' + (description || 'No additional detail recorded.'),
    'Reason: ' + (reason || 'No additional reason provided.')
  ].join('\n\n');

  if (window.Swal) {
    Swal.fire({
      title: title || 'Alert Details',
      text: summary,
      icon: 'info',
      confirmButtonText: 'Close'
    });
    return;
  }

  alert(summary);
}

function renderMicaLoadError(target, message) {
  if (!target || !target.length) return;
  target.html('<div class="col-12 py-4 text-center"><div class="empty-state"><div class="empty-state__icon"><i class="bi bi-exclamation-triangle text-warning"></i></div><h6 class="empty-state__title">Unable to load clinic intelligence.</h6><p class="empty-state__text">' + escapeHtml(message || 'Please try again.') + '</p><button type="button" class="btn btn-sm btn-primary mt-2" onclick="location.reload()">Retry</button></div></div>');
}

function loadRecommendedActions() {
  const container = $('#actionCardsContainer');
  if (micaActionCenterLocks.recommended && micaActionCenterLocks.recommended.readyState !== 4) {
    micaActionCenterLocks.recommended.abort();
  }
  container.html('<div class="col-12 text-center py-4 text-muted"><div class="spinner-border spinner-border-sm text-primary mb-2"></div><div>Analyzing live clinic records...</div></div>');

  micaActionCenterLocks.recommended = $.ajax({
    url: APP_URL + '/api/ai_assistant.php',
    method: 'GET',
    dataType: 'json',
    timeout: 20000,
    data: { action: $('#micaAlertScope').val() === 'archive' ? 'archived_actions' : 'recommended_actions' }
  });

  micaActionCenterLocks.recommended.done(function(res) {
    if (res && res.success && Array.isArray(res.data) && res.data.length > 0) {
      let html = '';
      res.data.forEach(a => {
        let badgeClass = 'app-badge--info';
        if (a.priority === 'critical') badgeClass = 'app-badge--danger';
        else if (a.priority === 'high') badgeClass = 'app-badge--warning';
        else if (a.priority === 'medium') badgeClass = 'app-badge--primary';

        const statusBadgeClass = (a.status === 'resolved') ? 'bg-success-subtle text-success' : ((a.status === 'in_progress' || a.status === 'in-progress') ? 'bg-warning-subtle text-warning' : 'bg-primary-subtle text-primary');
        const monitorText = micaUserRole === 'admin'
          ? 'Monitoring only â€” MICA auto-tracks this alert.'
          : 'Operational workflow continues in the relevant clinic module.';

        html += `
          <div class="col-md-6 col-lg-4" id="app-action-${a.id}">
            <div class="app-card">
              <div class="app-card__body">
                <div class="d-flex justify-content-between align-items-center mb-2 gap-2">
                  <span class="app-badge ${badgeClass}">${escapeHtml(a.priority === "informational" ? "low" : a.priority)}</span>
                  <span class="badge ${statusBadgeClass}">${escapeHtml(a.issue_status || actionStatusLabel(a.status))}</span>
                </div>
                <small class="text-muted">${a.read_at ? "Read" : "Unread"} · ${escapeHtml(a.target_type)} ${escapeHtml(a.target_id || "group")}</small>
                <h6 class="app-card__title">${escapeHtml(a.title)}</h6>
                <p class="text-secondary small mb-2">${escapeHtml(a.description)}</p>
                <p class="small mb-2"><strong>Next:</strong> Review the verified evidence and follow up in the responsible module.</p>
                <div class="app-info-strip mb-3">
                  <i class="bi bi-info-circle" aria-hidden="true"></i>
                  <span><strong>Reason:</strong> ${escapeHtml(a.reason || 'No additional reason recorded.')}</span>
                </div>
              </div>
              <div class="app-card__foot flex-column align-items-stretch">
                <div class="d-flex justify-content-between align-items-center gap-2 mb-2">
                  <small class="text-muted">${timeAgo(a.created_at)}</small>
                  <small class="text-muted">${escapeHtml(monitorText)}</small>
                </div>
                <div class="d-flex gap-2">
                  <button class="btn btn-sm btn-outline-primary flex-fill" onclick="loadAlertDetail(${Number(a.id)})">
                    <i class="bi bi-eye me-1"></i> View Details
                  </button>
                </div>
              </div>
            </div>
          </div>
        `;
      });
      container.html(html);
      return;
    }

    if (res && res.success && Array.isArray(res.data) && res.data.length === 0) {
      container.html(`
        <div class="col-12 py-5">
          <div class="empty-state">
            <div class="empty-state__icon">${icon('check2-circle')}</div>
            <h6 class="empty-state__title">No actions needed.</h6>
            <p class="empty-state__text">No active alerts are visible for your role. Check the last successful scan time.</p>
          </div>
        </div>
      `);
      return;
    }

    renderMicaLoadError(container, (res && res.message) || 'Unable to load clinic intelligence. Please try again.');
  }).fail(function(xhr) {
    const msg = xhr.status === 401 ? 'Your session expired. Please log in again.' : (xhr.status === 403 ? 'This monitoring view is not available for your account.' : 'Unable to load clinic intelligence. Please try again.');
    renderMicaLoadError(container, msg);
  }).always(function() {
    micaActionCenterLocks.recommended = null;
  });
}

function loadAlertDetail(id) {
  $.ajax({url: APP_URL+'/api/ai_assistant.php', method:'POST', dataType:'json',
    data:{action:'read_alert',action_id:id,csrf_token:csrfToken}}).done(function(res) {
    if(!res.success || !res.alert) { toastError(res.message || 'Alert unavailable.'); return; }
    const a=res.alert;
    const events=(a.events||[]).map(e=>'<li>'+escapeHtml(e.created_at)+' — '+escapeHtml(e.event_type)+': '+escapeHtml(e.details)+'</li>').join('');
    const body='<p><strong>'+escapeHtml(a.priority)+' · '+escapeHtml(a.issue_status || actionStatusLabel(a.status))+'</strong></p><p>'+escapeHtml(a.description)+'</p><p>Related: '+escapeHtml(a.target_type)+' '+escapeHtml(a.target_id || 'grouped records')+'</p><p>Detected: '+escapeHtml(a.created_at)+'</p><p><strong>Verified reason:</strong> '+escapeHtml(a.reason)+'</p><p>'+escapeHtml(a.evidence_note)+'</p><p><strong>Recommendation:</strong> Review in the responsible module. Reading does not resolve this issue.</p><p><a href="'+escapeHtml(a.navigation_url)+'">Open responsible module</a></p>'+(events?'<details><summary>View recorded progress</summary><ol>'+events+'</ol></details>':'<p>No recorded progress history.</p>');
    loadRecommendedActions();
    if(window.Swal) Swal.fire({titleText:a.title,html:body,width:680,confirmButtonText:'Close'});
    else alert(a.title+'\n'+a.description+'\n'+a.reason);
  }).fail(function(){toastError('Unable to load alert details.');});
}

function loadDailySummary() {
  if (micaActionCenterLocks.summary && micaActionCenterLocks.summary.readyState !== 4) {
    micaActionCenterLocks.summary.abort();
  }

  const summaryRow = $('#summaryMetricsRow');
  const briefBox = $('#executiveBriefingContent');
  summaryRow.html('<div class="col-12 text-center py-4 text-muted"><div class="spinner-border spinner-border-sm text-primary mb-2"></div><div>Loading daily clinic summary...</div></div>');

  micaActionCenterLocks.summary = $.ajax({
    url: APP_URL + '/api/ai_assistant.php',
    method: 'GET',
    dataType: 'json',
    timeout: 20000,
    data: { action: 'daily_summary' }
  });

  micaActionCenterLocks.summary.done(function(res) {
    if(res && !res.last_scan) $('#micaScanMetadata').text('No successful scan recorded. Run an intelligence scan to verify monitored sources.');
    if(res && res.last_scan) $('#micaScanMetadata').text('Last successful scan: '+res.last_scan.completed_at+' (Asia/Manila); duration '+res.last_scan.duration_ms+' ms.');
    if(res && res.last_attempt && res.last_attempt.status !== 'success') $('#micaScanMetadata').append(' Latest attempt: '+res.last_attempt.status+'. Displayed evidence may be stale.');
    if (res && res.success && res.metrics) {
      const m = res.metrics;
      if(m.profile==='mis') {
        const sys=m.system;const counts=sys.accounts;
        summaryRow.html(Object.entries(counts).map(([key,value])=>'<div class="col-6 col-md-3"><div class="card p-3"><strong>'+escapeHtml(value)+'</strong><span>'+escapeHtml(key)+'</span></div></div>').join(''));
        briefBox.text('Database: '+(sys.database_connected?'connected':'unavailable')+'; failed sign-ins (24h): '+sys.failed_logins_24h+'; latest registered backup: '+(sys.last_backup?.status || 'not recorded')+'. Updated '+sys.generated_at);return;
      }
      const metric = value => value === null || value === undefined ? 'Unavailable' : escapeHtml(value);
      const metrics=[['Consultations today',m.today_visits],['Consultations yesterday',m.yesterday_visits],['Waiting in queue',m.waiting_queue],['Active SOS',m.active_emergencies],['Pending account reviews',m.pending_registrations],['Low-stock medicines',m.low_stock_medicines],['Expired medicines',m.expired_medicines],['Expiring within 30 days',m.expiring_medicines_30d]];
      summaryRow.html(metrics.map(([label,value])=>'<div class="col-6 col-md-3"><div class="card border-0 shadow-sm p-3"><strong class="fs-4">'+metric(value)+'</strong><span class="small text-muted">'+label+'</span></div></div>').join(''));
      const brief='<p>Verified database counts as of '+escapeHtml(m.generated_at)+' (Asia/Manila).</p><p>Unavailable values are outside your access or could not be retrieved. Consultations count recorded encounters, not unique patients. The nurse handles clinical follow-up; Administration reviews accounts.</p>';
      briefBox.html(brief);
      return;
    }

    summaryRow.html('<div class="col-12 text-center py-4 text-muted">Daily summary is unavailable right now.</div>');
    briefBox.html('<div class="text-warning">Unable to load the live clinic briefing from the database.</div>');
  }).fail(function(xhr) {
    const msg = xhr.status === 401 ? 'Your session expired. Please log in again.' : 'Unable to load clinic intelligence. Please try again.';
    summaryRow.html('<div class="col-12 text-center py-4 text-muted">' + escapeHtml(msg) + '</div>');
    briefBox.html('<div class="text-warning">' + escapeHtml(msg) + '</div>');
  }).always(function() {
    micaActionCenterLocks.summary = null;
  });
}

function loadReorders(status, btn) {
  if (btn) {
    $(btn).addClass('active').siblings().removeClass('active');
  }
  if (micaActionCenterLocks.reorder && micaActionCenterLocks.reorder.readyState !== 4) {
    micaActionCenterLocks.reorder.abort();
  }

  const tbody = $('#reordersTableBody');
  tbody.html('<tr><td colspan="8" class="text-center py-4 text-muted"><div class="spinner-border spinner-border-sm text-primary mb-1"></div><div>Fetching reorders...</div></td></tr>');

  micaActionCenterLocks.reorder = $.ajax({
    url: APP_URL + '/api/ai_assistant.php',
    method: 'GET',
    dataType: 'json',
    timeout: 20000,
    data: { action: 'reorder_list', status: status }
  });

  micaActionCenterLocks.reorder.done(function(res) {
    if (res && res.success && Array.isArray(res.data) && res.data.length > 0) {
      let html = '';
      res.data.forEach(r => {
        micaReorders.set(Number(r.id),r);
        let statusBadge = (r.status === 'for_approval')
          ? '<span class="badge bg-warning text-dark">For Approval</span>'
          : (r.status === 'approved' ? '<span class="badge bg-success">Approved</span>' : '<span class="badge bg-danger">Rejected</span>');

        let actions = '';
        if (r.status === 'for_approval') {
          actions = `
            <button class="btn btn-sm btn-success" onclick="approveCachedReorder(${Number(r.id)})">
              <i class="bi bi-check-lg"></i> Approve
            </button>
            <button class="btn btn-sm btn-outline-danger" onclick="rejectReorder(${r.id})">
              <i class="bi bi-x-lg"></i>
            </button>
          `;
        } else if (r.status === 'approved') {
          actions = `
            <button class="btn btn-sm btn-primary" onclick="printCachedReorder(${Number(r.id)})">
              <i class="bi bi-printer"></i> Print Slip
            </button>
          `;
        }

        html += `
          <tr>
            <td>#${r.id}</td>
            <td><strong>${escapeHtml(r.medicine_name)}</strong><br><small class="text-muted">${escapeHtml(r.generic_name || '')}</small></td>
            <td>${r.actual_stock} ${escapeHtml(r.unit || 'pcs')}</td>
            <td><strong>${r.approved_quantity || r.suggested_quantity}</strong> ${escapeHtml(r.unit || 'pcs')}</td>
            <td><small>${escapeHtml(r.reason)}</small></td>
            <td>${statusBadge}</td>
            <td><small>${formatDateStr(r.created_at)}</small></td>
            <td>${actions}</td>
          </tr>
        `;
      });
      tbody.html(html);
      return;
    }

    tbody.html('<tr><td colspan="8" class="text-center py-4 text-muted">No reorder requisitions found.</td></tr>');
  }).fail(function(xhr) {
    const msg = xhr.status === 401 ? 'Your session expired. Please log in again.' : 'Reorder requests are temporarily unavailable.';
    tbody.html('<tr><td colspan="8" class="text-center py-4 text-muted">' + escapeHtml(msg) + '</td></tr>');
  }).always(function() {
    micaActionCenterLocks.reorder = null;
  });
}

function approveCachedReorder(id) { const r=micaReorders.get(id); if(r) openApproveModal(id,r.medicine_name,r.suggested_quantity); }
function printCachedReorder(id) { const r=micaReorders.get(id); if(r) openPrintSlip(r); }

function openApproveModal(id, medName, suggestedQty) {
  $('#modalReorderId').val(id);
  $('#modalMedName').val(medName);
  $('#modalAppQty').val(suggestedQty);
  $('#modalAppNotes').val('');
  BPCModal.show('approveReorderModal');
}

function submitApproveReorder() {
  const id = $('#modalReorderId').val();
  const qty = $('#modalAppQty').val();
  const notes = $('#modalAppNotes').val();

  if (qty < 1) {
    alert('Please enter a valid approved quantity.');
    return;
  }

  $('#btnConfirmApproveReorder').prop('disabled', true).text('Approving...');
  $.post(APP_URL + '/api/ai_assistant.php', {
    action: 'approve_reorder',
    reorder_id: id,
    approved_quantity: qty,
    notes: notes,
    csrf_token: csrfToken
  }, function(res) {
    $('#btnConfirmApproveReorder').prop('disabled', false).html('<i class="bi bi-check-lg me-1"></i> Confirm & Approve');
    if (res.success) {
      toastSuccess(res.message);
      BPCModal.hide('approveReorderModal');
      loadReorders('all');
      loadRecommendedActions();
    } else {
      toastError(res.message);
    }
  }, 'json');
}

function rejectReorder(id) {
  confirmAction('Reject this medicine reorder requisition?', function() {
    $.post(APP_URL + '/api/ai_assistant.php', {
      action: 'reject_reorder',
      reorder_id: id,
      csrf_token: csrfToken
    }, function(res) {
      if (res.success) {
        toastSuccess(res.message);
        loadReorders('all');
        loadRecommendedActions();
      } else {
        toastError(res.message);
      }
    }, 'json');
  });
}

function openPrintSlip(r) {
  const slip = `
    <div class="row g-2 mb-3">
      <div class="col-6"><strong>Requisition ID:</strong> REQ-${r.id}</div>
      <div class="col-6 text-end"><strong>Date Approved:</strong> ${formatDateStr(r.approved_at || r.created_at)}</div>
      <div class="col-6"><strong>Medicine Item:</strong> ${escapeHtml(r.medicine_name)} (${escapeHtml(r.generic_name || '')})</div>
      <div class="col-6 text-end"><strong>Stock at Request:</strong> ${r.current_stock} ${escapeHtml(r.unit || 'pcs')}</div>
      <div class="col-6"><strong>Approved Quantity:</strong> <span class="fs-5 fw-bold text-success">${r.approved_quantity || r.suggested_quantity} ${escapeHtml(r.unit || 'pcs')}</span></div>
      <div class="col-6 text-end"><strong>Status:</strong> APPROVED</div>
    </div>
    <div class="p-3 bg-light rounded-3 mb-2">
      <strong>Justification / Reason:</strong><br>
      ${escapeHtml(r.reason)}
      ${r.notes ? '<br><strong>Procurement Notes:</strong> ' + escapeHtml(r.notes) : ''}
    </div>
  `;
  $('#slipContent').html(slip);
  BPCModal.show('printSlipModal');
}

function printRequisitionSlip() {
  window.print();
}

let micaScanRunning = false;
function triggerAutomationScan() {
  if (micaScanRunning) return;
  micaScanRunning = true;
  const btn = $('#btnRunScan');
  btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> Scanning...');

  $.ajax({
    url: APP_URL + '/api/ai_assistant.php',
    method: 'POST',
    dataType: 'json',
    timeout: 60000,
    data: { action: 'run_automation', csrf_token: csrfToken }
  }).done(function(res) {
    btn.prop('disabled', false).html('<i class="bi bi-cpu-fill me-1"></i> Run Intelligence Scan');
    if (res && res.success) {
      toastSuccess(res.message || 'Scan completed successfully.');
      const r=res.data||{}; $('#micaScanMetadata').text('Scan complete: '+r.evaluated+' issues detected; '+r.new+' new, '+r.updated+' updated, '+r.resolved+' resolved, '+r.invalidated+' invalidated; '+r.duration_ms+' ms.');
      loadRecommendedActions();
      loadDailySummary();
      if (document.getElementById('reordersTableBody')) {
        loadReorders('all');
      }
    } else {
      toastError((res && res.message) || 'Scan completed with no actionable result.');
    }
  }).fail(function(xhr) {
    btn.prop('disabled', false).html('<i class="bi bi-cpu-fill me-1"></i> Run Intelligence Scan');
    const msg = xhr.responseJSON?.message || (xhr.status === 401 ? 'Your session expired. Please log in again.' : 'Scan did not complete in this request. Check the latest scan status before retrying.');
    toastError(msg);
  }).always(function(){micaScanRunning=false;});
}

function askMicaDirect(text) {
  $('#directQueryInput').val(text);
  submitDirectQuery();
}

function renderMicaMarkdown(text) {
  const source = String(text || '').trim();
  if (!source) {
    return '<p class="mb-0 text-muted">No answer available.</p>';
  }


  const formatInline = function(value) {
    let out = escapeHtml(value)
      .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
      .replace(/\*(.+?)\*/g, '<em>$1</em>')
      .replace(/`([^`]+)`/g, '<code>$1</code>')
      .replace(/\[(.+?)\]\((https?:\/\/[^\s)]+)\)/g, '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>');
    return out.replace(/\n/g, '<br>');
  };

  const blocks = source.split(/\n\s*\n+/).filter(Boolean);
  let html = '';

  blocks.forEach(function(block) {
    const lines = block.split(/\n/).map(function(line) {
      return line.trim();
    }).filter(Boolean);

    if (lines.length && lines.every(function(line) {
      return /^[-*]\s+/.test(line) || /^\d+\.\s+/.test(line);
    })) {
      const items = lines.map(function(line) {
        return '<li>' + formatInline(line.replace(/^(?:[-*]|\d+\.)\s+/, '')) + '</li>';
      }).join('');
      html += '<ul class="mb-2 ps-3">' + items + '</ul>';
      return;
    }

    html += '<p class="mb-2">' + formatInline(block) + '</p>';
  });

  return html;
}

function submitDirectQuery() {
  const input = $('#directQueryInput');
  const query = $.trim(input.val());
  if (!query) return;

  const respBox = $('#directQueryResponse');
  respBox.removeClass('d-none').html('<div class="text-muted"><div class="spinner-border spinner-border-sm text-primary me-2"></div>Consulting MICA live database...</div>');

  $.ajax({
    url: APP_URL + '/api/ai_assistant.php',
    method: 'POST',
    dataType: 'json',
    timeout: 20000,
    data: {
      action: 'chat',
      message: query,
      csrf_token: csrfToken
    }
  }).done(function(res) {
    if (res && res.success) {
      const reply = res.reply || 'No answer available.';
      const source = escapeHtml(res.source || 'grounded');
      respBox.html('<div class="d-flex align-items-center gap-2 mb-2"><span class="badge bg-primary">MICA Intelligence</span> <small class="text-muted">' + source + '</small></div>' + renderMicaMarkdown(reply));
    } else {
      respBox.html('<div class="text-danger"><strong>MICA request failed.</strong><br>' + escapeHtml(res && res.message ? res.message : 'Error executing query.') + '</div>');
    }
  }).fail(function(xhr) {
    let msg = 'Unable to complete this MICA request right now.';
    if (xhr.status === 401) {
      msg = 'Your session expired. Please log in again.';
    } else if (xhr.status === 403) {
      msg = 'This request is not permitted for your account.';
    } else if (xhr.status === 429) {
      msg = 'Too many requests. Please wait a moment and try again.';
    } else if (xhr.status === 0) {
      msg = 'Connection failed. Please check your internet connection.';
    } else if (xhr.status >= 500) {
      msg = 'MICA server is unavailable. Please try again shortly.';
    }
    respBox.html('<div class="text-danger"><strong>MICA request failed.</strong><br>' + escapeHtml(msg) + '</div>');
  });
}

function escapeHtml(str) {
  return String(str ?? '').replace(/[&<>"']/g, function (c) {
    return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
  });
}

function timeAgo(dateStr) {
  if (!dateStr) return '';
  const d = new Date(dateStr);
  return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
}

function formatDateStr(dateStr) {
  if (!dateStr) return 'â€”';
  const d = new Date(dateStr);
  return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
}
</script>
</body>
</html>
