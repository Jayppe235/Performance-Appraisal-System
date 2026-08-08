#!/usr/bin/env bash
set -euo pipefail

archive="${1:?backup archive required}"
config_file="${2:-/etc/pmas/backup.env}"
confirmation="${3:-}"
[[ "$confirmation" == "RESTORE" ]] || {
  echo "Refusing restore without the literal confirmation argument RESTORE" >&2
  exit 2
}
[[ -f "$archive" && -r "$config_file" ]] || { echo "Archive or config is unavailable" >&2; exit 1; }
# shellcheck disable=SC1090
source "$config_file"

: "${PMAS_DB_HOST:?missing PMAS_DB_HOST}"
: "${PMAS_DB_NAME:?missing PMAS_DB_NAME}"
: "${PMAS_DB_USER:?missing PMAS_DB_USER}"
: "${PMAS_DB_PASS:?missing PMAS_DB_PASS}"
: "${PMAS_UPLOADS_DIR:?missing PMAS_UPLOADS_DIR}"

umask 077
work_dir="$(mktemp -d)"
trap 'rm -rf "$work_dir"' EXIT
tar -xzf "$archive" -C "$work_dir"
[[ -f "$work_dir/database.sql" && -f "$work_dir/uploads.tar.gz" ]] || {
  echo "Invalid PMAS backup" >&2
  exit 1
}

MYSQL_PWD="$PMAS_DB_PASS" mysql \
  --host="$PMAS_DB_HOST" --port="${PMAS_DB_PORT:-3306}" \
  --user="$PMAS_DB_USER" "$PMAS_DB_NAME" < "$work_dir/database.sql"
mkdir -p "$PMAS_UPLOADS_DIR"
tar -xzf "$work_dir/uploads.tar.gz" -C "$PMAS_UPLOADS_DIR"
echo "Restore completed from $archive"
