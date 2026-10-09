<?php
/**
 * includes/notification_ui.php
 * ------------------------------------------------------------------
 * SHARED Notification Center UI partial for all roles.
 * Provides filters, search, sort, bulk actions, notifications table,
 * details modal (with SOS support), and all client-side logic.
 *
 * Expects $roleDir (e.g. 'admin' | 'nurse' | 'student') and $pageTitle.
 * ------------------------------------------------------------------
 */
$roleDir = $roleDir ?? 'student';

// Map notification types to readable "module" labels + icons
$typeMeta = [
    'emergency'   => ['label' => 'Emergency',   'icon' => 'exclamation-triangle-fill', 'color' => 'danger'],
    'appointment' => ['label' => 'Appointment', 'icon' => 'calendar-check-fill',        'color' => 'primary'],
    'medicine'    => ['label' => 'Medicine',    'icon' => 'capsule-fill',               'color' => 'success'],
    'inventory'   => ['label' => 'Inventory',   'icon' => 'box-seam-fill',              'color' => 'warning'],
    'health'      => ['label' => 'Health',      'icon' => 'heart-pulse-fill',           'color' => 'info'],
    'consultation'=> ['label' => 'Consultation','icon' => 'heart-pulse',                'color' => 'secondary'],
    'announcement'=> ['label' => 'Announcement','icon' => 'megaphone-fill',             'color' => 'warning'],
    'walkin'      => ['label' => 'Walk-in',     'icon' => 'person-walking',             'color' => 'info'],
    'system'      => ['label' => 'System',      'icon' => 'gear-fill',                  'color' => 'secondary'],
];
?>
<div class="page-enter fade-in">
  <!-- Header -->
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h6 class="section-title mb-0"><i class="bi bi-bell-fill text-success me-1"></i> Notification Center</h6>
    <div class="d-flex flex-wrap gap-2 align-items-center">
      <button class="btn btn-sm btn-outline-primary" id="refreshNotifBtn"><i class="bi bi-arrow-clockwise me-1"></i> Refresh</button>
      <button class="btn btn-sm btn-outline-success" id="markAllReadBtn"><i class="bi bi-check-all me-1"></i> Mark All as Read</button>
      <button class="btn btn-sm btn-outline-danger" id="deleteSelectedBtn" disabled><i class="bi bi-trash me-1"></i> Delete Selected</button>
    </div>
  </div>

  <!-- Filters / Search / Sort -->
  <div class="card p-3 mb-3">
    <div class="row g-2 align-items-center">
      <div class="col-md-3">
        <select id="filterType" class="form-select form-select-sm">
          <option value="all">All Notifications</option>
          <option value="unread">Unread</option>
          <option value="read">Read</option>
          <option value="emergency">Emergency</option>
          <option value="appointment">Appointment</option>
          <option value="consultation">Consultation</option>
          <option value="health">Health</option>
          <option value="medicine">Medicine</option>
          <option value="inventory">Inventory</option>
          <option value="stock_alert">Stock Alert</option>
          <option value="student">Student</option>
          <option value="walkin">Walk-in</option>
          <option value="announcement">Announcement</option>
          <option value="system">System</option>
          <option value="account">Account</option>
        </select>
      </div>
      <div class="col-md-4">
        <div class="input-group input-group-sm">
          <span class="input-group-text bg-transparent"><i class="bi bi-search"></i></span>
          <input type="text" id="searchNotif" class="form-control" placeholder="Search title or message...">
        </div>
      </div>
      <div class="col-md-2">
        <select id="sortNotif" class="form-select form-select-sm">
          <option value="newest">Newest first</option>
          <option value="oldest">Oldest first</option>
        </select>
      </div>
      <div class="col-md-3 text-md-end">
        <span class="text-muted small" id="notifCount"></span>
      </div>
    </div>
  </div>

  <!-- Notifications Table -->
  <div class="card p-3">
    <div class="table-responsive">
      <table class="table table-hover align-middle" id="notifTable" style="width:100%">
        <thead>
          <tr>
            <th style="width:36px;"><input type="checkbox" id="selectAllNotif" class="form-check-input"></th>
            <th>Notification</th>
            <th>Module</th>
            <th>Priority</th>
            <th>Status</th>
            <th>Received</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody></tbody>
      </table>
    </div>
  </div>
</div>

<!-- ============================================================ -->
<!-- Notification Detail Modal                                    -->
<!-- ============================================================ -->
<div class="modal fade" id="notifDetailModal" tabindex="-1" aria-hidden="true" aria-labelledby="notifDetailModalTitle">
  <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header modal-header--info">
      <h5 class="modal-title" id="notifDetailModalTitle"><?= bpc_icon('bell') ?> Notification Details</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" id="notifDetailBody">
        <div class="text-center text-muted py-4">Loading...</div>
      </div>
      <div class="modal-footer border-0 justify-content-between" id="notifDetailFooter" style="display:none;">
        <button type="button" class="btn btn-outline-danger btn-sm" id="deleteOneBtn"><i class="bi bi-trash me-1"></i> Delete</button>
        <div>
          <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Close</button>
          <a href="#" class="btn btn-primary btn-sm d-none" id="openRelatedBtn" target="_blank"><i class="bi bi-box-arrow-up-right me-1"></i> Open Related Page</a>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ============================================================ -->
<!-- SOS Detail Modal (Emergency notifications)                   -->
<!-- ============================================================ -->
<div class="modal fade" id="notifSosModal" tabindex="-1" aria-hidden="true" aria-labelledby="notifSosModalTitle" data-bs-backdrop="static">
  <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header modal-header--danger">
      <h5 class="modal-title" id="notifSosModalTitle"><?= bpc_icon('alert-triangle') ?> Emergency SOS Details</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" id="notifSosBody">
        <div class="text-center text-muted py-4">Loading...</div>
      </div>
      <div class="modal-footer border-0 justify-content-center gap-2" id="notifSosFooter" style="display:none;">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
        <button type="button" class="btn btn-success px-4" id="sosAcceptBtn2"><i class="bi bi-check-circle me-1"></i> Accept SOS</button>
        <button type="button" class="btn btn-outline-success px-4" id="sosResolveBtn2"><i class="bi bi-check2-all me-1"></i> Resolved</button>
      </div>
    </div>
  </div>
</div>

<script>
window.__initNotificationUI = function () {
  if (window.__notificationUIInitialized) {
    if (typeof window.loadNotifCenter === 'function') {
      window.loadNotifCenter();
    }
    return;
  }
  window.__notificationUIInitialized = true;

  (function () {
  'use strict';

  function getAppBase() {
    if (typeof APP_URL !== 'undefined' && APP_URL && APP_URL !== 'http://localhost' && APP_URL !== 'http://127.0.0.1') {
      return APP_URL;
    }
    if (window.APP_URL && window.APP_URL !== 'http://localhost' && window.APP_URL !== 'http://127.0.0.1') {
      return window.APP_URL;
    }
    const path = window.location.pathname;
    const match = path.match(/^(\/[^\/]+\/)/);
    return window.location.origin + (match ? match[1] : '');
  }
  const APP = getAppBase();
  const NOTIFICATION_PAGE_URL = window.NOTIFICATION_PAGE_URL || 'notifications.php';
  const selectedNotificationId = parseInt(new URLSearchParams(window.location.search).get('id') || 0);
  const csrf = $('meta[name="csrf-token"]').attr('content');
  let notifTableInstance = null;
  let initialNotificationHandled = false;

  // ============================================================
  // DataTable instance manager (singleton)
  // ============================================================
  function initNotifTable() {
    if ($.fn.DataTable.isDataTable('#notifTable')) {
      notifTableInstance = $('#notifTable').DataTable();
      return notifTableInstance;
    }

    notifTableInstance = $('#notifTable').DataTable({
      data: [],
      columns: [
        { orderable: false, searchable: false },
        null, null, null, null, null,
        { orderable: false, searchable: false },
      ],
      pageLength: 10,
      order: [],
      language: {
        emptyTable: '<div class="text-center text-muted py-4"><i class="bi bi-bell-slash fs-3 d-block mb-2"></i>No notifications yet.</div>',
        zeroRecords: '<div class="text-center text-muted py-4"><i class="bi bi-bell-slash fs-3 d-block mb-2"></i>No matching notifications found.</div>'
      },
      createdRow: function (row) {
        const $input = $(row).find('input.notif-check');
        const isRead = $input.data('is-read') === 1 || $input.data('is-read') === '1';
        if (!isRead) {
          $(row).addClass('unread-notification');
        }
        if (selectedNotificationId && $input.val() == selectedNotificationId) {
          $(row).addClass('selected-notification');
        }
      },
    });

    return notifTableInstance;
  }

  // ============================================================
  // Helpers
  // ============================================================
  function typeMeta(type) {
    const map = {
      emergency: { label: 'Emergency',    icon: 'exclamation-triangle-fill', color: 'danger' },
      appointment: { label: 'Appointment', icon: 'calendar-check-fill',      color: 'primary' },
      medicine: { label: 'Medicine',      icon: 'capsule-fill',              color: 'success' },
      inventory: { label: 'Inventory',    icon: 'box-seam-fill',             color: 'warning' },
      health: { label: 'Health',          icon: 'heart-pulse-fill',          color: 'info' },
      consultation: { label: 'Consultation', icon: 'heart-pulse',            color: 'secondary' },
      announcement: { label: 'Announcement', icon: 'megaphone-fill',         color: 'warning' },
      walkin: { label: 'Walk-in',         icon: 'person-walking',            color: 'info' },
      system: { label: 'System',          icon: 'gear-fill',                 color: 'secondary' },
    };
    return map[type] || { label: type, icon: 'bell-fill', color: 'secondary' };
  }

  function statusBadge(isRead) {
    return isRead
      ? '<span class="badge bg-secondary">Read</span>'
      : '<span class="badge bg-warning text-dark">Unread</span>';
  }

  function priorityBadge(p) {
    const colors = { low: 'secondary', medium: 'info', high: 'warning text-dark', emergency: 'danger' };
    const icons  = { low: 'arrow-down', medium: 'dash', high: 'arrow-up', emergency: 'exclamation-triangle-fill' };
    const c = colors[p] || 'info';
    const i = icons[p] || 'dash';
    return `<span class="badge bg-${c.split(' ')[0]}"><i class="bi bi-${i} me-1"></i>${p || 'medium'}</span>`;
  }

  function timeAgo(dateStr) {
    if (!dateStr) return '';
    const d = new Date(dateStr.replace(' ', 'T') + 'Z');
    const diff = Math.floor((Date.now() - d.getTime()) / 1000);
    if (diff < 60) return 'Just now';
    const m = Math.floor(diff / 60);
    if (m < 60) return m + 'm ago';
    const h = Math.floor(m / 60);
    if (h < 24) return h + 'h ago';
    const days = Math.floor(h / 24);
    if (days < 7) return days + 'd ago';
    return d.toLocaleDateString();
  }

  // ============================================================
  // Load notifications into DataTable
  // ============================================================
  window.loadNotifCenter = function (page) {
    const table = initNotifTable();

    const params = {
      action: 'list',
      filter: $('#filterType').val(),
      search: $('#searchNotif').val(),
      sort: $('#sortNotif').val(),
      limit: 50,
    };
    if (page !== undefined) params.offset = page * 50;

    $.get(APP + '/api/notifications.php', params, function (res) {
      if (!res || !res.success) {
        $('#notifCount').text('');
        table.clear().draw();
        $('#selectAllNotif').prop('checked', false);
        updateDeleteBtn();
        return;
      }
      $('#notifCount').text(res.total + ' notification(s)' + (res.unread_count ? ' · ' + res.unread_count + ' unread' : ''));

      if (!res.data || res.data.length === 0) {
        table.clear().draw();
        $('#selectAllNotif').prop('checked', false);
        updateDeleteBtn();
        return;
      }

      const rows = res.data.map(function (n) {
        const tm = typeMeta(n.type);
        const sosBadge = (n.type === 'emergency' && n.sos && n.sos.emergency_id)
          ? ' <span class="badge bg-danger ms-1"><i class="bi bi-geo-alt-fill me-1"></i>SOS</span>' : '';
        const unreadDot = n.is_read ? '' : '<span class="notif-dot ms-1" style="background:var(--success);"></span>';
        return [
          `<input type="checkbox" class="form-check-input notif-check" value="${n.id}" data-is-read="${n.is_read ? 1 : 0}">`,
          `<div class="d-flex align-items-start gap-2">
             <span class="badge bg-${tm.color} rounded-circle d-flex align-items-center justify-content-center" style="width:28px;height:28px;font-size:.75rem;">
               <i class="bi bi-${tm.icon}"></i>
             </span>
             <div>
               <div class="fw-semibold small">${escapeHtml(n.title)}${sosBadge}${unreadDot}</div>
               <div class="text-muted" style="font-size:.78rem;">${escapeHtml(n.message)}</div>
             </div>
           </div>`,
          `<span class="badge bg-${tm.color} bg-opacity-10 text-${tm.color}" style="font-weight:600;">${tm.label}</span>`,
          priorityBadge(n.priority),
          statusBadge(n.is_read),
          `<span class="small text-muted" title="${escapeHtml(n.created_at)}">${timeAgo(n.created_at)}</span>`,
          `<div class="d-flex gap-1">
             <button class="btn btn-sm btn-outline-primary view-notif" data-id="${n.id}" title="View details"><i class="bi bi-eye"></i></button>
             <button class="btn btn-sm btn-outline-danger del-notif" data-id="${n.id}" title="Delete"><i class="bi bi-trash"></i></button>
           </div>`,
        ];
      });

      table.clear();
      table.rows.add(rows);
      table.draw();

      $('#selectAllNotif').prop('checked', false);
      updateDeleteBtn();

      if (selectedNotificationId && !initialNotificationHandled) {
        initialNotificationHandled = true;
        const $row = $('#notifTable tbody tr').has(`input.notif-check[value="${selectedNotificationId}"]`);
        if ($row.length) {
          $row.addClass('selected-notification');
          $row.get(0).scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
        showNotificationDetails(selectedNotificationId);
      }
    }, 'json').fail(function () {
      table.clear().draw();
      $('#selectAllNotif').prop('checked', false);
      updateDeleteBtn();
    });
  };

  $('#filterType, #sortNotif').on('change', function () { loadNotifCenter(); });
  let searchT;
  $('#searchNotif').on('keyup', function () {
    clearTimeout(searchT);
    searchT = setTimeout(function () { loadNotifCenter(); }, 350);
  });
  $('#refreshNotifBtn').on('click', function () {
    $(this).html('<span class="spinner-border spinner-border-sm me-1"></span> Loading...');
    loadNotifCenter();
    const btn = $(this);
    setTimeout(function () { btn.html('<i class="bi bi-arrow-clockwise me-1"></i> Refresh'); }, 600);
  });

  // Select all
  $('#selectAllNotif').on('change', function () {
    $('.notif-check').prop('checked', this.checked);
    updateDeleteBtn();
  });
  $(document).on('change', '.notif-check', function () { updateDeleteBtn(); });
  function updateDeleteBtn() {
    const n = $('.notif-check:checked').length;
    $('#deleteSelectedBtn').prop('disabled', n === 0);
  }

  // ============================================================
  // View details
  // ============================================================
  function showNotificationDetails(id) {
    if (!id) return;
    $.get(APP + '/api/notifications.php', { action: 'details', id }, function (res) {
      if (!res.success) return;
      const n = res.data;

      // Emergency SOS → specialized modal
      if (n.type === 'emergency' && n.sos && n.sos.emergency_id) {
        openNotifSos(n);
        return;
      }

      const tm = typeMeta(n.type);
      const html =
        `<div class="mb-3 text-center">
           <span class="badge bg-${tm.color} rounded-circle d-inline-flex align-items-center justify-content-center" style="width:52px;height:52px;font-size:1.3rem;">
             <i class="bi bi-${tm.icon}"></i>
           </span>
           <h5 class="fw-bold mt-2 mb-0">${escapeHtml(n.title)}</h5>
           ${statusBadge(n.is_read)} ${priorityBadge(n.priority)}
         </div>
         <hr>
         <div class="row g-2 small">
           <div class="col-12"><div class="text-muted">Description</div><div class="fw-semibold">${escapeHtml(n.message)}</div></div>
           <div class="col-6"><div class="text-muted">Date</div><div class="fw-semibold">${escapeHtml(formatFull(n.created_at))}</div></div>
           <div class="col-6"><div class="text-muted">Status</div><div class="fw-semibold">${n.is_read ? 'Read' : 'Unread'}</div></div>
           <div class="col-6"><div class="text-muted">Module</div><div class="fw-semibold">${tm.label}</div></div>
           <div class="col-6"><div class="text-muted">Priority</div><div class="fw-semibold text-capitalize">${escapeHtml(n.priority || 'medium')}</div></div>
         </div>`;

      $('#notifDetailBody').html(html);
      $('#notifDetailModal').data('notif-id', n.id);
      $('#notifDetailModal').data('notif-link', n.link || '');
      const $openBtn = $('#openRelatedBtn');
      if (n.link) {
        $openBtn.attr('href', n.link).removeClass('d-none');
      } else {
        $openBtn.addClass('d-none');
      }
      $('#notifDetailFooter').show();
      new bootstrap.Modal(document.getElementById('notifDetailModal')).show();
      if (typeof window.loadNotifications === 'function') window.loadNotifications();
    }, 'json');
  }

  $(document).on('click', '.view-notif', function () {
    const id = $(this).data('id');
    showNotificationDetails(id);
  });

  $(document).on('click', '#notifTable tbody tr', function (e) {
    if ($(e.target).closest('input, button, a, .form-check-input, .view-notif, .del-notif').length) return;
    const id = $(this).find('input.notif-check').val();
    if (!id) return;
    $('.view-notif[data-id="' + id + '"]').click();
  });

  function formatFull(dt) {
    if (!dt) return '—';
    const d = new Date(dt.replace(' ', 'T'));
    return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) +
      ' · ' + d.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
  }

  // ============================================================
  // SOS modal
  // ============================================================
  function openNotifSos(n) {
    const sos = n.sos || {};
    const hasGps = sos.latitude != null && sos.longitude != null && sos.latitude !== '' && sos.longitude !== '';
    const mapsUrl = hasGps ? 'https://www.google.com/maps?q=' + encodeURIComponent(sos.latitude + ',' + sos.longitude) : '#';
    const isManual = sos.location_source === 'manual' || (!hasGps && sos.location);
    const statusLabels = { active: 'Active', responded: 'Responded', resolved: 'Resolved', false_alarm: 'False Alarm' };
    const statusColors = { active: 'danger', responded: 'warning text-dark', resolved: 'success', false_alarm: 'secondary' };

    let locationHtml = '';
    if (hasGps) {
      const latF = Number(sos.latitude).toFixed(6);
      const lngF = Number(sos.longitude).toFixed(6);
      const accLabel = sos.location_accuracy ? 'Accuracy: ±' + escapeHtml(sos.location_accuracy) : 'Accuracy: ±8 meters';
      locationHtml =
        `<div class="p-3 bg-light rounded-3 border border-danger-subtle">
           <div class="fw-bold text-danger mb-1"><i class="bi bi-crosshair me-1"></i> Current Location (Live GPS):</div>
           <div class="fs-6 fw-bold text-dark mb-1">${latF}, ${lngF}</div>
           <div class="small text-muted mb-2">${accLabel} · Updated: ${escapeHtml(formatFull(sos.location_updated_at || sos.sos_created_at))}</div>
           <a href="${mapsUrl}" target="_blank" rel="noopener" class="btn btn-sm btn-danger">
             <i class="bi bi-box-arrow-up-right me-1"></i> View on Map
           </a>
         </div>`;
    } else if (isManual && sos.location) {
      const repName = [sos.reporter_first_name, sos.reporter_last_name].filter(Boolean).join(' ');
      locationHtml =
        `<div class="p-3 bg-light rounded-3 border border-primary-subtle">
           <div class="fw-bold text-primary mb-1"><i class="bi bi-geo-alt-fill text-danger me-1"></i> Reported Location:</div>
           <div class="fs-6 fw-bold text-dark mb-1">${escapeHtml(sos.location)}</div>
           <div class="small text-muted">Manually reported by Adviser${repName ? ' (' + escapeHtml(repName) + ')' : ''}</div>
         </div>`;
    } else {
      locationHtml = `<div class="p-2 rounded-3 bg-light text-muted small"><i class="bi bi-info-circle me-1"></i>Location unavailable.</div>`;
    }

    const html =
      `<div class="mb-3 text-center">
         <div class="display-6 text-danger"><i class="bi bi-exclamation-triangle-fill"></i></div>
         <h5 class="fw-bold mb-1">${escapeHtml([sos.first_name, sos.last_name].filter(Boolean).join(' ') || 'Unknown Student')}</h5>
         <span class="badge bg-${statusColors[sos.sos_status] || 'secondary'}">${statusLabels[sos.sos_status] || sos.sos_status || 'Unknown'}</span>
       </div>
       <hr>
       <div class="row g-2 small">
         <div class="col-6"><div class="text-muted">Student Name</div><div class="fw-semibold">${escapeHtml([sos.first_name, sos.last_name].filter(Boolean).join(' ') || '—')}</div></div>
         <div class="col-6"><div class="text-muted">Student Number</div><div class="fw-semibold">${escapeHtml(sos.student_number || '—')}</div></div>
         <div class="col-6"><div class="text-muted">Course</div><div class="fw-semibold">${escapeHtml(sos.course || '—')}</div></div>
         <div class="col-6"><div class="text-muted">Year Level</div><div class="fw-semibold">${escapeHtml(sos.year_level || '—')}</div></div>
         <div class="col-12"><div class="text-muted">Emergency Type</div><div class="fw-semibold text-danger">${escapeHtml(sos.emergency_type || '—')}</div></div>
         <div class="col-12"><div class="text-muted">Message</div><div class="fw-semibold">${escapeHtml(sos.description || '—')}</div></div>
         <div class="col-6"><div class="text-muted">Date / Time</div><div class="fw-semibold">${escapeHtml(formatFull(sos.sos_created_at))}</div></div>
         <div class="col-6"><div class="text-muted">Contact</div><div class="fw-semibold">${escapeHtml(sos.phone || '—')}</div></div>
       </div>
       <hr>
       <div>
         <div class="text-muted small mb-1 fw-semibold">Location</div>
         ${locationHtml}
       </div>`;

    $('#notifSosBody').html(html);
    $('#notifSosModal').data('sos', sos);
    $('#notifSosModal').data('notif-id', n.id);

    // Show Accept/Resolve only for staff
    const isStaff = (window.MICA_USER && (window.MICA_USER.role === 'nurse' || window.MICA_USER.role === 'admin'));
    const $footer = $('#notifSosFooter');
    if (isStaff) {
      $footer.show();
      const st = sos.sos_status || 'active';
      const $accept = $('#sosAcceptBtn2'); // renamed to avoid clash
      const $resolve = $('#sosResolveBtn2');
      if (st === 'resolved' || st === 'false_alarm') { $accept.prop('disabled', true); $resolve.prop('disabled', true); }
      else if (st === 'responded') { $accept.prop('disabled', true); $resolve.prop('disabled', false); }
      else { $accept.prop('disabled', false); $resolve.prop('disabled', false); }
    } else {
      $footer.hide();
    }
    new bootstrap.Modal(document.getElementById('notifSosModal')).show();
    if (typeof window.loadNotifications === 'function') window.loadNotifications();
  }

  // Accept / Resolve SOS
  $(document).on('click', '#sosAcceptBtn2', function () { updateSosStatus2('accept'); });
  $(document).on('click', '#sosResolveBtn2', function () { updateSosStatus2('resolve'); });
  function updateSosStatus2(status) {
    const modalEl = document.getElementById('notifSosModal');
    const sos = $(modalEl).data('sos') || {};
    const emergencyId = sos.emergency_id;
    if (!emergencyId) return;
    $.post(APP + '/api/ai_assistant.php', {
      action: 'sos_update_status', emergency_id: emergencyId, status, csrf_token: csrf,
    }, function (res) {
      if (res && res.success) {
        Swal.fire({ icon: 'success', title: 'SOS ' + (status === 'accept' ? 'Accepted' : 'Resolved'), timer: 2000, showConfirmButton: false });
        loadNotifCenter();
      } else {
        toastError((res && res.message) || 'Failed to update SOS status.');
      }
    }, 'json');
  }

  // ============================================================
  // Mark all read
  // ============================================================
  $('#markAllReadBtn').on('click', function () {
    $.post(APP + '/api/notifications.php', { action: 'mark_all_read', csrf_token: csrf }, function (res) {
      if (res.success) { toastSuccess('All notifications marked as read.'); loadNotifCenter(); if (typeof window.loadNotifications === 'function') window.loadNotifications(); }
      else toastError(res.message);
    }, 'json');
  });

  // ============================================================
  // Delete single
  // ============================================================
  $(document).on('click', '.del-notif', function () {
    const id = $(this).data('id');
    confirmDelete(function () {
      $.post(APP + '/api/notifications.php', { action: 'delete', id, csrf_token: csrf }, function (res) {
        if (res.success) { toastSuccess(res.message); loadNotifCenter(); if (typeof window.loadNotifications === 'function') window.loadNotifications(); }
        else toastError(res.message);
      }, 'json');
    });
  });

  // Delete from details modal
  $('#deleteOneBtn').on('click', function () {
    const id = $('#notifDetailModal').data('notif-id');
    if (!id) return;
    confirmDelete(function () {
      $.post(APP + '/api/notifications.php', { action: 'delete', id, csrf_token: csrf }, function (res) {
        if (res.success) {
          bootstrap.Modal.getInstance(document.getElementById('notifDetailModal')).hide();
          toastSuccess(res.message);
          loadNotifCenter();
          if (typeof window.loadNotifications === 'function') window.loadNotifications();
        } else toastError(res.message);
      }, 'json');
    });
  });

  // ============================================================
  // Delete selected (bulk)
  // ============================================================
  $('#deleteSelectedBtn').on('click', function () {
    const ids = $('.notif-check:checked').map(function () { return $(this).val(); }).get();
    if (ids.length === 0) return;
    confirmDelete(function () {
      $.post(APP + '/api/notifications.php', { action: 'delete_selected', ids, csrf_token: csrf }, function (res) {
        if (res.success) { toastSuccess(res.message); $('#selectAllNotif').prop('checked', false); loadNotifCenter(); if (typeof window.loadNotifications === 'function') window.loadNotifications(); }
        else toastError(res.message);
      }, 'json');
    });
  });

  // ============================================================
  // Initial load
  // ============================================================
  loadNotifCenter();
  })();
};
</script>
