---
sidebar_position: 3
---

# Docker

This guide will help you deploy Databasement using Docker. This is the simplest deployment method, using a single container that includes everything you need.

## Prerequisites

- [Docker](https://docs.docker.com/engine/install/) installed on your system

## Quick Start (SQLite)

The simplest way to run Databasement with SQLite as the database:

```bash
# Generate an application key
APP_KEY=$(docker run --rm davidcrty/databasement:latest php artisan key:generate --show)


# Run the container
docker run -d \
  --name databasement \
  -p 2226:2226 \
  -e APP_KEY=$APP_KEY \
  -e DB_CONNECTION=sqlite \
  -e DB_DATABASE=/data/database.sqlite \
  -e ENABLE_QUEUE_WORKER=true \
  -v ./databasement-data:/data \
  davidcrty/databasement:latest
```

:::note
The `ENABLE_QUEUE_WORKER=true` environment variable enables the background queue worker inside the container. This is required for processing backup and restore jobs. When using Docker Compose, the worker runs as a separate service instead.
:::

Access the application at http://localhost:2226

## Custom User ID (PUID/PGID)

By default, the application runs as PUID/PGID `1000`. You can customize this using the `PUID` and `PGID` environment variables:

```bash
# Run with custom PUID/PGID
docker run -d \
...
  -e PUID=1001 \
  -e PGID=1001 \
...
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
When using `--user`, you must manually set directory permissions before starting the container since the automatic permission fix requires root.
:::
