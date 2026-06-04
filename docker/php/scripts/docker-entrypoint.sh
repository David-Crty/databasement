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

exec "$@"
