#!/usr/bin/env sh

set -eu

if [ "${APP_ENV:-production}" = "production" ] && [ "${APP_DEMO_MODE:-false}" = "true" ]; then
    echo "APP_DEMO_MODE darf in der Produktion nicht aktiviert sein." >&2
    exit 1
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
