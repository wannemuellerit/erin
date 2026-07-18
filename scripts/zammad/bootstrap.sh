#!/usr/bin/env bash

set -Eeuo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=common.sh
source "${SCRIPT_DIR}/common.sh"

require_zammad_env
"${SCRIPT_DIR}/healthcheck.sh"

admin_email="$(env_value ZAMMAD_BOOTSTRAP_ADMIN_EMAIL)"
integration_email="$(env_value ZAMMAD_INTEGRATION_EMAIL)"
group_name="$(env_value ZAMMAD_GROUP)"
callback_url="$(env_value ZAMMAD_WEBHOOK_CALLBACK_URL)"
webhook_secret="$(env_value ZAMMAD_WEBHOOK_SECRET)"

if [[ -z "${admin_email}" ]]; then
    echo "ZAMMAD_BOOTSTRAP_ADMIN_EMAIL fehlt in docker/zammad/.env." >&2
    echo "Trage die E-Mail des im Browser eingerichteten Administrators ein." >&2
    exit 1
fi
if [[ -z "${integration_email}" ]]; then
    echo "ZAMMAD_INTEGRATION_EMAIL fehlt in docker/zammad/.env." >&2
    exit 1
fi
if [[ -z "${group_name}" || -z "${callback_url}" || ${#webhook_secret} -lt 32 ]]; then
    echo "Gruppe, Callback oder Webhook-Secret sind unvollständig." >&2
    exit 1
fi

bootstrap_output="$(
    zammad_compose exec -T \
        -e ERIN_ZAMMAD_ADMIN_EMAIL="${admin_email}" \
        -e ERIN_ZAMMAD_INTEGRATION_EMAIL="${integration_email}" \
        -e ERIN_ZAMMAD_GROUP="${group_name}" \
        -e ERIN_ZAMMAD_CALLBACK_URL="${callback_url}" \
        -e ERIN_ZAMMAD_WEBHOOK_SECRET="${webhook_secret}" \
        zammad-railsserver \
        bundle exec rails runner /opt/erin/bootstrap.rb
)"
token="$(printf '%s\n' "${bootstrap_output}" | sed -n 's/^ERIN_ZAMMAD_TOKEN=//p' | tail -n 1)"
unset bootstrap_output

if [[ -z "${token}" ]]; then
    echo "Zammad hat kein API-Token für Erin zurückgegeben." >&2
    exit 1
fi

runtime_dir="${ZAMMAD_DIR}/runtime"
runtime_env="${runtime_dir}/erin.env"
mkdir -p "${runtime_dir}"
chmod 700 "${runtime_dir}"
umask 077
{
    printf 'ZAMMAD_ENABLED=true\n'
    printf 'ZAMMAD_URL=http://zammad:8080\n'
    printf 'ZAMMAD_TOKEN=%s\n' "${token}"
    printf 'ZAMMAD_GROUP=%s\n' "${group_name}"
    printf 'ZAMMAD_WEBHOOK_SECRET=%s\n' "${webhook_secret}"
    printf 'ZAMMAD_TIMEOUT=10\n'
    printf 'ZAMMAD_ALLOW_LOCAL_HTTP=true\n'
    printf 'ZAMMAD_LOCAL_HTTP_HOSTS=zammad,laravel\n'
    printf 'ZAMMAD_WEBHOOK_CALLBACK_URL=%s\n' "${callback_url}"
} > "${runtime_env}"
chmod 600 "${runtime_env}"

if [[ ! -f "${ERIN_ROOT}/.env" ]]; then
    echo "Erin-.env fehlt; die Integrationswerte liegen in docker/zammad/runtime/erin.env." >&2
    exit 1
fi

while IFS='=' read -r key value; do
    [[ -z "${key}" ]] && continue
    set_env_value "${ERIN_ROOT}/.env" "${key}" "${value}"
done < "${runtime_env}"

unset token

docker compose --project-directory "${ERIN_ROOT}" exec -T laravel php artisan optimize:clear
docker compose --project-directory "${ERIN_ROOT}" restart laravel queue

echo "Zammad-Gruppe, technischer Benutzer, API-Token, Webhook und Trigger sind eingerichtet."
echo "Erin wurde lokal konfiguriert; sensible Werte stehen nur in ignorierten Dateien."
echo "Prüfung: docker compose exec -T laravel php artisan erin:zammad:smoke"
