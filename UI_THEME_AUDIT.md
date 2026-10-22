# BPC UI Theme Audit

Read-only static scan of each PHP view in pages/ and auth/. Inline-style and hex counts include both presentation and dynamic status/layout styles; review each occurrence before moving it so behavior is preserved.

Baseline shared-CSS scan before the theme refactor: 9 non-minified CSS files, 822 color literal occurrences, 182 repeated selector groups / 353 additional selector blocks (approximate regex scan; nested at-rules can overcount). The shared shell and design-system styles load on most views, but page-specific markup and embedded styles still diverge.

| Page / view | Problems found | Fix needed |
|---|---|---|
| pages/admin/activity_logs.php | 2 inline style attribute(s) | move static presentation into shared classes; retain dynamic state through semantic classes |
| pages/admin/admin_accounts.php | 2 inline style attribute(s) | move static presentation into shared classes; retain dynamic state through semantic classes |
| pages/admin/announcements.php | 1 inline style attribute(s) | move static presentation into shared classes; retain dynamic state through semantic classes |
| pages/admin/dashboard.php | no inline style attributes detected; 4 hardcoded hex color(s) | replace authored color values with semantic theme tokens |
| pages/admin/emergencies.php | no inline style attributes detected | continue using shared tokens and components |
| pages/admin/inventory.php | no inline style attributes detected | continue using shared tokens and components |
| pages/admin/medical_records.php | no inline style attributes detected; no page-level H1 in this view | add one semantic H1 or reuse the shared page title |
| pages/admin/notifications.php | no inline style attributes detected | continue using shared tokens and components |
| pages/admin/pending_accounts.php | no inline style attributes detected; no page-level H1 in this view | add one semantic H1 or reuse the shared page title |
| pages/admin/profile.php | 2 inline style attribute(s) | move static presentation into shared classes; retain dynamic state through semantic classes |
| pages/admin/pwa_debug.php | no inline style attributes detected | continue using shared tokens and components |
| pages/admin/qr_management.php | no inline style attributes detected | continue using shared tokens and components |
| pages/admin/qr_scanner.php | no inline style attributes detected; no page-level H1 in this view | add one semantic H1 or reuse the shared page title |
| pages/admin/reports.php | 2 inline style attribute(s) | move static presentation into shared classes; retain dynamic state through semantic classes |
| pages/admin/security.php | 1 inline style attribute(s) | move static presentation into shared classes; retain dynamic state through semantic classes |
| pages/admin/settings.php | no inline style attributes detected | continue using shared tokens and components |
| pages/admin/students.php | no inline style attributes detected; no page-level H1 in this view | add one semantic H1 or reuse the shared page title |
| pages/admin/users.php | 9 inline style attribute(s) | move static presentation into shared classes; retain dynamic state through semantic classes |
| pages/adviser/announcements.php | 1 inline style attribute(s) | move static presentation into shared classes; retain dynamic state through semantic classes |
| pages/adviser/consultations.php | 2 inline style attribute(s) | move static presentation into shared classes; retain dynamic state through semantic classes |
| pages/adviser/dashboard.php | 12 inline style attribute(s); 1 hardcoded hex color(s) | move static presentation into shared classes; retain dynamic state through semantic classes; replace authored color values with semantic theme tokens |
| pages/adviser/emergencies.php | 1 inline style attribute(s) | move static presentation into shared classes; retain dynamic state through semantic classes |
| pages/adviser/messages.php | 8 inline style attribute(s); 3 hardcoded hex color(s) | move static presentation into shared classes; retain dynamic state through semantic classes; replace authored color values with semantic theme tokens |
| pages/adviser/my_advisory.php | 1 inline style attribute(s) | move static presentation into shared classes; retain dynamic state through semantic classes |
| pages/adviser/my_qr.php | 4 inline style attribute(s) | move static presentation into shared classes; retain dynamic state through semantic classes |
| pages/adviser/notifications.php | no inline style attributes detected | continue using shared tokens and components |
| pages/adviser/profile.php | 2 inline style attribute(s) | move static presentation into shared classes; retain dynamic state through semantic classes |
| pages/adviser/queue.php | 9 inline style attribute(s); 3 hardcoded hex color(s) | move static presentation into shared classes; retain dynamic state through semantic classes; replace authored color values with semantic theme tokens |
| pages/adviser/student_detail.php | no inline style attributes detected | continue using shared tokens and components |
| pages/adviser/students.php | 5 inline style attribute(s) | move static presentation into shared classes; retain dynamic state through semantic classes |
| pages/ai_assistant.php | 2 inline style attribute(s); 2 hardcoded hex color(s) | move static presentation into shared classes; retain dynamic state through semantic classes; replace authored color values with semantic theme tokens |
| pages/nurse/announcements.php | 2 inline style attribute(s) | move static presentation into shared classes; retain dynamic state through semantic classes |
| pages/nurse/certificate_print.php | 4 inline style attribute(s); 15 hardcoded hex color(s) | move static presentation into shared classes; retain dynamic state through semantic classes; replace authored color values with semantic theme tokens |
| pages/nurse/certificates.php | 1 inline style attribute(s) | move static presentation into shared classes; retain dynamic state through semantic classes |
| pages/nurse/dashboard.php | 10 inline style attribute(s); 4 hardcoded hex color(s) | move static presentation into shared classes; retain dynamic state through semantic classes; replace authored color values with semantic theme tokens |
| pages/nurse/dispensing.php | 1 inline style attribute(s); 4 hardcoded hex color(s) | move static presentation into shared classes; retain dynamic state through semantic classes; replace authored color values with semantic theme tokens |
| pages/nurse/emergencies.php | 5 inline style attribute(s); 1 hardcoded hex color(s) | move static presentation into shared classes; retain dynamic state through semantic classes; replace authored color values with semantic theme tokens |
| pages/nurse/health_monitoring.php | 2 inline style attribute(s) | move static presentation into shared classes; retain dynamic state through semantic classes |
| pages/nurse/inventory.php | no inline style attributes detected | continue using shared tokens and components |
| pages/nurse/medical_records.php | 3 inline style attribute(s) | move static presentation into shared classes; retain dynamic state through semantic classes |
| pages/nurse/messages.php | 14 inline style attribute(s); 11 hardcoded hex color(s) | move static presentation into shared classes; retain dynamic state through semantic classes; replace authored color values with semantic theme tokens |
| pages/nurse/notifications.php | no inline style attributes detected | continue using shared tokens and components |
| pages/nurse/patient_history.php | 7 inline style attribute(s) | move static presentation into shared classes; retain dynamic state through semantic classes |
| pages/nurse/profile.php | 2 inline style attribute(s) | move static presentation into shared classes; retain dynamic state through semantic classes |
| pages/nurse/qr_management.php | no inline style attributes detected | continue using shared tokens and components |
| pages/nurse/queue.php | 23 inline style attribute(s); 6 hardcoded hex color(s) | move static presentation into shared classes; retain dynamic state through semantic classes; replace authored color values with semantic theme tokens |
| pages/nurse/reports.php | 2 inline style attribute(s) | move static presentation into shared classes; retain dynamic state through semantic classes |
| pages/nurse/walkin.php | 17 inline style attribute(s); 4 hardcoded hex color(s) | move static presentation into shared classes; retain dynamic state through semantic classes; replace authored color values with semantic theme tokens |
| pages/qr_verify.php | 3 inline style attribute(s) | move static presentation into shared classes; retain dynamic state through semantic classes |
| pages/queue_display.php | 6 inline style attribute(s); 52 hardcoded hex color(s) | move static presentation into shared classes; retain dynamic state through semantic classes; replace authored color values with semantic theme tokens |
| pages/student/announcements.php | 1 inline style attribute(s) | move static presentation into shared classes; retain dynamic state through semantic classes |
| pages/student/certificates.php | 1 inline style attribute(s) | move static presentation into shared classes; retain dynamic state through semantic classes |
| pages/student/dashboard.php | 25 inline style attribute(s) | move static presentation into shared classes; retain dynamic state through semantic classes |
| pages/student/health_monitoring.php | 2 inline style attribute(s); 4 hardcoded hex color(s) | move static presentation into shared classes; retain dynamic state through semantic classes; replace authored color values with semantic theme tokens |
| pages/student/medical_record.php | 1 inline style attribute(s) | move static presentation into shared classes; retain dynamic state through semantic classes |
| pages/student/messages.php | 8 inline style attribute(s); 3 hardcoded hex color(s) | move static presentation into shared classes; retain dynamic state through semantic classes; replace authored color values with semantic theme tokens |
| pages/student/my_qr.php | 3 inline style attribute(s); 6 hardcoded hex color(s) | move static presentation into shared classes; retain dynamic state through semantic classes; replace authored color values with semantic theme tokens |
| pages/student/notifications.php | no inline style attributes detected | continue using shared tokens and components |
| pages/student/personal_info.php | no inline style attributes detected; no page-level H1 in this view | add one semantic H1 or reuse the shared page title |
| pages/student/profile.php | 2 inline style attribute(s) | move static presentation into shared classes; retain dynamic state through semantic classes |
| pages/student/queue.php | 9 inline style attribute(s); 3 hardcoded hex color(s) | move static presentation into shared classes; retain dynamic state through semantic classes; replace authored color values with semantic theme tokens |
| pages/student/vaccination.php | 1 inline style attribute(s) | move static presentation into shared classes; retain dynamic state through semantic classes |
| pages/style_guide.php | no inline style attributes detected | continue using shared tokens and components |
| auth/forgot_password.php | no inline style attributes detected | continue using shared tokens and components |
| auth/login.php | 1 inline style attribute(s) | move static presentation into shared classes; retain dynamic state through semantic classes |
| auth/logout.php | no inline style attributes detected; no page-level H1 in this view | add one semantic H1 or reuse the shared page title |
| auth/register.php | 10 inline style attribute(s); 10 hardcoded hex color(s) | move static presentation into shared classes; retain dynamic state through semantic classes; replace authored color values with semantic theme tokens |
| auth/register_hub.php | 2 inline style attribute(s) | move static presentation into shared classes; retain dynamic state through semantic classes |
| auth/reset_password.php | 1 inline style attribute(s); 1 hardcoded hex color(s) | move static presentation into shared classes; retain dynamic state through semantic classes; replace authored color values with semantic theme tokens |
| auth/verify_registration.php | no inline style attributes detected; no page-level H1 in this view | add one semantic H1 or reuse the shared page title |

## Shared findings

- 62 existing app views were found before this refactor; a local-only style guide has been added, bringing the current total to 63 under pages/. There are 7 authentication views.
- Baseline PHP scan: 221 inline style attributes and 126 hex colors in page views; 15 inline style attributes and 11 hex colors in auth views.
- Most dashboard pages use a shared sidebar and topbar, but page title markup, embedded styles, status colors, table/form conventions, and empty-state patterns remain partly page-specific.
- Doctor is not a supported authenticated role in includes/auth.php; the app exposes Admin, Nurse, Student, and Teacher (Teacher pages live under pages/adviser/).
- No production data was altered. Database row-level test-data review is separate from this static UI scan.
