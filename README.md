# AI-Enhanced School Clinic Management System for Bulacan Polytechnic College Healthcare Service Improvement

## Setup (XAMPP)

1. Copy the `bpc_clinic_system` folder into `htdocs/`.
2. Start Apache + MySQL in XAMPP.
3. Open phpMyAdmin → Import → select `database/bpc_clinic_system.sql`. This creates the
   `bpc_clinic_system` database with all tables and seeds one admin account.
4. Default admin login: **username** `admin`, password reset needed — use
   "Forgot Password" on the login page once (demo mode shows the reset link inline
   since no mail server is configured), or update the password hash directly in
   `users` table using `password_hash()`.
5. Visit `http://localhost/bpc_clinic_system/`.
6. Register a student account via the Register page to test the student portal.
   Nurse accounts are created by the admin (Nurse Management module — see roadmap).

## What's included in this phase

- Full database schema (`database/bpc_clinic_system.sql`) — all 15+ tables from the spec.
- Complete auth system: login, registration, forgot/reset password, change password,
  remember-me, CSRF protection, password hashing, role-based redirects, session mgmt.
- Emerald-green glassmorphism dashboard theme (`assets/css/style.css`) matching the
  brief's color spec, responsive sidebar/topbar, Chart.js analytics.
- Admin Dashboard: live stat cards + 4 charts (visits, illnesses, medicine usage, BMI trend).
- Nurse Dashboard: today's patients/appointments, pending requests, low-stock alert banner.
- Student Dashboard: upcoming appointment, latest BMI, visit count, BMI history chart, announcements feed.
- Appointment system (book / approve / reject / complete / cancel) with AJAX + notifications.
- Health Monitoring module (nurse encodes vitals → auto BMI + status; student views trend charts + history).
- Medicine Inventory (add medicine, stock in/out, low-stock & expired badges).
- AI Health Assistant — safe rule-based responder (never diagnoses, flags emergencies,
  defers to the nurse) with an optional hook to plug in a real LLM API
  (`config/ai_config.php` + `includes/ai_assistant.php`).
- Profile management: update info, change password, upload profile picture.
- Notification system (bell dropdown, polls every 30s).
- Activity logging on every sensitive action (`activity_logs` table).

## Roadmap — next phases (not yet built)

- Admin: Student Management, Nurse Management (add/edit/deactivate accounts).
- Full Medical Records module (consultation entry, attachments, printable record).
- Medical Certificate generator (PDF).
- Vaccination Record CRUD.
- Announcements CRUD (admin/nurse post, pinning).
- Reports module with PDF/Excel export (daily/weekly/monthly/yearly).
- Health Analytics page (gender/course/year-level distribution charts).
- Activity Logs viewer UI, System Settings page (logo, backup/restore).
- QR code student check-in, SMS/Email notification wiring (hooks are already
  noted inline, e.g. in `auth/forgot_password.php`).
- Dark mode toggle.

Tell me which module to build next and I'll continue the same way — page by page.
