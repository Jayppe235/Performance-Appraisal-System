#!/usr/bin/env bash
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
skip_tests=false
[[ "${1:-}" == "--skip-tests" ]] && skip_tests=true
cd "$root"

if [[ "$skip_tests" == false ]]; then
  npm ci
  composer install --no-interaction --prefer-dist
  npm test
  vendor/bin/phpunit --configuration phpunit.xml.dist
  npm run build
fi

[[ -f dist/index.html ]] || { echo "dist/index.html is missing" >&2; exit 1; }
rm -rf release/site
mkdir -p release/site/assets/uploads release/site/bin
cp -a dist/. release/site/
for directory in api assets components dashboards includes reports; do
  [[ -d "$directory" ]] && cp -a "$directory" release/site/
done
cp .htaccess index.php login.php logout.php release/site/
cp scripts/Check-Database.php scripts/Bootstrap-TestAccounts.php release/site/bin/

COMPOSER_VENDOR_DIR="$root/release/site/vendor" composer install \
  --no-dev --optimize-autoloader --no-interaction --prefer-dist
[[ -f release/site/vendor/autoload.php ]] || { echo "Production Composer install failed" >&2; exit 1; }

find release/site -type f \( -name '*.bak' -o -name '*.log' -o -name '*.sql' \
  -o -name '*.dump' -o -name '*.ps1' \) -delete
rm -rf release/site/assets/uploads
mkdir -p release/site/assets/uploads

for forbidden in src tests node_modules database setup.php .env .env.local composer.json package.json; do
  [[ ! -e "release/site/$forbidden" ]] || { echo "Forbidden deployment item: $forbidden" >&2; exit 1; }
done

tar -C release/site -czf release/pmas-production.tar.gz .
echo "Created release/pmas-production.tar.gz"
