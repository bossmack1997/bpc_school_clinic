<?php
/**
 * includes/dashboard_ui.php
 * ------------------------------------------------------------------
 * Shared dashboard building blocks for every role dashboard
 * (Admin / Nurse / Teacher / Student).
 *
 * Keeps the visual language identical across roles while letting each
 * dashboard compose its own content. Loaded automatically by config.
 *
 * Provides:
 *   dbg_greeting()                — time-aware greeting string
 *   dbg_hero()                    — role hero banner
 *   dbg_stat()                    — statistic / monitoring card
 *   dbg_panel_open()/close()      — panel wrapper with header
 *   dbg_alert()                   — monitoring / alert row
 *   dbg_monitor_open()/close()    — emergency monitor block
 *   dbg_action_tile()             — quick action tile
 *   dbg_empty()                   — professional empty state
 *   dbg_skeleton_rows()           — loading skeleton
 *   dbg_error_state()             — friendly API failure state
 * ------------------------------------------------------------------
 */

if (!function_exists('dbg_greeting')) {
    function dbg_greeting(): string
    {
        $hour = (int)(new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('G');
        if ($hour >= 5 && $hour < 12) return 'Good morning';
        if ($hour >= 12 && $hour < 18) return 'Good afternoon';
        return 'Good evening';
    }
}

if (!function_exists('dbg_hero')) {
    /**
     * Role hero.
     *
     * @param array $o role, name, title, desc, chips[], tone, badge_icon, badge_label, badge_alert
     */
    function dbg_hero(array $o): void
    {
        $role    = $o['role'] ?? '';
        $tone    = $o['tone'] ?? '';
        $chips   = $o['chips'] ?? [];
        $aside   = isset($o['badge_icon']);
        ?>
        <section class="dbg-hero<?= $tone ? ' dbg-hero--' . e($tone) : '' ?>" data-aos="fade-down">
          <div class="dbg-hero__inner">
            <div class="dbg-hero__text">
              <?php if ($role !== ''): ?>
                <span class="dbg-hero__role">
                  <i class="bi bi-shield-check"></i><?= e($role) ?>
                </span>
              <?php endif; ?>
              <h2 class="dbg-hero__title"><?= $o['title'] ?? '' ?></h2>
              <?php if (!empty($o['desc'])): ?>
                <p class="dbg-hero__desc"><?= $o['desc'] ?></p>
              <?php endif; ?>
              <?php if (!empty($chips)): ?>
                <div class="dbg-hero__meta">
                  <?php foreach ($chips as $chip): ?>
                    <?php if (!is_array($chip)) continue; ?>
                    <span class="dbg-hero__chip">
                      <span class="dbg-dot<?= !empty($chip['dot']) ? ' dbg-dot--' . e($chip['dot']) : '' ?>"></span>
                      <?= e($chip['label'] ?? '') ?><?= isset($chip['value']) && $chip['value'] !== '' ? ' <b>' . e((string)$chip['value']) . '</b>' : '' ?>
                    </span>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>
            <?php if ($aside): ?>
              <div class="dbg-hero__aside">
                <div class="dbg-hero__badge<?= !empty($o['badge_alert']) ? ' dbg-hero__badge--alert' : '' ?>">
                  <i class="bi bi-<?= e($o['badge_icon']) ?>"></i>
                  <span><?= e($o['badge_label'] ?? '') ?></span>
                </div>
              </div>
            <?php endif; ?>
          </div>
        </section>
        <?php
    }
}

if (!function_exists('dbg_stat')) {
    /**
     * Statistic card.
     *
     * @param array $o tone, icon, value, label, note(html), trend, trend_dir, solid, count(bool)
     */
    function dbg_stat(array $o): void
    {
        $tone  = $o['tone'] ?? 'green';
        $value = $o['value'] ?? 0;
        $count = !empty($o['count']);
        ?>
        <div class="dbg-stat dbg-stat--<?= e($tone) ?><?= !empty($o['solid']) ? ' dbg-stat--solid' : '' ?>">
          <div class="dbg-stat__top">
            <div class="dbg-stat__icon"><i class="bi bi-<?= e($o['icon'] ?? 'graph-up') ?>"></i></div>
            <?php if (!empty($o['trend'])): ?>
              <span class="dbg-stat__trend dbg-stat__trend--<?= e($o['trend_dir'] ?? 'flat') ?>">
                <i class="bi bi-<?= ($o['trend_dir'] ?? 'flat') === 'down' ? 'arrow-down-right' : (($o['trend_dir'] ?? 'flat') === 'up' ? 'arrow-up-right' : 'dash') ?>"></i>
                <?= e($o['trend']) ?>
              </span>
            <?php endif; ?>
          </div>
          <div class="dbg-stat__value"<?= !empty($o['id']) ? ' id="' . e($o['id']) . '"' : '' ?>><?php
            if ($count && is_numeric($value)) {
                echo '<span class="counter-animate" data-count="' . (int)$value . '">0</span>';
            } else {
                echo $value;
            }
          ?></div>
          <div class="dbg-stat__label"><?= e($o['label'] ?? '') ?></div>
          <?php if (!empty($o['note'])): ?>
            <div class="dbg-stat__note"><?= $o['note'] ?></div>
          <?php endif; ?>
        </div>
        <?php
    }
}

if (!function_exists('dbg_panel_open')) {
    function dbg_panel_open(array $o = []): void
    {
        $classes = 'dbg-panel' . (!empty($o['class']) ? ' ' . $o['class'] : '');
        ?>
        <section class="<?= $classes ?>">
          <?php if (!empty($o['title']) || !empty($o['action'])): ?>
            <div class="dbg-panel__head">
              <div class="dbg-panel__head-text">
                <?php if (!empty($o['title'])): ?>
                  <h3 class="dbg-panel__title">
                    <?php if (!empty($o['icon'])): ?><i class="bi bi-<?= e($o['icon']) ?>"></i><?php endif; ?>
                    <span><?= e($o['title']) ?></span>
                  </h3>
                <?php endif; ?>
                <?php if (!empty($o['subtitle'])): ?>
                  <p class="dbg-panel__sub"><?= e($o['subtitle']) ?></p>
                <?php endif; ?>
              </div>
              <?php if (!empty($o['action'])): ?>
                <div class="dbg-btn-row dbg-panel__actions"><?= $o['action'] ?></div>
              <?php endif; ?>
            </div>
          <?php endif; ?>
          <div class="dbg-panel__body<?= !empty($o['flush']) ? ' dbg-panel__body--flush' : '' ?>">
        <?php
    }
}

if (!function_exists('dbg_panel_close')) {
    function dbg_panel_close(?string $footer = null): void
    {
        ?>
          </div>
          <?php if ($footer !== null): ?>
            <div class="dbg-panel__foot"><?= $footer ?></div>
          <?php endif; ?>
        </section>
        <?php
    }
}

if (!function_exists('dbg_alert')) {
    /**
     * Monitoring / alert row.
     *
     * @param array $o level(critical|warning|info|ok), icon, title, desc, count, href
     */
    function dbg_alert(array $o): void
    {
        $level = $o['level'] ?? 'ok';
        $tag   = $o['href'] ?? null;
        $inner = '<div class="dbg-alert__icon"><i class="bi bi-' . e($o['icon'] ?? 'info-circle') . '"></i></div>'
            . '<div class="dbg-alert__body">'
            . '<div class="dbg-alert__title">' . e($o['title'] ?? '') . '</div>'
            . '<div class="dbg-alert__desc">' . ($o['desc'] ?? '') . '</div>'
            . '</div>'
            . (isset($o['count']) ? '<div class="dbg-alert__count" style="color:' . e($o['count_color'] ?? 'var(--bpc-ink)') . '">' . e((string)$o['count']) . '</div>' : '');
        ?>
        <?php if ($tag): ?>
          <a class="dbg-alert dbg-alert--<?= e($level) ?>" href="<?= e($tag) ?>"><?= $inner ?></a>
        <?php else: ?>
          <div class="dbg-alert dbg-alert--<?= e($level) ?>"><?= $inner ?></div>
        <?php endif; ?>
        <?php
    }
}

if (!function_exists('dbg_monitor_open')) {
    function dbg_monitor_open(array $o = []): void
    {
        $mode  = !empty($o['active']) ? 'danger' : 'ok';
        $badge = !empty($o['active']) ? ($o['badge'] ?? 'Active Emergency') : ($o['badge'] ?? 'All Clear');
        ?>
        <section class="dbg-monitor dbg-monitor--<?= $mode ?>">
          <span class="dbg-monitor__badge">
            <i class="bi bi-<?= !empty($o['active']) ? 'exclamation-triangle-fill' : 'shield-check' ?>"></i><?= e($badge) ?>
          </span>
          <div class="dbg-monitor__count"><?= e((string)($o['count'] ?? 0)) ?></div>
          <div class="dbg-monitor__label"><?= e($o['label'] ?? '') ?></div>
        <?php
    }
}

if (!function_exists('dbg_monitor_close')) {
    function dbg_monitor_close(): void
    {
        echo '</section>';
    }
}

if (!function_exists('dbg_action_tile')) {
    /**
     * Quick action tile.
     *
     * @param array $o tone, icon, title, desc, href, onclick, target
     */
    function dbg_action_tile(array $o): void
    {
        $tag   = !empty($o['onclick']) ? 'button' : 'a';
        $attrs = $tag === 'button'
            ? ' type="button" onclick="' . e($o['onclick']) . '"'
            : ' href="' . e($o['href'] ?? '#') . '"';
        if ($tag === 'a' && !empty($o['target'])) $attrs .= ' target="_blank" rel="noopener"';
        ?>
        <<?= $tag ?> class="dbg-action dbg-action--<?= e($o['tone'] ?? 'green') ?>"<?= $attrs ?>>
          <span class="dbg-action__icon"><i class="bi bi-<?= e($o['icon'] ?? 'grid') ?>"></i></span>
          <span class="dbg-action__title"><?= e($o['title'] ?? '') ?></span>
          <?php if (!empty($o['desc'])): ?>
            <span class="dbg-action__desc"><?= e($o['desc']) ?></span>
          <?php endif; ?>
        </<?= $tag ?>>
        <?php
    }
}

if (!function_exists('dbg_empty')) {
    /**
     * Professional empty state.
     *
     * @param array $o icon, title, desc, tone
     */
    function dbg_empty(array $o): void
    {
        ?>
        <div class="dbg-empty<?= !empty($o['tone']) ? ' dbg-empty--' . e($o['tone']) : '' ?>">
          <div class="dbg-empty__icon"><i class="bi bi-<?= e($o['icon'] ?? 'check2-circle') ?>"></i></div>
          <h6><?= e($o['title'] ?? 'Nothing here yet') ?></h6>
          <?php if (!empty($o['desc'])): ?><p><?= e($o['desc']) ?></p><?php endif; ?>
          <?php if (!empty($o['action'])): ?><div class="dbg-btn-row dbg-btn-row--center" style="margin-top:14px;"><?= $o['action'] ?></div><?php endif; ?>
        </div>
        <?php
    }
}

if (!function_exists('dbg_skeleton_rows')) {
    function dbg_skeleton_rows(int $rows = 3): void
    {
        echo '<div class="dbg-skel-stack">';
        for ($i = 0; $i < $rows; $i++) {
            echo '<div class="dbg-skel" style="height:52px;border-radius:12px;"></div>';
        }
        echo '</div>';
    }
}

if (!function_exists('dbg_error_state')) {
    /**
     * Client-side error state target. Never exposes SQL/PHP internals.
     */
    function dbg_error_state(string $message = 'Unable to load this information.'): string
    {
        return '<div class="dbg-empty dbg-empty--danger" data-dbg-error>'
            . '<div class="dbg-empty__icon"><i class="bi bi-wifi-off"></i></div>'
            . '<h6>' . e($message) . '</h6>'
            . '<p>Check your connection and try again. If this keeps happening, contact the clinic administrator.</p>'
            . '<div class="dbg-btn-row dbg-btn-row--center" style="margin-top:14px;">'
            . '<button type="button" class="dbg-btn dbg-btn--ghost dbg-btn--sm" onclick="window.location.reload()">'
            . '<i class="bi bi-arrow-clockwise"></i> Retry</button></div></div>';
    }
}

if (!function_exists('dbg_state_js')) {
    /**
     * Shared client-side state helpers.
     *
     * List pages previously handled a failed fetch with `if (!res.success)
     * return;`, which left the container permanently blank with no
     * explanation and no way to recover. These helpers render the same
     * empty/error/loading states used by the dashboards so every list
     * page behaves consistently and always offers a way forward.
     *
     * Emit once per page after jQuery is available:
     *   <?= dbg_state_js() ?>
     */
    function dbg_state_js(): string
    {
        return <<<'HTML'
<script>
/**
 * Shared list-state renderer.
 *
 * Every data-driven panel uses these three calls so loading, empty and
 * error all look and behave the same across roles. Error states always
 * offer a Retry that re-runs the loader, rather than leaving a blank box.
 */
window.BPCState = (function ($) {
  'use strict';

  function escapeHtml(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function icon(name) {
    return '<i class="bi bi-' + name + '" aria-hidden="true"></i>';
  }

  /** Show a loading state while a request is in flight. */
  function loading(selector, message) {
    var el = typeof selector === 'string' ? $(selector) : $(selector);
    if (!el.length) return;
    el.html(
      '<div class="dbg-empty" role="status" aria-live="polite">' +
        '<div class="dbg-empty__icon">' + icon('hourglass-split') + '</div>' +
        '<h6>' + escapeHtml(message || 'Loading...') + '</h6>' +
      '</div>'
    );
  }

  /**
   * Show an empty state explaining why there is nothing to show, with an
   * optional action (e.g. a Clear Search button).
   */
  function empty(selector, opts) {
    opts = opts || {};
    var el = typeof selector === 'string' ? $(selector) : $(selector);
    if (!el.length) return;
    var html =
      '<div class="dbg-empty">' +
        '<div class="dbg-empty__icon">' + icon(opts.icon || 'inbox') + '</div>' +
        '<h6>' + escapeHtml(opts.title || 'No records found.') + '</h6>' +
        (opts.desc ? '<p>' + escapeHtml(opts.desc) + '</p>' : '') +
      '</div>';
    if (opts.action) {
      html += '<div class="dbg-btn-row dbg-btn-row--center" style="margin-top:14px;">' + opts.action + '</div>';
    }
    el.html(html);
  }

  /**
   * Show a recoverable error state. Always offers Retry unless the caller
   * passes an action, so the user is never stuck on a dead panel.
   */
  function error(selector, message, retryFn) {
    var el = typeof selector === 'string' ? $(selector) : $(selector);
    if (!el.length) return;
    var action =
      '<button type="button" class="dbg-btn dbg-btn--ghost dbg-btn--sm">' +
        icon('arrow-clockwise') + ' Retry</button>';
    var html =
      '<div class="dbg-empty dbg-empty--danger" role="alert">' +
        '<div class="dbg-empty__icon">' + icon('wifi-off') + '</div>' +
        '<h6>' + escapeHtml(message || 'Unable to load this information.') + '</h6>' +
        '<p>Check your connection and try again. If this keeps happening, contact the clinic administrator.</p>' +
        '<div class="dbg-btn-row dbg-btn-row--center" style="margin-top:14px;">' + action + '</div>' +
      '</div>';
    el.html(html);
    // Bind the retry without inline JS so it works under a strict CSP.
    el.find('[data-bpc-retry]').off('click.bpcState');
    el.find('button').first().off('click.bpcState').on('click.bpcState', function () {
      if (typeof retryFn === 'function') {
        loading(el, 'Loading...');
        retryFn();
      } else {
        window.location.reload();
      }
    });
  }

  /**
   * Standard failure handler for a jQuery ajax callback.
   *
   * Usage:  if (!res.success) { BPCState.error('#panel', 'Unable to load consultations.', loadList); return; }
   */
  function fail(selector, res, retryFn, fallbackMessage) {
    var msg = fallbackMessage
      || (res && res.message)
      || 'Unable to load this information.';
    error(selector, msg, retryFn);
    if (res && res.message) {
      // The server already gave a safe, user-facing message.
      console.warn('[BPCState]', res.message);
    }
  }

  /**
   * Table-scoped variant of empty(). A panel's state markup must live
   * inside a <td>, otherwise the browser hoists it out of the table and
   * the layout breaks — so tables get the same visual language through
   * this helper instead of inline HTML on every list page.
   */
  function tableEmpty(selector, colspan, opts) {
    opts = opts || {};
    var el = typeof selector === 'string' ? $(selector) : $(selector);
    if (!el.length) return;
    var html =
      '<div class="dbg-empty">' +
        '<div class="dbg-empty__icon">' + icon(opts.icon || 'inbox') + '</div>' +
        '<h6>' + escapeHtml(opts.title || 'No records found.') + '</h6>' +
        (opts.desc ? '<p>' + escapeHtml(opts.desc) + '</p>' : '') +
      '</div>';
    el.html('<tr><td colspan="' + (colspan || 1) + '">' + html + '</td></tr>');
  }

  /** Table-scoped variant of error(), with the same always-available Retry. */
  function tableError(selector, colspan, message, retryFn) {
    var el = typeof selector === 'string' ? $(selector) : $(selector);
    if (!el.length) return;
    var html =
      '<div class="dbg-empty dbg-empty--danger" role="alert">' +
        '<div class="dbg-empty__icon">' + icon('wifi-off') + '</div>' +
        '<h6>' + escapeHtml(message || 'Unable to load this information.') + '</h6>' +
        '<p>Check your connection and try again. If this keeps happening, contact the clinic administrator.</p>' +
        '<div class="dbg-btn-row dbg-btn-row--center" style="margin-top:14px;">' +
          '<button type="button" class="dbg-btn dbg-btn--ghost dbg-btn--sm">' +
            icon('arrow-clockwise') + ' Retry</button>' +
        '</div>' +
      '</div>';
    el.html('<tr><td colspan="' + (colspan || 1) + '">' + html + '</td></tr>');
    el.find('button').first().off('click.bpcState').on('click.bpcState', function () {
      if (typeof retryFn === 'function') { loading(el, 'Loading...'); retryFn(); }
      else { window.location.reload(); }
    });
  }

  return {
    loading: loading,
    empty: empty,
    error: error,
    fail: fail,
    tableEmpty: tableEmpty,
    tableError: tableError,
    escapeHtml: escapeHtml
  };
})(jQuery);
</script>
HTML;
    }
}
