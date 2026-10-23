# Local testing

Apache denies this directory. Run PHP suites from the project root using
`C:/xampp/php/php.exe -d session.save_path=scratch tests/<suite>.php`.
Use Node for `.cjs` suites after reviewing their fixture/server requirements.

`consent_checkbox_validation.php` and `mobile_clinic_messages_validation.php`
inspect source markup/styles; they are not browser layout tests.
`doctor_invitation_response.php` checks subprocess response contracts.
`mis_admin_http.php`, `basic_registration_http.php`, and `doctor_invitations.php`
create disposable local databases; inspect each suite before running. Never
point tests at a live database. Some older suites use the configured database
inside a transaction; do not assume every test is isolated.

`registration_scroll_probe.php` was moved from the web root. It uses
`../auth/register.php` and must only be served by a temporary development server
bound to loopback, where the project is its document root. Apache intentionally
blocks it. Browser `.cjs` suites are preferred for automated regression.

Clinical release regressions use `php tests/mis_admin_http.php --doctor-clinical
--staff-verification`. Add `--doctor-browser` with a separate headless Chrome
debugging instance on loopback port 9337 for eight-width Doctor and staff-modal
checks. `staff_verification_checks.php` is an included fixture, not a standalone
script. `doctor_nurse_verification_suite.php` now invokes the disposable suite;
it no longer writes transactional fixtures into the configured school database.
`nurse_authorization_safety_audit.php` now asserts restrictive authorization,
rather than reproducing the previously documented unsafe grants.

Cleanup audits:

- `python tools/audit_cleanup_sources.py`: offline inventory, candidate references,
  PHP lint and Node syntax. Results: `scratch/cleanup-audit/sources.json`.
- `C:/xampp/php/php.exe tools/audit_cleanup_data.php`: CLI and loopback only,
  read-only transaction, candidate IDs, table counts and declared FK orphan counts.
  Redirect output into `scratch/cleanup-audit/database.json`; never publish it.
- `python tools/audit_icons.py`: optional icon audit; downloads the Bootstrap icon
  definition from jsDelivr and skips runtime/browser/vendor trees.

These audit tools do not approve deletions. Before any database cleanup, verify
provenance and all application relationships, produce an exact ID allowlist,
obtain explicit approval, create a backup and verify its restore in isolation,
then use a reviewed transaction. Preserve medical records and audit history.
