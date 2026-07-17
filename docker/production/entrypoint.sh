#!/usr/bin/env sh

set -eu

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
