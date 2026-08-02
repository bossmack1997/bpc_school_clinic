 # SOS Enhancement Task List

Goal: Enhance the existing SOS system WITHOUT replacing or duplicating existing functionality.

## Steps

- [x] 1. Create `database/migration_sos_enhance.sql` — add `sos_emergency_id` column to `notifications`
- [x] 2. Update `includes/functions.php` — `push_notification()` optional `sos_emergency_id` param (defensive column check)
- [x] 3. Update `api/ai_assistant.php`:
  - [x] Update SOS success message to required text
  - [x] Pass `sos_emergency_id` when notifying nurses/admins
  - [x] Add `sos_update_status` action (accept/resolve + notify student)
- [x] 4. Update `api/notifications.php`:
  - [x] Emergency notifications always at top, newest first
  - [x] Join SOS details into emergency notification payload
- [x] 5. Update `components/mica.php`:
  - [x] Request GPS AFTER confirm/submit (promise-based), never block
  - [x] Update success message
- [x] 6. Update `assets/js/mica.js` — remove auto-GPS call on modal open
- [x] 7. Update `components/navbar.php` — add SOS details modal shell (with Accept/Resolve footer for nurses)
- [x] 8. Update `assets/js/main.js`:
  - [x] Poll every 5 seconds
  - [x] Red indicator for unread + emergency styling
  - [x] Emergency at top (server handles) + render SOS items
  - [x] Click SOS notification → open details modal
  - [x] Accept/Resolve handlers
- [x] 9. Add `.unread-dot` / `.sos-notif-item` styles to `assets/css/style.css`
- [x] 10. Run the SQL migration (column added + backfilled existing SOS notifications)
- [x] 11. Verify PHP syntax + final self-check
- [x] 12. Verify JS syntax (node --check on main.js & mica.js)
- [x] 13. Verify DB migration applied (sos_emergency_id column EXISTS)
- [x] 14. Clean up all temporary diagnostic files
