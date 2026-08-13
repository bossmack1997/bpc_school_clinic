# Smart Digital Health Pass Fix — TODO

## Root Cause

`api/qr_codes.php?action=student_health_pass` runs SQL against tables/columns created
only by migration files (`student_qrs`, `queue_numbers`, `qr_scan_logs`, `qr_settings`,
`students.last_checkin_at`). If migrations aren't applied, a PDOException → HTTP 500 →
jQuery `.fail()` → "Network error loading health pass."

## Task (Option A: Harden API + Fix JS)

- [ ] 1. Harden `student_health_pass` action in `api/qr_codes.php`
     - Wrap queries in try/catch helpers so missing tables/columns never crash (returns safe defaults)
     - Use LEFT JOIN + COALESCE defaults
     - Always return valid JSON: `{ success, student, qr, health_pass }` or `{ success:false, message }`
- [ ] 2. Harden `medical_record_summary` + `scan_logs` fallbacks similarly
- [ ] 3. Add a helper in `includes/functions.php` to (optionally) write a QR PNG to `qr_path`
- [ ] 4. Fix `pages/student/my_qr.php` frontend:
     - Robust fetch with try/catch/finally + HTTP status handling + clear error messages
     - Guarantee QR renders (server-made PNG or qrcodejs fallback)
     - Fix Download as `student-{student_number}.png`
     - Fix Print to only print the health-pass card
     - Populate all fields with default values ("Not Available")
- [ ] 5. Rediscover CSS print rules for print-only card (in style.css or inline)
- [ ] 6. PHP lint + review all changed files

## Acceptance

- Health Pass loads automatically with no "Network error"
- QR auto-generated if missing, displayed
- All student fields populated (defaults when absent)
- Download works as `student-<number>.png`
- Print prints only the card
