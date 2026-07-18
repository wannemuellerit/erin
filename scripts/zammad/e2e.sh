#!/usr/bin/env bash

set -Eeuo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=common.sh
source "${SCRIPT_DIR}/common.sh"

require_command curl
require_command jq
require_command base64
require_command sha256sum
require_zammad_env
"${SCRIPT_DIR}/healthcheck.sh"

if [[ "$(env_value ZAMMAD_ENABLED "${ERIN_ROOT}/.env")" != "true" ]]; then
    echo "Die lokale Erin-Zammad-Integration ist nicht aktiviert." >&2
    echo "Führe zuerst scripts/zammad/bootstrap.sh aus." >&2
    exit 1
fi

docker compose --project-directory "${ERIN_ROOT}" exec -T \
    laravel php scripts/zammad/e2e.php prepare

state_file="${ZAMMAD_DIR}/runtime/e2e-state.json"
token="$(env_value ZAMMAD_TOKEN "${ERIN_ROOT}/.env")"
external_ticket_id="$(jq -er '.external_ticket_id' "${state_file}")"
marker="$(jq -er '.marker' "${state_file}")"
reply_body="Öffentliche Zammad-E2E-Antwort ${marker}."
reply_contents="%PDF-1.4
% Zammad zu Erin ${marker}
%%EOF"
reply_checksum="$(printf '%s' "${reply_contents}" | sha256sum | cut -d' ' -f1)"
reply_data="$(printf '%s' "${reply_contents}" | base64 -w 0)"
reply_payload="$(jq -nc \
    --argjson ticket_id "${external_ticket_id}" \
    --arg body "${reply_body}" \
    --arg data "${reply_data}" \
    '{
        ticket_id: $ticket_id,
        subject: "Automatische öffentliche E2E-Antwort",
        body: $body,
        content_type: "text/plain",
        type: "note",
        internal: false,
        sender: "Agent",
        attachments: [{
            filename: "zammad-e2e-antwort.pdf",
            data: $data,
            "mime-type": "application/pdf"
        }]
    }'
)"
response_file="${ZAMMAD_DIR}/runtime/e2e-zammad-response.json"

curl --fail-with-body --silent --show-error \
    --config <(printf 'header = "Authorization: Token token=%s"\n' "${token}") \
    --header "Content-Type: application/json" \
    --request POST \
    --data-binary "${reply_payload}" \
    "http://127.0.0.1:$(env_value ZAMMAD_FORWARD_PORT)/api/v1/ticket_articles" \
    > "${response_file}"
chmod 600 "${response_file}"
external_reply_article_id="$(jq -er '.id' "${response_file}")"

jq \
    --arg article_id "${external_reply_article_id}" \
    --arg body "${reply_body}" \
    --arg checksum "${reply_checksum}" \
    '. + {
        external_reply_article_id: $article_id,
        reply_body: $body,
        reply_attachment_sha256: $checksum
    }' "${state_file}" > "${state_file}.tmp"
chmod 600 "${state_file}.tmp"
mv "${state_file}.tmp" "${state_file}"

verified=false
for _ in $(seq 1 60); do
    if docker compose --project-directory "${ERIN_ROOT}" exec -T \
        laravel php scripts/zammad/e2e.php verify; then
        verified=true
        break
    fi
    sleep 1
done

unset token reply_data reply_payload
if [[ "${verified}" != true ]]; then
    echo "Die Zammad-Antwort oder ihr Anhang wurde nicht innerhalb von 60 Sekunden nach Erin importiert." >&2
    echo "Prüfe Queue, Zammad-Scheduler und Webhook mit scripts/zammad/logs.sh." >&2
    exit 1
fi

echo "Der echte lokale Ticket-, Antwort-, Webhook-, ClamAV- und Anhangsfluss war erfolgreich."
