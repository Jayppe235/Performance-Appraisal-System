# PMAS production deployment

PMAS is deployed as one HTTPS site: the Vite build is served at the subdomain root, PHP is served from the same document root, and only PHP connects to private MySQL. Never expose MySQL port 3306 to the public internet and never put database credentials in a `VITE_*` variable.

## Hosting requirements

- Apache with `mod_rewrite` and `mod_headers`, PHP 8.1 or newer, PDO MySQL, OpenSSL, Mbstring, GD, ZIP, and Composer support
- A private MySQL database/user, HTTPS certificate, and a subdomain whose document root is the PMAS directory
- Writable `assets/uploads` and any report temporary directories required by the host
- Ability to configure server environment variables (hosting panel, Apache virtual host, or a protected mechanism outside the public document root)

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
2. Copy `.env.production.example` as a reference only. Configure those names in the hosting panel; do not upload a file containing real secrets into the public directory.
3. Use `PMAS_APP_URL=https://pmas.your-domain.example` and `PMAS_BASE_PATH=/` for a subdomain-root deployment.
4. Set `PMAS_DATA_KEY` to the exact key used by the local database before changing database names or users. For this legacy local installation, an administrator can calculate the prior fallback locally with:

   ```powershell
   php -r "echo hash('sha256', 'pmas_db_clean' . 'root' . 'DIPASCAF'), PHP_EOL;"
   ```

   Store the output directly in the host's secret/environment settings. Do not paste it into source control, tickets, chat, or frontend variables. Back it up in the organization's password manager; losing it makes encrypted values unreadable.

## Move the database

Stop writes during the final export so local and hosted records cannot diverge.

```powershell
mysqldump --host=localhost --user=root --single-transaction --routines --triggers --events --default-character-set=utf8mb4 pmas_db_clean > pmas-production.sql
```

Create the hosted database and a dedicated user limited to that database. Import through the hosting panel or command line:

```text
mysql --host=PRIVATE_DB_HOST --user=APP_DB_USER --password HOST_DB_NAME < pmas-production.sql
```

Delete local/export copies from any public or shared location after the validated import. Compare key table counts (users, evaluations, assignments, notifications), log in with each role, and verify an encrypted value can still be read before reopening writes.

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
