---
sidebar_position: 1
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
  -p 8000:8000 \
  -e APP_KEY=$APP_KEY \
  -e DB_CONNECTION=sqlite \
  -e DB_DATABASE=/app/database/database.sqlite \
  -v databasement-storage:/app/storage \
  -v databasement-database:/app/database \
  davidcrty/databasement:latest
```

Access the application at http://localhost:8000

## Production Setup (External Database)

For production, we recommend using MySQL or PostgreSQL instead of SQLite.

### 1. Generate the Application Key

```bash
docker run --rm davidcrty/databasement:latest php artisan key:generate --show
```

Save this key - you'll need it for the `APP_KEY` environment variable.

### 2. Prepare Your Database

Create a database and user for Databasement on your MySQL/PostgreSQL server:

**MySQL:**
```sql
CREATE DATABASE databasement CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'databasement'@'%' IDENTIFIED BY 'your-secure-password';
GRANT ALL PRIVILEGES ON databasement.* TO 'databasement'@'%';
FLUSH PRIVILEGES;
```

**PostgreSQL:**
```sql
CREATE DATABASE databasement;
CREATE USER databasement WITH ENCRYPTED PASSWORD 'your-secure-password';
GRANT ALL PRIVILEGES ON DATABASE databasement TO databasement;
```

### 3. Run the Container

```bash
docker run -d \
  --name databasement \
  -p 8000:8000 \
  -e APP_NAME=Databasement \
  -e APP_ENV=production \
  -e APP_DEBUG=false \
  -e APP_URL=https://backup.yourdomain.com \
  -e APP_KEY=base64:your-generated-key \
  -e DB_CONNECTION=mysql \
  -e DB_HOST=your-mysql-host \
  -e DB_PORT=3306 \
  -e DB_DATABASE=databasement \
  -e DB_USERNAME=databasement \
  -e DB_PASSWORD=your-secure-password \
  -e LOG_CHANNEL=stderr \
  -v databasement-storage:/app/storage \
  davidcrty/databasement:latest
```

### 4. Access the Application

Open your browser and navigate to your configured URL (or http://localhost:8000 for local setups).

## Using an Environment File

For easier management, create an `.env` file:

```bash title=".env"
APP_NAME=Databasement
APP_ENV=production
APP_DEBUG=false
APP_URL=https://backup.yourdomain.com
APP_KEY=base64:your-generated-key

DB_CONNECTION=mysql
DB_HOST=your-mysql-host
DB_PORT=3306
DB_DATABASE=databasement
DB_USERNAME=databasement
DB_PASSWORD=your-secure-password

LOG_CHANNEL=stderr
MYSQL_CLI_TYPE=mariadb
```

Then run with:

```bash
docker run -d \
  --name databasement \
  -p 8000:8000 \
  --env-file .env \
  -v databasement-storage:/app/storage \
  davidcrty/databasement:latest
```

## Behind a Reverse Proxy

When running behind a reverse proxy (nginx, Traefik, Caddy), make sure to:

1. Set `APP_URL` to your public HTTPS URL
2. Configure your proxy to forward the `X-Forwarded-*` headers

Example nginx configuration:

```nginx
server {
    listen 443 ssl http2;
    server_name backup.yourdomain.com;

    ssl_certificate /path/to/cert.pem;
    ssl_certificate_key /path/to/key.pem;

    location / {
        proxy_pass http://localhost:8000;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
```

## Updating

To update to the latest version:

```bash
# Pull the latest image
docker pull davidcrty/databasement:latest

# Stop and remove the old container
docker stop databasement
docker rm databasement

# Start a new container with the same configuration
docker run -d \
  --name databasement \
  -p 8000:8000 \
  --env-file .env \
  -v databasement-storage:/app/storage \
  davidcrty/databasement:latest
```

The container automatically runs database migrations on startup, so your data will be migrated to the new schema.

## Troubleshooting

### View Logs

```bash
docker logs databasement
docker logs -f databasement  # Follow logs
```

### Access the Container Shell

```bash
docker exec -it databasement sh
```

### Run Artisan Commands

```bash
docker exec databasement php artisan migrate:status
docker exec databasement php artisan queue:work --once
```

### Check Database Connection

```bash
docker exec databasement php artisan db:show
```
