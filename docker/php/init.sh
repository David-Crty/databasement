#!/bin/sh
set -e

if [ "$APP_ENV" = "production" ]; then
    php artisan optimize
fi

php artisan migrate --force
php artisan scribe:generate
docker-php-entrypoint --config /etc/frankenphp/Caddyfile --adapter caddyfile
