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
[[ -n "$mysql_id" ]] || {
    echo "MySQL läuft nicht." >&2
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

compose_json="$(docker compose --env-file "$env_file" -f "$compose_file" config --format json)"
storage_access_key="$(jq -r '.services["php-fpm"].environment.AWS_ACCESS_KEY_ID // empty' <<<"$compose_json")"
storage_secret_key="$(jq -r '.services["php-fpm"].environment.AWS_SECRET_ACCESS_KEY // empty' <<<"$compose_json")"
storage_region="$(jq -r '.services["php-fpm"].environment.AWS_DEFAULT_REGION // empty' <<<"$compose_json")"
storage_bucket="$(jq -r '.services["php-fpm"].environment.AWS_BUCKET // empty' <<<"$compose_json")"
storage_endpoint="$(jq -r '.services["php-fpm"].environment.AWS_ENDPOINT // empty' <<<"$compose_json")"
unset compose_json
for variable in storage_access_key storage_secret_key storage_region storage_bucket storage_endpoint; do
    if [[ -z "${!variable}" ]]; then
        echo "Produktive S3-Konfiguration ist unvollständig: ${variable}." >&2
        exit 2
    fi
done
docker run --rm \
    --user "$(id -u):$(id -g)" \
    --entrypoint /bin/sh \
    -e HOME=/tmp \
    -e "AWS_ACCESS_KEY_ID=${storage_access_key}" \
    -e "AWS_SECRET_ACCESS_KEY=${storage_secret_key}" \
    -e "AWS_DEFAULT_REGION=${storage_region}" \
    -e "AWS_BUCKET=${storage_bucket}" \
    -e "AWS_ENDPOINT=${storage_endpoint}" \
    -v "$work_dir/objects:/backup" \
    amazon/aws-cli:2.36.10@sha256:1ce4fd2ea9b640019af76a94e91adeed901d20363b4bb2ed095b45c75e5565cc \
    -ec '
        aws --endpoint-url "$AWS_ENDPOINT" s3 sync \
            "s3://$AWS_BUCKET" /backup --no-progress --only-show-errors
    '
unset storage_access_key storage_secret_key storage_region storage_bucket storage_endpoint

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
    --tag mysql-object-storage \
    --tag "$timestamp"
restic check --read-data-subset=1/50
restic forget --host erin-production --tag erin \
    --keep-within 48h --keep-daily 35 --keep-weekly 12 --keep-monthly 13 \
    --prune

echo "Verschlüsseltes Offsite-Backup ${timestamp} wurde erstellt und geprüft."
