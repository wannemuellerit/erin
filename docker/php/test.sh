#!/bin/sh
set -eu

if [ "${APP_ENV:-}" != "testing" ] || [ "${DB_DATABASE:-}" != "erin_testing" ]; then
    echo "Refusing to reset a database outside the dedicated test environment." >&2
    exit 1
fi

php artisan migrate:fresh --force --no-interaction
exec vendor/bin/pest --colors=always "$@"
