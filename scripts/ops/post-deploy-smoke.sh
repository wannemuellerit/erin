#!/usr/bin/env bash

set -euo pipefail

compose_file="${COMPOSE_FILE:-compose.production.yaml}"
base_url="${ERIN_SMOKE_BASE_URL:?ERIN_SMOKE_BASE_URL fehlt}"

docker compose -f "$compose_file" exec -T php-fpm php artisan about --only=environment
docker compose -f "$compose_file" exec -T php-fpm php artisan erin:ops:queue-health --json
docker compose -f "$compose_file" exec -T php-fpm php artisan schedule:list
docker compose -f "$compose_file" exec -T php-fpm php artisan storage:link >/dev/null 2>&1 || true

curl --fail --silent --show-error --max-time 10 "${base_url%/}/up" >/dev/null
curl --fail --silent --show-error --max-time 10 "${base_url%/}/login" >/dev/null
curl --fail --silent --show-error --max-time 10 \
    -H "Accept: application/json" \
    "${base_url%/}/health/ready" >/dev/null
