# Single Smart QR Code System — Implementation Checklist

## Goal

Redesign the QR module into a **Smart Student QR System**: one permanent QR per
student that encodes **only an encrypted token (no PII)**, auto-determined
workflows on scan, full QR status lifecycle, QR audit history, reports export,
and MICA integration.

## Requirements & Acceptance Criteria

- [x] One Student = One Permanent QR Code (auto-generated on registration)
- [x] QR payload encodes ONLY an encrypted token (e.g. `BPC-4A8F91D72E3B6A8C`) — NO student number, name, birthdate, address, course, or contact
- [x] Backward compatibility with legacy JSON QRs (`{"v":1,...}`) already in the field
- [x] Student can only View / Download / Print QR (cannot regenerate/edit)
- [x] QR Statuses: Active / Inactive / Revoked / Regenerated / Deactivated / Without
- [x] Only Administrators can Regenerate or Revoke QRs
- [x] When QR regenerated → previous QR immediately invalid (archived)
- [x] Duplicate scan prevention (30s cooldown)
- [x] Token validation via HMAC
- [x] Smart Scan auto-detects: appointment / walk-in / dispensing / certificate / emergency
- [x] Scan audit logs (student, date, purpose, nurse, device, browser, status)
- [x] QR timeline (scanned → checked-in → queue → consultation → dispensing → certificate → completed)
- [x] MICA post-scan greeting with queue number + students ahead
- [x] Reports export: QR Scan Logs / Daily Check-ins / Walk-in Stats / Appointment Attendance / Queue Reports in CSV (PDF/Excel export actions)

## Implementation Steps

- [x] Step 1: Database migration — `student_qr_history` audit table + new `qr_settings` keys
- [x] Step 2: `includes/functions.php` — token-only QR payload builder, dual-format verify, archive/revoke helpers
- [x] Step 3: `api/qr_codes.php` — admin-only regenerate, new `revoke` action, harden timeline/logs
- [x] Step 4: `includes/qr_management_ui.php` — 6-status badges, admin-only regenerate/revoke
- [x] Step 5: Reports — CSV/Excel export actions + buttons (QR Management `Export Logs` CSV/Excel + Reports pages CSV/Excel)
- [x] Step 6: `pages/student/my_qr.php` — status handling (regenerated/revoked notices)
- [x] Step 7: PHP lint all modified files
- [x] Step 8: Update core TODO.md

## Files Created / Modified

- [x] `database/migration_qr_single_token.sql` (new)
- [x] `includes/functions.php`
- [x] `api/qr_codes.php`
- [x] `includes/qr_management_ui.php`
- [x] `api/reports.php`
- [x] `pages/nurse/reports.php`
- [x] `pages/admin/reports.php`
- [x] `pages/student/my_qr.php`

## Test Plan (manual)

- [ ] Run SQL migration `database/migration_qr_single_token.sql`
- [ ] Existing students still verify with legacy JSON / new BPC- token after `ensure_student_qr`
- [ ] Admin can Regenerate (old archived + invalid) and Revoke (set revoked + reason)
- [ ] Nurse CANNOT regenerate or revoke (buttons hidden / API blocked)
- [ ] Health Pass loads valid JSON and renders QR (view / download / print)
- [ ] Smart scan detects appointment vs walk-in and issues queue + MICA greeting
- [ ] Duplicate scan within 30s is rejected
- [ ] Reports export CSV / Excel / PDF
