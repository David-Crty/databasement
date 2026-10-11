#!/bin/sh
# Default startup script for Databasement container
# PUID/PGID are set via ENV in Dockerfile (default: 1000)

set -e

if [ "$(id -u)" = "0" ]; then
    USERHOME=$(grep application /etc/passwd | cut -d ":" -f6)

    echo "Setting up user application with PUID=${PUID} PGID=${PGID}..."
    groupmod -o -g "${PGID}" application
    usermod -o -u "${PUID}" application
    usermod -d "${USERHOME}" application

    if [ "${ENABLE_WEB_SERVER}" != "false" ]; then
        /usr/local/bin/fix-permissions.sh -R application:application /data
    fi
    /usr/local/bin/fix-permissions.sh -R application:application "${USERHOME}"

    if [ -n "${DATABASEMENT_URL:-}" ]; then
        echo "Agent mode"
        exec su -m -s /bin/sh application -c 'exec php artisan agent:run'
    fi

    export SUPERVISOR_USER="root"
    export APP_USER="application"
else
    if [ -n "${DATABASEMENT_URL:-}" ]; then
        echo "Agent mode"
        exec php artisan agent:run
    fi

    echo "Not running as root, skipping permission fix..."
    sed -i '/^user=/d' /config/supervisord.conf
fi

exec /usr/bin/supervisord -c /config/supervisord.conf
