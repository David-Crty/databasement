#!/bin/sh
set -e

if [ "$PHP_ENV" = "prod" ]; then
    php artisan optimize
    php artisan migrate --force
fi
docker-php-entrypoint --config /etc/frankenphp/Caddyfile --adapter caddyfile
