---
sidebar_position: 7
---

# NAS Platforms

This guide provides platform-specific instructions for deploying Databasement on popular NAS (Network Attached Storage) and home server systems.

## Overview

NAS platforms typically use bind mounts instead of Docker volumes, which requires matching the container's user ID with the host system's permissions. Databasement makes this easy with `UID` and `GID` environment variables that automatically configure the correct permissions.


## Common Configuration

### Generate APP_KEY

Before deploying, generate an application key:

```bash
docker run --rm davidcrty/databasement:latest php artisan key:generate --show
```

### Required Environment Variables

All platforms need these environment variables:

| Variable              | Value                                    |
|-----------------------|------------------------------------------|
| `APP_KEY`             | Your generated key (from above)          |
| `DB_CONNECTION`       | `sqlite`                                 |
| `DB_DATABASE`         | `/data/database.sqlite`                  |
| `ENABLE_QUEUE_WORKER` | `true`                                   |
| `TZ`                  | Your timezone (e.g., `America/New_York`) |

For MySQL/PostgreSQL configuration, see the [configuration guide](./configuration.md#database-configuration).

### Default UID/GID by Platform

| Platform        | UID   | GID   | Environment Variables    |
|-----------------|-------|-------|--------------------------|
| Unraid          | 99    | 100   | `UID=99` `GID=100`       |
| Synology        | 1000  | 1000  | `UID=1000` `GID=1000`    |
| TrueNAS SCALE   | 568   | 568   | `UID=568` `GID=568`      |
| QNAP            | 500   | 100   | `UID=500` `GID=100`      |
| OpenMediaVault  | 1000  | 100   | `UID=1000` `GID=100`     |

## Unraid

Unraid runs containers as `nobody:users` (UID 99, GID 100) by default.

### Setup

1. Add a new container with repository: `davidcrty/databasement:latest`
2. Configure port: `2226` → `2226`
3. Add path mapping: `/mnt/user/appdata/databasement` → `/data`
4. Add environment variables:
   - `UID` = `99`
   - `GID` = `100`
   - Plus all [required environment variables](#required-environment-variables)

## Synology DSM

Synology DSM typically uses UID/GID `1000` for the first user, which matches the container's default.

### Setup (Container Manager - DSM 7.2+)

1. Open **Container Manager** → **Registry**
2. Search for `davidcrty/databasement` and download
3. Create container:
   - **Port**: `2226` → `2226`
   - **Volume**: `/docker/databasement` → `/data`
   - **Environment**: Add [required variables](#required-environment-variables)
   - **Enable auto-restart**: Yes

### Custom User ID

If you need a different user, find your UID via SSH (`id your-username`), then add `UID` and `GID` environment variables.

## TrueNAS SCALE

TrueNAS SCALE uses `apps` user (UID 568) by default for applications.

### Setup

1. Go to **Apps** → **Discover Apps** → **Custom App**
2. Follow the [Docker Compose guide](./docker-compose.md), adding `UID: 568` and `GID: 568` to environment variables for both `app` and `worker` services
3. Volume: `/mnt/pool/apps/databasement` → `/data`
4. Add [environment variables](#required-environment-variables)

## QNAP

QNAP Container Station supports Docker containers with custom configurations.

### Setup (Container Station)

1. Open **Container Station** → **Create**
2. Search for `davidcrty/databasement`
3. Configure:
   - **Port**: `2226` → `2226`
   - **Volume**: `/Container/databasement` → `/data`
   - **Environment**: Add `UID=500`, `GID=100`, plus [required variables](#required-environment-variables)

## OpenMediaVault

OpenMediaVault uses Docker via omv-extras plugin. Follow the [Docker Compose guide](./docker-compose.md) with `UID: 1000` and `GID: 100` environment variables (adjust with `id your-username`).

## Proxmox (LXC)

For Proxmox, run Databasement in an LXC container or VM with Docker.

Proxmox delegates UID/GID to the LXC container or VM, so set the `UID` and `GID` environment variables to match the user running Docker inside your container.

### Setup

1. Create an unprivileged LXC (Debian 12 or Ubuntu 22.04)
2. Enable `nesting=1` feature
3. Install Docker inside the LXC
4. Follow the standard [Docker guide](./docker.md)

## Troubleshooting

### Permission Denied Errors

If you encounter permission issues:

1. Check the container logs for permission-related errors:
   ```bash
   docker logs databasement
   ```

2. Verify the `UID` and `GID` environment variables match your platform's requirements (see [table above](#default-uidgid-by-platform))

3. If using `--user` flag instead of `UID`/`GID` env vars, you must manually set permissions:
   ```bash
   sudo chown -R UID:GID /path/to/databasement
   ```

### Verify Container User

Check which user the container processes are running as:

```bash
docker exec databasement ps aux
```
