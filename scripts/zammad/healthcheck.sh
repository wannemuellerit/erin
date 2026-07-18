#!/usr/bin/env bash

set -Eeuo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=common.sh
source "${SCRIPT_DIR}/common.sh"

require_command curl
require_zammad_env

timeout_seconds="${ZAMMAD_HEALTH_TIMEOUT:-600}"
started_at="${SECONDS}"
forward_port="$(env_value ZAMMAD_FORWARD_PORT)"
forward_port="${forward_port:-8090}"

while (( SECONDS - started_at < timeout_seconds )); do
    init_container="$(zammad_compose ps -a -q zammad-init 2>/dev/null || true)"
    nginx_container="$(zammad_compose ps -q zammad-nginx 2>/dev/null || true)"

    init_ready=false
    nginx_ready=false

    if [[ -n "${init_container}" ]]; then
        init_state="$(docker inspect \
            --format '{{.State.Status}}:{{.State.ExitCode}}' \
            "${init_container}" 2>/dev/null || true)"
        [[ "${init_state}" == "exited:0" ]] && init_ready=true
    fi

    if [[ -n "${nginx_container}" ]]; then
        nginx_state="$(docker inspect \
            --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}' \
            "${nginx_container}" 2>/dev/null || true)"
        [[ "${nginx_state}" == "healthy" ]] && nginx_ready=true
    fi

    if [[ "${init_ready}" == true && "${nginx_ready}" == true ]] \
        && curl --fail --silent --show-error \
            --max-time 5 \
            "http://127.0.0.1:${forward_port}/" >/dev/null; then
        echo "Zammad ist gesund und unter http://localhost:${forward_port} erreichbar."
        exit 0
    fi

    sleep 5
done

echo "Zammad wurde innerhalb von ${timeout_seconds} Sekunden nicht gesund." >&2
zammad_compose ps >&2 || true
echo "Details: scripts/zammad/logs.sh" >&2
exit 1
