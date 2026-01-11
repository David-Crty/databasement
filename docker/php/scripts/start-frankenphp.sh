#!/bin/sh
set -e

if [ "$APP_ENV" = "production" ]; then
    php artisan optimize
fi
php artisan db:wait --allow-missing-db
if [ "$ENABLE_DATABASE_MIGRATION" = "true" ]; then
    php artisan migrate --force
fi
frankenphp run --config /etc/frankenphp/Caddyfile --adapter caddyfile
