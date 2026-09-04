/* =====================================================================
   BPC Clinic System — Core JS (Enhanced)
   Features: AOS, Dark Mode, Animated Counters, Notifications, Modals,
             Real-time Clock, Collapsible Sidebar, Page Loader
   ===================================================================== */

/**
 * Global helper: Returns the appropriate notification center URL based on user role.
 * Accessible everywhere across the app in all scopes.
 */
function getNotificationPageUrl() {
  if (window.NOTIFICATION_PAGE_URL && typeof window.NOTIFICATION_PAGE_URL === 'string') {
    return window.NOTIFICATION_PAGE_URL;
  }
  var base = (typeof APP_URL !== 'undefined' && APP_URL) ? APP_URL : (window.location.origin + '/bpc_clinic_system');
  var role = (window.MICA_USER && window.MICA_USER.role)
    || (document.getElementById('mica-assistant') && document.getElementById('mica-assistant').getAttribute('data-mica-role'))
    || '';

  if (role === 'admin') return base + '/pages/admin/notifications.php';
  if (role === 'nurse') return base + '/pages/nurse/notifications.php';
  if (role === 'teacher' || role === 'adviser') return base + '/pages/adviser/notifications.php';
  if (role === 'student') return base + '/pages/student/notifications.php';

  var path = window.location.pathname || '';
  if (path.includes('/admin/')) return base + '/pages/admin/notifications.php';
  if (path.includes('/nurse/')) return base + '/pages/nurse/notifications.php';
  if (path.includes('/adviser/')) return base + '/pages/adviser/notifications.php';
  if (path.includes('/student/')) return base + '/pages/student/notifications.php';

  return base + '/pages/student/notifications.php';
}

window.getNotificationPageUrl = getNotificationPageUrl;

$(function () {
  'use strict';

  // ================================================================
  // AOS Animation Init
  // ================================================================
  if (typeof AOS !== 'undefined') {
    AOS.init({
      duration: 600,
      once: true,
      offset: 40,
      easing: 'ease-out-cubic',
    });
  }

// ================================================================
  // Page Loader — Hide immediately on DOM ready
  // ================================================================
  setTimeout(function () {
    $('.page-loader').addClass('hidden');
    setTimeout(function () {
      $('.page-loader').remove();
    }, 400);
  }, 300); // Brief delay to let CSS paint, then hide

  // ================================================================
  // Sidebar Toggle (Mobile) - hamburger button
  // ================================================================
  $('#sidebarToggle').on('click', function () {
    $('.sidebar').toggleClass('show');
  });

  // Close sidebar on outside click (mobile)
  $(document).on('click', function (e) {
    if (window.innerWidth < 1200) {
      if (!$(e.target).closest('.sidebar, #sidebarToggle').length) {
        $('.sidebar').removeClass('show');
      }
    }
  });

  // ================================================================
  // Sidebar Collapse Toggle (Desktop) - pin button
  // ================================================================
  const storedCollapsed = localStorage.getItem('sidebar-collapsed') === 'true';

  function applySidebarState(collapsed) {
    if (collapsed) {
      $('.sidebar').addClass('collapsed');
      $('.main-content').css('margin-left', '72px');
      $('.main-content').css('width', 'calc(100% - 72px)');
    } else {
      $('.sidebar').removeClass('collapsed');
      $('.main-content').css('margin-left', '');
      $('.main-content').css('width', '');
    }
    localStorage.setItem('sidebar-collapsed', collapsed);
  }

  // Apply stored state on load (only for desktop)
  if (window.innerWidth >= 1200) {
    applySidebarState(storedCollapsed);
  }

  $(document).on('click', '.sidebar-toggle-collapse', function (e) {
    e.stopPropagation();
    const isCollapsed = $('.sidebar').hasClass('collapsed');
    applySidebarState(!isCollapsed);
  });

  // Reset sidebar on window resize crossing the threshold
  let lastWidth = window.innerWidth;
  $(window).on('resize', function () {
    const w = window.innerWidth;
    if ((lastWidth < 1200 && w >= 1200) || (lastWidth >= 1200 && w < 1200)) {
      if (w >= 1200) {
        applySidebarState(localStorage.getItem('sidebar-collapsed') === 'true');
        $('.sidebar').removeClass('show');
      } else {
        $('.sidebar').removeClass('collapsed');
        $('.main-content').css('margin-left', '');
        $('.main-content').css('width', '');
      }
    }
    lastWidth = w;
  });

  // ================================================================
  // Dark Mode Toggle
  // ================================================================
  const darkToggle = $('#darkModeToggle');
  const storedTheme = localStorage.getItem('clinic-theme') || 'light';

  function applyTheme(theme) {
    if (theme === 'dark') {
      document.documentElement.setAttribute('data-theme', 'dark');
      darkToggle.find('i').removeClass('bi-moon-stars').addClass('bi-sun');
      darkToggle.attr('title', 'Switch to Light Mode');
    } else {
      document.documentElement.removeAttribute('data-theme');
      darkToggle.find('i').removeClass('bi-sun').addClass('bi-moon-stars');
      darkToggle.attr('title', 'Switch to Dark Mode');
    }
    localStorage.setItem('clinic-theme', theme);
  }

  applyTheme(storedTheme);

  darkToggle.on('click', function () {
    const current = document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
    applyTheme(current === 'dark' ? 'light' : 'dark');
  });

  // ================================================================
  // Real-time Clock
  // ================================================================
  function updateClock() {
    const now = new Date();
    const options = { weekday: 'short', year: 'numeric', month: 'short', day: 'numeric' };
    const dateStr = now.toLocaleDateString('en-US', options);
    const timeStr = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true });

    $('.topbar-clock-time').text(timeStr);
    $('.topbar-clock-date').text(dateStr);
  }

  if ($('.topbar-clock').length) {
    updateClock();
    setInterval(updateClock, 1000);
  }

  // ================================================================
  // Auto-dismiss Flash Alerts
  // ================================================================
  setTimeout(function () {
    $('.alert-auto-dismiss').fadeOut(400);
  }, 4000);

  // ================================================================
  // Notification Service & Polling
  // ================================================================
  const NOTIFICATION_POLL_INTERVAL = 30000;

  function markNotificationRead(id, callback) {
    if (!id) {
      if (typeof callback === 'function') callback();
      return;
    }
    var base = (typeof APP_URL !== 'undefined' && APP_URL) ? APP_URL : (window.location.origin + '/bpc_clinic_system');
    $.post(base + '/api/notifications.php', {
      action: 'mark_read',
      id: id,
      csrf_token: $('meta[name="csrf-token"]').attr('content')
    }, function (res) {
      if (res && res.success) {
        if (typeof window.loadNotifications === 'function') {
          window.loadNotifications();
        }
        if (typeof window.loadNotifCenter === 'function') {
          window.loadNotifCenter();
        }
        if (typeof callback === 'function') callback(null);
      } else {
        if (typeof callback === 'function') callback((res && res.message) || 'Failed to mark notification as read.');
      }
    }, 'json').fail(function () {
      if (typeof callback === 'function') callback('Network error');
    });
  }

  function markAllNotificationsRead(callback) {
    var base = (typeof APP_URL !== 'undefined' && APP_URL) ? APP_URL : (window.location.origin + '/bpc_clinic_system');
    $.post(base + '/api/notifications.php', {
      action: 'mark_all_read',
      csrf_token: $('meta[name="csrf-token"]').attr('content')
    }, function (res) {
      if (res && res.success) {
        if (typeof window.loadNotifications === 'function') {
          window.loadNotifications();
        }
        if (typeof window.loadNotifCenter === 'function') {
          window.loadNotifCenter();
        }
      }
      if (typeof callback === 'function') callback(res && res.success ? null : (res && res.message) || 'Failed to mark all as read.');
    }, 'json').fail(function () {
      if (typeof callback === 'function') callback('Network error');
    });
  }

  // Start single notification poller per page session
  if ($('#notifBadge').length && !window.__NOTIF_POLL_ACTIVE__) {
    window.__NOTIF_POLL_ACTIVE__ = true;
    if (typeof loadNotifications === 'function') {
      loadNotifications();
      setInterval(loadNotifications, NOTIFICATION_POLL_INTERVAL);
    }
  }

  $(document).on('click', '#notifList a.notif-dropdown-item, #notifList a.notif-link, #notifList a.sos-notif-item', function (e) {
    var $el = $(this);
    var id = $el.data('id');
    var href = $el.attr('href');
    var isSos = $el.hasClass('sos-notif-item');

    if (isSos) {
      e.preventDefault();
      var rawSos = $el.attr('data-sos');
      if (rawSos) {
        try {
          var sosData = JSON.parse(decodeURIComponent(rawSos));
          if (typeof openSosDetailsModal === 'function') {
            openSosDetailsModal(sosData);
          }
        } catch (err) {
          console.warn('[Notifications] Failed to parse SOS details:', err);
        }
      }
      return;
    }

    // Regular notification link
    if (id) {
      var base = (typeof APP_URL !== 'undefined' && APP_URL) ? APP_URL : (window.location.origin + '/bpc_clinic_system');
      $.post(base + '/api/notifications.php', {
        action: 'mark_read',
        id: id,
        csrf_token: $('meta[name="csrf-token"]').attr('content')
      });
    }

    if (!href || href === '#' || href === 'javascript:void(0)') {
      e.preventDefault();
      var targetUrl = getNotificationPageUrl() + (id ? '?id=' + encodeURIComponent(id) : '');
      window.location.href = targetUrl;
    }
  });

  // Accept SOS
  $(document).on('click', '#sosAcceptBtn', function () {
    updateSosStatus('accept');
  });

  // Resolve SOS
  $(document).on('click', '#sosResolveBtn', function () {
    updateSosStatus('resolve');
  });

  // ================================================================
  // Animated Counters (Optimized via requestAnimationFrame)
  // ================================================================
  function animateCounterElement(el) {
    const $el = $(el);
    if ($el.data('counted')) return;
    $el.data('counted', true);
    const target = parseInt($el.data('count')) || 0;
    const duration = 1000;
    const startTime = performance.now();

    if (target === 0) {
      $el.text('0');
      return;
    }

    function update(now) {
      const elapsed = now - startTime;
      const progress = Math.min(elapsed / duration, 1);
      const easeProgress = 1 - Math.pow(1 - progress, 3);
      const current = Math.floor(easeProgress * target);
      $el.text(current.toLocaleString());
      if (progress < 1) {
        requestAnimationFrame(update);
      } else {
        $el.text(target.toLocaleString());
      }
    }
    requestAnimationFrame(update);
  }

  function animateCounters() {
    $('.counter-animate').each(function () {
      animateCounterElement(this);
    });
  }

  // Use IntersectionObserver for counter animation on scroll
  if ('IntersectionObserver' in window) {
    const observer = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          animateCounterElement(entry.target);
          observer.unobserve(entry.target);
        }
      });
    }, { threshold: 0.2 });

    $('.counter-animate').each(function () {
      observer.observe(this);
    });
  } else {
    animateCounters();
  }

  // ================================================================
  // Tooltip Initialization
  // ================================================================
  if (typeof bootstrap !== 'undefined') {
    $('[data-bs-toggle="tooltip"]').each(function () {
      new bootstrap.Tooltip(this);
    });
  }

}); // end DOM ready

// ================================================================
// Escape HTML helper (global — avoids XSS in dynamic notification HTML)
// ================================================================
function escapeHtml(text) {
  if (text === null || text === undefined) return '';
  return String(text)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

// ================================================================
// Notifications
// ================================================================
function loadNotifications() {
  var base = (typeof APP_URL !== 'undefined' && APP_URL) ? APP_URL : (window.location.origin + '/bpc_clinic_system');
  var notifPageUrl = typeof window.getNotificationPageUrl === 'function'
    ? window.getNotificationPageUrl()
    : (window.NOTIFICATION_PAGE_URL || 'notifications.php');

  $.get(base + '/api/notifications.php', { action: 'latest' }, function (res) {
    if (!res || !res.success) return;
    const unread = res.unread_count || 0;
    const $badge = $('#notifBadge');

    // Show the badge when there are unread notifications (red indicator)
    if (unread > 0) {
      $badge.text(unread).removeClass('d-none');
    } else {
      $badge.addClass('d-none');
    }

    const $list = $('#notifList').empty();
    if (!res.data || res.data.length === 0) {
      $list.append('<li><a class="dropdown-item text-muted small text-center" href="#">No notifications</a></li>');
    } else {
      const userRole = (window.MICA_USER && window.MICA_USER.role)
        || (document.getElementById('mica-assistant') && document.getElementById('mica-assistant').getAttribute('data-mica-role'))
        || 'student';
      const isStaff = userRole === 'nurse' || userRole === 'admin';

      res.data.slice(0, 12).forEach(function (n) {
        const timeAgo = (typeof timeSince === 'function') ? timeSince(n.created_at || '') : 'Just now';
        const isSos = n.type === 'emergency' && n.sos && n.sos.emergency_id;
        const titleSafe = escapeHtml(n.title || 'Notification');
        const msgSafe = escapeHtml(n.message || '');
        const notifId = n.id ? encodeURIComponent(n.id) : '';

        if (isSos && isStaff) {
          // ---- Emergency SOS notification (red indicator, opens details) ----
          const sos = n.sos;
          const sosStatus = sos.sos_status || 'active';
          const statusLabel = sosStatus.charAt(0).toUpperCase() + sosStatus.slice(1);
          const hasLocation = sos.latitude != null && sos.longitude != null && sos.latitude !== '' && sos.longitude !== '';

          const sosEl = document.createElement('li');
          const sosJson = encodeURIComponent(JSON.stringify(sos));
          sosEl.innerHTML =
            `<a href="#" class="dropdown-item notif-dropdown-item sos-notif-item ${n.is_read ? '' : 'unread'}"
                data-id="${notifId}"
                data-sos="${sosJson}">
               <div class="d-flex align-items-start gap-2">
                 <div class="mt-1 flex-shrink-0">
                   <span class="notif-dot" style="background:var(--danger); box-shadow:0 0 0 3px rgba(220,53,69,0.2);"></span>
                 </div>
                 <div class="flex-grow-1">
                   <div class="small fw-semibold text-danger">${titleSafe}</div>
                   <div class="text-muted" style="font-size:.75rem">${msgSafe}</div>
                   <div class="d-flex align-items-center gap-2 mt-1">
                     <span class="badge bg-danger" style="font-size:.6rem;">${statusLabel}</span>
                     ${hasLocation ? '<span class="badge bg-primary" style="font-size:.6rem;"><i class="bi bi-geo-alt-fill me-1"></i>GPS</span>' : ''}
                     <span class="text-muted" style="font-size:.65rem">${timeAgo}</span>
                   </div>
                 </div>
               </div>
             </a>`;
          $list.append(sosEl);
        } else {
          // ---- Regular notification ----
          const dotColor = n.type === 'emergency'
            ? 'background:var(--danger);'
            : n.type === 'appointment'
              ? 'background:var(--warning);'
              : n.type === 'health'
                ? 'background:var(--success);'
                : 'background:var(--info);';

          let link = n.link;
          if (!link || link === '#' || link === 'javascript:void(0)') {
            link = notifPageUrl + (notifId ? '?id=' + notifId : '');
          }

          $list.append(
            `<li><a class="dropdown-item notif-dropdown-item notif-link ${n.is_read ? '' : 'unread'}" href="${escapeHtml(link)}" data-id="${notifId}">
               <div class="d-flex align-items-start gap-2">
                 <div class="mt-1 flex-shrink-0">
                   <span class="notif-dot" style="${dotColor}"></span>
                 </div>
                 <div class="flex-grow-1">
                   <div class="small fw-semibold ${n.is_read ? '' : 'text-primary'}">${titleSafe}</div>
                   <div class="text-muted" style="font-size:.75rem">${msgSafe}</div>
                   <div class="text-muted" style="font-size:.65rem">${timeAgo}</div>
                 </div>
               </div>
             </a></li>`
          );
        }
      });

      // Always show a "View all notifications" link to the full Notification Center.
      $list.append('<li><hr class="dropdown-divider"></li>');
      $list.append(`<li><a class="dropdown-item text-center small text-primary" href="${notifPageUrl}"><i class="bi bi-bell me-1"></i>View all notifications</a></li>`);
    }
  }, 'json').fail(function (xhr, status, err) {
    if (window.console && console.debug) {
      console.debug('[Notifications] Poll failed (will retry):', status, err);
    }
  });
}

window.loadNotifications = loadNotifications;

// ================================================================
// SOS Details Modal — populate with full student + GPS details
// ================================================================
function openSosDetailsModal(sos) {
  if (!$('#sosDetailsModal').length) return;

  var isStaff = (window.MICA_USER && (window.MICA_USER.role === 'nurse' || window.MICA_USER.role === 'admin'));

  var studentName = [sos.first_name, sos.last_name].filter(Boolean).join(' ') || 'Unknown Student';
  var hasGps = sos.latitude != null && sos.longitude != null && sos.latitude !== '' && sos.longitude !== '';
  var mapsUrl = hasGps ? 'https://www.google.com/maps?q=' + encodeURIComponent(sos.latitude + ',' + sos.longitude) : '#';
  var isManual = sos.location_source === 'manual' || (!hasGps && sos.location);

  var locationHtml = '';
  if (hasGps) {
    var latF = Number(sos.latitude).toFixed(6);
    var lngF = Number(sos.longitude).toFixed(6);
    var accLabel = sos.location_accuracy ? 'Accuracy: ±' + escapeHtml(sos.location_accuracy) : 'Accuracy: ±8 meters';
    locationHtml =
      '<div class="p-3 bg-light rounded-3 border border-danger-subtle">' +
        '<div class="fw-bold text-danger mb-1"><i class="bi bi-crosshair me-1"></i> Current Location (Live GPS):</div>' +
        '<div class="fs-6 fw-bold text-dark mb-1">' + latF + ', ' + lngF + '</div>' +
        '<div class="small text-muted mb-2">' + accLabel + ' · Updated: ' + (timeLabel || 'Just now') + '</div>' +
        '<a href="' + mapsUrl + '" target="_blank" rel="noopener" class="btn btn-sm btn-danger">' +
          '<i class="bi bi-box-arrow-up-right me-1"></i> View on Map' +
        '</a>' +
      '</div>';
  } else if (isManual && sos.location) {
    var repName = [sos.reporter_first_name, sos.reporter_last_name].filter(Boolean).join(' ');
    locationHtml =
      '<div class="p-3 bg-light rounded-3 border border-primary-subtle">' +
        '<div class="fw-bold text-primary mb-1"><i class="bi bi-geo-alt-fill text-danger me-1"></i> Reported Location:</div>' +
        '<div class="fs-6 fw-bold text-dark mb-1">' + escapeHtml(sos.location) + '</div>' +
        '<div class="small text-muted">Manually reported by Adviser' + (repName ? ' (' + escapeHtml(repName) + ')' : '') + '</div>' +
      '</div>';
  } else {
    locationHtml = '<div class="p-2 rounded-3 bg-light text-muted small"><i class="bi bi-info-circle me-1"></i>Location unavailable.</div>';
  }

  var html =
    '<div class="mb-3 text-center">' +
      '<div class="display-6 text-danger"><i class="bi bi-exclamation-triangle-fill"></i></div>' +
      '<h5 class="fw-bold mb-0">' + escapeHtml(studentName) + '</h5>' +
      '<div class="mt-1">' + statusBadge + '</div>' +
    '</div>' +
    '<hr>' +
    '<div class="row g-2 small">' +
      '<div class="col-6"><div class="text-muted">Student ID</div><div class="fw-semibold">' + escapeHtml(sos.student_number || 'N/A') + '</div></div>' +
      '<div class="col-6"><div class="text-muted">Course</div><div class="fw-semibold">' + escapeHtml(sos.course || 'N/A') + '</div></div>' +
      '<div class="col-6"><div class="text-muted">Year Level</div><div class="fw-semibold">' + escapeHtml(sos.year_level || 'N/A') + '</div></div>' +
      '<div class="col-6"><div class="text-muted">Contact Number</div><div class="fw-semibold">' + escapeHtml(contact) + '</div></div>' +
      '<div class="col-12"><div class="text-muted">Emergency Type</div><div class="fw-semibold text-danger">' + escapeHtml(sos.emergency_type || 'N/A') + '</div></div>' +
      '<div class="col-12"><div class="text-muted">Message</div><div class="fw-semibold">' + escapeHtml(sos.description || 'N/A') + '</div></div>' +
      '<div class="col-6"><div class="text-muted">Date</div><div class="fw-semibold">' + dateLabel + '</div></div>' +
      '<div class="col-6"><div class="text-muted">Time</div><div class="fw-semibold">' + timeLabel + '</div></div>' +
    '</div>' +
    '<hr>' +
    '<div>' +
      '<div class="text-muted small mb-1 fw-semibold">Location</div>' +
      locationHtml +
    '</div>';

  $('#sosDetailsBody').html(html);

  // Store the full SOS object + emergency id for Accept/Resolve
  $('#sosDetailsModal').data('sos', sos);
  $('#sosDetailsModal').data('emergency-id', sos.emergency_id);
  $('#sosDetailsModal').data('sos-status', sos.sos_status || 'active');

  // Show Accept/Resolve actions only for staff (nurse/admin)
  var $footer = $('#sosDetailsFooter');
  var $acceptBtn = $('#sosAcceptBtn');
  var $resolveBtn = $('#sosResolveBtn');
  var status = sos.sos_status || 'active';

  if (isStaff) {
    $footer.show();
    // Disable actions appropriately based on status
    if (status === 'resolved' || status === 'false_alarm') {
      $acceptBtn.prop('disabled', true).addClass('disabled');
      $resolveBtn.prop('disabled', true).addClass('disabled');
    } else if (status === 'responded') {
      $acceptBtn.prop('disabled', true).addClass('disabled');
      $resolveBtn.prop('disabled', false).removeClass('disabled');
    } else {
      $acceptBtn.prop('disabled', false).removeClass('disabled');
      $resolveBtn.prop('disabled', false).removeClass('disabled');
    }
  } else {
    $footer.hide();
  }

  var modalEl = document.getElementById('sosDetailsModal');
  var bsModal = bootstrap.Modal.getOrCreateInstance(modalEl);
  bsModal.show();
}

// ================================================================
// SOS — Accept / Resolve
// ================================================================
function updateSosStatus(status) {
  var modalEl = document.getElementById('sosDetailsModal');
  if (!modalEl) return;
  var emergencyId = $(modalEl).data('emergency-id');
  if (!emergencyId) return;

  var $btn = status === 'accept' ? $('#sosAcceptBtn') : $('#sosResolveBtn');
  var originalHtml = $btn.html();
  $btn.html('<span class="spinner-border spinner-border-sm me-1"></span> Updating...').prop('disabled', true);

  $.post(APP_URL + '/api/ai_assistant.php', {
    action: 'sos_update_status',
    emergency_id: emergencyId,
    status: status,
    csrf_token: $('meta[name="csrf-token"]').attr('content')
  }, function (res) {
    $btn.html(originalHtml).prop('disabled', false);
    if (res && res.success) {
      Swal.fire({ icon: 'success', title: 'SOS ' + (status === 'accept' ? 'Accepted' : 'Resolved'), text: res.message || 'Status updated.', timer: 3000, timerProgressBar: true });
      // Refresh notifications & re-render the modal with the updated status
      loadNotifications();
      var sos = { ...($(modalEl).data('sos') || {}), sos_status: status === 'accept' ? 'responded' : 'resolved' };
      $(modalEl).data('sos', sos);
      openSosDetailsModal(sos);
    } else {
      Swal.fire({ icon: 'error', title: 'Error', text: (res && res.message) || 'Failed to update SOS status.' });
    }
  }, 'json').fail(function () {
    $btn.html(originalHtml).prop('disabled', false);
    Swal.fire({ icon: 'error', title: 'Error', text: 'Network error. Please try again.' });
  });
}

// ================================================================
// Time Since Helper
// ================================================================
function timeSince(dateStr) {
  const now = new Date();
  const date = new Date(dateStr.replace(' ', 'T') + 'Z');
  const seconds = Math.floor((now - date) / 1000);
  if (seconds < 60) return 'Just now';
  const minutes = Math.floor(seconds / 60);
  if (minutes < 60) return minutes + 'm ago';
  const hours = Math.floor(minutes / 60);
  if (hours < 24) return hours + 'h ago';
  const days = Math.floor(hours / 24);
  if (days < 7) return days + 'd ago';
  return date.toLocaleDateString();
}

// ================================================================
// SweetAlert Wrappers
// ================================================================
function confirmAction(message, callback) {
  Swal.fire({
    title: 'Are you sure?',
    text: message,
    icon: 'question',
    showCancelButton: true,
    confirmButtonColor: '#0B6B3A',
    cancelButtonColor: '#8A94A0',
    confirmButtonText: 'Yes, proceed',
    cancelButtonText: 'Cancel',
    reverseButtons: true,
    customClass: {
      confirmButton: 'btn btn-primary me-2',
      cancelButton: 'btn btn-secondary',
    },
    buttonsStyling: false,
  }).then(function (result) {
    if (result.isConfirmed) callback();
  });
}

function toastSuccess(msg) {
  Swal.fire({
    icon: 'success',
    title: msg,
    timer: 2000,
    showConfirmButton: false,
    position: 'top-end',
    toast: true,
    customClass: {
      popup: 'rounded-4 shadow-lg',
    },
  });
}

function toastError(msg) {
  Swal.fire({
    icon: 'error',
    title: 'Error',
    text: msg,
    confirmButtonColor: '#0B6B3A',
    customClass: {
      confirmButton: 'btn btn-primary',
    },
    buttonsStyling: false,
  });
}

// ================================================================
// Loading Overlay Helper
// ================================================================
function showLoading(show) {
  if (show) {
    $('body').append(
      '<div class="loading-overlay"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div></div>'
    );
  } else {
    $('.loading-overlay').remove();
  }
}

// ================================================================
// DataTable Defaults (Premium styling)
// ================================================================
if (typeof $.fn.DataTable !== 'undefined') {
  $.extend(true, $.fn.dataTable.defaults, {
    language: {
      search: '<i class="bi bi-search me-1"></i>',
      searchPlaceholder: 'Search...',
      lengthMenu: '_MENU_ per page',
      emptyTable: 'No data available',
    },
    dom: '<"d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2"<"d-flex align-items-center gap-2"l><"d-flex"f>>t<"d-flex justify-content-between align-items-center mt-3 flex-wrap gap-2"<"d-flex align-items-center"i><"d-flex"p>>',
    pageLength: 10,
    lengthMenu: [[5, 10, 25, 50, -1], [5, 10, 25, 50, 'All']],
  });
}

// ================================================================
// Date inputs — context-aware min/max constraints
// ----------------------------------------------------
// Birthdate / date-of-birth fields must allow PAST dates up to today
// (max = today) and must NOT be forced to >= today. Other date inputs
// (appointments, follow-ups, expiration) default to "today or later"
// only when no `min` attribute is already present.
// ================================================================
(function () {
  var birthdateNames = /^(birthdate|date_of_birth|birth_date)$/i;
  var today = function () { return new Date().toISOString().split('T')[0]; };

  $(document).on('focus', 'input[type="date"]', function () {
    var $this = $(this);
    var name = $this.attr('name') || '';

    if (birthdateNames.test(name)) {
      // Birthdate: allow any past date up to (and including) today.
      if (!$this.attr('max')) { $this.attr('max', today()); }
      // Never impose a `min` on birthdate fields.
      return;
    }

    // All other date inputs: enforce "today or later" if no min set.
    if (!$this.attr('min')) { $this.attr('min', today()); }
  });
})();

// ================================================================
// Logout Confirmation (SweetAlert2)
// ================================================================
$(document).on('click', '.logout-link', function (e) {
  e.preventDefault();
  const logoutUrl = $(this).data('logout-url') || APP_URL + '/auth/logout.php';
  Swal.fire({
    title: 'Logout Confirmation',
    text: 'Are you sure you want to logout?',
    icon: 'question',
    showCancelButton: true,
    confirmButtonColor: '#D64545',
    cancelButtonColor: '#8A94A0',
    confirmButtonText: 'Logout',
    cancelButtonText: 'Cancel',
    reverseButtons: true,
    customClass: {
      confirmButton: 'btn btn-danger me-2',
      cancelButton: 'btn btn-secondary',
    },
    buttonsStyling: false,
  }).then(function (result) {
    if (result.isConfirmed) {
      window.location.href = logoutUrl;
    }
  });
});

// ================================================================
// Delete Confirmation (SweetAlert2) - Global
// ================================================================
function confirmDelete(callback) {
  Swal.fire({
    title: 'Delete Confirmation',
    text: 'This action cannot be undone.',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#D64545',
    cancelButtonColor: '#8A94A0',
    confirmButtonText: 'Delete',
    cancelButtonText: 'Cancel',
    reverseButtons: true,
    customClass: {
      confirmButton: 'btn btn-danger me-2',
      cancelButton: 'btn btn-secondary',
    },
    buttonsStyling: false,
  }).then(function (result) {
    if (result.isConfirmed) callback();
  });
}

// ================================================================
// Profile Image Preview
// ================================================================
function previewProfileImage(event) {
  const file = event.target.files[0];
  if (file) {
    const reader = new FileReader();
    reader.onload = function (e) {
      $('#profilePic').attr('src', e.target.result);
    };
    reader.readAsDataURL(file);
  }
}

$(document).on('change', '#picInput', function () {
  const file = this.files[0];
  if (file) {
    const reader = new FileReader();
    reader.onload = function (e) {
      $('#profilePicPreview').attr('src', e.target.result);
      $('#profilePicDropzone').addClass('has-image');
    };
    reader.readAsDataURL(file);
  }
});
