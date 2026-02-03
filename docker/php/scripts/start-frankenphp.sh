#!/bin/sh
set -e

if [ "$APP_ENV" = "production" ]; then
    php artisan optimize
fi
php artisan db:wait --allow-missing-db
if [ "$ENABLE_DATABASE_MIGRATION" = "true" ]; then
    php artisan migrate --force
fi

if [ "$APP_ENV" = "production" ]; then
    # Production: Use Octane worker mode for ~4x better performance
    php artisan octane:frankenphp --host=0.0.0.0 --port=2226 --workers="${OCTANE_WORKERS:-auto}" --max-requests="${OCTANE_MAX_REQUESTS:-500}"
else
    # Development: Use classic mode (easier debugging, no state persistence issues)
    frankenphp run --config /etc/frankenphp/Caddyfile --adapter caddyfile
fi
