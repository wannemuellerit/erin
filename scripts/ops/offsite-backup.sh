#!/usr/bin/env bash

set -euo pipefail

if [[ "${ERIN_BACKUP_CONFIRM:-}" != "BACKUP_TO_SEPARATE_ENCRYPTED_REPOSITORY" ]]; then
    echo "Setze ERIN_BACKUP_CONFIRM=BACKUP_TO_SEPARATE_ENCRYPTED_REPOSITORY." >&2
    exit 2
fi

for variable in RESTIC_REPOSITORY RESTIC_PASSWORD ERIN_DEPLOY_ENV_FILE; do
    if [[ -z "${!variable:-}" ]]; then
        echo "${variable} fehlt." >&2
        exit 2
    fi
done
for command in docker restic jq sha256sum; do
    command -v "$command" >/dev/null || {
        echo "Erforderliches Werkzeug fehlt: ${command}" >&2
        exit 1
    }
done

project_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$project_dir"
compose_file="compose.production.yaml"
env_file="$ERIN_DEPLOY_ENV_FILE"
timestamp="$(date -u +%Y%m%dT%H%M%SZ)"
work_dir="$(mktemp -d "${TMPDIR:-/tmp}/erin-backup.XXXXXX")"
umask 077

cleanup() {
    find "$work_dir" -depth -mindepth 1 -delete 2>/dev/null || true
    rmdir "$work_dir" 2>/dev/null || true
}
trap cleanup EXIT

mkdir -m 0700 "$work_dir/mysql" "$work_dir/objects"
mysql_id="$(docker compose --env-file "$env_file" -f "$compose_file" ps -q mysql)"
minio_id="$(docker compose --env-file "$env_file" -f "$compose_file" ps -q minio)"
[[ -n "$mysql_id" && -n "$minio_id" ]] || {
    echo "MySQL oder MinIO läuft nicht." >&2
    exit 1
}

docker compose --env-file "$env_file" -f "$compose_file" exec -T mysql sh -ec '
    export MYSQL_PWD="$MYSQL_ROOT_PASSWORD"
    exec mysqldump --host=127.0.0.1 --user=root --single-transaction \
        --routines --events --triggers --hex-blob --set-gtid-purged=OFF \
        "$MYSQL_DATABASE"
' > "$work_dir/mysql/erin.sql"
[[ -s "$work_dir/mysql/erin.sql" ]] || {
    echo "MySQL-Dump ist leer." >&2
    exit 1
}

minio_user="$(docker inspect --format '{{range .Config.Env}}{{println .}}{{end}}' "$minio_id" | sed -n 's/^MINIO_ROOT_USER=//p' | head -n1)"
minio_password="$(docker inspect --format '{{range .Config.Env}}{{println .}}{{end}}' "$minio_id" | sed -n 's/^MINIO_ROOT_PASSWORD=//p' | head -n1)"
bucket="$(docker inspect --format '{{range .Config.Env}}{{println .}}{{end}}' "$minio_id" | sed -n 's/^MINIO_BUCKET=//p' | head -n1)"
bucket="${bucket:-erin-private}"
docker run --rm \
    --network "container:${minio_id}" \
    --user "$(id -u):$(id -g)" \
    -e HOME=/tmp \
    -e MC_CONFIG_DIR=/tmp/.mc \
    -e "MINIO_ROOT_USER=${minio_user}" \
    -e "MINIO_ROOT_PASSWORD=${minio_password}" \
    -e "AWS_BUCKET=${bucket}" \
    -v "$work_dir/objects:/backup" \
    minio/mc@sha256:a7fe349ef4bd8521fb8497f55c6042871b2ae640607cf99d9bede5e9bdf11727 \
    sh -ec '
        mc alias set source http://127.0.0.1:9000 "$MINIO_ROOT_USER" "$MINIO_ROOT_PASSWORD" >/dev/null
        mc mirror --preserve --overwrite "source/$AWS_BUCKET" /backup
    '
unset minio_user minio_password

(
    cd "$work_dir"
    find mysql objects -type f -print0 | sort -z | xargs -0 sha256sum > manifest.sha256
)
jq -n \
    --arg schema "erin-offsite-backup-v1" \
    --arg created_at "$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
    --arg release "$(docker inspect --format '{{index .Config.Labels "org.opencontainers.image.revision"}}' "$(docker compose --env-file "$env_file" -f "$compose_file" ps -q php-fpm)")" \
    '{schema:$schema,created_at:$created_at,release_sha:$release,scope:["mysql","private_object_storage"]}' \
    > "$work_dir/backup.json"

if ! restic snapshots --json >/dev/null 2>&1; then
    restic init
fi
restic backup "$work_dir" \
    --host erin-production \
    --tag erin \
    --tag mysql-minio \
    --tag "$timestamp"
restic check --read-data-subset=1/50
restic forget --host erin-production --tag erin \
    --keep-within 48h --keep-daily 35 --keep-weekly 12 --keep-monthly 13 \
    --prune

echo "Verschlüsseltes Offsite-Backup ${timestamp} wurde erstellt und geprüft."
