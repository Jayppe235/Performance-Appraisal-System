# PMAS online deployment runbook

## 1. Server layout

Configure the temporary site's document root as `/srv/pmas/current` (or the
school-provided equivalent). The deployment creates:

```text
<deploy-root>/
  current -> releases/<commit>
  previous -> releases/<previous-commit>
  releases/
  shared/uploads/
```

Set `assets/uploads` writable by the PHP worker. Do not make the rest of the
release writable by PHP.

## 2. Production environment

Configure the variables from `.env.production.example` in the web server or
PHP-FPM environment. Required values are `PMAS_ENV=production`, the temporary
HTTPS URL, `PMAS_BASE_PATH=/`, dedicated database credentials, and a stable
random `PMAS_DATA_KEY` of at least 32 characters. Never create a `VITE_*`
database or secret variable.

Restart or reload PHP after changing environment variables. Confirm from the
server shell:

```bash
php bin/Check-Database.php
curl --fail https://temporary-host/api/health.php
```

## 3. Clean database and test accounts

Generate the structure-only schema locally:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/Export-ProductionSchema.ps1
```

Import `release/production-schema.sql` into the empty hosted database. Then,
from a private server shell, temporarily set a strong test password and the
school email domain:

```bash
export PMAS_BOOTSTRAP_PASSWORD='temporary-secret'
export PMAS_BOOTSTRAP_EMAIL_DOMAIN='school.example.edu'
php bin/Bootstrap-TestAccounts.php --create
unset PMAS_BOOTSTRAP_PASSWORD
```

The script creates role accounts `9900001` through `9900005`, forces a password
change, and prints their role mapping. Before launch, ensure the accounts have
not created production records and run `php bin/Bootstrap-TestAccounts.php
--remove`.

## 4. GitHub production environment

Create a protected GitHub Environment named `production`, require an authorized
reviewer, and add:

- `PMAS_SSH_PRIVATE_KEY`
- `PMAS_SSH_KNOWN_HOSTS` (verified host-key line supplied by school IT)
- `PMAS_SSH_HOST`
- `PMAS_SSH_PORT`
- `PMAS_SSH_USER`
- `PMAS_DEPLOY_ROOT`
- `PMAS_HEALTH_URL` (base temporary URL, without a trailing slash)

Pull requests run verification only. A reviewed push to `main` builds and
uploads a release, atomically changes `current`, and checks the health endpoint.
The workflow restores `previous` automatically when the health check fails.

## 5. Backups

Install `scripts/Backup-Production.sh` outside the web root and create a
root-readable `/etc/pmas/backup.env`:

```text
PMAS_DB_HOST=private-db-host
PMAS_DB_PORT=3306
PMAS_DB_NAME=pmas
PMAS_DB_USER=pmas_backup
PMAS_DB_PASS=secret
PMAS_UPLOADS_DIR=/srv/pmas/shared/uploads
PMAS_BACKUP_DIR=/srv/backups/pmas
PMAS_BACKUP_RETENTION_DAYS=30
PMAS_RCLONE_DESTINATION=encrypted-remote:pmas
```

Schedule it daily with cron. Test quarterly by restoring into a separate,
empty database and temporary uploads directory:

```bash
bash scripts/Restore-ProductionBackup.sh /path/to/pmas-TIMESTAMP.tar.gz \
  /etc/pmas/restore-test.env RESTORE
```

Never test restoration against the live database.

## 6. Acceptance and final domain

Complete the role, evaluation, reporting, upload, notification, SSE, email, and
AI acceptance checks in `PRODUCTION_DEPLOYMENT.md` while the development PC and
XAMPP are off. After approval, point the final school subdomain at the same
document root and update `PMAS_APP_URL` and `PMAS_REACT_URL`; keep the database
credentials and `PMAS_DATA_KEY` unchanged.
