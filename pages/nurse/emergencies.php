<?php
/**
 * pages/nurse/emergencies.php
 * =====================================================================
 * NURSE Emergency Center — active and historical SOS alerts.
 * Uses the existing api/emergencies.php endpoints (list / update_status).
 * This page backs the "Emergency Center" links on the nurse dashboard.
 * =====================================================================
 */
require_once __DIR__ . '/../../config/config.php';
require_role('nurse');

$pageTitle = 'Emergency Center';
$pageDescription = 'Respond to active SOS emergency alerts';
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
    <div class="content-area dbg-canvas page-enter">

      <section class="dbg-hero dbg-hero--danger" data-aos="fade-down">
        <div class="dbg-hero__inner">
          <div class="dbg-hero__text">
            <span class="dbg-hero__role"><i class="bi bi-shield-fill-exclamation"></i>Clinic Nurse</span>
            <h2 class="dbg-hero__title">Emergency Center</h2>
            <p class="dbg-hero__desc">Acknowledge, respond to, and resolve SOS alerts raised across campus.</p>
            <div class="dbg-hero__meta">
              <span class="dbg-hero__chip" id="emgHeroActive"><span class="dbg-dot"></span>Active alerts <b>—</b></span>
              <span class="dbg-hero__chip"><span class="dbg-dot dbg-dot--warn"></span>Total on record <b>—</b></span>
            </div>
          </div>
        </div>
      </section>

      <div class="row g-3 g-xl-4">
        <div class="col-xl-4">
          <?php dbg_panel_open(['icon' => 'lightning-charge-fill', 'title' => 'Response Actions']); ?>
            <div class="dbg-actions" style="grid-template-columns:repeat(2,minmax(0,1fr));">
              <a class="dbg-action dbg-action--red" href="<?= APP_URL ?>/pages/nurse/queue.php">
                <span class="dbg-action__icon"><i class="bi bi-broadcast"></i></span>
                <span class="dbg-action__title">Live Queue</span>
                <span class="dbg-action__desc">See who is waiting</span>
              </a>
              <a class="dbg-action dbg-action--blue" href="<?= APP_URL ?>/pages/nurse/queue.php">
                <span class="dbg-action__icon"><i class="bi bi-person-plus"></i></span>
                <span class="dbg-action__title">Walk-in</span>
                <span class="dbg-action__desc">Register a patient</span>
              </a>
            </div>
            <hr class="dbg-divider">
            <p class="dbg-muted" style="font-size:0.78rem;margin:0;">
              <i class="bi bi-info-circle me-1"></i>Use the action buttons on an alert to acknowledge it,
              take responsibility, or mark it resolved once the student is safe.
            </p>
          <?php dbg_panel_close(); ?>
        </div>

        <div class="col-xl-8">
          <?php dbg_panel_open([
              'icon'  => 'exclamation-triangle-fill',
              'title' => 'SOS Alerts',
              'action'=> '<button type="button" class="dbg-btn dbg-btn--ghost dbg-btn--sm" id="emgRefresh"><i class="bi bi-arrow-clockwise"></i> Refresh</button>',
          ]); ?>
            <div id="emgAlertList">
              <?php dbg_skeleton_rows(4); ?>
            </div>
          <?php dbg_panel_close(); ?>
        </div>
      </div>

    </div>
  </div>
</div>
<?php include __DIR__ . '/../../components/footer.php'; ?>

<script>
(function () {
  'use strict';

  var host = document.getElementById('emgAlertList');
  var csrf = ($('meta[name="csrf-token"]').attr('content')) || '';
  var busy = false;

  var STATUS_LABEL = {
    new: 'New', active: 'Active', acknowledged: 'Acknowledged',
    responding: 'Responding', responded: 'Responded', resolved: 'Resolved',
    cancelled: 'Cancelled', false_alarm: 'False Alarm'
  };
  var STATUS_CLASS = {
    new: 'dbg-badge--red', active: 'dbg-badge--red', acknowledged: 'dbg-badge--orange',
    responding: 'dbg-badge--blue', responded: 'dbg-badge--orange',
    resolved: '', cancelled: 'dbg-badge--muted', false_alarm: 'dbg-badge--muted'
  };
  var OPEN_STATES = ['new', 'active', 'acknowledged', 'responding', 'responded'];

  function esc(v) {
    return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function actionsFor(e) {
    if (e.status === 'resolved' || e.status === 'cancelled' || e.status === 'false_alarm') return '';
    var btns = '';
    if (e.status === 'new' || e.status === 'active') {
      btns += '<button class="dbg-btn dbg-btn--primary dbg-btn--sm" data-op="acknowledge" data-id="' + e.id + '">Acknowledge</button>';
    }
    if (e.status !== 'responding') {
      btns += '<button class="dbg-btn dbg-btn--ghost dbg-btn--sm" data-op="respond" data-id="' + e.id + '">Respond</button>';
    }
    btns += '<button class="dbg-btn dbg-btn--danger dbg-btn--sm" data-op="resolve" data-id="' + e.id + '">Resolve</button>';
    return btns;
  }

  function render(list) {
    var active = list.filter(function (e) { return OPEN_STATES.indexOf(e.status) !== -1; }).length;

    $('#emgHeroActive b').text(active);
    $('#emgHeroActive .dbg-dot').attr('class', 'dbg-dot' + (active ? ' dbg-dot--danger' : ''));
    $('#emgHeroActive').parent().find('.dbg-hero__chip').eq(1).find('b').text(list.length);

    if (!list.length) {
      host.innerHTML = '<div class="dbg-empty"><div class="dbg-empty__icon"><i class="bi bi-shield-check"></i></div>'
        + '<h6>No emergency alerts</h6><p>Everything is currently normal. New SOS alerts will appear here immediately.</p></div>';
      return;
    }

    host.innerHTML = '<div class="dbg-alert-list">' + list.map(function (e) {
      var level = (e.status === 'resolved' || e.status === 'cancelled' || e.status === 'false_alarm')
        ? 'ok' : (e.status === 'responding' ? 'info' : 'critical');
      return '<div class="dbg-alert dbg-alert--' + level + '">'
        + '<div class="dbg-alert__icon"><i class="bi bi-' + (level === 'ok' ? 'shield-check' : 'exclamation-octagon-fill') + '"></i></div>'
        + '<div class="dbg-alert__body">'
        + '<div class="dbg-alert__title">'
        + (e.first_name ? esc(e.first_name + ' ' + e.last_name) : 'Student #' + esc(e.student_id))
        + ' <span class="dbg-mono dbg-muted" style="font-weight:400;">' + esc(e.student_number || '') + '</span>'
        + '</div>'
        + '<div class="dbg-alert__desc">'
        + esc(e.emergency_type || 'Emergency')
        + (e.location ? ' · <i class="bi bi-geo-alt"></i> ' + esc(e.location) : '')
        + ' · ' + esc(e.created_at || '')
        + '</div>'
        + '<div style="margin-top:8px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">'
        + '<span class="dbg-badge ' + (STATUS_CLASS[e.status] || 'dbg-badge--muted') + '">'
        + esc(STATUS_LABEL[e.status] || e.status) + '</span>'
        + (e.priority && e.priority !== 'normal'
            ? '<span class="dbg-badge dbg-badge--orange">' + esc(e.priority) + '</span>' : '')
        + '</div>'
        + '</div>'
        + '<div class="dbg-btn-row" style="justify-content:flex-end;">' + actionsFor(e) + '</div>'
        + '</div>';
    }).join('') + '</div>';
  }

  function errorState(msg) {
    host.innerHTML = <?= json_encode(dbg_error_state('Unable to load emergency alerts.')) ?>;
  }

  function load() {
    $.ajax({
      url: APP_URL + '/api/emergencies.php',
      method: 'GET',
      dataType: 'json',
      data: { action: 'list', history: 1 },
      success: function (res) {
        if (res && res.success && Array.isArray(res.data)) render(res.data);
        else errorState();
      },
      error: errorState
    });
  }

  $(document).on('click', '#emgRefresh', load);

  $(document).on('click', '[data-op]', function () {
    var op = $(this).data('op');
    var id = $(this).data('id');
    if (busy) return;
    busy = true;

    var send = function (extra) {
      var payload = $.extend({ action: 'update_status', emergency_id: id, op: op, csrf_token: csrf }, extra || {});
      $.post(APP_URL + '/api/emergencies.php', payload, function (res) {
        if (res && res.success) {
          if (typeof Swal !== 'undefined') {
            Swal.fire({ icon: 'success', title: 'Updated', text: res.message || 'Emergency status updated.', timer: 1600, timerProgressBar: true });
          }
        } else if (typeof Swal !== 'undefined') {
          Swal.fire({ icon: 'error', title: 'Could not update', text: (res && res.message) || 'Please try again.' });
        }
        busy = false;
        load();
      }, 'json').fail(function (xhr) {
        if (typeof Swal !== 'undefined') Swal.fire({ icon: 'error', title: 'Connection error', text: (xhr.responseJSON && xhr.responseJSON.message) || (xhr.status ? 'Server error (HTTP '+xhr.status+').' : 'Connection lost. Check status before retrying.') });
        busy = false;
      });
    };

    if (op === 'resolve') {
      Swal.fire({
        input: 'text',
        inputLabel: 'Resolution notes',
        inputPlaceholder: 'What happened?',
        showCancelButton: true,
        confirmButtonText: 'Mark resolved',
        confirmButtonColor: '#DC3545',
        preConfirm: function (notes) { if(!notes || !notes.trim()){ Swal.showValidationMessage('Enter resolution notes.'); return false; } return { resolution_notes: notes.trim() }; }
      }).then(function (r) {
        if (!r.isConfirmed) { busy = false; return; }
        send({ resolution_notes: r.value.resolution_notes });
      });
    } else {
      send();
    }
  });

  load();
  setInterval(function () { if (!document.hidden && !busy) load(); }, 20000);
})();
</script>
</body>
</html>
