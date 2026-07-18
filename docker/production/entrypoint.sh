#!/usr/bin/env sh

set -eu

if [ "${APP_ENV:-production}" = "production" ] && [ "${APP_DEMO_MODE:-false}" = "true" ]; then
    echo "APP_DEMO_MODE darf in der Produktion nicht aktiviert sein." >&2
    exit 1
fi

if [ "${APP_ENV:-production}" = "production" ]; then
    if [ ! -r /app/.erin-build-sha ]; then
        echo "Der eingebettete Build-SHA fehlt." >&2
        exit 1
    fi

    if [ ! -r /app/.erin-governance-trust-root-sha256 ]; then
        echo "Der eingebettete Governance-Trust-Root-Fingerprint fehlt." >&2
        exit 1
    fi

    governance_trust_root_sha256="$(cat /app/.erin-governance-trust-root-sha256)"
    if ! printf '%s' "$governance_trust_root_sha256" | grep -Eq '^[0-9a-f]{64}$'; then
        echo "Der eingebettete Governance-Trust-Root-Fingerprint ist ungültig." >&2
        exit 1
    fi

    image_build_sha="$(cat /app/.erin-build-sha)"
    if ! printf '%s' "$image_build_sha" | grep -Eq '^[0-9a-f]{40}$'; then
        echo "Der eingebettete Build-SHA ist ungültig." >&2
        exit 1
    fi

    if [ "${ERIN_BUILD_SHA:-}" != "$image_build_sha" ] || [ "${ERIN_APP_TAG:-}" != "$image_build_sha" ]; then
        echo "Build-SHA, Laufzeit-SHA und unveränderliches Image-Tag stimmen nicht überein." >&2
        exit 1
    fi
fi

mkdir -p \
    storage/framework/cache \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache

php artisan config:cache
php artisan event:cache

if [ "${1:-}" = "php-fpm" ]; then
    php artisan view:cache
fi

exec "$@"
