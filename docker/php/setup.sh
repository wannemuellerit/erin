#!/usr/bin/env sh

set -eu

composer install --no-interaction --prefer-dist

if [ ! -f .env ]; then
    cp .env.example .env
fi

if grep -q '^APP_KEY=$' .env; then
    php artisan key:generate --no-interaction
fi

if ! grep -q '^VAPID_SUBJECT=' .env; then
    printf '\nVAPID_SUBJECT=mailto:noreply@wannemueller.dev\n' >> .env
fi

if ! grep -q '^VAPID_PUBLIC_KEY=' .env; then
    printf 'VAPID_PUBLIC_KEY=\n' >> .env
fi

if ! grep -q '^VAPID_PRIVATE_KEY=' .env; then
    printf 'VAPID_PRIVATE_KEY=\n' >> .env
fi

if ! grep -q '^VITE_VAPID_PUBLIC_KEY=' .env; then
    printf 'VITE_VAPID_PUBLIC_KEY="${VAPID_PUBLIC_KEY}"\n' >> .env
fi

if grep -q '^VAPID_PUBLIC_KEY=$' .env || grep -q '^VAPID_PRIVATE_KEY=$' .env; then
    php artisan webpush:vapid --force --no-interaction
fi

php artisan wayfinder:generate --with-form
php artisan migrate --force --isolated --no-interaction
