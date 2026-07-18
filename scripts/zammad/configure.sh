#!/usr/bin/env bash

set -Eeuo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=common.sh
source "${SCRIPT_DIR}/common.sh"

require_command openssl
umask 077

with_admin=false
if [[ "${1:-}" == "--with-admin" ]]; then
    with_admin=true
elif [[ $# -gt 0 ]]; then
    echo "Verwendung: scripts/zammad/configure.sh [--with-admin]" >&2
    exit 2
fi

if [[ ! -f "${ZAMMAD_ENV_FILE}" ]]; then
    cp "${ZAMMAD_DIR}/.env.example" "${ZAMMAD_ENV_FILE}"
    chmod 600 "${ZAMMAD_ENV_FILE}"
fi

postgres_password="$(env_value POSTGRES_PASS)"
if [[ -z "${postgres_password}" || "${postgres_password}" == "CHANGE_ME" ]]; then
    set_env_value "${ZAMMAD_ENV_FILE}" POSTGRES_PASS "$(openssl rand -hex 32)"
fi

webhook_secret="$(env_value ZAMMAD_WEBHOOK_SECRET)"
if [[ -z "${webhook_secret}" || "${webhook_secret}" == "CHANGE_ME" ]]; then
    set_env_value "${ZAMMAD_ENV_FILE}" ZAMMAD_WEBHOOK_SECRET "$(openssl rand -hex 32)"
fi

if [[ "${with_admin}" == true ]]; then
    require_command python3

    admin_email="${ZAMMAD_ADMIN_EMAIL:-}"
    admin_password="${ZAMMAD_ADMIN_PASSWORD:-}"
    integration_email="${ZAMMAD_INTEGRATION_EMAIL:-}"

    if [[ -t 0 ]]; then
        if [[ -z "${admin_email}" ]]; then
            read -r -p "Zammad-Administrator-E-Mail: " admin_email
        fi
        if [[ -z "${admin_password}" ]]; then
            read -r -s -p "Zammad-Administrator-Passwort: " admin_password
            echo
        fi
        if [[ -z "${integration_email}" ]]; then
            read -r -p "E-Mail des technischen Erin-Benutzers: " integration_email
        fi
    fi

    if [[ ! "${admin_email}" =~ ^[^[:space:]@]+@[^[:space:]@]+\.[^[:space:]@]+$ ]]; then
        echo "Die Administrator-E-Mail ist ungültig." >&2
        exit 1
    fi
    if (( ${#admin_password} < 12 )); then
        echo "Das Administrator-Passwort muss mindestens 12 Zeichen lang sein." >&2
        exit 1
    fi
    if [[ ! "${integration_email}" =~ ^[^[:space:]@]+@[^[:space:]@]+\.[^[:space:]@]+$ ]]; then
        echo "Die E-Mail des technischen Benutzers ist ungültig." >&2
        exit 1
    fi

    autowizard="$(
        ZAMMAD_ADMIN_EMAIL="${admin_email}" \
        ZAMMAD_ADMIN_PASSWORD="${admin_password}" \
        python3 - <<'PY'
import base64
import json
import os

payload = {
    "TextModuleLocale": {"Locale": "de-de"},
    "Users": [
        {
            "login": os.environ["ZAMMAD_ADMIN_EMAIL"],
            "firstname": "Erin",
            "lastname": "Administrator",
            "email": os.environ["ZAMMAD_ADMIN_EMAIL"],
            "password": os.environ["ZAMMAD_ADMIN_PASSWORD"],
        }
    ],
    "Settings": [
        {"name": "product_name", "value": "Erin Support"},
        {"name": "system_online_service", "value": False},
    ],
}
encoded = base64.b64encode(
    json.dumps(payload, separators=(",", ":")).encode("utf-8")
)
print(encoded.decode("ascii"))
PY
    )"

    set_env_value "${ZAMMAD_ENV_FILE}" ZAMMAD_BOOTSTRAP_ADMIN_EMAIL "${admin_email}"
    set_env_value "${ZAMMAD_ENV_FILE}" ZAMMAD_BOOTSTRAP_ADMIN_PASSWORD ""
    set_env_value "${ZAMMAD_ENV_FILE}" ZAMMAD_INTEGRATION_EMAIL "${integration_email}"
    set_env_value "${ZAMMAD_ENV_FILE}" AUTOWIZARD_JSON "${autowizard}"

    unset admin_password ZAMMAD_ADMIN_PASSWORD
fi

echo "Lokale Zammad-Konfiguration ist vorbereitet: docker/zammad/.env"
echo "Die Datei ist von Git ausgeschlossen und nur für den aktuellen Benutzer lesbar."
if [[ "${with_admin}" == false ]]; then
    echo "Ohne --with-admin wird das Administratorkonto beim ersten Browser-Aufruf eingerichtet."
fi
