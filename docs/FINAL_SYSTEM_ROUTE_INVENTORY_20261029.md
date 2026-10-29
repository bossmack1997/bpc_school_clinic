# Route inventory — October 29, 2026

Every tracked page, API and authentication PHP route is listed below. This is a static inventory, not a claim that every route or button passed a browser test. Guards may be inherited from configuration or included entry points. API action detection is heuristic. No route is approved for deletion by this inventory.

| Route | Purpose/title | Observed local guard | Disposition |
| --- | --- | --- | --- |
| `api/account_provisioning.php` | account provisioning | require_permission('manage_users') | Retain pending feature/dependency review |
| `api/activity_logs.php` | activity logs | require_admin_permission('view_audit_logs') | Retain pending feature/dependency review |
| `api/admin_dashboard.php` | admin dashboard | require_role('admin') | Retain pending feature/dependency review |
| `api/ai_assistant.php` | ai assistant | require_admin_permission('manage_users'); require_login() | Retain pending feature/dependency review |
| `api/announcements.php` | announcements | require_login() | Retain pending feature/dependency review |
| `api/certificates.php` | certificates | require_login() | Retain pending feature/dependency review |
| `api/clinical.php` | clinical | require_doctor() | Retain pending feature/dependency review |
| `api/cron_automation.php` | cron automation | Delegated/inspect include chain | Retain pending feature/dependency review |
| `api/dispensing.php` | dispensing | require_login() | Retain pending feature/dependency review |
| `api/emergencies.php` | emergencies | require_admin_permission('view_emergencies'); require_login() | Retain pending feature/dependency review |
| `api/global_sos.php` | global sos | require_admin_permission('view_emergencies'); require_role(['admin','nurse']) | Retain pending feature/dependency review |
| `api/health_monitoring.php` | health monitoring | require_login() | Retain pending feature/dependency review |
| `api/inventory.php` | inventory | require_primary_admin(); require_role(['admin', 'nurse']) | Retain pending feature/dependency review |
| `api/medical_records.php` | medical records | require_login() | Retain pending feature/dependency review |
| `api/medicine_requisition_download.php` | medicine requisition download | Delegated/inspect include chain | Retain pending feature/dependency review |
| `api/messages.php` | messages | require_login() | Retain pending feature/dependency review |
| `api/mica_clinic_tools.php` | mica clinic tools | require_permission('clinical_records_read') | Retain pending feature/dependency review |
| `api/mica_inventory.php` | mica inventory | Delegated/inspect include chain | Retain pending feature/dependency review |
| `api/mica_tts.php` | mica tts | require_login() | Retain pending feature/dependency review |
| `api/my_advisory.php` | my advisory | require_role('teacher') | Retain pending feature/dependency review |
| `api/notifications.php` | notifications | require_login() | Retain pending feature/dependency review |
| `api/notifications/save-fcm-token.php` | save-fcm-token | Delegated/inspect include chain | Retain pending feature/dependency review |
| `api/nurse_dashboard.php` | nurse dashboard | require_role('nurse') | Retain pending feature/dependency review |
| `api/nurse_documents.php` | nurse documents | require_login() | Retain pending feature/dependency review |
| `api/patients.php` | patients | require_login() | Retain pending feature/dependency review |
| `api/physician_queue.php` | physician queue | require_doctor(); require_login() | Retain pending feature/dependency review |
| `api/profile.php` | profile | require_login() | Retain pending feature/dependency review |
| `api/qr_codes.php` | qr codes | require_login() | Retain pending feature/dependency review |
| `api/qr_image.php` | qr image | require_login() | Retain pending feature/dependency review |
| `api/queue.php` | queue | require_login() | Retain pending feature/dependency review |
| `api/report_exports.php` | report exports | require_login() | Retain pending feature/dependency review |
| `api/reports.php` | reports | require_admin_permission('view_reports'); require_role(['admin', 'nurse']) | Retain pending feature/dependency review |
| `api/sections.php` | sections | require_admin_permission('manage_sections'); require_role(['admin', 'teacher']) | Retain pending feature/dependency review |
| `api/settings.php` | settings | require_admin_permission('manage_settings'); require_primary_admin(); require_role('admin') | Retain pending feature/dependency review |
| `api/students_api.php` | students api | require_admin_permission('manage_users'); require_role(['nurse', 'admin']) | Retain pending feature/dependency review |
| `api/system_backups.php` | system backups | require_admin_permission('backups_manage') | Retain pending feature/dependency review |
| `api/users.php` | users | require_primary_admin(); require_role('admin') | Retain pending feature/dependency review |
| `api/vaccinations.php` | vaccinations | require_login() | Retain pending feature/dependency review |
| `api/walkin.php` | walkin | require_login() | Retain pending feature/dependency review |
| `api/wellbeing.php` | wellbeing | require_login() | Retain pending feature/dependency review |
| `auth/activate_account.php` | activate account | Delegated/inspect include chain | Retain pending feature/dependency review |
| `auth/confirm_password.php` | confirm password | require_login() | Retain pending feature/dependency review |
| `auth/forgot_password.php` | Forgot Password | Delegated/inspect include chain | Retain pending feature/dependency review |
| `auth/login.php` | Login | Delegated/inspect include chain | Retain pending feature/dependency review |
| `auth/logout.php` | logout | Delegated/inspect include chain | Retain pending feature/dependency review |
| `auth/register.php` | Create an Account | Delegated/inspect include chain | Retain pending feature/dependency review |
| `auth/register_hub.php` | Create an Account | Delegated/inspect include chain | Retain pending feature/dependency review |
| `auth/reset_password.php` | Reset Password | Delegated/inspect include chain | Retain pending feature/dependency review |
| `auth/setup_account.php` | setup account | Delegated/inspect include chain | Retain pending feature/dependency review |
| `auth/verify_registration.php` | Verify Email Ownership | Delegated/inspect include chain | Retain pending feature/dependency review |
| `dashboard.php` | dashboard | require_login() | Retain pending feature/dependency review |
| `index.php` | index | Delegated/inspect include chain | Retain pending feature/dependency review |
| `pages/admin/account_exceptions.php` | account exceptions | require_role('admin') | Retain pending feature/dependency review |
| `pages/admin/activity_logs.php` | Activity History | require_admin_permission('view_audit_logs') | Retain pending feature/dependency review |
| `pages/admin/admin_accounts.php` | Admin Accounts | require_login(); require_primary_admin() | Retain pending feature/dependency review |
| `pages/admin/announcements.php` | Announcements | require_primary_admin() | Retain pending feature/dependency review |
| `pages/admin/backups.php` | Backup & Recovery | require_admin_permission('backups_manage') | Retain pending feature/dependency review |
| `pages/admin/clinical_legacy_dashboard.php` | Dashboard | require_role('admin') | Retain pending feature/dependency review |
| `pages/admin/dashboard.php` | System Administration Dashboard | require_permission('system_manage'); require_role('admin') | Retain pending feature/dependency review |
| `pages/admin/emergencies.php` | Emergency Oversight Center | require_admin_permission('view_emergencies') | Retain pending feature/dependency review |
| `pages/admin/inventory.php` | Medicine Inventory | require_role('admin') | Retain pending feature/dependency review |
| `pages/admin/medical_records.php` | medical records | require_admin_permission('view_reports') | Retain pending feature/dependency review |
| `pages/admin/notifications.php` | Notifications | require_role('admin') | Retain pending feature/dependency review |
| `pages/admin/pending_accounts.php` | pending accounts | require_role('admin') | Retain pending feature/dependency review |
| `pages/admin/profile.php` | profile | require_role('admin') | Retain pending feature/dependency review |
| `pages/admin/provision_accounts.php` | Create Accounts | require_permission('manage_users') | Retain pending feature/dependency review |
| `pages/admin/pwa_debug.php` | PWA Diagnostics & Device Readiness | require_primary_admin() | Retain pending feature/dependency review |
| `pages/admin/qr_management.php` | QR Management | require_primary_admin() | Retain pending feature/dependency review |
| `pages/admin/qr_scanner.php` | qr scanner | require_primary_admin() | Retain pending feature/dependency review |
| `pages/admin/reports.php` | Reports | require_admin_permission('view_reports') | Retain pending feature/dependency review |
| `pages/admin/role_profiles.php` | Administrator Management | require_primary_admin() | Retain pending feature/dependency review |
| `pages/admin/sections.php` | Sections & Classes | require_admin_permission('manage_sections') | Retain pending feature/dependency review |
| `pages/admin/security.php` | Security | require_admin_permission('manage_security') | Retain pending feature/dependency review |
| `pages/admin/settings.php` | System Settings | require_admin_permission('manage_security'); require_admin_permission('manage_settings'); require_role('admin') | Retain pending feature/dependency review |
| `pages/admin/students.php` | students | require_role('admin') | Retain pending feature/dependency review |
| `pages/admin/system_monitoring.php` | System Monitoring | require_permission('system_manage') | Retain pending feature/dependency review |
| `pages/admin/users.php` | People Directory & Account Monitoring | require_role('admin') | Retain pending feature/dependency review |
| `pages/adviser/announcements.php` | Clinic Announcements | require_role('teacher') | Retain pending feature/dependency review |
| `pages/adviser/consultations.php` | My Health Record | require_role('teacher') | Retain pending feature/dependency review |
| `pages/adviser/dashboard.php` | Teacher Dashboard | require_role('teacher') | Retain pending feature/dependency review |
| `pages/adviser/emergencies.php` | Emergency Reports | require_role('teacher') | Retain pending feature/dependency review |
| `pages/adviser/messages.php` | messages | require_role('teacher') | Retain pending feature/dependency review |
| `pages/adviser/my_advisory.php` | My Advisory Class | require_role('teacher') | Retain pending feature/dependency review |
| `pages/adviser/my_qr.php` | My Clinic QR | require_role('teacher') | Retain pending feature/dependency review |
| `pages/adviser/notifications.php` | Notifications | require_role('teacher') | Retain pending feature/dependency review |
| `pages/adviser/profile.php` | My Profile | require_role('teacher') | Retain pending feature/dependency review |
| `pages/adviser/queue.php` | Faculty Clinic Queue | require_role('teacher') | Retain pending feature/dependency review |
| `pages/adviser/settings.php` | settings | require_role('teacher') | Retain pending feature/dependency review |
| `pages/adviser/student_detail.php` | Student Detail | require_role('teacher') | Retain pending feature/dependency review |
| `pages/adviser/students.php` | My Advisory Class | require_role('teacher') | Retain pending feature/dependency review |
| `pages/ai_assistant.php` | MICA - Clinic Intelligence & Automation Center | require_role(['admin', 'nurse', 'student', 'teacher']) | Retain pending feature/dependency review |
| `pages/doctor/clinic_monitoring.php` | clinic monitoring | require_doctor() | Retain pending feature/dependency review |
| `pages/doctor/consultations.php` | consultations | Delegated/inspect include chain | Retain pending feature/dependency review |
| `pages/doctor/dashboard.php` | Clinical Command Center | Delegated/inspect include chain | Retain pending feature/dependency review |
| `pages/doctor/emergencies.php` | emergencies | require_doctor() | Retain pending feature/dependency review |
| `pages/doctor/inventory.php` | inventory | require_doctor() | Retain pending feature/dependency review |
| `pages/doctor/messages.php` | messages | require_doctor() | Retain pending feature/dependency review |
| `pages/doctor/nurse_verification.php` | Nurse Verification | require_doctor() | Retain pending feature/dependency review |
| `pages/doctor/patients.php` | patients | Delegated/inspect include chain | Retain pending feature/dependency review |
| `pages/doctor/profile.php` | profile | require_doctor() | Retain pending feature/dependency review |
| `pages/doctor/queue.php` | Clinic Queue | Delegated/inspect include chain | Retain pending feature/dependency review |
| `pages/doctor/reports.php` | reports | require_doctor() | Retain pending feature/dependency review |
| `pages/doctor/settings.php` | settings | require_doctor() | Retain pending feature/dependency review |
| `pages/doctor_messages.php` | Physician Messages | require_role(['nurse','admin']) | Retain pending feature/dependency review |
| `pages/medicine_requisition.php` | medicine requisition | Delegated/inspect include chain | Retain pending feature/dependency review |
| `pages/nurse/account.php` | Nurse Account Dashboard | require_login() | Retain pending feature/dependency review |
| `pages/nurse/announcements.php` | Clinic Announcements | require_role('nurse') | Retain pending feature/dependency review |
| `pages/nurse/certificate_print.php` | Print Certificate | require_role(['nurse','admin']) | Retain pending feature/dependency review |
| `pages/nurse/certificates.php` | Medical Certificates | require_role('nurse') | Retain pending feature/dependency review |
| `pages/nurse/credentials.php` | Professional Credentials | require_role('nurse') | Retain pending feature/dependency review |
| `pages/nurse/dashboard.php` | Nurse Dashboard | require_role('nurse') | Retain pending feature/dependency review |
| `pages/nurse/dispensing.php` | Medicine Dispensing | require_role('nurse') | Retain pending feature/dependency review |
| `pages/nurse/emergencies.php` | Emergency Center | require_role('nurse') | Retain pending feature/dependency review |
| `pages/nurse/health_monitoring.php` | Health Monitoring | require_role('nurse') | Retain pending feature/dependency review |
| `pages/nurse/inventory.php` | Medicine Inventory | require_role('nurse') | Retain pending feature/dependency review |
| `pages/nurse/medical_records.php` | Student Search & Records | require_role('nurse') | Retain pending feature/dependency review |
| `pages/nurse/messages.php` | messages | require_role('nurse') | Retain pending feature/dependency review |
| `pages/nurse/notifications.php` | Notifications | require_role('nurse') | Retain pending feature/dependency review |
| `pages/nurse/patient_history.php` | Patient Clinic History | require_role('nurse') | Retain pending feature/dependency review |
| `pages/nurse/profile.php` | My Profile | require_role('nurse') | Retain pending feature/dependency review |
| `pages/nurse/qr_management.php` | QR Management | require_role('nurse') | Retain pending feature/dependency review |
| `pages/nurse/queue.php` | Clinic Queue Management | require_role('nurse') | Retain pending feature/dependency review |
| `pages/nurse/reports.php` | Reports | require_role('nurse') | Retain pending feature/dependency review |
| `pages/nurse/settings.php` | settings | require_role('nurse') | Retain pending feature/dependency review |
| `pages/nurse/walkin.php` | Walk-in Patient Management | require_role('nurse') | Retain pending feature/dependency review |
| `pages/qr_verify.php` | QR Verification | Delegated/inspect include chain | Retain pending feature/dependency review |
| `pages/queue_display.php` | queue display | Delegated/inspect include chain | Retain pending feature/dependency review |
| `pages/report_exports.php` | Report Exports | require_login() | Retain pending feature/dependency review |
| `pages/student/announcements.php` | Clinic Announcements | require_role('student') | Retain pending feature/dependency review |
| `pages/student/certificates.php` | Medical Certificates | require_role('student') | Retain pending feature/dependency review |
| `pages/student/dashboard.php` | Student Dashboard | require_role('student') | Retain pending feature/dependency review |
| `pages/student/health_monitoring.php` | Health Monitoring | require_role('student') | Retain pending feature/dependency review |
| `pages/student/medical_record.php` | My Medical Record | require_role('student') | Retain pending feature/dependency review |
| `pages/student/messages.php` | messages | require_role('student') | Retain pending feature/dependency review |
| `pages/student/my_qr.php` | Smart Health Pass | require_role('student') | Retain pending feature/dependency review |
| `pages/student/notifications.php` | Notifications | require_role('student') | Retain pending feature/dependency review |
| `pages/student/personal_info.php` | personal info | require_role('student') | Retain pending feature/dependency review |
| `pages/student/profile.php` | My Profile | require_role('student') | Retain pending feature/dependency review |
| `pages/student/queue.php` | Clinic Queue Tracker | require_role('student') | Retain pending feature/dependency review |
| `pages/student/settings.php` | settings | require_role('student') | Retain pending feature/dependency review |
| `pages/student/vaccination.php` | Vaccination Record | require_role('student') | Retain pending feature/dependency review |
| `pages/style_guide.php` | UI Style Guide | require_login() | Retain pending feature/dependency review |
| `setup/initial_admin.php` | Initial Primary Administrator Setup | Delegated/inspect include chain | Retain pending feature/dependency review |
| `setup/migrate_announcement_publication.php` | migrate announcement publication | Delegated/inspect include chain | Retain pending feature/dependency review |
| `setup/migrate_doctor_invitations.php` | migrate doctor invitations | Delegated/inspect include chain | Retain pending feature/dependency review |
| `setup/migrate_people_monitoring.php` | migrate people monitoring | Delegated/inspect include chain | Retain pending feature/dependency review |
| `setup/run_admin_departures.php` | run admin departures | Delegated/inspect include chain | Retain pending feature/dependency review |
| `setup/verify_database.php` | verify database | Delegated/inspect include chain | Retain pending feature/dependency review |
