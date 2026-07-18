#!/usr/bin/env bash

set -euo pipefail

project_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$project_dir"

if [[ "${ERIN_PILOT_DRILL_CONFIRM:-}" != "SYNTHETIC_LOCAL_ONLY" ]]; then
    echo "Setze ERIN_PILOT_DRILL_CONFIRM=SYNTHETIC_LOCAL_ONLY." >&2
    exit 2
fi

scenario="${1:-pass}"

docker compose exec -T laravel php artisan erin:ops:pilot-drill \
    --scenario="$scenario" \
    --confirm=SYNTHETIC_LOCAL_ONLY \
    --json
