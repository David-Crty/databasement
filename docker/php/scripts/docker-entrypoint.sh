#!/bin/sh
set -e

# Normalize timezone configuration before launching any child process:
#   - APP_DISPLAY_TIMEZONE drives all user-facing display in the app.
#   - For backwards compatibility, fall back to TZ if it was set the old way.
#   - Then force the container's system TZ to UTC so libc-based output
#     (mariadb-dump comments, log timestamps, etc.) matches storage, which
#     Laravel always keeps in UTC (see config/app.php).
if [ -z "${APP_DISPLAY_TIMEZONE:-}" ] && [ -n "${TZ:-}" ] && [ "${TZ}" != "UTC" ]; then
    export APP_DISPLAY_TIMEZONE="${TZ}"
fi
export TZ=UTC

# A worker started with `command: ... php artisan queue:work ...` would bypass
# start.sh and run as root. Run it as a worker-only supervisord instead.
case "$*" in
    *"artisan queue:work"*)
        echo "WARNING: Overriding the command to run the queue worker is deprecated and its arguments are ignored." >&2
        echo "WARNING: Remove 'command:' from the worker service and set ENABLE_QUEUE_WORKER=true, ENABLE_WEB_SERVER=false, ENABLE_SCHEDULER=false instead." >&2
        echo "WARNING: See https://david-crty.github.io/databasement/self-hosting/migrate-to-1-10" >&2
        export ENABLE_QUEUE_WORKER=true ENABLE_WEB_SERVER=false ENABLE_SCHEDULER=false
        exec /usr/local/bin/start.sh
        ;;
esac

exec "$@"
