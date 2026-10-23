# Shared UI refinement — 23 October 2026

Branch: `fix/shared-ui-refinement`
Rollback reference: `rollback/ui-refinement-20261023` (`77725a5`)

## Findings and changes

- The shared sidebar contained one MICA AI entry, in the Doctor profile. Removed it; other role menus already omitted MICA. The assistant component, APIs, permissions, history and database remain unchanged.
- People Directory moved its assistant launcher into document flow. Restored its fixed bottom-right placement and increased bottom content clearance.
- Login, registration, invitation setup and the general UI enhancer separately managed password visibility. Replaced those implementations with `password-visibility.js`, loaded by the shared head. Each original input retains its name, value, validation, autocomplete and submission behavior. Buttons sit inside the right edge, use a 44px touch target, expose accessible state and work independently. Dynamically inserted fields are supported. Removed obsolete styles and handlers.
- Added a mobile drawer close button, background scroll lock, focus placement, keyboard focus wrapping, Escape support, close after menu selection, and inert hidden navigation. Existing desktop collapse and resize behavior remain in use.
- Long role names wrap; section spacing is smaller; basic teachers no longer see an empty advisory section. Icon rail links now retain accessible labels and hover titles. Short mobile viewports omit the contact footer so navigation has space to scroll.
- Four Doctor routes were empty files. Inventory, emergencies and reports now reuse existing authorized pages behind `require_doctor()`. Clinic Monitoring redirects to the existing dashboard; its redundant menu entry was removed.
- Browser testing found that the nurse dashboard refresh script referenced a missing `nurseActivitySummary`. Restored that live summary element so chart initialization and refresh can complete.

## Files changed

- `assets/css/shared-ui.css`: shared password controls and sidebar refinements.
- `assets/css/ui-overrides.css`: remove obsolete password styling.
- `assets/css/workflow-mobile.css`: fixed MICA launcher and directory clearance.
- `assets/js/password-visibility.js`: reusable password enhancement.
- `assets/js/main.js`: drawer interactions and accessibility.
- `assets/js/ui-enhance.js`: remove superseded password implementation.
- `auth/login.php`, `auth/register.php`, `auth/setup_account.php`: remove local visibility handlers; retain form validation.
- `components/sidebar.php`, `includes/head.php`: shared navigation, styles and script loading.
- `pages/doctor/clinic_monitoring.php`, `pages/doctor/emergencies.php`, `pages/doctor/inventory.php`, `pages/doctor/reports.php`: repair blank routes.
- `pages/nurse/dashboard.php`: restore the missing live summary target.
- `tests/shared_ui_browser.cjs`, `tests/mis_admin_http.php`: disposable cross-role browser coverage.
- `tests/mis_admin_browser.cjs`: verify requested fixed launcher placement.
- `tests/registration_browser.cjs`: include 1920px in registration coverage.
- This report.

## Validation

- PHP syntax checks for every modified PHP file; Node syntax checks for every modified/new JavaScript file; `git diff --check`.
- `php tests/basic_registration_http.php --browser`: 108 HTTP checks passed, including the Chrome registration suite at 320, 360, 375, 390, 430, 768, 1024, 1440 and 1920px. Covers registration, uploads, OTP, password reset, activation, authorization, AJAX validation, real form submissions, password toggle state/value/focus, scrolling and field error placement.
- `php tests/mis_admin_http.php --browser`: 47 HTTP checks passed, including real Chrome directory layouts, modal scrolling, submission failures/retry policies and Doctor invitation setup. Existing role and CSRF rejection tests passed.
- `php tests/mis_admin_http.php --shared-ui-browser`: 47 HTTP checks passed, including the final cross-role browser suite after the nurse summary repair; results are in `scratch/shared-ui-verified-results.txt`. Exercises MIS, Doctor, Student, Teacher and Nurse dashboards and every rendered sidebar destination at 320, 375, 390, 430, 768, 1024, 1440 and 1920px. Checks overflow, independent navigation scrolling, footer separation, drawer focus/close/scroll lock, single password controls, visibility toggling, single floating MICA launcher and widget opening, and uncaught JavaScript exceptions.
- Browser screenshots are local, ignored artifacts in `scratch/shared-ui`, `scratch/mis-browser` and `scratch/registration-browser`.

All HTTP suites use disposable local databases copied from schema only, synthetic users and mail transport isolation. They remove their fixture databases and uploads. No production schema or records were changed by this implementation.

## Scope and remaining verification

Responsive coverage includes role dashboards, sidebar destinations, registration and the tested invitation modal. It does not establish visual or functional correctness for every possible record, detail page, chart, dialog or real device. No live AI provider request, microphone interaction, real outbound notification, camera/QR scan, backup restore or production deployment was performed. The MICA launcher/widget was tested; live AI answers and external integrations were preserved but not certified end to end. Existing security and registration tests cover the exercised workflows, not every clinical mutation.

The branch is intended for pull request review; do not interpret the synthetic browser results as production deployment approval. No secrets, generated uploads or fixture output belong in the commit.
