#!/usr/bin/env bash
set -euo pipefail

config_file="${1:-/etc/pmas/backup.env}"
[[ -r "$config_file" ]] || { echo "Backup config is not readable: $config_file" >&2; exit 1; }
# shellcheck disable=SC1090
source "$config_file"

: "${PMAS_DB_HOST:?missing PMAS_DB_HOST}"
: "${PMAS_DB_NAME:?missing PMAS_DB_NAME}"
: "${PMAS_DB_USER:?missing PMAS_DB_USER}"
: "${PMAS_DB_PASS:?missing PMAS_DB_PASS}"
: "${PMAS_UPLOADS_DIR:?missing PMAS_UPLOADS_DIR}"
: "${PMAS_BACKUP_DIR:?missing PMAS_BACKUP_DIR}"

umask 077
stamp="$(date -u +%Y%m%dT%H%M%SZ)"
work_dir="$PMAS_BACKUP_DIR/.pmas-$stamp"
archive="$PMAS_BACKUP_DIR/pmas-$stamp.tar.gz"
mkdir -p "$work_dir"
trap 'rm -rf "$work_dir"' EXIT

MYSQL_PWD="$PMAS_DB_PASS" mysqldump \
  --host="$PMAS_DB_HOST" --port="${PMAS_DB_PORT:-3306}" \
  --user="$PMAS_DB_USER" --single-transaction --routines --triggers --events \
  --default-character-set=utf8mb4 "$PMAS_DB_NAME" > "$work_dir/database.sql"
tar -C "$PMAS_UPLOADS_DIR" -czf "$work_dir/uploads.tar.gz" .
printf '%s\n' "$stamp" > "$work_dir/created-at.txt"
tar -C "$work_dir" -czf "$archive" .
sha256sum "$archive" > "$archive.sha256"

find "$PMAS_BACKUP_DIR" -maxdepth 1 -type f -name 'pmas-*.tar.gz*' \
  -mtime "+${PMAS_BACKUP_RETENTION_DAYS:-30}" -delete

if [[ -n "${PMAS_RCLONE_DESTINATION:-}" ]]; then
  rclone copy "$archive" "$archive.sha256" "$PMAS_RCLONE_DESTINATION"
fi
echo "Backup created: $archive"
