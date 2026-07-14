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

# Fail fast when no application encryption key is configured. Databasement uses
# APP_KEY to encrypt stored database credentials, SSH private keys and cloud
# secrets, so it must be a unique, secret value per deployment (never the repo
# default). Accept it either as a real environment variable or from .env.
if [ -z "${APP_KEY:-}" ] && ! grep -qE '^APP_KEY=base64:' /app/.env 2>/dev/null; then
    echo "ERROR: APP_KEY is not set." >&2
    echo "Generate one with: docker run --rm davidcrty/databasement:latest php artisan key:generate --show" >&2
    echo "Then pass it as the APP_KEY environment variable (or set it in .env)." >&2
    exit 1
fi

exec "$@"
