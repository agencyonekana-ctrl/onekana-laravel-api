#!/usr/bin/env bash
set -euo pipefail

: "${RESTORE_ARCHIVE:?RESTORE_ARCHIVE is required}"
: "${BACKUP_ENCRYPTION_KEY:?BACKUP_ENCRYPTION_KEY is required}"
: "${DB_HOST:?DB_HOST is required}"
: "${DB_DATABASE:?DB_DATABASE is required}"
: "${DB_USERNAME:?DB_USERNAME is required}"
: "${DB_PASSWORD:?DB_PASSWORD is required}"
: "${RESTORE_CONFIRM:?Set RESTORE_CONFIRM=YES after verifying the target environment}"

if [[ "$RESTORE_CONFIRM" != "YES" ]]; then
  printf 'Restore cancelled: RESTORE_CONFIRM must be YES.\n' >&2
  exit 2
fi
if [[ ! -f "$RESTORE_ARCHIVE" ]]; then
  printf 'Restore archive not found.\n' >&2
  exit 2
fi

base_dir="$(cd "$(dirname "$0")/.." && pwd)"
work_dir="$(mktemp -d)"
trap 'rm -rf "$work_dir"' EXIT

openssl enc -d -aes-256-cbc -pbkdf2 -pass env:BACKUP_ENCRYPTION_KEY -in "$RESTORE_ARCHIVE" \
  | tar -C "$work_dir" -xzf -
test -s "$work_dir/database.sql"

MYSQL_PWD="$DB_PASSWORD" mysql --host="$DB_HOST" --user="$DB_USERNAME" "$DB_DATABASE" < "$work_dir/database.sql"
if [[ -d "$work_dir/private-files" ]]; then
  mkdir -p "$base_dir/storage/private"
  cp -a "$work_dir/private-files/." "$base_dir/storage/private/"
fi

printf 'Restore completed. Run migrations and readiness checks before reopening traffic.\n'
