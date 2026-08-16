# Vercel MySQL setup

Vercel deploys the PHP application but does not upload the MySQL database that
runs in XAMPP. PMAS must connect to a public, hosted **MySQL or MariaDB** service.
Do not attach Neon Postgres to this project without first rewriting its MySQL
queries and migrations for PostgreSQL.

## 1. Export the local database

From PowerShell on the XAMPP computer (adjust the MySQL executable path if
needed):

```powershell
C:\xampp\mysql\bin\mysqldump.exe -u root --single-transaction --routines --triggers pmas_db_clean > pmas-production.sql
```

Treat this dump as confidential because it contains account and evaluation
data. Do not commit it to GitHub.

## 2. Create and import a hosted MySQL database

Create a MySQL 8-compatible database with a provider that permits connections
from Vercel serverless functions. Import `pmas-production.sql` using that
provider's import console or MySQL client, then also run:

```sql
SOURCE database/migration_041_database_sessions.sql;
```

If the full dump already contains `user_sessions`, the migration is harmless.

## 3. Configure Vercel

In **Vercel project > Settings > Environment Variables**, add these for
Production (and Preview if previews need database access):

```text
PMAS_ENV=production
PMAS_APP_URL=https://your-project.vercel.app
PMAS_BASE_PATH=/
PMAS_DATABASE_URL=mysql://USER:PASSWORD@HOST:3306/DATABASE
PMAS_DATA_KEY=the-same-stable-secret-with-at-least-32-characters
```

The URL must begin with `mysql://` or `mariadb://`. Percent-encode `@`, `:`,
`/`, `#`, and other special characters inside the username or password. Instead
of `PMAS_DATABASE_URL`, the five `PMAS_DB_HOST`, `PMAS_DB_PORT`,
`PMAS_DB_NAME`, `PMAS_DB_USER`, and `PMAS_DB_PASS` variables may be used.

Never set the database host to `localhost` on Vercel; that refers to the
temporary serverless container, not the XAMPP computer.

## 4. Redeploy and verify

Environment variable changes apply only to a new deployment. Redeploy, then
open:

```text
https://your-project.vercel.app/api/health.php
```

It must return `{"ok":true,"message":"Application is available."}` before
testing login. If it returns HTTP 503, re-check the hosted database firewall,
credentials, database name, and whether the provider requires TLS.

The production session handler stores sessions in `user_sessions`, which keeps
login state valid when requests are handled by different Vercel instances.
