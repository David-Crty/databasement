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
docker volume create databasement-data
# Run the container
docker run -d \
  --name databasement \
  -p 2226:2226 \
  -e APP_KEY=$APP_KEY \
  -e DB_CONNECTION=sqlite \
  -e DB_DATABASE=/data/database.sqlite \
  -e ENABLE_QUEUE_WORKER=true \
  -v databasement-data:/data \
  davidcrty/databasement:latest
```

:::note
The `ENABLE_QUEUE_WORKER=true` environment variable enables the background queue worker inside the container. This is required for processing backup and restore jobs. When using Docker Compose, the worker runs as a separate service instead.
:::

Access the application at http://localhost:2226

## Use local directory as data volume

```bash
docker run -d \
  --name databasement \
  -p 2226:2226 \
  -e APP_KEY=YOUR_APP_KEY \
  -e DB_CONNECTION=sqlite \
  -e DB_DATABASE=/data/database.sqlite \
  -e ENABLE_QUEUE_WORKER=true \
  -v /path/to/databasement/data:/data \
  davidcrty/databasement:latest
```

## Custom User ID (UID/GID)

By default, the application runs as UID/GID `1000`. You can customize this using the `UID` and `GID` environment variables:

```bash
# Run with custom UID/GID
docker run -d \
  --name databasement \
  -p 2226:2226 \
  -e APP_KEY=YOUR_APP_KEY \
  -e DB_CONNECTION=sqlite \
  -e DB_DATABASE=/data/database.sqlite \
  -e ENABLE_QUEUE_WORKER=true \
  -e UID=1001 \
  -e GID=1001 \
  -v /path/to/databasement/data:/data \
  davidcrty/databasement:latest
```

:::tip
Find your user's UID/GID with `id username`. The container will automatically set the correct permissions on `/data` for the specified UID/GID.
:::

### Alternative: Using `--user` flag

You can also use Docker's `--user` flag. When using this method, the container skips the automatic permission fix (useful if you've already set permissions manually):

```bash
docker run -d \
  --user 499:499 \
  --name databasement \
  -p 2226:2226 \
  -e APP_KEY=YOUR_APP_KEY \
  -e DB_CONNECTION=sqlite \
  -e DB_DATABASE=/data/database.sqlite \
  -e ENABLE_QUEUE_WORKER=true \
  -v /path/to/databasement/data:/data \
  davidcrty/databasement:latest
```

:::note
When using `--user`, you must ensure the directory has correct ownership before starting the container: `sudo chown 499:499 /path/to/databasement/data`
:::

:::tip
For NAS platforms like **Unraid**, **Synology**, or **TrueNAS**, see the [NAS Platforms](./nas-platforms.md) guide for platform-specific instructions.
:::
