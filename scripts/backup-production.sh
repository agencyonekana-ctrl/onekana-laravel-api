#!/usr/bin/env bash
set -euo pipefail

: "${DB_HOST:?DB_HOST is required}"
: "${DB_DATABASE:?DB_DATABASE is required}"
: "${DB_USERNAME:?DB_USERNAME is required}"
: "${DB_PASSWORD:?DB_PASSWORD is required}"
: "${BACKUP_DIR:?BACKUP_DIR is required}"
: "${BACKUP_ENCRYPTION_KEY:?BACKUP_ENCRYPTION_KEY is required}"

timestamp="$(date -u +%Y%m%dT%H%M%SZ)"
work_dir="$(mktemp -d)"
archive="${BACKUP_DIR%/}/onekana-${timestamp}.tar.gz.enc"
trap 'rm -rf "$work_dir"' EXIT

mkdir -p "$BACKUP_DIR"
MYSQL_PWD="$DB_PASSWORD" mysqldump --single-transaction --quick --routines \
  --host="$DB_HOST" --user="$DB_USERNAME" "$DB_DATABASE" > "$work_dir/database.sql"
cp -R "$(dirname "$0")/../storage/private" "$work_dir/private-files"
tar -C "$work_dir" -czf - database.sql private-files \
  | openssl enc -aes-256-cbc -salt -pbkdf2 -pass env:BACKUP_ENCRYPTION_KEY -out "$archive"
chmod 600 "$archive"
find "$BACKUP_DIR" -type f -name 'onekana-*.tar.gz.enc' -mtime +30 -delete

printf 'Encrypted backup created: %s\n' "$archive"
