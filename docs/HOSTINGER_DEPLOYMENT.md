# Hostinger deployment — BPC School Clinic

## Root cause and configuration

The uploaded application showed a database failure because production credentials were not verifiably available to `config/database.php`. The earlier code could also fall back to the XAMPP `root` account when variables were absent. Production now fails closed: `DB_HOST`, `DB_NAME`, `DB_USER`, and `DB_PASS` must be provided privately. The password is never stored in PHP source or the deployment archive.

The application remains one codebase. XAMPP is detected as local and retains its existing database defaults. Hostinger uses `APP_ENV=production` and the values in `.env.production.example`.

## Upload

1. Back up the existing Hostinger files and database from hPanel before replacing anything.
2. Upload the contents of the prepared ZIP into `public_html/bpc_clinic_system/`. Do not upload the ZIP into a publicly accessible location and leave it there.
3. Keep production uploads and any newer runtime files when replacing application code. Do not overwrite a newer production `.env`.
4. Create the real production environment file privately. Prefer a location outside `public_html` and configure `APP_ENV_FILE` at the hosting environment level when available. Otherwise place `.env` in the application root; the supplied Apache/LiteSpeed rules deny HTTP access to it.
5. Use `APP_URL=https://bpcschoolclinic.org/bpc_clinic_system` exactly, without a trailing slash.
6. In hPanel > Databases > Management, copy the exact database name, username, and host. `localhost` is valid only when hPanel shows it for this website/database relationship.
7. Keep file permissions conventional: directories `0755`, PHP/static files `0644`. Writable runtime directories are `assets/images/profiles`, `assets/images/student_ids`, `uploads`, `uploads/messages`, and `storage`; use the least-permissive setting supported by the hosting account and never `0777` unless Hostinger support explicitly requires it temporarily.

## Database import

1. Export the current source database with structure and data using UTF-8/`utf8mb4`; include routines only if they exist.
2. Create a fresh backup of the target Hostinger database.
3. Import through hPanel phpMyAdmin into the exact configured database. Do not select options that drop existing tables unless this is an explicitly approved full replacement with a verified backup.
4. Apply only missing additive migrations after comparing the target schema. Do not rerun destructive historical migrations blindly.
5. From Hostinger Terminal, run `php setup/verify_database.php`. This is read-only and does not print secrets.

## Required hosting capabilities

- PHP 8.0 or newer; PHP 8.2 recommended.
- PDO MySQL, mbstring, JSON, OpenSSL, and fileinfo.
- cURL for OpenRouter/SMTP-related HTTP integrations; GD/ZIP/Intl are recommended where used.
- HTTPS enabled for the production domain.
- Apache/LiteSpeed `.htaccess` support.
- SMTP and outbound HTTPS access for OTP/MICA integrations.

## Post-deployment checks

1. Open the HTTPS login page and verify CSS/JS assets load without mixed-content errors.
2. Test one authorized account for each role and confirm its dashboard redirect.
3. Refresh an authenticated page to verify session persistence, then test logout.
4. Submit a non-production registration fixture and complete OTP only if SMTP is configured.
5. Verify People Directory, queue, consultation, inventory, QR, SOS, messaging, profile upload, and PWA service-worker registration.
6. Verify MICA returns a controlled disabled/unavailable response before adding provider keys, then test it again after keys are configured.
7. Confirm `.env`, `config/database.php`, SQL files, `scratch/`, `setup/`, and `storage/` return HTTP 403.
8. Review the private PHP error log for connection references; do not enable `display_errors` in production.

## Rollback

1. Put the site in maintenance mode or restrict access.
2. Restore the backed-up application files without overwriting production uploads created after the backup.
3. Restore the database backup only when required; this can discard newer records, so obtain explicit authorization first.
4. Restore the previous private environment file.
5. Re-run the read-only verifier and role/login smoke tests.

## Tests that must remain pending until Hostinger credentials/services exist

- Actual production PDO connection and exact Hostinger hostname.
- Production schema comparison after import.
- Real login/session behavior on Hostinger.
- SMTP OTP delivery, OpenRouter/MICA response, Firebase push delivery, camera QR scan, cron execution, and installed-PWA behavior on the live domain.

