---
sidebar_position: 4
---

# Docker Compose

This guide will help you deploy Databasement using Docker Compose. This method is ideal when you want to run Databasement alongside its own dedicated database container.

## Prerequisites

- [Docker](https://docs.docker.com/engine/install/) and [Docker Compose](https://docs.docker.com/compose/install/)

## Quick Start

### 1. Create Project Directory

```bash
mkdir databasement && cd databasement
```

### 2. Generate Application Key

```bash
docker run --rm davidcrty/databasement:latest php artisan key:generate --show
```

Save this key for the next step.

### 3. Create Environment File

Create a `.env` file with your configuration. This file is shared between the `app` and `worker` services to ensure consistent settings.

#### SQLite

```bash title=".env"
APP_URL=http://localhost:2226
APP_KEY=base64:your-generated-key-here

# Database (SQLite)
DB_CONNECTION=sqlite
DB_DATABASE=/data/database.sqlite
```

#### MySQL

```bash title=".env"
APP_URL=http://localhost:2226
APP_KEY=base64:your-generated-key-here

# Database (MySQL)
DB_CONNECTION=mysql
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=databasement
DB_USERNAME=databasement
DB_PASSWORD=your-secure-password
```

#### PostgreSQL

```bash title=".env"
APP_URL=http://localhost:2226
APP_KEY=base64:your-generated-key-here

# Database (PostgreSQL)
DB_CONNECTION=pgsql
DB_HOST=postgres
DB_PORT=5432
DB_DATABASE=databasement
DB_USERNAME=databasement
DB_PASSWORD=your-secure-password
```

:::tip S3 Storage
To store backups in AWS S3 or S3-compatible storage (MinIO, DigitalOcean Spaces, etc.), see the [S3 Storage Configuration](../self-hosting/configuration#s3-storage) section.
:::

### 4. Create docker-compose.yml

#### SQLite

```yaml title="docker-compose.yml"
services:
  app:
    image: davidcrty/databasement:latest
    container_name: databasement
    restart: unless-stopped
    ports:
      - "2226:2226"
    env_file: .env
    volumes:
      - ./data:/data
    healthcheck:
      test: ["CMD", "curl", "-f", "http://localhost:2226/health"]
      interval: 10s
      timeout: 5s
      retries: 5

  worker:
    image: davidcrty/databasement:latest
    container_name: databasement-worker
    restart: unless-stopped
    command: sh -c "php artisan db:wait --check-migrations && php artisan queue:work --queue=backups,default --tries=3 --timeout=3600 --sleep=3 --max-jobs=1000"
    env_file: .env
    volumes:
      - ./data:/data
    depends_on:
      app:
        condition: service_healthy
```

#### MySQL

```yaml title="docker-compose.yml"
services:
  app:
    image: davidcrty/databasement:latest
    container_name: databasement
    restart: unless-stopped
    ports:
      - "2226:2226"
    env_file: .env
    volumes:
      - ./data:/data
    depends_on:
      mysql:
        condition: service_healthy
    healthcheck:
      test: ["CMD", "curl", "-f", "http://localhost:2226/health"]
      interval: 10s
      timeout: 5s
      retries: 5

  worker:
    image: davidcrty/databasement:latest
    container_name: databasement-worker
    restart: unless-stopped
    command: sh -c "php artisan db:wait --check-migrations && php artisan queue:work --queue=backups,default --tries=3 --timeout=3600 --sleep=3 --max-jobs=1000"
    env_file: .env
    volumes:
      - ./data:/data
    depends_on:
      app:
        condition: service_healthy
      mysql:
        condition: service_healthy

  mysql:
    image: mysql:8.0
    container_name: databasement-mysql
    restart: unless-stopped
    environment:
      MYSQL_ROOT_PASSWORD: your-root-password
      MYSQL_DATABASE: databasement
      MYSQL_USER: databasement
      MYSQL_PASSWORD: your-secure-password
    volumes:
      - ./mysql-data:/var/lib/mysql
    healthcheck:
      test: ["CMD", "mysqladmin", "ping", "-h", "localhost"]
      interval: 10s
      timeout: 5s
      retries: 5
```

#### PostgreSQL

```yaml title="docker-compose.yml"
services:
  app:
    image: davidcrty/databasement:latest
    container_name: databasement
    restart: unless-stopped
    ports:
      - "2226:2226"
    env_file: .env
    volumes:
      - ./data:/data
    depends_on:
      postgres:
        condition: service_healthy
    healthcheck:
      test: ["CMD", "curl", "-f", "http://localhost:2226/health"]
      interval: 10s
      timeout: 5s
      retries: 5

  worker:
    image: davidcrty/databasement:latest
    container_name: databasement-worker
    restart: unless-stopped
    command: sh -c "php artisan db:wait --check-migrations && php artisan queue:work --queue=backups,default --tries=3 --timeout=3600 --sleep=3 --max-jobs=1000"
    env_file: .env
    volumes:
      - ./data:/data
    depends_on:
      app:
        condition: service_healthy
      postgres:
        condition: service_healthy

  postgres:
    image: postgres:16
    container_name: databasement-postgres
    restart: unless-stopped
    environment:
      POSTGRES_DB: databasement
      POSTGRES_USER: databasement
      POSTGRES_PASSWORD: your-secure-password
    volumes:
      - ./postgres-data:/var/lib/postgresql/data
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U databasement -d databasement"]
      interval: 10s
      timeout: 5s
      retries: 5
```

:::tip
Remember to restart both the `app` and `worker` services whenever you change the `.env` file: `docker compose restart app worker`
:::

:::tip
The `worker` service runs the Laravel queue worker as a separate container. This provides better stability and allows independent restarts without affecting the web application. The worker processes backup and restore jobs from the queue.
:::

### 5. Start the Services

```bash
docker compose up -d
```

### 6. Verify the Setup

Wait for all services to be healthy, then verify the application is running:

```bash
# Check service status
docker compose ps

# Verify health endpoint
curl http://localhost:2226/health
```

### 7. Access the Application

Open http://localhost:2226 in your browser.

:::note
To expose your Databasement instance with HTTPS, you can use Traefik as a reverse proxy. For detailed instructions on
how to configure Traefik with Docker, please refer to
the [official Traefik documentation](https://doc.traefik.io/traefik/expose/docker/).
:::

## Custom User ID (PUID/PGID)

By default, the application runs as PUID/PGID `1000`. You can customize this using the `PUID` and `PGID` environment variables:

```yaml title="docker-compose.yml"
services:
  app:
    image: davidcrty/databasement:latest
    environment:
      PUID: 1001
      PGID: 1001
    # ... rest of config

  worker:
    image: davidcrty/databasement:latest
    environment:
      PUID: 1001
      PGID: 1001
    # ... rest of config
```

:::tip
Find your user's PUID/PGID with `id username`. The container will automatically set the correct permissions on `/data` for the specified PUID/PGID.
:::

### Rootless Containers

For rootless Docker or Podman environments, use the `user` directive. When using this method, the container runs entirely as the specified user and skips the automatic permission fix:

```yaml
services:
  app:
    image: davidcrty/databasement:latest
    user: "1010:1010"
    # ... rest of config

  worker:
    image: davidcrty/databasement:latest
    user: "1010:1010"
    # ... rest of config
```

:::note
When using `user`, you must manually set `/data` directory volume permissions before starting the container since the automatic permission fix requires root: `sudo chown 1010:1010 /path/to/databasement/data`
:::

## Troubleshooting

### Database connection errors
```
SQLSTATE[HY000] [2002] No such file or directory
# or 
SQLSTATE[HY000] [2002] Connection refused
```

**Cause:** The database container isn't ready yet, or the port/host configuration is incorrect.

**Solution:**
1. Check if the database container is healthy: `docker compose ps`
2. Verify `DB_PORT` matches your database (MySQL: `3306`, PostgreSQL: `5432`)
3. Ensure `DB_HOST` matches your Docker Compose service name exactly (e.g. `mysql` or `postgres`)

### Container keeps restarting

**Cause:** Application error during startup.

**Solution:** Check the logs to identify the issue:
```bash
docker compose logs -f app
docker compose logs -f worker
```

### Permission denied on /data

**Cause:** The container user doesn't have write access to the mounted volume.

**Solution:** Either:
1. Set `PUID`/`PGID` to match your host user (see [Custom User ID](#custom-user-id-puidpgid))
2. Or fix permissions manually: `sudo chown -R 1000:1000 ./data` (replace `1000` with your PUID/PGID)

### Database tables are empty after startup

**Cause:** This is expected on first run. Migrations run automatically when the app starts.

**Solution:** Check if migrations ran successfully:
```bash
docker compose logs app | grep -i migration
```

You should see: `All migrations have been run!`

### More troubleshooting

For additional troubleshooting options including debug mode, trusted proxy configuration, and artisan commands, see the [Configuration Troubleshooting](../self-hosting/configuration#troubleshooting) section.

See also [Docker Networking](../user-guide/database-servers#docker-networking) if you're having issues connecting to your database server.
