# BPC School Clinic Management System

PHP and MySQL/MariaDB clinic portal for Admin, Nurse, Student, and Teacher/Adviser roles. The project includes role-specific pages, API endpoints, QR check-in, queues, clinical records, inventory, messaging, SOS, notifications, and MICA integrations. The source remains a PHP, MySQL, vanilla JavaScript, and Bootstrap application.

## Run locally with XAMPP

1. Place this folder at `C:\xampp\htdocs\bpc_clinic_system`.
2. Start **Apache** and **MySQL** in the XAMPP Control Panel.
3. Copy `.env.example` to `.env`. Set `DB_HOST`, `DB_NAME`, `DB_USER`, and `DB_PASS` for the local database. XAMPP's default local MySQL account is shown in the example; change it if your installation uses different credentials.
4. For a new database, import `database/bpc_clinic_system.sql` using phpMyAdmin. That file is the baseline schema; it does not by itself represent every later feature migration.
5. Bring the schema up to the version expected by this application by applying only the migrations that are missing from your database. The migration SQL files are in `database/`; some are one-time changes and some transform existing data. Back up the database first and review the target schema before applying migrations. There is currently no automatic migration runner or migration-history table.
6. `database/migration_registration_role_medical_details.sql` is required for the teacher and nurse registration fields. Its nullable columns have been applied to the local XAMPP database used during the latest audit.
7. Optional AI and voice features need their provider settings in `.env`. Set a unique `APP_KEY` for any deployed instance. `APP_URL` may be omitted for local XAMPP; the application derives its URL from the current request.
8. Open `http://localhost/bpc_clinic_system/`.

To check a migrated database, run `php scratch/check_migrations.php` from the project directory. The script checks tables and columns mentioned in migration files; it is a partial migration check, not a complete schema validator.

## Project layout

- `auth/` — login, registration, verification, password reset, logout.
- `pages/admin/`, `pages/nurse/`, `pages/student/`, `pages/adviser/` — role-specific views.
- `api/` — JSON and file/API workflows called by pages.
- `includes/` and `components/` — shared authorization, UI, database helpers, and MICA components.
- `assets/` — stylesheets, scripts, icons, and uploaded profile images.
- `database/` — baseline schema and incremental migration SQL.

The primary navigation links are defined in `components/sidebar.php`; the role directory for Teacher pages is `pages/adviser/`.

## Verification and limitations

The `scratch/` directory contains audit and test utilities. Review a script before running it against a database with real records: some workflow test scripts change queue, inventory, or notification data. The full role-by-role workflows require authenticated test accounts and should be exercised in a browser against a backed-up test database.

See [AUDIT.md](AUDIT.md) for the latest inventory, verified repairs, and test results.

## Hostinger production configuration

Keep the XAMPP `.env` unchanged. On Hostinger, create a separate private environment configuration (preferably outside `public_html`, selected with `APP_ENV_FILE`, or through server environment variables):

```dotenv
APP_ENV=production
APP_URL=https://your-production-domain.example
APP_KEY=<unique-random-64-hex-character-key>
DB_HOST=localhost
DB_PORT=3306
DB_NAME=u962995739_bpc_clinic
DB_USER=u962995739_bpc_user
DB_PASS=<read-from-the-private-production-environment>
DB_CHARSET=utf8mb4
```

Confirm `DB_HOST` in hPanel's database management screen. `localhost` is appropriate only when the PHP application and MySQL database are hosted in the same applicable Hostinger environment; remote database access uses the host details shown by hPanel.

After importing a reviewed backup without dropping existing production data, run this read-only check from Hostinger's terminal:

```bash
php setup/verify_database.php
```

For XAMPP, use `php setup/verify_database.php --allow-local`. The checker never prints the database password. It verifies the active PDO connection, database/host identity, charset, required tables and columns, centralized connection usage, HTTPS production URL, and private-directory protection.
