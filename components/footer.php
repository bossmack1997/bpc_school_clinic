<?php
/**
 * components/footer.php
 * ------------------------------------------------------------------
 * SINGLE footer partial for every authenticated page.
 * Replaced includes/footer_scripts.php — ensures a consistent global
 * script load order: jQuery → Bootstrap → core plugins → main.js →
 * MICA (single bundle) → MICA.init().
 * ------------------------------------------------------------------
 */
?>
<!-- ================================================================== -->
<!-- Global Scripts (single load source — no duplication)             -->
<!-- ================================================================== -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
<script src="<?= app_url('assets/js/main.js') ?>"></script>

<?php if (isset($_SESSION['user_id']) || is_logged_in()): ?>
<script>
(function () {
  'use strict';
  function runDeferredInits() {
    if (typeof window.__initInventoryUI === 'function') {
      try { window.__initInventoryUI(); } catch (e) { console.warn('Inventory UI init failed:', e); }
    }
    if (typeof window.__initNotificationUI === 'function') {
      try { window.__initNotificationUI(); } catch (e) { console.warn('Notification UI init failed:', e); }
    }
    if (typeof window.__initQrManagementUI === 'function') {
      try { window.__initQrManagementUI(); } catch (e) { console.warn('QR Management UI init failed:', e); }
    }
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', runDeferredInits);
  } else {
    runDeferredInits();
  }
})();
</script>
<!-- ================================================================== -->
<!-- MICA Global Assistant (only on authenticated pages)              -->
<!-- Single component include — one FAB, one widget, no duplicates.    -->
<!-- ================================================================== -->
<?php include __DIR__ . '/mica.php'; ?>
<script src="<?= app_url('assets/js/mica.js') ?>"></script>
<script>
(function () {
  'use strict';
  var maxRetries = 50;
  var retryDelay = 100;
  function tryInit(attempt) {
    if (typeof MICA !== 'undefined' && typeof MICA.init === 'function') {
      try { MICA.init(); console.log('[MICA] Initialized successfully'); }
      catch (e) { console.warn('[MICA] Init error:', e); }
      return;
    }
    if (attempt >= maxRetries) { console.warn('[MICA] Failed to initialize after ' + maxRetries + ' attempts'); return; }
    setTimeout(function () { tryInit(attempt + 1); }, retryDelay);
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { tryInit(0); });
  } else {
    tryInit(0);
  }
})();
</script>
<?php endif; ?>

