# Smart QR Gap-Fix Implementation Checklist

## Goal

Close the remaining gaps in the Single Smart QR Code System without redesigning
unrelated modules or changing the BPC theme.

## Steps

- [x] Step 1: Fix inverted role checks in `api/qr_codes.php`
      (regenerate → admin-only; deactivate/reactivate/mark_printed/generate_dynamic → nurse+admin)
- [x] Step 2: Add QR Scanner + QR Management links to admin sidebar; QR Management to nurse sidebar
      (`components/sidebar.php`)
- [x] Step 3: Make `includes/qr_management_ui.php` role-aware
      (hide regenerate/revoke/generate for nurses; add Revoke button + reason for admins; Export Logs CSV + Excel)
- [x] Step 4: Add Excel (SheetJS) + PDF export to nurse/admin reports pages
- [x] Step 5: Pass role flag from `pages/admin/qr_management.php` and
      `pages/nurse/qr_management.php` to the shared UI
- [x] Step 6: PHP lint all modified files
- [x] Step 7: Update `TODO_SMART_QR_SINGLE_TOKEN.md` + core `TODO.md`

## Files Modified

- `api/qr_codes.php`
- `components/sidebar.php`
- `includes/qr_management_ui.php`
- `pages/nurse/reports.php`
- `pages/admin/reports.php`
- `pages/admin/qr_management.php`
- `pages/nurse/qr_management.php`
