# BPC School Clinic UI Style Guide

`assets/css/theme.css` is the shared source for visual tokens and common component styling. It loads after the role pages' legacy styles so new UI can use the same colors, type, spacing, radius, and surfaces.

## Rules for new and refactored UI

- Use semantic tokens such as `var(--primary)`, `var(--surface)`, `var(--text-primary)`, `var(--border)`, `var(--danger)`, and `var(--space-4)`.
- Do not add inline `style` attributes or hardcoded color values to PHP markup. Put reusable presentation in the shared theme or a component stylesheet.
- Use the shared Bootstrap primitives: `.btn` variants, `.card`, `.form-control`, `.form-select`, `.table`, `.badge`, `.alert`, `.modal`, `.dropdown-menu`.
- Use one page title (`h1`), then section titles (`h2`) and card titles (`h3`). Keep page actions in the page header and align the heading left.
- Give every field a visible label. Pair validation states with readable inline messages. Empty and loading lists should explain state and the next available action.
- Use Bootstrap Icons already shipped with the app for new iconography. Keep icons decorative (`aria-hidden="true"`) unless the icon itself conveys the accessible name.
- Keep red for emergency or destructive actions. Use green for BPC primary actions, amber for warnings, and blue for informational status.
- Dark mode is selected by `data-theme="dark"` on the root element. Use semantic tokens so surfaces and text adapt without page-specific light colors.
- Keep interactive text and controls at WCAG AA contrast (4.5:1 for normal text; 3:1 for large text and important non-text controls).

## Token groups

- Brand and status: `--bpc-green-*`, `--bpc-red-*`, `--bpc-amber-*`, `--bpc-blue-*`, `--bpc-gray-*`, plus semantic `--primary`, `--success`, `--danger`, `--warning`, and `--info`.
- Surface and text: `--background`, `--surface`, `--surface-alt`, `--border`, `--text-primary`, `--text-secondary`, `--text-muted`.
- Typography: `--font-sans`, `--text-page-title`, `--text-section-title`, `--text-card-title`, `--text-body`, `--text-small`, `--text-caption`, `--text-label`.
- Spacing and shape: `--space-1`, `--space-2`, `--space-3`, `--space-4`, `--space-6`, `--space-8`, `--space-12`, `--radius-sm`, `--radius-md`, `--radius-lg`, `--radius-full`.
- Elevation: `--shadow-sm`, `--shadow-md`, and `--shadow-lg`.

## Preview

In local development, sign in and open `/pages/style_guide.php`. The route returns 404 outside `APP_ENV=local`.

## Existing migration debt

Legacy page templates still contain inline styles and locally defined colors. They are being migrated page by page; avoid adding to that debt. The style guide is the target for refactors, not a claim that all legacy markup has already been converted.
