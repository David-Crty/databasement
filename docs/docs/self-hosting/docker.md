---
sidebar_position: 3
---

# Docker

This guide will help you deploy Databasement using Docker. This is the simplest deployment method, using a single container that includes everything you need.

## Prerequisites

- [Docker](https://docs.docker.com/engine/install/) installed on your system
- A [supported application database](intro#supported-application-database-versions) (SQLite, MySQL, MariaDB, or PostgreSQL)

## Quick Start (SQLite)

### 1. Create Project Directory

```bash
mkdir databasement && cd databasement
```

### 2. Generate Application Key

```bash
APP_KEY=$(docker run --rm davidcrty/databasement:1 php artisan key:generate --show)
```

### 3. Create Environment File

Create a `.env` file with your configuration:

```bash title=".env"
APP_URL=http://localhost:2226
APP_KEY=base64:your-generated-key-here

# Database (SQLite)
DB_CONNECTION=sqlite
DB_DATABASE=/data/database.sqlite

# Enable the background queue worker inside the container
ENABLE_QUEUE_WORKER=true
```

### 4. Run the Container

```bash
docker run -d \
  --name databasement \
  -p 2226:2226 \
  --env-file .env \
  -v ./databasement-data:/data \
  davidcrty/databasement:1
```

:::note
The `ENABLE_QUEUE_WORKER=true` environment variable enables the background queue worker inside the container. This is required for processing backup and restore jobs. When using Docker Compose, the worker runs as a separate service instead.
:::

Access the application at http://localhost:2226

:::tip Pin a version
See [Versioning](versioning) for available tags.
:::

## Custom User ID (PUID/PGID)

By default, the application runs as PUID/PGID `1000`. You can customize this by adding `PUID` and `PGID` to your `.env` file:

```bash title=".env"
PUID=1001
PGID=1001
```

:::tip
Find your user's PUID/PGID with `id username`. The container will automatically set the correct permissions on `/data` for the specified PUID/PGID.
:::

### Rootless Containers

For rootless Docker or Podman environments, use the `--user` flag. When using this method, the container runs entirely as the specified user and skips the automatic permission fix:

```bash
# Create the data directory and set permissions first
mkdir /path/to/databasement/data
sudo chown 499:499 /path/to/databasement/data

docker run -d \
...
  --user 499:499 \
...
```

:::note
When using `--user`, you must manually set `/data` directory volume permissions before starting the container since the automatic permission fix requires root: `sudo chown 499:499 /path/to/databasement/data`
:::

## Updating

Pull the new image and recreate the container. Migrations run automatically on startup.

```bash
docker pull davidcrty/databasement:1
docker stop databasement && docker rm databasement
docker run -d \
  --name databasement \
  -p 2226:2226 \
  --env-file .env \
  -v ./databasement-data:/data \
  davidcrty/databasement:1
```

See [Versioning](versioning) for available versions.

## Troubleshooting

If you encounter issues, see the [Docker Compose Troubleshooting](./docker-compose#troubleshooting) section for common problems and solutions.

For additional troubleshooting options including debug mode and configuration issues, see the [Configuration Troubleshooting](./configuration/application#troubleshooting) section.

See also [Docker Networking](../user-guide/database-servers#docker-networking) if you're having issues connecting to your database server.
