#!/bin/sh
set -e

if [ "$APP_ENV" = "production" ]; then
    php artisan optimize
fi
php artisan db:wait --allow-missing-db
if [ "$ENABLE_DATABASE_MIGRATION" = "true" ]; then
    php artisan migrate --force
fi

if [ "$OCTANE_ENABLED" = "true" ]; then
    # Octane worker mode: ~6x better performance, app bootstraps once
    exec php artisan octane:frankenphp --host=0.0.0.0 --port=2226 --workers="${OCTANE_WORKERS:-2}" --max-requests="${OCTANE_MAX_REQUESTS:-500}"
else
    # Classic mode: easier debugging, no state persistence issues
    exec frankenphp run --config /etc/frankenphp/Caddyfile --adapter caddyfile
fi
