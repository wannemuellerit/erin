#!/usr/bin/env bash

set -euo pipefail

project_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$project_dir"

compose_file="${COMPOSE_FILE:-compose.production.yaml}"
app_service="${ERIN_APP_SERVICE:-php-fpm}"

docker compose -f "$compose_file" exec -T "$app_service" \
    php artisan erin:ops:readiness --strict --probe --json

docker compose -f "$compose_file" exec -T "$app_service" \
    php artisan erin:ops:security-audit --json

docker compose -f "$compose_file" exec -T "$app_service" \
    php artisan erin:stripe:staging-check --remote

docker compose -f "$compose_file" exec -T "$app_service" \
    php artisan erin:zammad:smoke

docker compose -f "$compose_file" exec -T "$app_service" \
    php artisan erin:ops:queue-health --json

echo "Technische Release-Gates und strukturierte Evidenzprüfung sind grün."
