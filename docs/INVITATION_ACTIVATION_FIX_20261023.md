# Invitation activation fix — 2026-10-23

## Root cause and trace

`pages/admin/admin_accounts.php` authenticates/authorizes the MIS primary
administrator, verifies CSRF and a replay nonce, then invokes
`DoctorInvitationService::create()` or `resend()`. The service generates a random
256-bit token, stores its SHA-256 hash, and commits before invoking the mail
transport. `includes/mail.php::send_doctor_invitation_email()` embeds the generated
`auth/setup_account.php#token=...` URL in the HTML and plain-text email.

The generated link already targets the recipient page, not a protected API.
However, `config/config.php` calls `enforce_profile_route()` before that page can
execute. If the browser has an administrator cookie whose account is still active
but whose session has expired, this global guard emits HTTP 401 JSON with
`Reauthentication required.` The page also previously redirected any signed-in
user to a dashboard. This is a session-state bug, not a mobile-only routing bug.

A new isolated HTTP assertion reproduced the failure on the original code after
expiring a synthetic MIS session. The same assertion now passes.

## Changes

- `includes/permission_profiles.php`: exempt only the actual executed recipient
  setup file from global profile enforcement, using canonical filesystem paths.
  There is no query-controlled bypass or broad exemption for auth/API routes.
- `auth/setup_account.php`: handle recipients independently of existing login
  cookies, without redirecting, logging in, logging out or elevating the current
  browser session. Retain CSRF-protected activation, private/no-store responses
  and no-referrer policy; add noindex. Use a standalone responsive layout without
  authenticated dashboard widgets. Retain password rules and add an independent
  accessible password visibility toggle.
- Fragment verification now uses a hidden CSRF-protected POST form on initial
  load and hash changes. Opening the same invitation again in the same tab
  revalidates it. Malformed fragments produce a friendly invalid-link page.
- Render the password form only when the service confirms eligibility, including
  the intended Doctor profile. Expired, invalid, used or ineligible links cannot
  render an activation form.
- `tests/mis_admin_http.php`: add expired-cookie, token-bound activation, session
  isolation, privilege-injection and existing-account/permission preservation
  assertions; add `--invitation-browser` fixture integration.
- `tests/invitation_browser.cjs`: fresh isolated Chrome contexts, mobile emulation
  and desktop layouts, fragment POST, validation, activation and error/replay UI.

No schema change was needed. The existing service already enforces expiration,
pending/approved account eligibility, admin/co-admin role and Doctor profile,
server-side password strength and `password_hash()`. Its transaction locks the
target user, profile and invitation, rechecks eligibility, conditionally consumes
the unexpired unused token and activates only its stored `user_id`. It invalidates
other tokens and writes a token-free audit event. Those controls are unchanged.

MIS endpoints still require authentication, an unexpired session, appropriate
permissions and CSRF; invocation of recipient setup does not refresh the expired
MIS session. Existing current-password reauthentication for privileged profile
management actions is unchanged.

## Verification

- `php tests/mis_admin_http.php --invitation-browser`: **55 HTTP/suite assertions
  passed**, including **22 additional browser assertions** run by the child suite.
- Browser coverage: fresh mobile and desktop contexts with no prior session;
  widths 320, 360, 390, 430, 1024 and 1366; no horizontal overflow; password mismatch
  and visibility; successful activation; same-tab replay; expired and invalid-link
  messages; no activation form on rejected links; invitation token absent from
  HTTP request URLs. Mobile uses Chrome mobile emulation, not physical iOS hardware.
- `php tests/doctor_invitation_response.php`: **7 response contract checks passed**.
- Three modified PHP files linted successfully; new CJS syntax and `git diff
  --check` passed. Both rendered mobile/desktop screenshots were visually inspected.
- Local database table counts match the earlier cleanup audit; **zero declared
  foreign-key orphan rows**. Existing MIS account and permission values are also
  compared directly inside the disposable suite.

Tests use isolated local schemas, fake mail transport or intentional mail failure,
synthetic accounts and discarded test-server access logs. Screenshots are under
ignored `scratch/invitation-browser/`; the final suite log is under ignored
`scratch/invitation-browser-suite.log`. No actual school records were changed.

Newly generated emails keep secrets in fragments, which browsers do not send to
servers; application errors do not print tokens. Legacy query-token links remain
accepted for compatibility, but their query strings can appear in web-server
access logs. Deployments should redact query strings for this route and use only
the generated fragment URLs for new invitations. Live SMTP inbox delivery,
Hostinger deployment and physical-device testing were not performed.
