#!/usr/bin/env bash

set -euo pipefail

if [[ "${ERIN_RESTORE_DRILL_CONFIRM:-}" != "LOCAL_ISOLATED_DOCKER_ONLY" ]]; then
    echo "Setze ERIN_RESTORE_DRILL_CONFIRM=LOCAL_ISOLATED_DOCKER_ONLY." >&2
    exit 2
fi

project_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$project_dir"

compose_file="${COMPOSE_FILE:-compose.yaml}"
if [[ "$compose_file" != "compose.yaml" && "$compose_file" != "./compose.yaml" ]]; then
    echo "Der lokale Drill akzeptiert ausschließlich compose.yaml als Quelle." >&2
    exit 2
fi

environment_output="$(docker compose -f "$compose_file" exec -T laravel php artisan env)"
if [[ "$environment_output" != *"[local]"* && "$environment_output" != *"[testing]"* ]]; then
    echo "Der lokale Drill verweigert nicht-lokale Quellumgebungen." >&2
    exit 2
fi

for command in docker openssl jq sha256sum tar find stat; do
    if ! command -v "$command" >/dev/null 2>&1; then
        echo "Erforderliches Werkzeug fehlt: $command" >&2
        exit 1
    fi
done

timestamp="$(date -u +%Y%m%dT%H%M%SZ)"
random_suffix="$(openssl rand -hex 4)"
drill_id="local-restore-${timestamp}-${random_suffix}"
runtime_name="${drill_id,,}"
evidence_root="${ERIN_RESTORE_EVIDENCE_DIR:-$project_dir/storage/app/operations/evidence/restore}"
mkdir -p "$evidence_root"
evidence_root="$(cd "$evidence_root" && pwd)"

allowed_root="$project_dir/storage/app/operations/evidence/restore"
mkdir -p "$allowed_root"
allowed_root="$(cd "$allowed_root" && pwd)"
if [[ "$evidence_root" != "$allowed_root" && "$evidence_root" != "$allowed_root/"* ]]; then
    echo "Der Evidenzpfad muss unter storage/app/operations/evidence/restore liegen." >&2
    exit 2
fi

final_output_dir="$evidence_root/$drill_id"
output_dir="$evidence_root/.${drill_id}.part"
if ! mkdir -m 0750 "$output_dir"; then
    echo "Evidenzverzeichnis existiert bereits oder kann nicht erstellt werden." >&2
    exit 1
fi

container_user="$(id -u):$(id -g)"

mysql_restore="${runtime_name}-mysql"
minio_restore="${runtime_name}-minio"
network_restore="${runtime_name}-network"
scratch_container="${runtime_name}-scratch"
scratch_volume="${runtime_name}-scratch-tmpfs"
work_dir="/scratch"
key_file="$work_dir/drill.key"
mac_key_file="$work_dir/drill.mac.key"
master_key_file="$work_dir/drill.master.key"
database_artifact="$output_dir/database.sql.enc"
objects_artifact="$output_dir/object-storage.tar.enc"
database_hmac_file="$database_artifact.hmac"
objects_hmac_file="$objects_artifact.hmac"
evidence_file="$output_dir/evidence.json"
iterations=600000
operation_started_at="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
started_at=""
started_epoch=0
runtime_cleaned=false
evidence_finalized=false
source_canary_inserted=false
source_object_canary_inserted=false
database_canary_row_id=""
database_canary_id="erin-restore-canary-${runtime_name}"
database_canary_object_path="erin-restore-canary/${runtime_name}.txt"
scratch_tmpfs_verified=false
scratch_tmpfs_removed=false
source_quiesced=false
source_quiesce_released=false
source_maintenance_enabled_by_drill=false
source_was_already_in_maintenance=false
source_laravel_paused=false
source_background_services_stopped=false
source_maintenance_state_restored=false
source_background_services_restored=false
retain_restored_dump_for_diagnostics=true

cleanup_runtime() {
    docker rm -f "$mysql_restore" "$minio_restore" >/dev/null 2>&1 || true
    docker network rm "$network_restore" >/dev/null 2>&1 || true
    docker rm -f "$scratch_container" >/dev/null 2>&1 || true
    docker volume rm "$scratch_volume" >/dev/null 2>&1 || true
}

source_mysql_scalar() {
    local query="$1"

    docker compose -f "$compose_file" exec -T mysql sh -ec '
        export MYSQL_PWD="$MYSQL_ROOT_PASSWORD"
        exec mysql \
            --host=127.0.0.1 \
            --user=root \
            --batch \
            --skip-column-names \
            "$MYSQL_DATABASE" \
            --execute="$1"
    ' sh "$query"
}

cleanup_source_canary() {
    if [[ "$source_canary_inserted" == "true" && "$database_canary_row_id" =~ ^[1-9][0-9]*$ ]]; then
        source_mysql_scalar \
            "DELETE FROM audit_logs WHERE id = ${database_canary_row_id} AND event = '${database_canary_id}';"
        source_canary_inserted=false
    fi
}

cleanup_source_object_canary() {
    if [[ "$source_object_canary_inserted" != "true" ]]; then
        return
    fi

    docker run --rm \
        --network "container:$source_minio_id" \
        --user "$container_user" \
        --entrypoint /bin/sh \
        -e HOME=/tmp \
        -e MC_CONFIG_DIR=/tmp/.mc \
        -e "MINIO_ROOT_USER=$minio_user" \
        -e "MINIO_ROOT_PASSWORD=$minio_password" \
        -e "AWS_BUCKET=$bucket" \
        -e "CANARY_PATH=$database_canary_object_path" \
        minio/mc:RELEASE.2025-08-13T08-35-41Z \
        -ec '
            mc alias set source http://127.0.0.1:9000 \
                "$MINIO_ROOT_USER" "$MINIO_ROOT_PASSWORD" >/dev/null
            if mc stat "source/$AWS_BUCKET/$CANARY_PATH" >/dev/null 2>&1; then
                mc rm --force "source/$AWS_BUCKET/$CANARY_PATH" >/dev/null
            fi
            ! mc stat "source/$AWS_BUCKET/$CANARY_PATH" >/dev/null 2>&1
        '
    source_object_canary_inserted=false
}

release_source_quiesce() {
    local release_failed=false

    if [[ "$source_laravel_paused" == "true" ]]; then
        if docker unpause "$source_laravel_id" >/dev/null; then
            source_laravel_paused=false
        else
            release_failed=true
        fi
    fi

    if [[ "$source_maintenance_enabled_by_drill" == "true" && "$source_laravel_paused" == "false" ]]; then
        if docker compose -f "$compose_file" exec -T laravel php artisan up >/dev/null \
            && ! docker compose -f "$compose_file" exec -T laravel \
                sh -ec 'test -f storage/framework/down'; then
            source_maintenance_enabled_by_drill=false
            source_maintenance_state_restored=true
        else
            release_failed=true
        fi
    elif [[ "$source_was_already_in_maintenance" == "true" ]]; then
        if docker compose -f "$compose_file" exec -T laravel \
            sh -ec 'test -f storage/framework/down'; then
            source_maintenance_state_restored=true
        else
            release_failed=true
        fi
    elif [[ "$source_laravel_paused" == "false" ]]; then
        source_maintenance_state_restored=true
    fi

    if [[ "$source_background_services_stopped" == "true" ]]; then
        if docker compose -f "$compose_file" start queue scheduler >/dev/null; then
            source_background_services_stopped=false
        else
            release_failed=true
        fi
    fi

    if [[ "$source_background_services_stopped" == "false" ]]; then
        if [[ "$(docker inspect --format '{{.State.Running}}' "$source_queue_id" 2>/dev/null)" == "true" \
            && "$(docker inspect --format '{{.State.Running}}' "$source_scheduler_id" 2>/dev/null)" == "true" ]]; then
            source_background_services_restored=true
        else
            release_failed=true
        fi
    fi

    if [[ "$release_failed" == "true" ]]; then
        return 1
    fi

    source_quiesce_released=true
}

purge_incomplete_evidence() {
    if [[ "$evidence_finalized" != "true" && -d "$output_dir" ]]; then
        find "$output_dir" -depth -mindepth 1 -delete
        rmdir "$output_dir"
    fi
}

cleanup_all() {
    local original_status=$?
    local cleanup_status=0

    trap - EXIT
    set +e
    cleanup_source_object_canary || cleanup_status=1
    cleanup_source_canary || cleanup_status=1
    release_source_quiesce || cleanup_status=1
    cleanup_runtime
    purge_incomplete_evidence || cleanup_status=1

    if (( original_status != 0 )); then
        exit "$original_status"
    fi

    exit "$cleanup_status"
}
trap cleanup_all EXIT

source_mysql_id="$(docker compose -f "$compose_file" ps -q mysql)"
source_minio_id="$(docker compose -f "$compose_file" ps -q minio)"
source_laravel_id="$(docker compose -f "$compose_file" ps -q laravel)"
source_queue_id="$(docker compose -f "$compose_file" ps -q queue)"
source_scheduler_id="$(docker compose -f "$compose_file" ps -q scheduler)"
if [[ -z "$source_mysql_id" || -z "$source_minio_id" || -z "$source_laravel_id" \
    || -z "$source_queue_id" || -z "$source_scheduler_id" ]]; then
    echo "MySQL, MinIO, Laravel, Queue und Scheduler müssen für den lokalen Drill laufen." >&2
    exit 1
fi

scratch_image="$(docker inspect --format '{{.Config.Image}}' "$source_laravel_id")"
docker volume create \
    --driver local \
    --opt type=tmpfs \
    --opt device=tmpfs \
    --opt o=size=2147483648,nosuid,nodev,noexec \
    "$scratch_volume" >/dev/null
docker run -d \
    --name "$scratch_container" \
    --user root \
    --mount "type=volume,source=$scratch_volume,target=/scratch" \
    --mount "type=bind,source=$output_dir,target=/evidence" \
    --entrypoint sh \
    "$scratch_image" \
    -ec 'chmod 0700 /scratch; exec sleep 86400' >/dev/null
docker exec "$scratch_container" chown "$container_user" /scratch

scratch_options="$(docker volume inspect --format '{{json .Options}}' "$scratch_volume")"
scratch_mount_type="$(
    docker inspect --format \
        '{{range .Mounts}}{{if eq .Destination "/scratch"}}{{.Type}}{{end}}{{end}}' \
        "$scratch_container"
)"
if [[ "$scratch_options" != *'"type":"tmpfs"'* \
    || "$scratch_options" != *'"device":"tmpfs"'* \
    || "$scratch_options" != *"nosuid"* \
    || "$scratch_options" != *"nodev"* \
    || "$scratch_options" != *"noexec"* \
    || "$scratch_mount_type" != "volume" ]]; then
    echo "Der sensible Arbeitsbereich ist kein gehärtetes Docker-tmpfs." >&2
    exit 1
fi
scratch_tmpfs_verified=true

docker exec "$scratch_container" sh -ec '
    umask 077
    openssl rand -hex 32 > /scratch/drill.master.key
    master_key_hex="$(tr -d "\n" < /scratch/drill.master.key)"
    printf "erin-local-restore-encryption-v1" \
        | openssl dgst -sha256 -mac HMAC -macopt "hexkey:$master_key_hex" \
        | awk "{print \$NF}" > /scratch/drill.key
    printf "erin-local-restore-integrity-v1" \
        | openssl dgst -sha256 -mac HMAC -macopt "hexkey:$master_key_hex" \
        | awk "{print \$NF}" > /scratch/drill.mac.key
    unset master_key_hex
    rm -f /scratch/drill.master.key
    chmod 0600 /scratch/drill.key /scratch/drill.mac.key
'

artifact_hmac() {
    local artifact="$1"
    local mac_key_hex

    docker exec "$scratch_container" sh -ec '
        mac_key_hex="$(tr -d "\n" < /scratch/drill.mac.key)"
        openssl dgst -sha256 -mac HMAC -macopt "hexkey:$mac_key_hex" "$1" \
            | awk "{print \$NF}"
    ' sh "/evidence/$(basename "$artifact")"
}

write_hmac_sidecar() {
    local artifact="$1"
    local sidecar="$2"
    local mac

    mac="$(artifact_hmac "$artifact")"
    if [[ ! "$mac" =~ ^[a-f0-9]{64}$ ]]; then
        echo "HMAC konnte nicht erzeugt werden." >&2
        return 1
    fi
    if [[ -e "$sidecar.part" || -L "$sidecar.part" || -e "$sidecar" || -L "$sidecar" ]]; then
        echo "HMAC-Sidecar existiert bereits oder ist unsicher." >&2
        return 1
    fi
    (
        umask 027
        set -o noclobber
        printf '%s\n' "$mac" > "$sidecar.part"
    )
    if [[ ! -f "$sidecar.part" || -L "$sidecar.part" ]] \
        || ! mv -nT "$sidecar.part" "$sidecar" \
        || [[ -e "$sidecar.part" || ! -f "$sidecar" || -L "$sidecar" ]]; then
        echo "HMAC-Sidecar konnte nicht exklusiv veröffentlicht werden." >&2
        return 1
    fi
    chmod 0640 "$sidecar"
}

verify_artifact_hmac() {
    local artifact="$1"
    local sidecar="$2"
    local expected
    local actual

    if [[ ! -f "$artifact" || ! -f "$sidecar" || -L "$sidecar" ]]; then
        return 1
    fi
    expected="$(tr -d '\n' < "$sidecar")"
    if [[ ! "$expected" =~ ^[a-f0-9]{64}$ ]]; then
        return 1
    fi
    actual="$(artifact_hmac "$artifact")"

    [[ "$actual" == "$expected" ]]
}

source_mysql_started="$(docker inspect --format '{{.State.StartedAt}}' "$source_mysql_id")"
source_minio_started="$(docker inspect --format '{{.State.StartedAt}}' "$source_minio_id")"

container_env() {
    local container_id="$1"
    local variable="$2"

    docker inspect --format '{{range .Config.Env}}{{println .}}{{end}}' "$container_id" \
        | sed -n "s/^${variable}=//p" \
        | head -n 1
}

minio_user="$(container_env "$source_minio_id" MINIO_ROOT_USER)"
minio_password="$(container_env "$source_minio_id" MINIO_ROOT_PASSWORD)"
bucket="$(container_env "$source_laravel_id" AWS_BUCKET)"
if [[ -z "$minio_user" || -z "$minio_password" || -z "$bucket" ]]; then
    echo "Lokale MinIO-Quellkonfiguration ist unvollständig." >&2
    exit 1
fi

echo "[$drill_id] Versetze die lokale Quelle kontrolliert in einen schreibgeschützten Snapshot-Zustand."
if docker compose -f "$compose_file" exec -T laravel \
    sh -ec 'test -f storage/framework/down'; then
    source_was_already_in_maintenance=true
else
    source_maintenance_enabled_by_drill=true
    docker compose -f "$compose_file" exec -T laravel \
        php artisan down --retry=60 >/dev/null
fi

source_background_services_stopped=true
docker compose -f "$compose_file" stop --timeout 60 queue scheduler >/dev/null
if [[ "$(docker inspect --format '{{.State.Running}}' "$source_queue_id")" != "false" \
    || "$(docker inspect --format '{{.State.Running}}' "$source_scheduler_id")" != "false" ]]; then
    echo "Queue und Scheduler konnten für den konsistenten Snapshot nicht angehalten werden." >&2
    exit 1
fi

docker pause "$source_laravel_id" >/dev/null
source_laravel_paused=true
if [[ "$(docker inspect --format '{{.State.Paused}}' "$source_laravel_id")" != "true" ]]; then
    echo "Der Laravel-Schreibpfad konnte für den konsistenten Snapshot nicht pausiert werden." >&2
    exit 1
fi
source_quiesced=true

docker exec "$scratch_container" sh -ec '
    umask 077
    printf "Erin referenzierter Restore-Canary: %s\n" "$1" \
        > /scratch/database-object-canary.txt
' sh "$drill_id"
docker exec "$scratch_container" chown "$container_user" /scratch/database-object-canary.txt
source_object_canary_inserted=true
docker run --rm \
    --network "container:$source_minio_id" \
    --user "$container_user" \
    --entrypoint /bin/sh \
    -e HOME=/tmp \
    -e MC_CONFIG_DIR=/tmp/.mc \
    -e "MINIO_ROOT_USER=$minio_user" \
    -e "MINIO_ROOT_PASSWORD=$minio_password" \
    -e "AWS_BUCKET=$bucket" \
    -e "CANARY_PATH=$database_canary_object_path" \
    -v "$scratch_volume:/scratch" \
    minio/mc:RELEASE.2025-08-13T08-35-41Z \
    -ec '
        mc alias set source http://127.0.0.1:9000 \
            "$MINIO_ROOT_USER" "$MINIO_ROOT_PASSWORD" >/dev/null
        mc cp /scratch/database-object-canary.txt \
            "source/$AWS_BUCKET/$CANARY_PATH" >/dev/null
        mc stat "source/$AWS_BUCKET/$CANARY_PATH" >/dev/null
    '
docker exec "$scratch_container" rm -f /scratch/database-object-canary.txt

database_canary_row_id="$(
    source_mysql_scalar "
        INSERT INTO audit_logs (event, metadata, created_at)
        VALUES (
            '${database_canary_id}',
            JSON_OBJECT(
                'drill_id',
                '${drill_id}',
                'classification',
                'LOCAL_SYNTHETIC_NOT_PRODUCTION_EVIDENCE',
                'restore_object_path',
                '${database_canary_object_path}'
            ),
            UTC_TIMESTAMP()
        );
        SELECT LAST_INSERT_ID();
    "
)"
if [[ ! "$database_canary_row_id" =~ ^[1-9][0-9]*$ ]]; then
    echo "Fachlicher Datenbank-Canary konnte nicht angelegt werden." >&2
    exit 1
fi
source_canary_inserted=true
database_last_restored_record_at="$(
    source_mysql_scalar "
        SELECT DATE_FORMAT(created_at, '%Y-%m-%dT%H:%i:%sZ')
        FROM audit_logs
        WHERE id = ${database_canary_row_id}
          AND event = '${database_canary_id}';
    "
)"
business_manifest_query="
    SELECT CONCAT_WS(
        '|',
        (SELECT COUNT(*) FROM users),
        (SELECT COALESCE(SUM(id), 0) FROM users),
        (SELECT COUNT(*) FROM companies),
        (SELECT COALESCE(SUM(id), 0) FROM companies),
        (SELECT COUNT(*) FROM candidate_profiles),
        (SELECT COALESCE(SUM(id), 0) FROM candidate_profiles),
        (SELECT COUNT(*) FROM job_postings),
        (SELECT COALESCE(SUM(id), 0) FROM job_postings),
        (SELECT COUNT(*) FROM applications),
        (SELECT COALESCE(SUM(id), 0) FROM applications),
        (SELECT COUNT(*) FROM audit_logs),
        (SELECT COALESCE(SUM(id), 0) FROM audit_logs)
    );
"
business_row_count_query="
    SELECT
        (SELECT COUNT(*) FROM users)
        + (SELECT COUNT(*) FROM companies)
        + (SELECT COUNT(*) FROM candidate_profiles)
        + (SELECT COUNT(*) FROM job_postings)
        + (SELECT COUNT(*) FROM applications)
        + (
            SELECT COUNT(*)
            FROM audit_logs
            WHERE event <> '${database_canary_id}'
        );
"
source_business_manifest="$(source_mysql_scalar "$business_manifest_query")"
source_business_manifest_sha="$(
    printf '%s' "$source_business_manifest" | sha256sum | awk '{print $1}'
)"
source_business_row_count="$(source_mysql_scalar "$business_row_count_query")"
if [[ ! "$source_business_row_count" =~ ^[1-9][0-9]*$ ]]; then
    echo "Die lokale Quelle enthält außer dem Drill-Canary keine prüfbaren fachlichen Datensätze." >&2
    exit 1
fi

echo "[$drill_id] Erzeuge verschlüsselten MySQL-Snapshot aus der lokalen Quelle."
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
        --order-by-primary \
        --complete-insert \
        --skip-extended-insert \
        --skip-comments \
        --set-gtid-purged=OFF \
        "$MYSQL_DATABASE"
' | docker exec -i "$scratch_container" sh -ec '
    umask 077
    set -C
    cat > /scratch/source-database.sql
    sed -n "/^INSERT INTO \`/p" /scratch/source-database.sql \
        | LC_ALL=C sort \
        | sha256sum \
        | awk "{print \$1}" > /scratch/source-database-content.sha256
    sed "/^INSERT INTO \`/d" /scratch/source-database.sql \
        | sed -E \
            -e "s/AUTO_INCREMENT=[0-9]+/AUTO_INCREMENT=__VALUE__/g" \
            -e "s/ CHARACTER SET utf8mb4 COLLATE/ COLLATE/g" \
            -e "s/\`(erin|erin_restore)\`/\`__DATABASE__\`/g" \
        | sha256sum \
        | awk "{print \$1}" > /scratch/source-database-structure.sha256
    openssl enc -aes-256-cbc -salt -pbkdf2 -iter "$1" -md sha256 \
        -pass file:/scratch/drill.key \
        -in /scratch/source-database.sql \
        > /evidence/database.sql.enc.part
' sh "$iterations"
docker exec "$scratch_container" chown "$container_user" /evidence/database.sql.enc.part
test -s "$database_artifact.part"
if [[ -e "$database_artifact" || -L "$database_artifact" ]] \
    || ! mv -nT "$database_artifact.part" "$database_artifact" \
    || [[ -e "$database_artifact.part" || ! -f "$database_artifact" || -L "$database_artifact" ]]; then
    echo "Das Datenbankartefakt konnte nicht exklusiv veröffentlicht werden." >&2
    exit 1
fi
chmod 0640 "$database_artifact"

source_database_content_sha="$(
    docker exec "$scratch_container" cat /scratch/source-database-content.sha256
)"
source_database_structure_sha="$(
    docker exec "$scratch_container" cat /scratch/source-database-structure.sha256
)"
if [[ ! "$source_database_content_sha" =~ ^[a-f0-9]{64}$ \
    || ! "$source_database_structure_sha" =~ ^[a-f0-9]{64}$ ]]; then
    echo "Die kanonischen Datenbank-Inhalts- und Strukturhashes konnten nicht erzeugt werden." >&2
    exit 1
fi
database_backup_created_at="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
write_hmac_sidecar "$database_artifact" "$database_hmac_file"

source_objects="$work_dir/source-objects"
docker exec "$scratch_container" mkdir -m 0700 "$source_objects"
docker exec "$scratch_container" chown "$container_user" "$source_objects"
docker run --rm \
    --network "container:$source_minio_id" \
    --user "$container_user" \
    --entrypoint /bin/sh \
    -e HOME=/tmp \
    -e MC_CONFIG_DIR=/tmp/.mc \
    -e "MINIO_ROOT_USER=$minio_user" \
    -e "MINIO_ROOT_PASSWORD=$minio_password" \
    -e "AWS_BUCKET=$bucket" \
    -v "$scratch_volume:/scratch" \
    minio/mc:RELEASE.2025-08-13T08-35-41Z \
    -ec '
        mc alias set source http://127.0.0.1:9000 "$MINIO_ROOT_USER" "$MINIO_ROOT_PASSWORD" >/dev/null
        mc mirror --overwrite "source/$AWS_BUCKET" /scratch/source-objects >/dev/null
    '

docker exec "$scratch_container" sh -ec '
    cd /scratch/source-objects
    find . -type f -printf "%P\n" | LC_ALL=C sort -u \
        > /scratch/source-application-object-keys.list
'
source_application_object_count="$(
    docker exec "$scratch_container" sh -ec \
        'wc -l < /scratch/source-application-object-keys.list | tr -d " "'
)"
source_application_object_manifest_sha="$(
    docker exec "$scratch_container" sha256sum /scratch/source-application-object-keys.list \
        | awk '{print $1}'
)"
cleanup_source_object_canary
cleanup_source_canary
if [[ -n "$(source_mysql_scalar "SELECT id FROM audit_logs WHERE id = ${database_canary_row_id};")" ]]; then
    echo "Der lokale Datenbank-Canary wurde aus der Quelle nicht entfernt." >&2
    exit 1
fi
if ! release_source_quiesce; then
    echo "Die lokale Quelle konnte nach dem konsistenten Snapshot nicht sicher freigegeben werden." >&2
    exit 1
fi

drill_canary_name="erin-drill-canary-${drill_id}.txt"
object_storage_last_restored_record_at="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
docker exec "$scratch_container" sh -ec \
    'printf "Erin local restore drill canary: %s\n" "$1" > "$2/$3"' \
    sh "$drill_id" "$source_objects" "$drill_canary_name"

manifest_for() {
    local directory="$1"
    local output="$2"

    docker exec "$scratch_container" sh -ec '
        cd "$1"
        find . -type f -print0 \
            | LC_ALL=C sort -z \
            | xargs -0 -r sha256sum \
            > "$2"
    ' sh "$directory" "$output"
}

bytes_for() {
    docker exec "$scratch_container" sh -ec \
        'find "$1" -type f -printf "%s\n" | awk "{ total += \$1 } END { print total + 0 }"' \
        sh "$1"
}

manifest_for "$source_objects" "$work_dir/source.manifest"
source_manifest_sha="$(
    docker exec "$scratch_container" sha256sum "$work_dir/source.manifest" | awk '{print $1}'
)"
source_object_count="$(
    docker exec "$scratch_container" sh -ec \
        'find "$1" -type f | wc -l | tr -d " "' sh "$source_objects"
)"
source_object_bytes="$(bytes_for "$source_objects")"

echo "[$drill_id] Verschlüssele den lokalen MinIO-Snapshot."
docker exec "$scratch_container" sh -ec '
    set -C
    tar -C /scratch/source-objects -cf - . \
        | openssl enc -aes-256-cbc -salt -pbkdf2 -iter "$1" -md sha256 \
            -pass file:/scratch/drill.key \
            > /evidence/object-storage.tar.enc.part
' sh "$iterations"
docker exec "$scratch_container" chown "$container_user" /evidence/object-storage.tar.enc.part
test -s "$objects_artifact.part"
if [[ -e "$objects_artifact" || -L "$objects_artifact" ]] \
    || ! mv -nT "$objects_artifact.part" "$objects_artifact" \
    || [[ -e "$objects_artifact.part" || ! -f "$objects_artifact" || -L "$objects_artifact" ]]; then
    echo "Das Objektartefakt konnte nicht exklusiv veröffentlicht werden." >&2
    exit 1
fi
chmod 0640 "$objects_artifact"
object_storage_backup_created_at="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
write_hmac_sidecar "$objects_artifact" "$objects_hmac_file"
backup_completed_at="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
started_at="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
started_epoch="$(date -u +%s)"

database_sha="$(sha256sum "$database_artifact" | awk '{print $1}')"
objects_sha="$(sha256sum "$objects_artifact" | awk '{print $1}')"
database_size="$(stat -c '%s' "$database_artifact")"
objects_size="$(stat -c '%s' "$objects_artifact")"
database_hmac_sha="$(sha256sum "$database_hmac_file" | awk '{print $1}')"
objects_hmac_sha="$(sha256sum "$objects_hmac_file" | awk '{print $1}')"

if ! verify_artifact_hmac "$database_artifact" "$database_hmac_file" \
    || ! verify_artifact_hmac "$objects_artifact" "$objects_hmac_file"; then
    echo "Encrypt-then-MAC-Prüfung vor dem Restore ist fehlgeschlagen." >&2
    exit 1
fi

wrong_key="$work_dir/wrong.key"
docker exec "$scratch_container" sh -ec 'umask 077; openssl rand -hex 32 > /scratch/wrong.key'
if docker exec "$scratch_container" openssl enc -d -aes-256-cbc \
    -pbkdf2 -iter "$iterations" -md sha256 \
    -pass file:/scratch/wrong.key \
    -in /evidence/database.sql.enc \
    -out /dev/null 2>/dev/null; then
    echo "Negativkontrolle fehlgeschlagen: Ein falscher Schlüssel wurde akzeptiert." >&2
    exit 1
fi
wrong_key_rejected=true

tampered_copy="$work_dir/tampered.enc"
docker exec "$scratch_container" sh -ec '
    cp /evidence/database.sql.enc /scratch/tampered.enc
    printf "\001" | dd of=/scratch/tampered.enc bs=1 seek=32 count=1 conv=notrunc status=none
'
tampered_actual="$(
    docker exec "$scratch_container" sh -ec '
        mac_key_hex="$(tr -d "\n" < /scratch/drill.mac.key)"
        openssl dgst -sha256 -mac HMAC -macopt "hexkey:$mac_key_hex" /scratch/tampered.enc \
            | awk "{print \$NF}"
    '
)"
if [[ "$tampered_actual" == "$(tr -d '\n' < "$database_hmac_file")" ]]; then
    echo "Negativkontrolle fehlgeschlagen: Manipulierter Ciphertext bestand den MAC." >&2
    exit 1
fi
tampered_ciphertext_rejected=true

tampered_mac_copy="$work_dir/tampered.hmac"
docker cp "$database_hmac_file" "$scratch_container:/scratch/tampered.hmac"
docker exec "$scratch_container" sh -ec '
    replacement=0
    if [ "$(head -c 1 /scratch/tampered.hmac)" = "0" ]; then
        replacement=1
    fi
    printf "%s" "$replacement" \
        | dd of=/scratch/tampered.hmac bs=1 seek=0 count=1 conv=notrunc status=none
'
tampered_mac_value="$(
    docker exec "$scratch_container" sh -ec 'tr -d "\n" < /scratch/tampered.hmac'
)"
if [[ "$(artifact_hmac "$database_artifact")" == "$tampered_mac_value" ]]; then
    echo "Negativkontrolle fehlgeschlagen: Manipulierter MAC wurde akzeptiert." >&2
    exit 1
fi
tampered_mac_rejected=true

echo "[$drill_id] Starte eine interne Docker-Umgebung ohne Host-Ports und mit tmpfs."
docker network create --internal "$network_restore" >/dev/null
mysql_password="$(openssl rand -hex 24)"
minio_restore_user="erin-drill"
minio_restore_password="$(openssl rand -hex 24)"

docker run -d \
    --name "$mysql_restore" \
    --network "$network_restore" \
    --tmpfs /var/lib/mysql:rw,nosuid,nodev,noexec,size=1073741824 \
    -e "MYSQL_ROOT_PASSWORD=$mysql_password" \
    -e MYSQL_DATABASE=erin_restore \
    mysql:8.4 >/dev/null

docker run -d \
    --name "$minio_restore" \
    --network "$network_restore" \
    --tmpfs /data:rw,nosuid,nodev,noexec,size=1073741824 \
    -e "MINIO_ROOT_USER=$minio_restore_user" \
    -e "MINIO_ROOT_PASSWORD=$minio_restore_password" \
    minio/minio:RELEASE.2025-09-07T16-13-09Z \
    server /data --console-address :9001 >/dev/null
for _ in $(seq 1 60); do
    if docker exec "$mysql_restore" mysqladmin ping \
        --host=127.0.0.1 --user=root --password="$mysql_password" --silent >/dev/null 2>&1; then
        break
    fi
    sleep 1
done
if ! docker exec "$mysql_restore" mysqladmin ping \
    --host=127.0.0.1 --user=root --password="$mysql_password" --silent >/dev/null 2>&1; then
    echo "Isoliertes Drill-MySQL wurde nicht rechtzeitig bereit." >&2
    exit 1
fi

for _ in $(seq 1 60); do
    if docker exec "$minio_restore" curl --fail --silent \
        http://127.0.0.1:9000/minio/health/ready >/dev/null 2>&1; then
        break
    fi
    sleep 1
done
if ! docker exec "$minio_restore" curl --fail --silent \
    http://127.0.0.1:9000/minio/health/ready >/dev/null 2>&1; then
    echo "Isoliertes Drill-MinIO wurde nicht rechtzeitig bereit." >&2
    exit 1
fi

network_internal="$(
    docker network inspect --format '{{.Internal}}' "$network_restore"
)"
mysql_port_bindings="$(docker inspect --format '{{json .HostConfig.PortBindings}}' "$mysql_restore")"
minio_port_bindings="$(docker inspect --format '{{json .HostConfig.PortBindings}}' "$minio_restore")"
mysql_tmpfs="$(docker inspect --format '{{json .HostConfig.Tmpfs}}' "$mysql_restore")"
minio_tmpfs="$(docker inspect --format '{{json .HostConfig.Tmpfs}}' "$minio_restore")"
if [[ "$network_internal" != "true" || "$mysql_port_bindings" != "{}" || "$minio_port_bindings" != "{}" ]]; then
    echo "Isolationsprüfung fehlgeschlagen: Netzwerk oder Host-Ports sind unsicher." >&2
    exit 1
fi
if [[ "$mysql_tmpfs" != *"/var/lib/mysql"* || "$minio_tmpfs" != *"/data"* ]]; then
    echo "Isolationsprüfung fehlgeschlagen: Restore-Daten liegen nicht in tmpfs." >&2
    exit 1
fi

echo "[$drill_id] Stelle MySQL ausschließlich in der tmpfs-Drillinstanz wieder her."
if ! verify_artifact_hmac "$database_artifact" "$database_hmac_file"; then
    echo "Datenbank-Ciphertext wurde vor Entschlüsselung nicht authentifiziert." >&2
    exit 1
fi
docker exec "$scratch_container" openssl enc -d -aes-256-cbc \
    -pbkdf2 -iter "$iterations" -md sha256 \
    -pass file:/scratch/drill.key \
    -in /evidence/database.sql.enc \
    | docker exec -i "$mysql_restore" sh -ec '
        export MYSQL_PWD="$MYSQL_ROOT_PASSWORD"
        exec mysql --host=127.0.0.1 --user=root erin_restore
    '

migration_count="$(
    docker exec "$mysql_restore" sh -ec '
        export MYSQL_PWD="$MYSQL_ROOT_PASSWORD"
        mysql --host=127.0.0.1 --user=root --batch --skip-column-names erin_restore \
            --execute="SELECT COUNT(*) FROM migrations"
    '
)"
table_count="$(
    docker exec "$mysql_restore" sh -ec '
        export MYSQL_PWD="$MYSQL_ROOT_PASSWORD"
        mysql --host=127.0.0.1 --user=root --batch --skip-column-names \
            --execute="SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '\''erin_restore'\''"
    '
)"
restored_business_manifest="$(
    docker exec "$mysql_restore" sh -ec '
        export MYSQL_PWD="$MYSQL_ROOT_PASSWORD"
        exec mysql \
            --host=127.0.0.1 \
            --user=root \
            --batch \
            --skip-column-names \
            erin_restore \
            --execute="$1"
    ' sh "$business_manifest_query"
)"
restored_business_manifest_sha="$(
    printf '%s' "$restored_business_manifest" | sha256sum | awk '{print $1}'
)"
restored_business_row_count="$(
    docker exec "$mysql_restore" sh -ec '
        export MYSQL_PWD="$MYSQL_ROOT_PASSWORD"
        exec mysql \
            --host=127.0.0.1 \
            --user=root \
            --batch \
            --skip-column-names \
            erin_restore \
            --execute="$1"
    ' sh "$business_row_count_query"
)"
database_canary_verified=false
restored_canary="$(
    docker exec "$mysql_restore" sh -ec '
        export MYSQL_PWD="$MYSQL_ROOT_PASSWORD"
        exec mysql \
            --host=127.0.0.1 \
            --user=root \
            --batch \
            --skip-column-names \
            erin_restore \
            --execute="$1"
    ' sh "
        SELECT CONCAT(event, '|', DATE_FORMAT(created_at, '%Y-%m-%dT%H:%i:%sZ'))
        FROM audit_logs
        WHERE id = ${database_canary_row_id}
          AND event = '${database_canary_id}';
    "
)"

restored_mysql_scalar() {
    local query="$1"

    docker exec "$mysql_restore" sh -ec '
        export MYSQL_PWD="$MYSQL_ROOT_PASSWORD"
        exec mysql \
            --host=127.0.0.1 \
            --user=root \
            --batch \
            --skip-column-names \
            erin_restore \
            --execute="$1"
    ' sh "$query"
}

restored_database_content_hash() {
    docker exec "$mysql_restore" sh -ec '
        export MYSQL_PWD="$MYSQL_ROOT_PASSWORD"
        exec mysqldump \
            --host=127.0.0.1 \
            --user=root \
            --single-transaction \
            --routines \
            --events \
            --triggers \
            --hex-blob \
            --order-by-primary \
            --complete-insert \
            --skip-extended-insert \
            --skip-comments \
            --set-gtid-purged=OFF \
            erin_restore
    ' | docker exec -i "$scratch_container" sh -ec '
        umask 077
        cat > /scratch/restored-database.sql
        sed -n "/^INSERT INTO \`/p" /scratch/restored-database.sql \
            | LC_ALL=C sort \
            | sha256sum \
            | awk "{print \$1}" > /scratch/restored-database-content.sha256
        sed "/^INSERT INTO \`/d" /scratch/restored-database.sql \
            | sed -E \
                -e "s/AUTO_INCREMENT=[0-9]+/AUTO_INCREMENT=__VALUE__/g" \
                -e "s/ CHARACTER SET utf8mb4 COLLATE/ COLLATE/g" \
                -e "s/\`(erin|erin_restore)\`/\`__DATABASE__\`/g" \
            | sha256sum \
            | awk "{print \$1}" > /scratch/restored-database-structure.sha256
        cat /scratch/restored-database-content.sha256
    '
    if [[ "$retain_restored_dump_for_diagnostics" != "true" ]]; then
        docker exec "$scratch_container" rm -f /scratch/restored-database.sql
    fi
}

if [[ "$restored_canary" == "${database_canary_id}|${database_last_restored_record_at}" ]]; then
    database_canary_verified=true
fi
restored_database_content_sha="$(restored_database_content_hash)"
restored_database_structure_sha="$(
    docker exec "$scratch_container" cat /scratch/restored-database-structure.sha256
)"
if [[ ! "$migration_count" =~ ^[1-9][0-9]*$ \
    || ! "$table_count" =~ ^[1-9][0-9]*$ \
    || "$restored_business_row_count" != "$source_business_row_count" \
    || "$restored_business_manifest_sha" != "$source_business_manifest_sha" \
    || "$restored_database_content_sha" != "$source_database_content_sha" \
    || "$restored_database_structure_sha" != "$source_database_structure_sha" \
    || "$database_canary_verified" != "true" ]]; then
    echo "MySQL-Restore enthält keine belastbare Schema- und Fachevidenz." >&2
    echo "Schema: migrations=$migration_count tables=$table_count canary=$database_canary_verified" >&2
    echo "Fachzeilen: source=$source_business_row_count restored=$restored_business_row_count" >&2
    echo "Grobmanifest: source=$source_business_manifest_sha restored=$restored_business_manifest_sha" >&2
    echo "Alle Tabellenzeilen: source=$source_database_content_sha restored=$restored_database_content_sha" >&2
    echo "Struktur: source=$source_database_structure_sha restored=$restored_database_structure_sha" >&2
    if [[ "$restored_database_structure_sha" != "$source_database_structure_sha" ]]; then
        echo "Abweichende Strukturhunks (nur Zeilenbereiche, keine Inhalte):" >&2
        docker exec "$scratch_container" sh -ec '
            sed "/^INSERT INTO \`/d" /scratch/source-database.sql \
                | sed -E \
                    -e "s/AUTO_INCREMENT=[0-9]+/AUTO_INCREMENT=__VALUE__/g" \
                    -e "s/ CHARACTER SET utf8mb4 COLLATE/ COLLATE/g" \
                    -e "s/\`(erin|erin_restore)\`/\`__DATABASE__\`/g" \
                > /scratch/source-database-structure.sql
            sed "/^INSERT INTO \`/d" /scratch/restored-database.sql \
                | sed -E \
                    -e "s/AUTO_INCREMENT=[0-9]+/AUTO_INCREMENT=__VALUE__/g" \
                    -e "s/ CHARACTER SET utf8mb4 COLLATE/ COLLATE/g" \
                    -e "s/\`(erin|erin_restore)\`/\`__DATABASE__\`/g" \
                > /scratch/restored-database-structure.sql
            diff -U0 \
                /scratch/source-database-structure.sql \
                /scratch/restored-database-structure.sql \
                | sed -n "/^@@/p" \
                | head -n 20
            echo "Abweichungsklassen:"
            diff -U0 \
                /scratch/source-database-structure.sql \
                /scratch/restored-database-structure.sql \
                | awk "
                    /^[+-]/ && !/^[+-]{3}/ {
                        line = substr(\$0, 2)
                        class = \"other\"
                        if (line ~ /AUTO_INCREMENT=/) class = \"auto_increment\"
                        else if (line ~ /^CREATE TABLE/) class = \"create_table\"
                        else if (line ~ /^CREATE.*(TRIGGER|PROCEDURE|FUNCTION|EVENT)/) class = \"programmable_object\"
                        else if (line ~ /^USE /) class = \"database_selection\"
                        else if (line ~ /character_set_client/) class = \"character_set_client\"
                        else if (line ~ /collation_connection/) class = \"collation_connection\"
                        else if (line ~ /SQL_MODE/) class = \"sql_mode\"
                        else if (line ~ /^LOCK TABLES/) class = \"lock_tables\"
                        else if (line ~ /^UNLOCK TABLES/) class = \"unlock_tables\"
                        else if (line ~ /^DROP /) class = \"drop_statement\"
                        else if (line ~ /^\\) ENGINE=/) class = \"table_options\"
                        counts[substr(\$0, 1, 1) \":\" class]++
                    }
                    END {
                        for (key in counts) print key, counts[key]
                    }
                " \
                | LC_ALL=C sort
            rm -f \
                /scratch/source-database-structure.sql \
                /scratch/restored-database-structure.sql
        ' >&2
    fi
    exit 1
fi
docker exec "$scratch_container" rm -f \
    /scratch/source-database.sql \
    /scratch/restored-database.sql
retain_restored_dump_for_diagnostics=false

restored_mysql_scalar "
    UPDATE audit_logs
    SET metadata = JSON_SET(metadata, '$.restore_negative_control', TRUE)
    WHERE id = ${database_canary_row_id}
      AND event = '${database_canary_id}';
" >/dev/null
non_id_changed_hash="$(restored_database_content_hash)"
if [[ "$non_id_changed_hash" == "$source_database_content_sha" ]]; then
    echo "Negativkontrolle fehlgeschlagen: Eine Nicht-ID-Änderung blieb unentdeckt." >&2
    exit 1
fi
non_id_database_change_detected=true
restored_mysql_scalar "
    UPDATE audit_logs
    SET metadata = JSON_REMOVE(metadata, '$.restore_negative_control')
    WHERE id = ${database_canary_row_id}
      AND event = '${database_canary_id}';
" >/dev/null
if [[ "$(restored_database_content_hash)" != "$source_database_content_sha" ]]; then
    echo "Die Datenbank ließ sich nach der Nicht-ID-Negativkontrolle nicht exakt zurücksetzen." >&2
    exit 1
fi

restored_mysql_scalar "
    DELETE FROM audit_logs
    WHERE id = ${database_canary_row_id}
      AND event = '${database_canary_id}';
" >/dev/null
missing_row_hash="$(restored_database_content_hash)"
if [[ "$missing_row_hash" == "$source_database_content_sha" ]]; then
    echo "Negativkontrolle fehlgeschlagen: Eine fehlende Datenbankzeile blieb unentdeckt." >&2
    exit 1
fi
missing_database_row_detected=true
restored_mysql_scalar "
    INSERT INTO audit_logs (id, event, metadata, created_at)
    VALUES (
        ${database_canary_row_id},
        '${database_canary_id}',
        JSON_OBJECT(
            'drill_id',
            '${drill_id}',
            'classification',
            'LOCAL_SYNTHETIC_NOT_PRODUCTION_EVIDENCE',
            'restore_object_path',
            '${database_canary_object_path}'
        ),
        STR_TO_DATE(
            '${database_last_restored_record_at}',
            '%Y-%m-%dT%H:%i:%sZ'
        )
    );
" >/dev/null
restored_database_content_sha="$(restored_database_content_hash)"
if [[ "$restored_database_content_sha" != "$source_database_content_sha" ]]; then
    echo "Die Datenbank ließ sich nach der Zeilen-Negativkontrolle nicht exakt zurücksetzen." >&2
    exit 1
fi

database_object_reference_query="
    SELECT path
    FROM (
        SELECT path FROM candidate_documents
            WHERE disk = 'private' AND path IS NOT NULL AND path <> ''
        UNION
        SELECT storage_path AS path FROM candidate_imports
            WHERE disk = 'private' AND storage_path IS NOT NULL AND storage_path <> ''
        UNION
        SELECT path FROM company_media
            WHERE disk = 'private' AND path IS NOT NULL AND path <> ''
        UNION
        SELECT path FROM job_media
            WHERE disk = 'private' AND path IS NOT NULL AND path <> ''
        UNION
        SELECT path FROM message_attachments
            WHERE disk = 'private' AND path IS NOT NULL AND path <> ''
        UNION
        SELECT path FROM support_ticket_attachments
            WHERE disk = 'private' AND path IS NOT NULL AND path <> ''
        UNION
        SELECT export_path AS path FROM gdpr_requests
            WHERE export_disk = 'private' AND export_path IS NOT NULL AND export_path <> ''
        UNION
        SELECT JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.restore_object_path')) AS path
            FROM audit_logs
            WHERE event = '${database_canary_id}'
              AND JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.restore_object_path')) IS NOT NULL
              AND JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.restore_object_path')) <> ''
    ) AS private_object_references
    ORDER BY path;
"
restored_mysql_scalar "$database_object_reference_query" \
    | docker exec -i "$scratch_container" sh -ec \
        'LC_ALL=C sort -u > /scratch/database-object-keys.list'
database_object_reference_count="$(
    docker exec "$scratch_container" sh -ec \
        'wc -l < /scratch/database-object-keys.list | tr -d " "'
)"
database_object_reference_manifest_sha="$(
    docker exec "$scratch_container" sha256sum /scratch/database-object-keys.list \
        | awk '{print $1}'
)"
docker exec "$scratch_container" sh -ec '
    comm -23 \
        /scratch/database-object-keys.list \
        /scratch/source-application-object-keys.list \
        > /scratch/missing-database-objects.list
    comm -13 \
        /scratch/database-object-keys.list \
        /scratch/source-application-object-keys.list \
        > /scratch/orphan-storage-objects.list
'
missing_database_object_count="$(
    docker exec "$scratch_container" sh -ec \
        'wc -l < /scratch/missing-database-objects.list | tr -d " "'
)"
orphan_storage_object_count="$(
    docker exec "$scratch_container" sh -ec \
        'wc -l < /scratch/orphan-storage-objects.list | tr -d " "'
)"
if (( missing_database_object_count != 0 || orphan_storage_object_count != 0 )); then
    echo "Datenbankpfade und MinIO-Objekte bilden keinen vollständigen bijektiven Snapshot." >&2
    echo "Fehlende Objekte: $missing_database_object_count; Orphans: $orphan_storage_object_count" >&2
    exit 1
fi
if [[ "$database_object_reference_manifest_sha" != "$source_application_object_manifest_sha" ]]; then
    echo "Die Pfadmanifeste von Datenbank und MinIO stimmen nicht überein." >&2
    exit 1
fi

docker exec "$scratch_container" sh -ec '
    cp /scratch/database-object-keys.list /scratch/negative-database-object-keys.list
    printf "__erin_missing_object_negative_control__\n" \
        >> /scratch/negative-database-object-keys.list
    LC_ALL=C sort -u -o \
        /scratch/negative-database-object-keys.list \
        /scratch/negative-database-object-keys.list
    comm -23 \
        /scratch/negative-database-object-keys.list \
        /scratch/source-application-object-keys.list \
        > /scratch/negative-missing-objects.list
    test "$(wc -l < /scratch/negative-missing-objects.list)" -gt 0

    cp /scratch/source-application-object-keys.list /scratch/negative-storage-object-keys.list
    printf "__erin_orphan_object_negative_control__\n" \
        >> /scratch/negative-storage-object-keys.list
    LC_ALL=C sort -u -o \
        /scratch/negative-storage-object-keys.list \
        /scratch/negative-storage-object-keys.list
    comm -13 \
        /scratch/database-object-keys.list \
        /scratch/negative-storage-object-keys.list \
        > /scratch/negative-orphan-objects.list
    test "$(wc -l < /scratch/negative-orphan-objects.list)" -gt 0

    : > /scratch/negative-empty-storage.list
    printf "__erin_empty_storage_reference_negative_control__\n" \
        > /scratch/negative-empty-database-refs.list
    comm -23 \
        /scratch/negative-empty-database-refs.list \
        /scratch/negative-empty-storage.list \
        > /scratch/negative-empty-storage-missing.list
    test "$(wc -l < /scratch/negative-empty-storage-missing.list)" -eq 1
'
missing_database_object_detected=true
orphan_storage_object_detected=true
empty_storage_with_database_reference_rejected=true
database_restored_at="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
database_restored_epoch="$(date -u +%s)"

restored_plain="$work_dir/restored-objects"
verified_plain="$work_dir/verified-objects"
negative_plain="$work_dir/negative-objects"
docker exec "$scratch_container" mkdir -m 0700 \
    "$restored_plain" "$verified_plain" "$negative_plain"
docker exec "$scratch_container" chown -R "$container_user" \
    "$restored_plain" "$verified_plain" "$negative_plain"
if ! verify_artifact_hmac "$objects_artifact" "$objects_hmac_file"; then
    echo "Objekt-Ciphertext wurde vor Entschlüsselung nicht authentifiziert." >&2
    exit 1
fi
docker exec "$scratch_container" sh -ec '
    openssl enc -d -aes-256-cbc -pbkdf2 -iter "$1" -md sha256 \
        -pass file:/scratch/drill.key \
        -in /evidence/object-storage.tar.enc \
        | tar -C /scratch/restored-objects -xf -
' sh "$iterations"

mc_restore() {
    docker run --rm \
        --network "container:$minio_restore" \
        --user "$container_user" \
        --entrypoint /bin/sh \
        -e HOME=/tmp \
        -e MC_CONFIG_DIR=/tmp/.mc \
        -e "MINIO_ROOT_USER=$minio_restore_user" \
        -e "MINIO_ROOT_PASSWORD=$minio_restore_password" \
        -e "AWS_BUCKET=$bucket" \
        -v "$scratch_volume:/scratch" \
        "$@"
}

mc_restore \
    -e "DRILL_CANARY_NAME=$drill_canary_name" \
    minio/mc:RELEASE.2025-08-13T08-35-41Z \
    -ec '
        mc alias set restore http://127.0.0.1:9000 "$MINIO_ROOT_USER" "$MINIO_ROOT_PASSWORD" >/dev/null
        mc mb --ignore-existing "restore/$AWS_BUCKET" >/dev/null
        test -r "/scratch/restored-objects/$DRILL_CANARY_NAME"
        mc cp --recursive /scratch/restored-objects/ "restore/$AWS_BUCKET/" >/dev/null
        mc stat "restore/$AWS_BUCKET/$DRILL_CANARY_NAME" >/dev/null
    '

mc_restore \
    -e "DRILL_CANARY_NAME=$drill_canary_name" \
    minio/mc:RELEASE.2025-08-13T08-35-41Z \
    -ec '
        mc alias set restore http://127.0.0.1:9000 "$MINIO_ROOT_USER" "$MINIO_ROOT_PASSWORD" >/dev/null
        mc cp --recursive "restore/$AWS_BUCKET/" /scratch/verified-objects/ >/dev/null
        test -r "/scratch/verified-objects/$DRILL_CANARY_NAME"
    '

manifest_for "$verified_plain" "$work_dir/restored.manifest"
restored_manifest_sha="$(
    docker exec "$scratch_container" sha256sum "$work_dir/restored.manifest" | awk '{print $1}'
)"
restored_object_count="$(
    docker exec "$scratch_container" sh -ec \
        'find "$1" -type f | wc -l | tr -d " "' sh "$verified_plain"
)"
restored_object_bytes="$(bytes_for "$verified_plain")"
if [[ "$source_manifest_sha" != "$restored_manifest_sha" \
    || "$source_object_count" != "$restored_object_count" \
    || "$source_object_bytes" != "$restored_object_bytes" ]]; then
    echo "MinIO-Restore stimmt nicht mit dem verschlüsselten Snapshot überein." >&2
    echo "Quelle: count=$source_object_count bytes=$source_object_bytes manifest=$source_manifest_sha" >&2
    echo "Restore: count=$restored_object_count bytes=$restored_object_bytes manifest=$restored_manifest_sha" >&2
    exit 1
fi

first_object_key="$drill_canary_name"
mc_restore \
    -e "OBJECT_KEY=$first_object_key" \
    minio/mc:RELEASE.2025-08-13T08-35-41Z \
    -ec '
        mc alias set restore http://127.0.0.1:9000 "$MINIO_ROOT_USER" "$MINIO_ROOT_PASSWORD" >/dev/null
        mc rm "restore/$AWS_BUCKET/$OBJECT_KEY" >/dev/null
    '
mc_restore \
    -e "DRILL_CANARY_NAME=$drill_canary_name" \
    minio/mc:RELEASE.2025-08-13T08-35-41Z \
    -ec '
        mc alias set restore http://127.0.0.1:9000 "$MINIO_ROOT_USER" "$MINIO_ROOT_PASSWORD" >/dev/null
        if [ -n "$(mc ls --recursive "restore/$AWS_BUCKET/")" ]; then
            mc cp --recursive "restore/$AWS_BUCKET/" /scratch/negative-objects/ >/dev/null
        fi
        test ! -e "/scratch/negative-objects/$DRILL_CANARY_NAME"
    '
manifest_for "$negative_plain" "$work_dir/negative.manifest"
negative_manifest_sha="$(
    docker exec "$scratch_container" sha256sum "$work_dir/negative.manifest" | awk '{print $1}'
)"
if [[ "$negative_manifest_sha" == "$source_manifest_sha" ]]; then
    echo "Negativkontrolle fehlgeschlagen: Fehlendes Objekt blieb unentdeckt." >&2
    exit 1
fi
missing_object_detected=true

mc_restore \
    -e "OBJECT_KEY=$first_object_key" \
    minio/mc:RELEASE.2025-08-13T08-35-41Z \
    -ec '
        mc alias set restore http://127.0.0.1:9000 "$MINIO_ROOT_USER" "$MINIO_ROOT_PASSWORD" >/dev/null
        mc cp "/scratch/restored-objects/$OBJECT_KEY" "restore/$AWS_BUCKET/$OBJECT_KEY" >/dev/null
    '

object_storage_restored_at="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
object_storage_restored_epoch="$(date -u +%s)"

source_identity_unchanged=false
if [[ "$(docker compose -f "$compose_file" ps -q mysql)" == "$source_mysql_id" \
    && "$(docker compose -f "$compose_file" ps -q minio)" == "$source_minio_id" \
    && "$(docker inspect --format '{{.State.StartedAt}}' "$source_mysql_id")" == "$source_mysql_started" \
    && "$(docker inspect --format '{{.State.StartedAt}}' "$source_minio_id")" == "$source_minio_started" ]]; then
    source_identity_unchanged=true
fi

if ! docker exec "$scratch_container" test -s "$key_file" \
    || ! docker exec "$scratch_container" test -s "$mac_key_file" \
    || docker exec "$scratch_container" test -e "$master_key_file"; then
    echo "Der ephemere Schlüssel-Lebenszyklus im tmpfs ist inkonsistent." >&2
    exit 1
fi

cleanup_runtime
if docker inspect "$mysql_restore" "$minio_restore" >/dev/null 2>&1 \
    || docker network inspect "$network_restore" >/dev/null 2>&1 \
    || docker inspect "$scratch_container" >/dev/null 2>&1 \
    || docker volume inspect "$scratch_volume" >/dev/null 2>&1; then
    echo "Die isolierte Restore-Umgebung wurde nicht vollständig entfernt." >&2
    exit 1
fi
scratch_tmpfs_removed=true
runtime_cleaned=true

completed_at="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
database_rto_achieved=$((database_restored_epoch - started_epoch))
object_rto_achieved=$((object_storage_restored_epoch - started_epoch))
database_backup_epoch="$(date -u -d "$database_backup_created_at" +%s)"
database_last_record_epoch="$(date -u -d "$database_last_restored_record_at" +%s)"
object_backup_epoch="$(date -u -d "$object_storage_backup_created_at" +%s)"
object_last_record_epoch="$(date -u -d "$object_storage_last_restored_record_at" +%s)"
if (( database_last_record_epoch > database_backup_epoch \
    || database_backup_epoch > started_epoch \
    || object_last_record_epoch > object_backup_epoch \
    || object_backup_epoch > started_epoch )); then
    echo "Die Backup-, Recovery-Point- und Drill-Zeitpunkte sind nicht kausal." >&2
    exit 1
fi
database_rpo_achieved=$((started_epoch - database_last_record_epoch))
object_rpo_achieved=$((started_epoch - object_last_record_epoch))
database_rpo_target="${ERIN_LOCAL_DRILL_DB_RPO_TARGET_SECONDS:-900}"
database_rto_target="${ERIN_LOCAL_DRILL_DB_RTO_TARGET_SECONDS:-7200}"
object_rpo_target="${ERIN_LOCAL_DRILL_OBJECT_RPO_TARGET_SECONDS:-1800}"
object_rto_target="${ERIN_LOCAL_DRILL_OBJECT_RTO_TARGET_SECONDS:-10800}"

for metric in \
    "$database_rpo_target" \
    "$database_rto_target" \
    "$object_rpo_target" \
    "$object_rto_target"; do
    if [[ ! "$metric" =~ ^[1-9][0-9]*$ ]]; then
        echo "RPO-/RTO-Ziele müssen positive Sekundenwerte sein." >&2
        exit 2
    fi
done
if (( database_rpo_achieved < 0 \
    || object_rpo_achieved < 0 \
    || database_rpo_achieved > database_rpo_target \
    || object_rpo_achieved > object_rpo_target \
    || database_rto_achieved > database_rto_target \
    || object_rto_achieved > object_rto_target )); then
    echo "Der lokale Restore-Drill hat ein RPO-/RTO-Ziel verfehlt." >&2
    exit 1
fi

if [[ -e "$evidence_file.part" || -L "$evidence_file.part" \
    || -e "$evidence_file" || -L "$evidence_file" ]]; then
    echo "Die Evidenzdatei oder ihre Staging-Datei existiert bereits oder ist unsicher." >&2
    exit 1
fi
set -o noclobber
jq -n \
    --arg drill_id "$drill_id" \
    --arg operation_started_at "$operation_started_at" \
    --arg started_at "$started_at" \
    --arg backup_completed_at "$backup_completed_at" \
    --arg database_backup_created_at "$database_backup_created_at" \
    --arg database_last_restored_record_at "$database_last_restored_record_at" \
    --arg database_restored_at "$database_restored_at" \
    --arg object_storage_backup_created_at "$object_storage_backup_created_at" \
    --arg object_storage_last_restored_record_at "$object_storage_last_restored_record_at" \
    --arg object_storage_restored_at "$object_storage_restored_at" \
    --arg completed_at "$completed_at" \
    --arg database_sha "$database_sha" \
    --arg objects_sha "$objects_sha" \
    --arg database_hmac_sha "$database_hmac_sha" \
    --arg objects_hmac_sha "$objects_hmac_sha" \
    --arg source_manifest_sha "$source_manifest_sha" \
    --arg restored_manifest_sha "$restored_manifest_sha" \
    --arg source_business_manifest_sha "$source_business_manifest_sha" \
    --arg restored_business_manifest_sha "$restored_business_manifest_sha" \
    --arg source_database_content_sha "$source_database_content_sha" \
    --arg restored_database_content_sha "$restored_database_content_sha" \
    --arg source_database_structure_sha "$source_database_structure_sha" \
    --arg restored_database_structure_sha "$restored_database_structure_sha" \
    --arg database_object_reference_manifest_sha "$database_object_reference_manifest_sha" \
    --arg source_application_object_manifest_sha "$source_application_object_manifest_sha" \
    --arg database_canary_id "$database_canary_id" \
    --argjson iterations "$iterations" \
    --argjson database_size "$database_size" \
    --argjson objects_size "$objects_size" \
    --argjson database_rpo_target "$database_rpo_target" \
    --argjson database_rto_target "$database_rto_target" \
    --argjson object_rpo_target "$object_rpo_target" \
    --argjson object_rto_target "$object_rto_target" \
    --argjson database_rto_achieved "$database_rto_achieved" \
    --argjson object_rto_achieved "$object_rto_achieved" \
    --argjson database_rpo_achieved "$database_rpo_achieved" \
    --argjson object_rpo_achieved "$object_rpo_achieved" \
    --argjson migration_count "$migration_count" \
    --argjson table_count "$table_count" \
    --argjson source_business_row_count "$source_business_row_count" \
    --argjson restored_business_row_count "$restored_business_row_count" \
    --argjson database_canary_verified "$database_canary_verified" \
    --argjson source_application_object_count "$source_application_object_count" \
    --argjson database_object_reference_count "$database_object_reference_count" \
    --argjson missing_database_object_count "$missing_database_object_count" \
    --argjson orphan_storage_object_count "$orphan_storage_object_count" \
    --argjson source_object_count "$source_object_count" \
    --argjson restored_object_count "$restored_object_count" \
    --argjson source_object_bytes "$source_object_bytes" \
    --argjson restored_object_bytes "$restored_object_bytes" \
    --argjson source_identity_unchanged "$source_identity_unchanged" \
    --argjson wrong_key_rejected "$wrong_key_rejected" \
    --argjson tampered_ciphertext_rejected "$tampered_ciphertext_rejected" \
    --argjson tampered_mac_rejected "$tampered_mac_rejected" \
    --argjson missing_object_detected "$missing_object_detected" \
    --argjson non_id_database_change_detected "$non_id_database_change_detected" \
    --argjson missing_database_row_detected "$missing_database_row_detected" \
    --argjson missing_database_object_detected "$missing_database_object_detected" \
    --argjson orphan_storage_object_detected "$orphan_storage_object_detected" \
    --argjson empty_storage_with_database_reference_rejected "$empty_storage_with_database_reference_rejected" \
    --argjson scratch_tmpfs_verified "$scratch_tmpfs_verified" \
    --argjson scratch_tmpfs_removed "$scratch_tmpfs_removed" \
    --argjson source_quiesced "$source_quiesced" \
    --argjson source_quiesce_released "$source_quiesce_released" \
    --argjson source_maintenance_state_restored "$source_maintenance_state_restored" \
    --argjson source_background_services_restored "$source_background_services_restored" \
    --argjson runtime_cleaned "$runtime_cleaned" \
    '{
        schema_version: 1,
        evidence_type: "local_encrypted_restore_drill",
        classification: "LOCAL_SYNTHETIC_NOT_PRODUCTION_EVIDENCE",
        production_gate_eligible: false,
        drill_id: $drill_id,
        environment: "local-isolated-docker",
        timing: {
            operation_started_at: $operation_started_at,
            started_at: $started_at,
            backup_completed_at: $backup_completed_at,
            database_backup_created_at: $database_backup_created_at,
            database_last_restored_record_at: $database_last_restored_record_at,
            database_restored_at: $database_restored_at,
            object_storage_backup_created_at: $object_storage_backup_created_at,
            object_storage_last_restored_record_at: $object_storage_last_restored_record_at,
            object_storage_restored_at: $object_storage_restored_at,
            completed_at: $completed_at,
            database_rpo_target_seconds: $database_rpo_target,
            database_rpo_achieved_seconds: $database_rpo_achieved,
            database_rto_target_seconds: $database_rto_target,
            database_rto_achieved_seconds: $database_rto_achieved,
            object_storage_rpo_target_seconds: $object_rpo_target,
            object_storage_rpo_achieved_seconds: $object_rpo_achieved,
            object_storage_rto_target_seconds: $object_rto_target,
            object_storage_rto_achieved_seconds: $object_rto_achieved
        },
        encryption: {
            algorithm: "AES-256-CBC",
            kdf: "PBKDF2-HMAC-SHA256",
            key_derivation: "HMAC-SHA256-DOMAIN-SEPARATED",
            iterations: $iterations,
            integrity: "HMAC-SHA256-ENCRYPT-THEN-MAC",
            key_origin: "ephemeral random local drill key",
            key_material_location: "docker-managed-tmpfs-volume",
            key_material_volume_removed: $scratch_tmpfs_removed,
            physical_destruction_claimed: false,
            plaintext_workspace_location: "docker-managed-tmpfs-volume",
            plaintext_workspace_volume_removed: $scratch_tmpfs_removed,
            plaintext_backup_retained: false
        },
        isolation: {
            internal_network: true,
            no_host_ports: true,
            mysql_tmpfs: true,
            object_storage_tmpfs: true,
            sensitive_scratch_tmpfs: $scratch_tmpfs_verified,
            sensitive_scratch_removed: $scratch_tmpfs_removed,
            source_environment_non_production: true,
            source_container_identity_unchanged: $source_identity_unchanged,
            source_quiesced_during_snapshot: $source_quiesced,
            source_quiesce_released: $source_quiesce_released,
            source_maintenance_state_restored: $source_maintenance_state_restored,
            source_background_services_restored: $source_background_services_restored,
            cleanup_verified: $runtime_cleaned
        },
        artifacts: {
            database: {
                file: "database.sql.enc",
                encrypted: true,
                ciphertext_format: "openssl-salted-v1",
                sha256: $database_sha,
                size_bytes: $database_size,
                hmac_file: "database.sql.enc.hmac",
                hmac_sha256: $database_hmac_sha,
                mac_verified_before_decryption: true
            },
            object_storage: {
                file: "object-storage.tar.enc",
                encrypted: true,
                ciphertext_format: "openssl-salted-v1",
                sha256: $objects_sha,
                size_bytes: $objects_size,
                hmac_file: "object-storage.tar.enc.hmac",
                hmac_sha256: $objects_hmac_sha,
                mac_verified_before_decryption: true
            }
        },
        validation: {
            database: {
                migration_count: $migration_count,
                table_count: $table_count,
                source_business_row_count: $source_business_row_count,
                restored_business_row_count: $restored_business_row_count,
                source_business_manifest_sha256: $source_business_manifest_sha,
                restored_business_manifest_sha256: $restored_business_manifest_sha,
                canonical_content_scope: "all-table-complete-insert-rows-sorted-from-encrypted-full-dump",
                source_canonical_content_sha256: $source_database_content_sha,
                restored_canonical_content_sha256: $restored_database_content_sha,
                structure_scope: "schema-routines-events-triggers-normalized-from-full-dump",
                source_structure_sha256: $source_database_structure_sha,
                restored_structure_sha256: $restored_database_structure_sha,
                drill_canary_verified: $database_canary_verified,
                drill_canary_id: $database_canary_id
            },
            object_storage: {
                source_application_object_count: $source_application_object_count,
                database_object_reference_count: $database_object_reference_count,
                database_object_reference_manifest_sha256: $database_object_reference_manifest_sha,
                application_object_key_manifest_sha256: $source_application_object_manifest_sha,
                missing_database_referenced_objects: $missing_database_object_count,
                orphan_application_objects: $orphan_storage_object_count,
                source_count: $source_object_count,
                restored_count: $restored_object_count,
                source_bytes: $source_object_bytes,
                restored_bytes: $restored_object_bytes,
                source_manifest_sha256: $source_manifest_sha,
                restored_manifest_sha256: $restored_manifest_sha,
                missing_objects: 0,
                unexpected_objects: 0,
                drill_canary_included: true
            }
        },
        negative_controls: {
            wrong_key_rejected: $wrong_key_rejected,
            tampered_ciphertext_rejected: $tampered_ciphertext_rejected,
            tampered_mac_rejected: $tampered_mac_rejected,
            missing_object_detected: $missing_object_detected,
            non_id_database_change_detected: $non_id_database_change_detected,
            missing_database_row_detected: $missing_database_row_detected,
            missing_database_object_detected: $missing_database_object_detected,
            orphan_storage_object_detected: $orphan_storage_object_detected,
            empty_storage_with_database_reference_rejected: $empty_storage_with_database_reference_rejected
        },
        result: {
            status: "passed",
            errors: []
        },
        external_gates: {
            independent_verification: "open",
            dpo_approval: "open",
            legal_approval: "open",
            production_restore: "open"
        }
    }' > "$evidence_file.part"
set +o noclobber
if [[ -e "$evidence_file" || -L "$evidence_file" ]] \
    || ! mv -nT "$evidence_file.part" "$evidence_file" \
    || [[ -e "$evidence_file.part" || ! -f "$evidence_file" || -L "$evidence_file" ]]; then
    echo "Die Evidenzdatei konnte nicht exklusiv veröffentlicht werden." >&2
    exit 1
fi
chmod 0640 "$evidence_file"
(
    set -o noclobber
    cd "$output_dir"
    sha256sum evidence.json > evidence.json.sha256.part
)
if [[ -e "$evidence_file.sha256" || -L "$evidence_file.sha256" ]] \
    || ! mv -nT "$evidence_file.sha256.part" "$evidence_file.sha256" \
    || [[ -e "$evidence_file.sha256.part" || ! -f "$evidence_file.sha256" || -L "$evidence_file.sha256" ]]; then
    echo "Der Evidenz-Sidecar konnte nicht exklusiv veröffentlicht werden." >&2
    exit 1
fi
chmod 0640 "$evidence_file.sha256"

container_evidence="/var/www/html/${evidence_file#"$project_dir/"}"
docker compose -f "$compose_file" exec -T laravel \
    php artisan erin:ops:restore-evidence:verify "$container_evidence" --json

if [[ -e "$final_output_dir" || -L "$final_output_dir" ]] \
    || ! mv -nT "$output_dir" "$final_output_dir" \
    || [[ -e "$output_dir" || ! -d "$final_output_dir" || -L "$final_output_dir" ]]; then
    echo "Das vollständige Evidenzpaket konnte nicht atomar veröffentlicht werden." >&2
    exit 1
fi
output_dir="$final_output_dir"
evidence_file="$output_dir/evidence.json"
evidence_finalized=true
trap - EXIT

echo "Lokaler verschlüsselter Restore-Drill erfolgreich."
echo "Evidenz: $evidence_file"
echo "Wichtig: Diese lokale synthetische Evidenz entsperrt kein Produktionsgate."
