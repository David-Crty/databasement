---
sidebar_position: 1
---

# Docker

This guide will help you deploy DBBackup using Docker. This is the simplest deployment method, using a single container that includes everything you need.

## Prerequisites

- [Docker](https://docs.docker.com/engine/install/) installed on your system

## Quick Start (SQLite)

The simplest way to run DBBackup with SQLite as the database:

```bash
# Generate an application key
APP_KEY=$(docker run --rm davidcrty/backup-manager:latest php artisan key:generate --show)

# Run the container
docker run -d \
  --name dbbackup \
  -p 8000:8000 \
  -e APP_KEY=$APP_KEY \
  -e DB_CONNECTION=sqlite \
  -e DB_DATABASE=/app/database/database.sqlite \
  -v dbbackup-storage:/app/storage \
  -v dbbackup-database:/app/database \
  davidcrty/backup-manager:latest
```

Access the application at http://localhost:8000

## Production Setup (External Database)

For production, we recommend using MySQL or PostgreSQL instead of SQLite.

### 1. Generate the Application Key

```bash
docker run --rm davidcrty/backup-manager:latest php artisan key:generate --show
```

Save this key - you'll need it for the `APP_KEY` environment variable.

### 2. Prepare Your Database

Create a database and user for DBBackup on your MySQL/PostgreSQL server:

**MySQL:**
```sql
CREATE DATABASE dbbackup CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'dbbackup'@'%' IDENTIFIED BY 'your-secure-password';
GRANT ALL PRIVILEGES ON dbbackup.* TO 'dbbackup'@'%';
FLUSH PRIVILEGES;
```

**PostgreSQL:**
```sql
CREATE DATABASE dbbackup;
CREATE USER dbbackup WITH ENCRYPTED PASSWORD 'your-secure-password';
GRANT ALL PRIVILEGES ON DATABASE dbbackup TO dbbackup;
```

### 3. Run the Container

```bash
docker run -d \
  --name dbbackup \
  -p 8000:8000 \
  -e APP_NAME=DBBackup \
  -e APP_ENV=production \
  -e APP_DEBUG=false \
  -e APP_URL=https://backup.yourdomain.com \
  -e APP_KEY=base64:your-generated-key \
  -e DB_CONNECTION=mysql \
  -e DB_HOST=your-mysql-host \
  -e DB_PORT=3306 \
  -e DB_DATABASE=dbbackup \
  -e DB_USERNAME=dbbackup \
  -e DB_PASSWORD=your-secure-password \
  -e LOG_CHANNEL=stderr \
  -v dbbackup-storage:/app/storage \
  davidcrty/backup-manager:latest
```

### 4. Access the Application

Open your browser and navigate to your configured URL (or http://localhost:8000 for local setups).

## Using an Environment File

For easier management, create an `.env` file:

```bash title=".env"
APP_NAME=DBBackup
APP_ENV=production
APP_DEBUG=false
APP_URL=https://backup.yourdomain.com
APP_KEY=base64:your-generated-key

DB_CONNECTION=mysql
DB_HOST=your-mysql-host
DB_PORT=3306
DB_DATABASE=dbbackup
DB_USERNAME=dbbackup
DB_PASSWORD=your-secure-password

LOG_CHANNEL=stderr
MYSQL_CLI_TYPE=mariadb
```

Then run with:

```bash
docker run -d \
  --name dbbackup \
  -p 8000:8000 \
  --env-file .env \
  -v dbbackup-storage:/app/storage \
  davidcrty/backup-manager:latest
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
docker pull davidcrty/backup-manager:latest

# Stop and remove the old container
docker stop dbbackup
docker rm dbbackup

# Start a new container with the same configuration
docker run -d \
  --name dbbackup \
  -p 8000:8000 \
  --env-file .env \
  -v dbbackup-storage:/app/storage \
  davidcrty/backup-manager:latest
```

The container automatically runs database migrations on startup, so your data will be migrated to the new schema.

## Troubleshooting

### View Logs

```bash
docker logs dbbackup
docker logs -f dbbackup  # Follow logs
```

### Access the Container Shell

```bash
docker exec -it dbbackup sh
```

### Run Artisan Commands

```bash
docker exec dbbackup php artisan migrate:status
docker exec dbbackup php artisan queue:work --once
```

### Check Database Connection

```bash
docker exec dbbackup php artisan db:show
```
