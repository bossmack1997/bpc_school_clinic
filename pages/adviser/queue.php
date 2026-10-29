<?php
/**
 * pages/adviser/queue.php
 * =====================================================================
 * Teacher / Faculty Portal â€” Live Clinic Queue Monitor & Check-In
 * AI-Enhanced School Clinic Management System for Bulacan Polytechnic College Healthcare Service Improvement
 * =====================================================================
 */
require_once __DIR__ . '/../../config/config.php';
require_role('teacher');
require_once __DIR__ . '/../../includes/clinic_availability.php';
$db = getDB();
$u = current_user();

$stmt = $db->prepare("SELECT * FROM teachers WHERE user_id = ?");
$stmt->execute([$u['id']]);
$teacher = $stmt->fetch();
$clinicAvailability=clinic_availability($db);

$pageTitle = 'Faculty Clinic Queue';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php include __DIR__ . '/../../includes/head.php'; ?>
</head>
<body>
<div class="app-wrapper">
  <?php include __DIR__ . '/../../components/sidebar.php'; ?>
  <div class="main-content">
    <?php include __DIR__ . '/../../components/navbar.php'; ?>
    <div class="content-area page-enter">

      <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
          <h4 class="fw-bold mb-1" style="color: var(--text-color, #1E293B);">
            <i class="bi bi-people-fill text-success me-2"></i> Faculty Clinic Queue Tracker
          </h4>
          <p class="text-muted small mb-0">Check live queue waiting times or get a ticket for clinic consultation / first aid.</p>
        </div>
        <div class="d-flex gap-2">
          <a href="my_qr.php" class="btn btn-outline-success">
            <i class="bi bi-qr-code-scan me-1"></i> My Staff QR Pass
          </a>
        </div>
      </div>

      <!-- Urgent Called Alert -->
      <div id="calledUrgentBox" class="app-alert-banner mb-4" style="display: none;">
        <i class="bi bi-bell-fill" aria-hidden="true"></i>
        <div class="flex-grow-1">
          <div class="app-alert-banner__title">Queue number is being called</div>
          <div class="app-alert-banner__text">Please proceed immediately to <strong>Station 1 (Nurse Consultation Room)</strong>.</div>
        </div>
        <button class="btn btn-light fw-semibold px-3" onclick="dismissCalledAlert()">
          <i class="bi bi-check2-circle me-1"></i> I'm On My Way
        </button>
      </div>

      <!-- Active Ticket Section -->
      <div id="queueAvailabilityNotice" class="alert alert-info" role="status"><?=e($clinicAvailability['reason'].' Clinic hours: '.$clinicAvailability['hours'].'. Use SOS for emergencies.')?></div>
      <div id="activeTicketContainer">
        <div class="card p-5 text-center shadow-sm border-0 mb-4" id="ticketLoadingState">
          <div class="spinner-border text-success mb-3" role="status"></div>
          <div class="text-muted fw-semibold">Checking queue status...</div>
        </div>
      </div>

      <!-- Live Clinic Overview Cards -->
      <div class="row g-3">
        <div class="col-md-4">
          <div class="card p-3 shadow-sm border-0 h-100">
            <div class="d-flex align-items-center gap-3">
              <div class="rounded-circle bg-success bg-opacity-10 text-success p-3 fs-3">
                <i class="bi bi-megaphone-fill"></i>
              </div>
              <div>
                <div class="text-muted small fw-semibold">NOW SERVING</div>
                <div class="fs-3 fw-bold text-success" id="overviewNowServing">---</div>
              </div>
            </div>
          </div>
        </div>

        <div class="col-md-4">
          <div class="card p-3 shadow-sm border-0 h-100">
            <div class="d-flex align-items-center gap-3">
              <div class="rounded-circle bg-warning bg-opacity-10 text-warning p-3 fs-3">
                <i class="bi bi-clock-history"></i>
              </div>
              <div>
                <div class="text-muted small fw-semibold">TOTAL WAITING</div>
                <div class="fs-3 fw-bold text-warning" id="overviewWaiting">0</div>
              </div>
            </div>
          </div>
        </div>

        <div class="col-md-4">
          <div class="card p-3 shadow-sm border-0 h-100">
            <div class="d-flex align-items-center gap-3">
              <div class="rounded-circle bg-primary bg-opacity-10 text-primary p-3 fs-3">
                <i class="bi bi-hospital-fill"></i>
              </div>
              <div>
                <div class="text-muted small fw-semibold">CLINIC STATUS</div>
                <div class="fs-5 fw-bold text-primary"><span class="badge <?= $clinicAvailability['is_open']?'bg-success':'bg-danger' ?>"><?= e(strtoupper($clinicAvailability['label'])) ?></span></div>
              </div>
            </div>
          </div>
        </div>
      </div>

    </div>
  </div>
</div>

<!-- MODAL: Join Queue / Self Check-In -->
<div class="modal fade" id="teacherCheckinModal" tabindex="-1" aria-labelledby="teacherCheckinModalTitle">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow">
      <div class="modal-header bg-success text-white">
        <h5 id="teacherCheckinModalTitle" class="modal-title fw-bold"><i class="bi bi-ticket-detailed-fill me-2"></i> Faculty Clinic Check-In</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form id="teacherCheckinForm" onsubmit="submitTeacherCheckin(event)">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
        <input type="hidden" name="patient_type" value="teacher">
        <div class="modal-body p-4">
          <div class="alert alert-light border d-flex align-items-center gap-2 mb-3">
            <i class="bi bi-info-circle-fill text-success fs-5"></i>
            <div class="small">Faculty & Staff walk-in consultations are prioritized based on clinical assessment.</div>
          </div>

          <div class="mb-3">
            <label class="form-label fw-semibold">Purpose of Visit / Chief Complaint <span class="text-danger">*</span></label>
            <textarea class="form-control" name="chief_complaint" rows="3" maxlength="220" required placeholder="e.g. Blood pressure check, headache, first aid, medication request..."></textarea>
          </div>

          <div class="mb-3">
            <label class="form-label fw-semibold">Urgency Level</label><p class="small text-muted">Patient urgency is preliminary; a Nurse confirms clinical priority. Use SOS immediately for emergencies.</p><div class="form-check"><input class="form-check-input" type="radio" name="priority" id="patientEmergencyWarning" value="emergency"><label class="form-check-label" for="patientEmergencyWarning">Emergency Warning</label></div>
            <div class="d-flex gap-2">
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="radio" name="priority" id="tPrioNormal" value="normal" checked>
                <label class="form-check-label text-success fw-bold" for="tPrioNormal">Routine</label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="radio" name="priority" id="tPrioUrgent" value="urgent">
                <label class="form-check-label text-warning fw-bold" for="tPrioUrgent">Priority Review</label>
              </div>
            </div>
          </div>
        </div>
        <div class="modal-footer bg-light">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-success fw-bold">
            <i class="bi bi-check2-circle me-1"></i> Join Queue
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../../components/footer.php'; ?>
<script>
  let lastTicketStatus = null;
  let isTeacherPolling = false;
  let teacherPollTimer = null;

  async function pollTeacherQueue() {
    if (isTeacherPolling) return;
    isTeacherPolling = true;
    try {
      const res = await fetch('<?= APP_URL ?>/api/queue.php?action=get_status');
      const json = await res.json();
      if (!json.success) return;

      const d = json.data;
      window.patientQueueAvailability = d.availability;
      const notice = document.getElementById('queueAvailabilityNotice');
      if (notice && d.availability) notice.textContent = `${d.availability.reason} Clinic hours: ${d.availability.hours}. Use SOS for emergencies.`;
      document.getElementById('overviewNowServing').innerText = d.now_serving ? d.now_serving.queue_number : 'None';
      document.getElementById('overviewWaiting').innerText = d.total_waiting || 0;
      renderTeacherTicket(d.my_ticket);
    } catch (err) {
      console.error('Queue poll error:', err);
    } finally {
      isTeacherPolling = false;
    }
  }

  function renderTeacherTicket(ticket) {
    const container = document.getElementById('activeTicketContainer');
    if (!ticket) {
      container.innerHTML = `
        <div class="card p-4 p-md-5 text-center shadow-sm border-0 mb-4">
          <div class="mb-3">
            <div class="rounded-circle bg-success bg-opacity-10 text-success d-inline-flex align-items-center justify-content-center p-3 fs-1" style="width: 80px; height: 80px;">
              <i class="bi bi-ticket-perforated"></i>
            </div>
          </div>
          <h4 class="fw-bold mb-2">No Active Queue Ticket</h4>
          <p class="text-muted mx-auto mb-4" style="max-width: 480px;">
            Visiting the clinic today? You can join the queue directly from your portal or scan your Staff QR Pass at the clinic desk.
          </p>
          <div class="d-flex justify-content-center gap-2 flex-wrap">
            <button class="btn btn-success btn-lg px-4 fw-bold" data-bs-toggle="modal" data-bs-target="#teacherCheckinModal" ${window.patientQueueAvailability?.is_open ? '' : 'disabled'}>
              <i class="bi bi-plus-circle-fill me-1"></i> Join Queue
            </button>
            <a href="my_qr.php" class="btn btn-outline-success btn-lg px-4">
              <i class="bi bi-qr-code-scan me-1"></i> View Staff QR
            </a>
          </div>
        </div>
      `;
      document.getElementById('calledUrgentBox').style.display = 'none';
      lastTicketStatus = null;
      return;
    }

    if (ticket.status === 'called' && lastTicketStatus !== 'called') {
      document.getElementById('calledUrgentBox').style.display = 'block';
      if (typeof showToast === 'function') {
        showToast(`NOW SERVING: Your ticket ${ticket.queue_number} is being called!`, 'danger');
      }
    } else if (ticket.status !== 'called') {
      document.getElementById('calledUrgentBox').style.display = 'none';
    }
    lastTicketStatus = ticket.status;

    const currStep = ticket.status === 'waiting' ? 2 : (ticket.status === 'called' ? 3 : (ticket.status === 'serving' ? 4 : 5));

    container.innerHTML = `
      <div class="app-queue-hero mb-4">
        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
          <div>
            <span class="app-badge" style="background:rgba(255,255,255,0.14);color:#fff;">
              <i class="bi bi-ticket-perforated me-1"></i> FACULTY TICKET
            </span>
            <span class="app-badge" style="background:rgba(255,255,255,0.14);color:#fff;margin-left:6px;">
              ${ticket.priority ? ticket.priority.toUpperCase() : 'NORMAL'}
            </span>
          </div>
          <div class="app-queue-hero__foot" style="margin:0;padding:0;border:0;">
            Issued today at ${ticket.created_at ? new Date(ticket.created_at).toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'}) : 'Today'}
          </div>
        </div>

        <div class="row align-items-center my-3">
          <div class="col-md-6 text-center text-md-start mb-3 mb-md-0">
            <div class="app-queue-hero__number">${ticket.queue_number}</div>
            <div class="app-queue-hero__caption">
              ${ticket.status === 'called' ? 'Now being called â€” please enter the room' : (ticket.status === 'serving' ? 'In consultation with the nurse' : (ticket.status === 'completed' ? 'Consultation completed' : 'Waiting in line'))}
            </div>
          </div>
          <div class="col-md-6">
            <div class="row g-2 text-center">
              <div class="col-6">
                <div class="p-3 rounded" style="background: rgba(255,255,255,0.12);">
                  <div class="fs-3 fw-bold">${ticket.patients_ahead ?? 0}</div>
                  <div class="small opacity-75">Patients Ahead</div>
                </div>
              </div>
              <div class="col-6">
                <div class="p-3 rounded" style="background: rgba(255,255,255,0.12);">
                  <div class="fw-bold text-break ${Number.isFinite(ticket.estimated_wait_minutes) ? 'fs-3' : 'small'}">${Number.isFinite(ticket.estimated_wait_minutes) ? '~' + ticket.estimated_wait_minutes + 'm' : 'Unavailable'}</div>
                  <div class="small opacity-75">Estimated wait</div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div class="app-queue-steps">
          <div class="app-step ${currStep >= 1 ? 'is-done' : ''} ${currStep === 1 ? 'is-current' : ''}">
            <div class="app-step__circle"><i class="bi bi-check-lg"></i></div>
            <div class="app-step__label">Checked In</div>
          </div>
          <div class="app-step ${currStep >= 2 ? 'is-done' : ''} ${currStep === 2 ? 'is-current' : ''}">
            <div class="app-step__circle">${currStep === 2 ? '<i class="bi bi-hourglass-split"></i>' : '<i class="bi bi-check-lg"></i>'}</div>
            <div class="app-step__label">Waiting</div>
          </div>
          <div class="app-step ${currStep >= 3 ? 'is-done' : ''} ${currStep === 3 ? 'is-current' : ''}">
            <div class="app-step__circle">${currStep === 3 ? '<i class="bi bi-bell-fill"></i>' : (currStep > 3 ? '<i class="bi bi-check-lg"></i>' : '3')}</div>
            <div class="app-step__label">Called</div>
          </div>
          <div class="app-step ${currStep >= 4 ? 'is-done' : ''} ${currStep === 4 ? 'is-current' : ''}">
            <div class="app-step__circle">${currStep === 4 ? '<i class="bi bi-heart-pulse-fill"></i>' : (currStep > 4 ? '<i class="bi bi-check-lg"></i>' : '4')}</div>
            <div class="app-step__label">Serving</div>
          </div>
          <div class="app-step ${currStep >= 5 ? 'is-done' : ''} ${currStep === 5 ? 'is-current' : ''}">
            <div class="app-step__circle"><i class="bi bi-flag-fill"></i></div>
            <div class="app-step__label">Done</div>
          </div>
        </div>
      </div>
    `;
  }

  function dismissCalledAlert() {
    document.getElementById('calledUrgentBox').style.display = 'none';
  }

  async function submitTeacherCheckin(e) {
    e.preventDefault();
    const form = document.getElementById('teacherCheckinForm');
    const formData = new FormData(form);
    formData.append('action', 'checkin');

    try {
      const res = await fetch('<?= APP_URL ?>/api/queue.php', { method: 'POST', body: formData });
      const json = await res.json();
      if (json.success) {
        if (typeof showToast === 'function') showToast(json.message, 'success');
        BPCModal.hide('teacherCheckinModal');
        form.reset();
        pollTeacherQueue();
      } else {
        if (typeof showToast === 'function') showToast(json.message, 'danger');
      }
    } catch (err) {
      console.error(err);
    }
  }

  // Poll with in-flight protection and tab visibility throttling
  function startTeacherPolling() {
    if (teacherPollTimer) clearInterval(teacherPollTimer);
    pollTeacherQueue();
    teacherPollTimer = setInterval(() => {
      if (!document.hidden) pollTeacherQueue();
    }, 3500);
  }

  document.addEventListener('visibilitychange', () => {
    if (!document.hidden) pollTeacherQueue();
  });

  window.addEventListener('beforeunload', () => {
    if (teacherPollTimer) clearInterval(teacherPollTimer);
  });

  startTeacherPolling();
</script>
</body>
</html>

