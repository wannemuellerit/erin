#!/usr/bin/env bash

set -euo pipefail

project_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$project_dir"

echo "Starte reproduzierbare 500er-CSV-/XLSX- und Missbrauchstests ..."
docker compose --profile tools run --rm pest \
    tests/Feature/Operations/ImportLoadAndAbuseTest.php \
    --group=ops

echo "Prüfe die aktuelle Queue gegen die konfigurierten Rückstaugrenzen ..."
docker compose exec -T laravel php artisan erin:ops:queue-health --json
