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
  // Skip-link target — give the main region a stable id once
  // ================================================================
  (function () {
    if (!document.getElementById('mainContent')) {
      var main = document.querySelector('.main-content');
      if (main) main.id = 'mainContent';
    }
  })();

  // ================================================================
  // Sidebar Toggle (Mobile) - hamburger button + scrim
  // ================================================================
  var $sidebar = $('.sidebar');
  var $scrim = $('#sidebarScrim');

  // Drawer open/close. Visibility is owned by the .is-visible class in
  // responsive.css so the fade transition can run; the hidden attribute
  // would kill it by forcing display:none.
  function setSidebar(open) {
    $sidebar.toggleClass('show', open);
    $scrim.toggleClass('is-visible', open);
    $('#sidebarToggle').attr('aria-expanded', open ? 'true' : 'false');
    $('.mica-fab').toggleClass('is-dimmed', open);
  }

  $('#sidebarToggle').on('click', function () {
    setSidebar(!$sidebar.hasClass('show'));
  });

  if ($scrim && $scrim.length) {
    $scrim.on('click', function () {
      setSidebar(false);
    });
  }

  // Close sidebar on outside click (mobile)
  $(document).on('click', function (e) {
    if (window.innerWidth < 1200) {
      if (!$(e.target).closest('.sidebar, #sidebarToggle, #sidebarScrim').length) {
        setSidebar(false);
      }
    }
  });

  $(document).on('keydown', function (e) {
    if (e.key === 'Escape' && $sidebar.hasClass('show')) {
      setSidebar(false);
      $('#sidebarToggle').trigger('focus');
    }
  });

  // ================================================================
  // Sidebar Collapse Toggle (Desktop) - pin button
  // ================================================================
  const storedCollapsed = localStorage.getItem('sidebar-collapsed') === 'true';

  // The collapsed class is the single source of truth. All geometry
  // (sidebar width and main-content offset) lives in design-system.css
  // and responsive.css, so this never writes inline styles that would
  // fight the stylesheet or drift from the breakpoints.
  const SIDEBAR_RAIL_MIN = 1360;
  const SIDEBAR_DRAWER_MAX = 1199;

  function applySidebarState(collapsed) {
    $('.sidebar').toggleClass('collapsed', !!collapsed);
    localStorage.setItem('sidebar-collapsed', !!collapsed);
  }

  // The rail is the default between 1200px and 1359px, so honour the
  // stored preference but never let it leak into drawer mode.
  if (window.innerWidth >= SIDEBAR_DRAWER_MAX + 1) {
    applySidebarState(storedCollapsed);
  }

  $(document).on('click', '.sidebar-toggle-collapse', function (e) {
    e.stopPropagation();
    applySidebarState(!$('.sidebar').hasClass('collapsed'));
  });

  // Reset sidebar on window resize crossing a breakpoint
  let lastWidth = window.innerWidth;
  $(window).on('resize', function () {
    const w = window.innerWidth;
    const crossedDrawer = (lastWidth <= SIDEBAR_DRAWER_MAX && w > SIDEBAR_DRAWER_MAX)
      || (lastWidth > SIDEBAR_DRAWER_MAX && w <= SIDEBAR_DRAWER_MAX);
    const crossedRail = (lastWidth < SIDEBAR_RAIL_MIN && w >= SIDEBAR_RAIL_MIN)
      || (lastWidth >= SIDEBAR_RAIL_MIN && w < SIDEBAR_RAIL_MIN);

    if (crossedDrawer) {
      if (w <= SIDEBAR_DRAWER_MAX) {
        // Entering drawer mode: close the drawer and clear the rail state
        setSidebar(false);
        $('.sidebar').removeClass('collapsed');
      } else {
        applySidebarState(localStorage.getItem('sidebar-collapsed') === 'true');
        setSidebar(false);
      }
    } else if (crossedRail) {
      // Entering the icon-rail range the stylesheet owns the width, so
      // drop the class to avoid a stale pin state on the way back out.
      if (w < SIDEBAR_RAIL_MIN) applySidebarState(false);
    }
    lastWidth = w;
  });

  // ================================================================
  // Dark Mode Toggle
  // ================================================================
  const darkToggle = $('#darkModeToggle');
  const storedTheme = localStorage.getItem('clinic-theme') || 'light';

  function applyTheme(theme) {
    const isDark = theme === 'dark';
    if (isDark) {
      document.documentElement.setAttribute('data-theme', 'dark');
    } else {
      document.documentElement.removeAttribute('data-theme');
    }
    darkToggle
      .attr('title', isDark ? 'Switch to light theme' : 'Switch to dark theme')
      .attr('aria-label', isDark ? 'Switch to light theme' : 'Switch to dark theme')
      .html(isDark ? darkToggle.attr('data-icon-sun') : darkToggle.attr('data-icon-moon'));
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
    const isCompact = window.matchMedia('(max-width: 767.98px)').matches;
    const options = isCompact
      ? { month: 'short', day: 'numeric', timeZone: 'Asia/Manila' }
      : { weekday: 'short', year: 'numeric', month: 'short', day: 'numeric', timeZone: 'Asia/Manila' };
    const dateStr = now.toLocaleDateString('en-US', options);
    const timeStr = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true, timeZone: 'Asia/Manila' });

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

  $(document).on('click', '#markAllNotificationsRead', function () {
    var button = this; button.disabled = true;
    markAllNotificationsRead(function (error) {
      button.disabled = false;
      $('#notificationActionStatus').text(error || 'All notifications marked as read.');
    });
  });
  $(document).on('click', '#notifList .notif-mark-read', function (event) {
    event.preventDefault(); event.stopPropagation();
    var button = this; button.disabled = true;
    markNotificationRead($(button).attr('data-id'), function (error) {
      button.disabled = false;
      $('#notificationActionStatus').text(error || 'Notification marked as read.');
    });
  });
  $('#notificationBell').on('show.bs.dropdown', function () { loadNotifications(); });

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
            markNotificationRead(id);
          }
        } catch (err) {
          console.warn('[Notifications] Failed to parse SOS details:', err);
        }
      }
      return;
    }

    // Read state is persisted before navigating, including the badge refresh.
    e.preventDefault();
    var destination = (!href || href === '#' || href === 'javascript:void(0)')
      ? getNotificationPageUrl() + (id ? '?id=' + encodeURIComponent(id) : '') : href;
    markNotificationRead(id, function (error) {
      if (error) { $('#notificationActionStatus').text(error); return; }
      window.location.href = destination;
    });
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

  // ================================================================
  // SOS — open modal from sidebar floating action
  // ================================================================
  $(document).on('click', '[data-action="open-sos"]', function (e) {
    e.preventDefault();
    if (typeof MICA !== 'undefined' && MICA.open) {
      MICA.open();
    }
    var sosModal = $('#sosModal');
    if (sosModal.length) {
      sosModal.modal('show');
    }
  });

  // ================================================================
  // Double-submit guard
  // ----------------------------------------------------------------
  // Disables a form's submit button while its request is in
  // flight, then restores it. Prevents the duplicate records
  // caused by an impatient double-click.
  //
  //   * The button is disabled the moment the form submits.
  //   * It is restored as soon as the POST that the submit
  //     triggered completes (background GET polling never
  //     restores it, so a slow save cannot be re-clicked).
  //   * A safety sweep restores buttons that were disabled
  //     by a submit which never reached the network (for
  //     example, one stopped by client-side validation), so
  //     no form is ever left permanently disabled.
  //   * Opt out with the .no-auto-disable class.
  // ================================================================
  $(document).on('submit', 'form', function () {
    var $btn = $(this).find(
      'button[type="submit"]:not(.no-auto-disable), input[type="submit"]:not(.no-auto-disable)'
    ).first();
    if ($btn.length) {
      $btn.prop('disabled', true).addClass('is-busy');
    }
  });

  $(document).on('ajaxComplete', function (event, xhr, settings) {
    if (settings && settings.type && String(settings.type).toUpperCase() === 'POST') {
      $('form .is-busy').prop('disabled', false).removeClass('is-busy');
    }
  });

  // Safety sweep: only runs when no request is in flight, so a
  // genuinely busy save is never interrupted.
  setInterval(function () {
    if (window.jQuery && jQuery.active === 0) {
      $('form .is-busy').prop('disabled', false).removeClass('is-busy');
    }
  }, 3000);

}); // end DOM ready

// ================================================================
// BPCModal — single source of truth for every Bootstrap modal.
//
// Root cause fixed here: page scripts previously called
//   new bootstrap.Modal(el).show()
// on every open. That constructor call creates a NEW instance
// each time and overwrites the one stored on the element, so
// the old instance's listeners (hidden.bs.modal cleanup,
// backdrop removal) keep firing alongside the new one's. The
// visible symptoms were stacked backdrops, a stuck dim layer
// after close, and modals that stop responding after first use.
//
// getOrCreateInstance() returns the existing instance when there
// is one and only creates it once, so open/close/reopen is
// idempotent.
// ================================================================
window.BPCModal = (function () {
  'use strict';

  function resolve(el) {
    if (!el) return null;
    if (typeof bootstrap === 'undefined' || !bootstrap.Modal) {
      // Bootstrap failed to load — degrade to a plain class
      // toggle so the dialog is still reachable rather than dead.
      el.classList.add('show');
      el.setAttribute('aria-hidden', 'false');
      return null;
    }
    return bootstrap.Modal.getOrCreateInstance(el);
  }

  function get(target) {
    var el = typeof target === 'string' ? document.getElementById(target) : target;
    if (!el) {
      console.warn('[BPCModal] modal element not found:', target);
      return null;
    }
    return resolve(el);
  }

  function show(target) {
    var m = get(target);
    if (m && typeof m.show === 'function') m.show();
    return m;
  }

  function hide(target) {
    var m = get(target);
    if (m && typeof m.hide === 'function') m.hide();
    return m;
  }

  /**
   * Reset every form inside a modal that opted in via
   * data-reset-on-close, clearing the previous operation's
   * values so they never leak into the next open. Fields
   * marked data-preserve (e.g. hidden ids the opener
   * re-populates) are kept.
   */
  function resetForms(modalEl) {
    if (!modalEl || !modalEl.querySelector) return;
    var forms = modalEl.querySelectorAll('form[data-reset-on-close], [data-reset-on-close] form');
    Array.prototype.forEach.call(forms, function (form) {
      Array.prototype.forEach.call(form.elements, function (field) {
        if (field.hasAttribute('data-preserve')) return;
        var type = (field.type || '').toLowerCase();
        if (field.tagName === 'SELECT') {
          field.selectedIndex = 0;
        } else if (type === 'checkbox' || type === 'radio') {
          field.checked = field.hasAttribute('checked');
        } else if (type !== 'button' && type !== 'submit' && type !== 'reset' && type !== 'hidden') {
          field.value = '';
        }
      });
      var msgs = form.querySelectorAll('.invalid-feedback, .form-error');
      Array.prototype.forEach.call(msgs, function (n) { n.textContent = ''; });
      Array.prototype.forEach.call(form.querySelectorAll('.is-invalid, .is-valid'), function (n) {
        n.classList.remove('is-invalid', 'is-valid');
      });
    });
  }

  // One delegated listener: whenever any modal finishes hiding,
  // reset its opted-in forms. Bound once, so it never duplicates.
  document.addEventListener('hidden.bs.modal', function (e) {
    resetForms(e.target);
    // Safety net: if a script error interrupted Bootstrap's own
    // cleanup, an orphaned .modal-backdrop can stay behind and
    // keep the page dimmed/unscrollable. When no modal remains
    // open, clear any leftover backdrop and restore scrolling.
    window.setTimeout(function () {
      if (document.querySelector('.modal.show')) return;
      document.querySelectorAll('.modal-backdrop').forEach(function (bd) { bd.remove(); });
      document.body.classList.remove('modal-open');
      document.body.style.paddingRight = '';
      document.body.style.overflow = '';
    }, 60);
  });

  return { get: get, show: show, hide: hide, resetForms: resetForms };
})();

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
  if (window.__notificationRequestPending) { window.__notificationRefreshQueued = true; return; }
  window.__notificationRequestPending = true;
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
      $badge.text(unread > 99 ? '99+' : unread).removeClass('d-none');
      $('#notificationBell').attr('aria-label', unread + ' unread notifications');
    } else {
      $badge.addClass('d-none');
      $('#notificationBell').attr('aria-label', 'Notifications, no unread notifications');
    }

    const $list = $('#notifList').empty();
    if (!res.data || res.data.length === 0) {
      $list.append('<li><a class="dropdown-item text-muted small text-center" href="#">No new notifications.</a></li>');
    } else {
      const userRole = (window.MICA_USER && window.MICA_USER.role)
        || (document.getElementById('mica-assistant') && document.getElementById('mica-assistant').getAttribute('data-mica-role'))
        || 'student';
      const isStaff = userRole === 'nurse' || userRole === 'admin';

      res.data.slice(0, 12).forEach(function (n) {
        const timeAgo = (typeof timeSince === 'function') ? timeSince(n.created_at || '') : 'Just now';
        const unreadItem = Number(n.is_read) === 0;
        const metaSafe = escapeHtml(n.type_label || n.type || 'Notification');
        const readControls = '<div class="notif-row-meta"><span>' + metaSafe + ' / ' + (unreadItem ? 'Unread' : 'Read') + '</span>' + (unreadItem ? '<button type="button" class="notif-mark-read" data-id="' + encodeURIComponent(n.id) + '">Mark as Read</button>' : '') + '</div>';
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
            `<a href="#" class="dropdown-item notif-dropdown-item sos-notif-item ${unreadItem ? 'unread' : ''}"
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
                     <span class="badge bg-danger" style="font-size:.6rem;">${escapeHtml(statusLabel)}</span>
                     ${hasLocation ? '<span class="badge bg-primary" style="font-size:.6rem;"><i class="bi bi-geo-alt-fill me-1"></i>GPS</span>' : ''}
                     <span class="text-muted" style="font-size:.65rem">${escapeHtml(timeAgo)}</span>
                   </div>
                 </div>
               </div>
             </a>`;
          sosEl.insertAdjacentHTML('beforeend', readControls);
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
          try { if (link && new URL(link, window.location.href).origin !== window.location.origin) link = null; } catch (error) { link = null; }
          if (!link || link === '#' || link === 'javascript:void(0)') {
            link = notifPageUrl + (notifId ? '?id=' + notifId : '');
          }

          $list.append(
            `<li><a class="dropdown-item notif-dropdown-item notif-link ${unreadItem ? 'unread' : ''}" href="${escapeHtml(link)}" data-id="${notifId}">
               <div class="d-flex align-items-start gap-2">
                 <div class="mt-1 flex-shrink-0">
                   <span class="notif-dot" style="${dotColor}"></span>
                 </div>
                 <div class="flex-grow-1">
                   <div class="small fw-semibold ${unreadItem ? 'text-primary' : ''}">${titleSafe}</div>
                   <div class="text-muted" style="font-size:.75rem">${msgSafe}</div>
                   <div class="text-muted" style="font-size:.65rem" title="${escapeHtml(n.created_at || '')}">${escapeHtml(timeAgo)} / ${escapeHtml(n.created_at || '')}</div>
                 </div>
               </div>
             </a>${readControls}</li>`
          );
        }
      });


    }
  }, 'json').fail(function (xhr, status, err) {
    if (window.console && console.debug) {
      console.debug('[Notifications] Poll failed (will retry):', status, err);
      $('#notificationActionStatus').text('Unable to refresh notifications. Retrying automatically.');
    }
  }).always(function () {
    window.__notificationRequestPending = false;
    if (window.__notificationRefreshQueued) { window.__notificationRefreshQueued = false; loadNotifications(); }
  });
}

window.loadNotifications = loadNotifications;

// ================================================================
// SOS Details Modal — populate with full student + GPS details
// ================================================================
function openSosDetailsModal(sos) {
  if (!$('#sosDetailsModal').length) return;

  var isStaff = (window.MICA_USER && (window.MICA_USER.role === 'nurse' || window.MICA_USER.role === 'admin'));

  var studentName = [sos.first_name, sos.last_name].filter(Boolean).join(' ') || 'Unknown reporter';
  var hasGps = bpcSosHasGps(sos);
  var mapsUrl = hasGps ? 'https://www.google.com/maps?q=' + encodeURIComponent(sos.latitude + ',' + sos.longitude) : '#';
  var isManual = sos.location_source === 'manual' || (!hasGps && sos.location);
  var statusLabels = { new:'New', active:'Active', acknowledged:'Accepted', responding:'Responding', responded:'Responded', resolved:'Resolved', cancelled:'Cancelled', false_alarm:'False Alarm' };
  var statusColors = { active: 'danger', responded: 'warning text-dark', resolved: 'success', false_alarm: 'secondary' };
  var statusBadge = '<span class="badge bg-' + (statusColors[sos.sos_status] || 'secondary') + '">' + (statusLabels[sos.sos_status] || (sos.sos_status || 'Unknown')) + '</span>';
  var contact = sos.contact_number || sos.phone || sos.mobile || sos.reporter_phone || 'N/A';
  var dateValue = sos.created_at || sos.sos_created_at || sos.location_updated_at || null;
  var dateLabel = dateValue ? new Date(dateValue.replace(' ', 'T')).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) : 'N/A';
  var timeLabel = dateValue ? new Date(dateValue.replace(' ', 'T')).toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' }) : 'N/A';

  var locationHtml = '';
  if (hasGps) {
    var latF = Number(sos.latitude).toFixed(6);
    var lngF = Number(sos.longitude).toFixed(6);
    var accLabel = escapeHtml(bpcSosAccuracyLabel(sos.location_accuracy));
    locationHtml =
      '<div class="p-3 bg-light rounded-3 border border-danger-subtle">' +
        '<div class="fw-bold text-danger mb-1"><i class="bi bi-crosshair me-1"></i> Reported GPS estimate:</div>' +
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
      '<div class="col-6"><div class="text-muted">Student / Employee ID</div><div class="fw-semibold">' + escapeHtml(sos.student_number || sos.employee_id || 'N/A') + '</div></div>' +
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

  html += '<hr><div class="small">Role: ' + escapeHtml(sos.reporter_role || 'Unknown') + '<br>Responder: ' + escapeHtml(sos.responder_name || 'Unassigned') + '<br>Accepted: ' + escapeHtml(sos.acknowledged_at || 'Pending') + '<br>Responding: ' + escapeHtml(sos.responded_at || 'Pending') + '</div>';
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
  $acceptBtn.toggle(['new','active'].includes(status));
  $resolveBtn.toggle(!['resolved','cancelled','false_alarm'].includes(status));

  if (isStaff && sos.can_respond !== false) {
    $footer.show();
    // Disable actions appropriately based on status
    if (['resolved','cancelled','false_alarm'].includes(status)) {
      $acceptBtn.prop('disabled', true).addClass('disabled');
      $resolveBtn.prop('disabled', true).addClass('disabled');
    } else if (['acknowledged','responding','responded'].includes(status)) {
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
async function bpcSosAction(emergencyId, operation, buttons) {
  if (bpcSosAction.pending) return null;
  bpcSosAction.pending = true;
  var $buttons = $(buttons); $buttons.prop('disabled', true);
  var buttonSnapshots = [];
  document.querySelectorAll(buttons).forEach(function(button){buttonSnapshots.push([button,button.innerHTML]);button.innerHTML='<span class="spinner-border spinner-border-sm me-1"></span> Updating...';});
  try {
    var notes = '';
    if (operation === 'resolve') {
      var answer = await Swal.fire({title: 'Resolve this SOS?', input: 'textarea', inputLabel: 'Resolution notes', showCancelButton: true,
        inputValidator: function(value) { if (!value || !value.trim()) return 'Enter resolution notes.'; }});
      if (!answer.isConfirmed) return null;
      notes = answer.value.trim();
    }
    var response;
    try {
      response = await fetch(APP_URL + '/api/emergencies.php', {method: 'POST', credentials: 'same-origin',
        headers: {'Accept':'application/json','Content-Type':'application/x-www-form-urlencoded'},
        body: new URLSearchParams({action:'update_status', emergency_id:emergencyId, op:operation === 'accept' ? 'accept' : operation,
          resolution_notes:notes, csrf_token:$('meta[name="csrf-token"]').attr('content') || ''})});
    } catch (error) { throw new Error('Connection lost. Check the emergency status before retrying.'); }
    var result;
    try { result = await response.json(); } catch (error) { throw new Error('The server returned an unreadable response (HTTP ' + response.status + '). Refresh to check the current status.'); }
    if (!response.ok || !result.success) throw new Error(result.message || 'Emergency update failed (HTTP ' + response.status + ').');
    if (typeof loadNotifications === 'function') loadNotifications();
    window.dispatchEvent(new Event('bpc-sos-updated'));
    Swal.fire({icon:'success',title:'SOS updated',text:result.message,timer:2000});
    return result;
  } catch (error) { Swal.fire({icon:'error',title:'Emergency update failed',text:error.message}); return null; }
  finally { bpcSosAction.pending = false; buttonSnapshots.forEach(function(snapshot){snapshot[0].innerHTML=snapshot[1];}); $buttons.prop('disabled', false); }
}
async function updateSosStatus(status) {
  var modalEl = document.getElementById('sosDetailsModal');
  if (!modalEl) return;
  var sos = $(modalEl).data('sos') || {};
  var result = await bpcSosAction($(modalEl).data('emergency-id'), status, '#sosAcceptBtn, #sosResolveBtn');
  if (result) openSosDetailsModal(Object.assign({}, sos, result, {sos_status:result.status}));
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

// ================================================================
// UNIFIED TOAST SYSTEM
// ----------------------------------------------------------------
// One non-blocking feedback channel for every role and every page.
//   success / error / warning / info / emergency
// Desktop  bottom-right · Mobile bottom-centre
// Errors are non-blocking by design: the surrounding form or panel
// keeps its state so the user can correct and retry immediately.
// Blocking acknowledgement is still available via showErrorDialog().
// ================================================================
var BPC_ICON_PATHS = {
  success: '<path d="M20 6 9 17l-5-5"/>',
  error: '<circle cx="12" cy="12" r="9"/><path d="M15 9l-6 6M9 9l6 6"/>',
  warning: '<path d="M10.3 3.6 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.6a2 2 0 0 0-3.4 0Z"/><path d="M12 9v4M12 17h.01"/>',
  info: '<circle cx="12" cy="12" r="9"/><path d="M12 16v-4M12 8h.01"/>',
  emergency: '<path d="M12 2 4 6v6c0 5 3.4 9.2 8 10 4.6-.8 8-5 8-10V6l-8-4Z"/><path d="M12 8v4M12 16h.01"/>'
};

var BPC_ICON_CLOSE = '<path d="M18 6 6 18M6 6l12 12"/>';

function bpcToastIcon(kind) {
  return '<svg class="bpc-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" '
    + 'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
    + (BPC_ICON_PATHS[kind] || BPC_ICON_PATHS.info) + '</svg>';
}

function bpcToastStack() {
  var stack = document.getElementById('appToastStack');
  if (!stack) {
    stack = document.createElement('div');
    stack.id = 'appToastStack';
    stack.className = 'app-toast-stack';
    stack.setAttribute('role', 'region');
    stack.setAttribute('aria-label', 'Notifications');
    document.body.appendChild(stack);
  }
  return stack;
}

function dismissBpcToast(el) {
  if (!el || el.dataset.leaving === '1') return;
  el.dataset.leaving = '1';
  el.classList.add('is-leaving');
  window.setTimeout(function () { if (el.parentNode) el.parentNode.removeChild(el); }, 220);
}

/**
 * Unified toast.
 * @param {string} message
 * @param {string} [kind]  success | error | warning | info | emergency
 * @param {string} [title]
 */
function bpcToast(message, kind, title) {
  if (!message) return null;
  kind = kind || 'info';

  var stack = bpcToastStack();
  var el = document.createElement('div');
  el.className = 'app-toast app-toast--' + kind;
  el.setAttribute('role', (kind === 'error' || kind === 'emergency') ? 'alert' : 'status');
  el.setAttribute('aria-live', (kind === 'error' || kind === 'emergency') ? 'assertive' : 'polite');

  var body = '';
  if (title) {
    body += '<div class="app-toast__title">' + escapeHtml(String(title)) + '</div>';
    body += '<p class="app-toast__text">' + escapeHtml(String(message)) + '</p>';
  } else {
    body += '<div class="app-toast__title">' + escapeHtml(String(message)) + '</div>';
  }

  el.innerHTML = '<span class="app-toast__icon">' + bpcToastIcon(kind) + '</span>'
    + '<div class="app-toast__body">' + body + '</div>'
    + '<button type="button" class="app-toast__close" aria-label="Dismiss notification">'
    + '<svg class="bpc-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" '
    + 'stroke-linecap="round" aria-hidden="true" focusable="false">' + BPC_ICON_CLOSE + '</svg>'
    + '</button>';

  el.querySelector('.app-toast__close').addEventListener('click', function () { dismissBpcToast(el); });

  stack.appendChild(el);

  var ttl = (kind === 'error' || kind === 'emergency') ? 7000 : 3200;
  var timer = window.setTimeout(function () { dismissBpcToast(el); }, ttl);

  // Pause the countdown while the pointer rests on the toast.
  el.addEventListener('mouseenter', function () { window.clearTimeout(timer); });
  el.addEventListener('mouseleave', function () { window.clearTimeout(timer); dismissBpcToast(el); });

  // Never let the stack grow past a readable number of rows.
  while (stack.children.length > 4) { dismissBpcToast(stack.firstElementChild); }

  return el;
}

window.bpcToast = bpcToast;

function toastSuccess(msg) { bpcToast(msg, 'success'); }
function toastError(msg, title) { bpcToast(msg, 'error', title); }
function toastWarning(msg) { bpcToast(msg, 'warning'); }
function toastInfo(msg) { bpcToast(msg, 'info'); }
function toastEmergency(msg) { bpcToast(msg, 'emergency', 'Emergency'); }

window.toastSuccess = toastSuccess;
window.toastError = toastError;
window.toastWarning = toastWarning;
window.toastInfo = toastInfo;
window.toastEmergency = toastEmergency;

/**
 * Blocking acknowledgement for the rare failure a user MUST read
 * (destructive actions, session loss). Same visual language as the
 * rest of the system — no raw technical text reaches the user.
 */
function showErrorDialog(message, title) {
  return Swal.fire({
    title: title || 'Something went wrong',
    html: '<p class="mb-0" style="font-size:0.9rem;color:var(--text-secondary)">'
      + escapeHtml(message || 'Unable to complete the request. Please try again.')
      + '</p>',
    showConfirmButton: true,
    confirmButtonText: 'Close',
    customClass: { confirmButton: 'btn btn-primary', popup: 'app-swal' },
    buttonsStyling: false
  });
}
window.showErrorDialog = showErrorDialog;

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
  // Shared empty state. DataTables renders this inside the table body, so it
  // must be a full-width block rather than a bare sentence, and it explains
  // what happened instead of just reporting "no data".
  function dtEmptyState() {
    return '<div class="empty-state" style="padding:32px 16px;">'
      + '<div class="empty-state__icon"><i class="bi bi-inbox" aria-hidden="true"></i></div>'
      + '<h6 class="empty-state__title">No records found</h6>'
      + '<p class="empty-state__text">There are currently no records matching this view. '
      + 'Try changing your search or filters.</p>'
      + '</div>';
  }

  $.extend(true, $.fn.dataTable.defaults, {
    language: {
      search: '<i class="bi bi-search me-1"></i>',
      searchPlaceholder: 'Search...',
      lengthMenu: '_MENU_ per page',
      zeroRecords: dtEmptyState(),
      emptyTable: dtEmptyState(),
      infoEmpty: 'Showing 0 to 0 of 0 records',
      infoFiltered: '(filtered from _MAX_ total records)',
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

function bpcSosHasGps(sos) {
  return sos.latitude !== null && sos.longitude !== null && sos.latitude !== '' && sos.longitude !== '' &&
    Number.isFinite(Number(sos.latitude)) && Number.isFinite(Number(sos.longitude)) && Math.abs(Number(sos.latitude)) <= 90 && Math.abs(Number(sos.longitude)) <= 180;
}
function bpcSosAccuracyLabel(value) {
  var accuracy = parseFloat(value);
  if (!Number.isFinite(accuracy) || accuracy < 0) return 'Accuracy unknown; location is approximate.';
  return 'Accuracy: �' + accuracy.toLocaleString() + ' meters' + (accuracy > 100 ? '. Poor accuracy; confirm the location with the reporter.' : '.');
}
