#!/bin/sh
set -e

# Default UID/GID if not set
UID="${UID:-1000}"
GID="${GID:-1000}"

# Check if running as root
if [ "$(id -u)" = "0" ]; then
    echo "Container started as root, setting up user and permissions..."

    # If using custom UID/GID, we need to update the existing application user
    if [ "$UID" != "1000" ] || [ "$GID" != "1000" ]; then
        echo "Custom UID/GID detected, updating application user..."

        # Delete existing application user and group
        deluser application 2>/dev/null || true
        delgroup application 2>/dev/null || true

        # Create group with new GID
        addgroup -g "$GID" application 2>/dev/null || true

        # Create user with new UID
        adduser -u "$UID" -G application -D -h /home/application application 2>/dev/null || true
    fi

    # Get the username and group name for this UID/GID
    USER_NAME=$(getent passwd "$UID" | cut -d: -f1)
    GROUP_NAME=$(getent group "$GID" | cut -d: -f1)

    if [ -z "$USER_NAME" ]; then
        USER_NAME="application"
    fi
    if [ -z "$GROUP_NAME" ]; then
        GROUP_NAME="application"
    fi

    echo "Using UID=$UID, GID=$GID (user: $USER_NAME, group: $GROUP_NAME)"

    # Export for supervisor to use in program configs
    export APP_USER="$USER_NAME"

    # Fix permissions on /data directory
    echo "Fixing permissions on /data..."
    chown -R "$UID:$GID" /data 2>/dev/null || true
    chmod -R 755 /data 2>/dev/null || true

    # Fix permissions on /data/caddy (FrankenPHP needs this)
    if [ -d "/data/caddy" ]; then
        chown -R "$UID:$GID" /data/caddy 2>/dev/null || true
    fi

    # Fix permissions on /tmp/backups
    chown -R "$UID:$GID" /tmp/backups 2>/dev/null || true

    # Fix permissions on /config/caddy
    if [ -d "/config/caddy" ]; then
        chown -R "$UID:$GID" /config/caddy 2>/dev/null || true
    fi

    # Fix permissions on /app (for composer/npm caches, storage, etc.)
    if [ -d "/app/storage" ]; then
        chown -R "$UID:$GID" /app/storage 2>/dev/null || true
    fi
    if [ -d "/app/bootstrap/cache" ]; then
        chown -R "$UID:$GID" /app/bootstrap/cache 2>/dev/null || true
    fi

    echo "Permissions fixed. Supervisor will run processes as $USER_NAME..."

    # Execute the command as root (supervisor will drop privileges per-program)
    exec "$@"
else
    echo "Container started as non-root user (UID=$(id -u)), skipping permission fix..."
    export APP_USER="$(whoami)"
    exec "$@"
fi
