# BPC Clinic UI/UX Audit

## Scope reviewed

- 62 PHP pages under `pages/` across Admin, Nurse, Student, Teacher/Adviser, and shared clinic workflows.
- Shared `includes/head.php`, `components/sidebar.php`, and `components/navbar.php`.
- Shared stylesheets: `style.css`, `design-system.css`, `components.css`, `responsive.css`, and `mica.css`.
- Authentication and registration entry points and direct stylesheet loading on standalone pages.

## Findings

1. **Two global style layers define the same tokens.** `style.css` and `design-system.css` both define names such as `--primary`, `--background`/`--bg`, text, borders, shadows, and spacing. The central stylesheet wins for overlapping names by load order, but legacy-only aliases still resolve to the older palette. This can create different colors inside one page.
2. **The shared CSS footprint is broad.** Every page using `includes/head.php` loads the legacy stylesheet and all three design-system stylesheets, plus MICA styling. Authentication pages also load the app shell rules. Page-level exceptions and embedded styles add additional specificity and make regressions harder to trace.
3. **Responsive rules are distributed across files.** `style.css`, `responsive.css`, and `components.css` all contain breakpoint rules. Their order is deterministic, but page and component exceptions can override each other.
4. **Inline styles are common.** Page templates and shared components contain numerous inline dimensions, visibility rules, colors, and spacing. Dynamic visibility and data-driven sizing are valid uses; presentation-only values should move to scoped component/page classes as each page is reviewed.
5. **Color tokens are not fully semantic.** The central system has a BPC green base, but some legacy aliases and one focus-ring token use unrelated values. A purple dashboard stat variant and blue-tinted page-local styles also remain candidates for page-by-page review.
6. **There is a shared shell to build on.** Authenticated pages consistently include the same role-aware sidebar and navbar, and the shared CSS defines common buttons, forms, tables, cards, and responsive rules. Refinements here have broad reach, so they require regression checks across roles.
7. **Current workspace changes are already present.** Several CSS, authentication, sidebar, and queue files have uncommitted edits, plus generated uploads and scratch scripts. These were left intact and treated as existing work while auditing.

## Changes made from confirmed findings

- Mapped legacy palette aliases to the central design tokens and changed the shared focus shadow to the BPC green.
- Corrected a malformed SweetAlert focus-shadow declaration and removed an unused purple dashboard tone.
- Constrained the shared notification dropdown to the viewport and added an accessible name to the dark-mode toggle.
- Added scoped spacing rules for fractional Bootstrap utility classes used by the registration form; Bootstrap does not ship those fractional utility classes.

## Recommended implementation order

1. Normalize shared design-token aliases and focus/interaction colors without changing role permissions or feature behavior.
2. Review the shared application shell and high-use components at desktop, tablet, and mobile widths.
3. Review authentication, dashboards, and then role feature pages in small groups, moving confirmed presentation-only inline styles into scoped rules.
4. Verify representative pages and interactions after each group; record any page that could not be checked in a live browser.

## Verification limitations

The local PHP registration route responds successfully, but no browser session is currently available for rendered visual checks. No account-changing form submissions have been made.
