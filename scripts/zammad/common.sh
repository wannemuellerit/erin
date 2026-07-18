#!/usr/bin/env bash

set -Eeuo pipefail

ZAMMAD_SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
ERIN_ROOT="$(cd -- "${ZAMMAD_SCRIPT_DIR}/../.." && pwd)"
ZAMMAD_DIR="${ERIN_ROOT}/docker/zammad"
ZAMMAD_ENV_FILE="${ZAMMAD_DIR}/.env"
ZAMMAD_COMPOSE_FILE="${ZAMMAD_DIR}/compose.yaml"

zammad_compose() {
    docker compose \
        --project-directory "${ZAMMAD_DIR}" \
        --env-file "${ZAMMAD_ENV_FILE}" \
        -f "${ZAMMAD_COMPOSE_FILE}" \
        "$@"
}

require_zammad_env() {
    if [[ ! -f "${ZAMMAD_ENV_FILE}" ]]; then
        echo "Die lokale Zammad-Konfiguration fehlt." >&2
        echo "Führe zuerst scripts/zammad/configure.sh aus." >&2
        exit 1
    fi
}

env_value() {
    local key="$1"
    local file="${2:-${ZAMMAD_ENV_FILE}}"

    awk -F= -v key="${key}" '
        $1 == key {
            sub(/^[^=]*=/, "")
            print
            exit
        }
    ' "${file}"
}

set_env_value() {
    local file="$1"
    local key="$2"
    local value="$3"
    local temporary

    temporary="$(mktemp "${file}.XXXXXX")"
    awk -v key="${key}" -v value="${value}" '
        BEGIN {
            replaced = 0
        }
        index($0, key "=") == 1 {
            print key "=" value
            replaced = 1
            next
        }
        {
            print
        }
        END {
            if (!replaced) {
                print key "=" value
            }
        }
    ' "${file}" > "${temporary}"
    chmod --reference="${file}" "${temporary}" 2>/dev/null || chmod 600 "${temporary}"
    mv "${temporary}" "${file}"
}

require_command() {
    local command_name="$1"

    if ! command -v "${command_name}" >/dev/null 2>&1; then
        echo "Erforderlicher Befehl fehlt: ${command_name}" >&2
        exit 1
    fi
}
