#!/usr/bin/env bash

set -euo pipefail

if [[ "${ERIN_DEPLOY_CONFIRM:-}" != "DEPLOY_VERIFIED_RELEASE" ]]; then
    echo "Setze ERIN_DEPLOY_CONFIRM=DEPLOY_VERIFIED_RELEASE." >&2
    exit 2
fi

release_sha="${ERIN_RELEASE_SHA:-}"
environment="${ERIN_DEPLOY_ENVIRONMENT:-}"
env_file="${ERIN_DEPLOY_ENV_FILE:-}"
if [[ ! "$release_sha" =~ ^[0-9a-f]{40}$ ]] \
    || [[ "$environment" != "staging" && "$environment" != "production" ]] \
    || [[ ! -r "$env_file" ]]; then
    echo "Release-SHA, Zielumgebung oder geschützte Env-Datei ist ungültig." >&2
    exit 2
fi

project_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$project_dir"

compose_file="compose.production.yaml"
state_dir="${ERIN_DEPLOY_STATE_DIR:-/var/lib/erin/deployments}"
evidence_dir="$project_dir/storage/app/operations/evidence/deployments"
mkdir -p "$state_dir" "$evidence_dir"
lock_file="$state_dir/${environment}.lock"
previous_file="$state_dir/${environment}.previous-sha"
current_file="$state_dir/${environment}.current-sha"
previous_sha=""
[[ -r "$current_file" ]] && previous_sha="$(tr -d '\n' < "$current_file")"

exec 9>"$lock_file"
if ! flock -n 9; then
    echo "Ein Deployment für ${environment} läuft bereits." >&2
    exit 1
fi

export ERIN_APP_TAG="$release_sha"
export ERIN_BUILD_SHA="$release_sha"
export ERIN_APP_IMAGE="${ERIN_APP_IMAGE:?ERIN_APP_IMAGE fehlt}"
export ERIN_NGINX_IMAGE="${ERIN_NGINX_IMAGE:?ERIN_NGINX_IMAGE fehlt}"
export ERIN_RUNTIME_ENV_SECRET_FILE="$env_file"
export COMPOSE_ENV_FILES="$env_file"
timestamp="$(date -u +%Y%m%dT%H%M%SZ)"
evidence="$evidence_dir/${environment}-${release_sha}-${timestamp}.json"
started_at="$(date -u +%Y-%m-%dT%H:%M:%SZ)"

rollback() {
    local status=$?
    if (( status == 0 )); then
        return
    fi

    if [[ "$previous_sha" =~ ^[0-9a-f]{40}$ ]]; then
        export ERIN_APP_TAG="$previous_sha"
        export ERIN_BUILD_SHA="$previous_sha"
        docker compose --env-file "$env_file" -f "$compose_file" up -d --no-build \
            php-fpm queue scheduler reverb nginx || true
    fi

    jq -n \
        --arg release "$release_sha" \
        --arg previous "$previous_sha" \
        --arg environment "$environment" \
        --arg started "$started_at" \
        --arg completed "$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
        --argjson exit_code "$status" \
        '{schema:"erin-deployment-v1",status:"rolled_back",release_sha:$release,previous_sha:$previous,environment:$environment,started_at:$started,completed_at:$completed,exit_code:$exit_code}' \
        > "$evidence"
    exit "$status"
}
trap rollback EXIT

docker compose --env-file "$env_file" -f "$compose_file" config -q
docker pull "${ERIN_APP_IMAGE}:${release_sha}"
docker pull "${ERIN_NGINX_IMAGE}:${release_sha}"
docker compose --env-file "$env_file" -f "$compose_file" run --rm migrate \
    php artisan erin:ops:readiness --strict --probe --json
docker compose --env-file "$env_file" -f "$compose_file" run --rm migrate \
    php artisan migrate --isolated --force
docker compose --env-file "$env_file" -f "$compose_file" up -d --no-build --remove-orphans
scripts/ops/post-deploy-smoke.sh

[[ "$previous_sha" =~ ^[0-9a-f]{40}$ ]] && printf '%s\n' "$previous_sha" > "$previous_file"
printf '%s\n' "$release_sha" > "$current_file"
jq -n \
    --arg release "$release_sha" \
    --arg previous "$previous_sha" \
    --arg environment "$environment" \
    --arg started "$started_at" \
    --arg completed "$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
    '{schema:"erin-deployment-v1",status:"deployed",release_sha:$release,previous_sha:$previous,environment:$environment,started_at:$started,completed_at:$completed}' \
    > "$evidence"
trap - EXIT
