#!/usr/bin/env bash

set -Eeuo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=common.sh
source "${SCRIPT_DIR}/common.sh"

require_zammad_env

postgres_password="$(env_value POSTGRES_PASS)"
webhook_secret="$(env_value ZAMMAD_WEBHOOK_SECRET)"
if [[ -z "${postgres_password}" || "${postgres_password}" == "CHANGE_ME" ]]; then
    echo "POSTGRES_PASS ist nicht sicher konfiguriert." >&2
    echo "Führe scripts/zammad/configure.sh aus." >&2
    exit 1
fi
if (( ${#webhook_secret} < 32 )); then
    echo "ZAMMAD_WEBHOOK_SECRET muss mindestens 32 Zeichen lang sein." >&2
    echo "Führe scripts/zammad/configure.sh aus." >&2
    exit 1
fi

erin_network="$(env_value ERIN_DOCKER_NETWORK)"
erin_network="${erin_network:-erin_default}"
if ! docker network inspect "${erin_network}" >/dev/null 2>&1; then
    echo "Das Erin-Netzwerk ${erin_network} existiert noch nicht." >&2
    echo "Starte zuerst den Erin-Stack mit: docker compose up -d" >&2
    exit 1
fi

if [[ -r /proc/sys/vm/max_map_count ]]; then
    max_map_count="$(< /proc/sys/vm/max_map_count)"
    if (( max_map_count < 262144 )); then
        echo "Elasticsearch benötigt vm.max_map_count >= 262144." >&2
        echo "Einmalig auf dem Docker-Host ausführen:" >&2
        echo "  sudo sysctl -w vm.max_map_count=262144" >&2
        exit 1
    fi
fi

zammad_compose up -d
"${SCRIPT_DIR}/healthcheck.sh"

# The official image consumes AUTOWIZARD_JSON only during the first database
# initialization. Remove the encoded password from the local env afterwards.
if [[ -n "$(env_value AUTOWIZARD_JSON)" ]]; then
    set_env_value "${ZAMMAD_ENV_FILE}" AUTOWIZARD_JSON ""
fi

echo "Zammad-Login: http://localhost:$(env_value ZAMMAD_FORWARD_PORT)"
echo "Führe nach der Ersteinrichtung scripts/zammad/bootstrap.sh aus."
