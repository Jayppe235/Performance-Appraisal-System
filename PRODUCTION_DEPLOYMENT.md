# PMAS production deployment

PMAS is deployed as one HTTPS site: the Vite build is served at the subdomain root, PHP is served from the same document root, and only PHP connects to private MySQL. Never expose MySQL port 3306 to the public internet and never put database credentials in a `VITE_*` variable.

Git deploys application code only. Database records and `assets/uploads` are persistent production data and are backed up and deployed independently. Local development never writes to the production database.

## Hosting requirements

- Apache with `mod_rewrite` and `mod_headers`, PHP 8.1 or newer, PDO MySQL, OpenSSL, Mbstring, GD, ZIP, and Composer support
- A private MySQL database/user, HTTPS certificate, and a subdomain whose document root is the PMAS directory
- Writable `assets/uploads` and any report temporary directories required by the host
- Ability to configure server environment variables (hosting panel, Apache virtual host, or a protected mechanism outside the public document root)
- Automated daily MySQL backups with a documented restore method and periodic encrypted off-host copies

## Prepare production safely

Create a deploy-only archive (tests, builds, installs production PHP dependencies, and rejects forbidden files):

```powershell
npm run release:bundle
```

The uploadable artifact is `release/pmas-production.zip`. Its `assets/uploads` directory is intentionally empty; copy only files approved for production into it before upload.

Create the finalized structure-only database script from the current local database. The command prompts for the database password and never embeds it in the script:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/Export-ProductionSchema.ps1
```

Import `release/production-schema.sql` into the empty hosted database, then import separately reviewed production records. Do not use `database/pmas.sql` for launch because it contains development seed data.

1. Revoke the Gemini key that was formerly committed in `includes/config.php`, then create a replacement key. Do not reuse the exposed key.
2. Copy `.env.production.example` as a reference only. Configure those names in the hosting panel; do not upload a file containing real secrets into the public directory. `PMAS_ENV=production` activates strict checks for HTTPS, a dedicated non-root database user, a password, and a data key of at least 32 characters. A host value of `localhost` is valid when PHP and private MySQL run on the same server.
3. Use `PMAS_APP_URL=https://pmas.your-domain.example` and `PMAS_BASE_PATH=/` for a subdomain-root deployment.
4. Set `PMAS_DATA_KEY` to the exact key used by the local database before changing database names or users. For this legacy local installation, an administrator can calculate the prior fallback locally with:

   ```powershell
   php -r "echo hash('sha256', 'pmas_db_clean' . 'root' . 'DIPASCAF'), PHP_EOL;"
   ```

   Store the output directly in the host's secret/environment settings. Do not paste it into source control, tickets, chat, or frontend variables. Back it up in the organization's password manager; losing it makes encrypted values unreadable.

## Move the database

Stop writes during the final export so local and hosted records cannot diverge. The repository helper writes the sensitive dump under ignored `private-backups/` and removes incomplete exports on failure:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/Export-ProductionData.ps1 -NoPassword
```

Create the hosted database and a dedicated user limited to that database. Import through the hosting panel or command line:

```text
mysql --host=PRIVATE_DB_HOST --user=APP_DB_USER --password HOST_DB_NAME < private-backups/pmas-production-TIMESTAMP.sql
```

Do not enable public remote MySQL access just to import. Prefer phpMyAdmin or a shell inside the hosting network. Delete temporary hosted import copies after validation.

Capture local counts before cutover:

```powershell
C:\xampp\php\php.exe scripts/Check-Database.php
```

Run the same CLI script from a private hosting shell with production environment variables, or compare `COUNT(*)` in phpMyAdmin for `users`, `evaluations`, `peer_evaluation_assignments`, `appraisal_periods`, and `notifications`. Log in with every role and verify encrypted values before reopening writes.

## Ongoing schema changes and backups

- Commit each schema change as a reviewed `database/migration_*.sql` file; never place live records in a migration.
- Back up production immediately before applying a migration, apply it explicitly through the private hosting shell or phpMyAdmin, record the filename/date/operator, then run the acceptance checks.
- Never import the local development database over production after launch. Local test data and production records intentionally remain separate.
- Retain daily host backups according to institutional policy and create an encrypted off-host backup periodically. Test restoration into a temporary database at least quarterly.
- Back up `assets/uploads` alongside the database. Git ignores runtime uploads, and the release bundle creates an empty upload directory.

## Build and upload

From a clean working tree:

```powershell
npm ci
npm test
npm run build
composer install --no-dev --optimize-autoloader
```

Upload the PHP application directories/files, `vendor`, `.htaccess`, and the **contents** of `dist` into the subdomain document root. The built `index.html` must replace the development `index.html`; do not deploy `src`, `node_modules`, tests, database dumps, debug scripts, or real environment files. Keep `api`, `includes`, `reports`, `assets`, and `vendor` beside the built `index.html`.

Suggested production document root:

```text
index.html
.htaccess
api/
assets/
includes/
reports/
vendor/
<Vite-generated asset directory>/
```

If the provider cannot keep `includes` outside the public document root, deny direct web access to it in the hosting configuration. Disable directory listings and ensure `.env*`, SQL files, logs, tests, setup/debug utilities, and Composer metadata cannot be downloaded.

## Acceptance checks

- Open `/login`, sign in, sign out, and refresh a nested route such as `/admin/overview` directly.
- Confirm `/api/health.php` returns HTTP 200 with `{ "ok": true }` and does not reveal database details.
- Confirm HTTPS session cookies are `Secure`, `HttpOnly`, and `SameSite=Lax`.
- Exercise every role, uploads/images, PDF and spreadsheet reports, notifications, live metrics/SSE, and AI features.
- Disconnect the client or stop the database: the blocking connectivity screen must appear and recover automatically when service returns.
- Confirm browser bundles contain no database password, data key, or AI key. Confirm port 3306 is not publicly reachable.
- Check the PHP/server logs after the smoke test and remove or protect `setup.php` and all `_debug*`, `_test*`, `_fix*`, and migration runner files before launch.

Local development remains `npm run dev` plus Apache/MySQL in XAMPP. The Vite proxy continues to map `/api` to `/PMAS/api` locally.

Use `.env.local.example` as the local reference with `PMAS_ENV=local`. Local defaults target XAMPP; do not replace them with production credentials.
