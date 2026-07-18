#!/usr/bin/env bash

set -euo pipefail

project_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$project_dir"

compose_file="${COMPOSE_FILE:-compose.production.yaml}"
output_dir="${1:-backups/mysql}"
timestamp="$(date -u +%Y%m%dT%H%M%SZ)"
backup_name="erin-${timestamp}.sql"
backup_file="${output_dir}/${backup_name}"
partial_file="${backup_file}.part"

umask 077
mkdir -p "$output_dir"
trap 'rm -f "$partial_file"' EXIT

docker compose -f "$compose_file" exec -T mysql sh -ec '
    export MYSQL_PWD="$MYSQL_ROOT_PASSWORD"
    exec mysqldump \
        --host=127.0.0.1 \
        --user=root \
        --single-transaction \
        --routines \
        --events \
        --triggers \
        --hex-blob \
        --set-gtid-purged=OFF \
        "$MYSQL_DATABASE"
' > "$partial_file"

if [[ ! -s "$partial_file" ]]; then
    echo "Der Datenbank-Dump ist leer; Backup wird verworfen." >&2
    exit 1
fi

mv "$partial_file" "$backup_file"
(
    cd "$output_dir"
    sha256sum "$backup_name" > "${backup_name}.sha256"
)
trap - EXIT

echo "Datenbank-Backup erstellt: ${backup_file}"
echo "Prüfsumme erstellt: ${backup_file}.sha256"
