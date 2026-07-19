#!/usr/bin/env sh

set -eu

php artisan db:seed --force
php artisan db:seed --class=Database\\Seeders\\BrowserTestSeeder --force
