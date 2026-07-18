#!/usr/bin/env bash

set -euo pipefail

if [[ "${ERIN_RESTORE_DRILL_CONFIRM:-}" != "RESTORE_IN_TEMP_DATABASE" ]]; then
    echo "Setze ERIN_RESTORE_DRILL_CONFIRM=RESTORE_IN_TEMP_DATABASE für einen isolierten Restore-Drill." >&2
    exit 2
fi

if [[ $# -ne 1 ]]; then
    echo "Aufruf: $0 backups/mysql/erin-YYYYMMDDTHHMMSSZ.sql" >&2
    exit 2
fi

project_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$project_dir"

compose_file="${COMPOSE_FILE:-compose.production.yaml}"
backup_file="$1"
backup_dir="$(cd "$(dirname "$backup_file")" && pwd)"
backup_name="$(basename "$backup_file")"
backup_file="${backup_dir}/${backup_name}"
checksum_file="${backup_dir}/${backup_name}.sha256"
drill_database="erin_restore_drill_$(date -u +%Y%m%d%H%M%S)"

if [[ ! -r "$backup_file" || ! -r "$checksum_file" ]]; then
    echo "Backup oder zugehörige SHA-256-Prüfsumme fehlt." >&2
    exit 1
fi

(
    cd "$backup_dir"
    sha256sum --check "${backup_name}.sha256"
)

if [[ ! "$drill_database" =~ ^erin_restore_drill_[0-9]{14}$ ]]; then
    echo "Unsicherer Name für die temporäre Restore-Datenbank." >&2
    exit 1
fi

drop_drill_database() {
    docker compose -f "$compose_file" exec -T -e DRILL_DATABASE="$drill_database" mysql sh -ec '
        export MYSQL_PWD="$MYSQL_ROOT_PASSWORD"
        mysql --host=127.0.0.1 --user=root \
            --execute="DROP DATABASE IF EXISTS \`$DRILL_DATABASE\`"
    ' >/dev/null
}
trap drop_drill_database EXIT

docker compose -f "$compose_file" exec -T -e DRILL_DATABASE="$drill_database" mysql sh -ec '
    export MYSQL_PWD="$MYSQL_ROOT_PASSWORD"
    mysql --host=127.0.0.1 --user=root \
        --execute="CREATE DATABASE \`$DRILL_DATABASE\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
'

docker compose -f "$compose_file" exec -T -e DRILL_DATABASE="$drill_database" mysql sh -ec '
    export MYSQL_PWD="$MYSQL_ROOT_PASSWORD"
    exec mysql --host=127.0.0.1 --user=root "$DRILL_DATABASE"
' < "$backup_file"

migration_count="$(
    docker compose -f "$compose_file" exec -T -e DRILL_DATABASE="$drill_database" mysql sh -ec '
        export MYSQL_PWD="$MYSQL_ROOT_PASSWORD"
        mysql --host=127.0.0.1 --user=root --batch --skip-column-names \
            "$DRILL_DATABASE" \
            --execute="SELECT COUNT(*) FROM migrations"
    '
)"

if [[ ! "$migration_count" =~ ^[1-9][0-9]*$ ]]; then
    echo "Restore-Drill fehlgeschlagen: Migrationstabelle fehlt oder ist leer." >&2
    exit 1
fi

echo "Restore-Drill erfolgreich: ${migration_count} Migrationen in isolierter Datenbank geprüft."
