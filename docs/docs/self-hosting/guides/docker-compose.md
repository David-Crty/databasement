---
sidebar_position: 2
---

# Docker Compose

This guide will help you deploy DBBackup using Docker Compose. This method is ideal when you want to run DBBackup alongside its own dedicated database container.

## Prerequisites

- [Docker](https://docs.docker.com/engine/install/) and [Docker Compose](https://docs.docker.com/compose/install/)

## Quick Start

### 1. Create Project Directory

```bash
mkdir dbbackup && cd dbbackup
```

### 2. Generate Application Key

```bash
docker run --rm davidcrty/backup-manager:latest php artisan key:generate --show
```

Save this key for the next step.

### 3. Create docker-compose.yml

```yaml title="docker-compose.yml"
services:
  app:
    image: davidcrty/backup-manager:latest
    container_name: dbbackup
    restart: unless-stopped
    ports:
      - "8000:8000"
    environment:
      APP_NAME: DBBackup
      APP_ENV: production
      APP_DEBUG: "false"
      APP_URL: http://localhost:8000
      APP_KEY: base64:your-generated-key-here
      DB_CONNECTION: mysql
      DB_HOST: db
      DB_PORT: 3306
      DB_DATABASE: dbbackup
      DB_USERNAME: dbbackup
      DB_PASSWORD: secure-password-change-me
      LOG_CHANNEL: stderr
      MYSQL_CLI_TYPE: mariadb
    volumes:
      - storage:/app/storage
    depends_on:
      db:
        condition: service_healthy

  db:
    image: mysql:8.0
    container_name: dbbackup-db
    restart: unless-stopped
    environment:
      MYSQL_ROOT_PASSWORD: root-password-change-me
      MYSQL_DATABASE: dbbackup
      MYSQL_USER: dbbackup
      MYSQL_PASSWORD: secure-password-change-me
    volumes:
      - mysql-data:/var/lib/mysql
    healthcheck:
      test: ["CMD", "mysqladmin", "ping", "-h", "localhost"]
      interval: 10s
      timeout: 5s
      retries: 5

volumes:
  storage:
  mysql-data:
```

### 4. Start the Services

```bash
docker compose up -d
```

### 5. Access the Application

Open http://localhost:8000 in your browser.

## With PostgreSQL

If you prefer PostgreSQL:

```yaml title="docker-compose.yml"
services:
  app:
    image: davidcrty/backup-manager:latest
    container_name: dbbackup
    restart: unless-stopped
    ports:
      - "8000:8000"
    environment:
      APP_NAME: DBBackup
      APP_ENV: production
      APP_DEBUG: "false"
      APP_URL: http://localhost:8000
      APP_KEY: base64:your-generated-key-here
      DB_CONNECTION: pgsql
      DB_HOST: db
      DB_PORT: 5432
      DB_DATABASE: dbbackup
      DB_USERNAME: dbbackup
      DB_PASSWORD: secure-password-change-me
      LOG_CHANNEL: stderr
    volumes:
      - storage:/app/storage
    depends_on:
      db:
        condition: service_healthy

  db:
    image: postgres:16
    container_name: dbbackup-db
    restart: unless-stopped
    environment:
      POSTGRES_DB: dbbackup
      POSTGRES_USER: dbbackup
      POSTGRES_PASSWORD: secure-password-change-me
    volumes:
      - postgres-data:/var/lib/postgresql/data
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U dbbackup -d dbbackup"]
      interval: 10s
      timeout: 5s
      retries: 5

volumes:
  storage:
  postgres-data:
```

## With Traefik (HTTPS)

For production with automatic HTTPS using Traefik:

```yaml title="docker-compose.yml"
services:
  traefik:
    image: traefik:v3.0
    container_name: traefik
    restart: unless-stopped
    command:
      - "--api.insecure=true"
      - "--providers.docker=true"
      - "--providers.docker.exposedbydefault=false"
      - "--entrypoints.web.address=:80"
      - "--entrypoints.websecure.address=:443"
      - "--certificatesresolvers.letsencrypt.acme.httpchallenge=true"
      - "--certificatesresolvers.letsencrypt.acme.httpchallenge.entrypoint=web"
      - "--certificatesresolvers.letsencrypt.acme.email=your-email@example.com"
      - "--certificatesresolvers.letsencrypt.acme.storage=/letsencrypt/acme.json"
    ports:
      - "80:80"
      - "443:443"
    volumes:
      - /var/run/docker.sock:/var/run/docker.sock:ro
      - letsencrypt:/letsencrypt

  app:
    image: davidcrty/backup-manager:latest
    container_name: dbbackup
    restart: unless-stopped
    environment:
      APP_NAME: DBBackup
      APP_ENV: production
      APP_DEBUG: "false"
      APP_URL: https://backup.yourdomain.com
      APP_KEY: base64:your-generated-key-here
      DB_CONNECTION: mysql
      DB_HOST: db
      DB_PORT: 3306
      DB_DATABASE: dbbackup
      DB_USERNAME: dbbackup
      DB_PASSWORD: secure-password-change-me
      LOG_CHANNEL: stderr
      MYSQL_CLI_TYPE: mariadb
    volumes:
      - storage:/app/storage
    depends_on:
      db:
        condition: service_healthy
    labels:
      - "traefik.enable=true"
      - "traefik.http.routers.dbbackup.rule=Host(`backup.yourdomain.com`)"
      - "traefik.http.routers.dbbackup.entrypoints=websecure"
      - "traefik.http.routers.dbbackup.tls.certresolver=letsencrypt"
      - "traefik.http.services.dbbackup.loadbalancer.server.port=8000"

  db:
    image: mysql:8.0
    container_name: dbbackup-db
    restart: unless-stopped
    environment:
      MYSQL_ROOT_PASSWORD: root-password-change-me
      MYSQL_DATABASE: dbbackup
      MYSQL_USER: dbbackup
      MYSQL_PASSWORD: secure-password-change-me
    volumes:
      - mysql-data:/var/lib/mysql
    healthcheck:
      test: ["CMD", "mysqladmin", "ping", "-h", "localhost"]
      interval: 10s
      timeout: 5s
      retries: 5

volumes:
  storage:
  mysql-data:
  letsencrypt:
```

## Using Environment Files

For better security, use a separate `.env` file:

```bash title=".env"
# Application
APP_NAME=DBBackup
APP_ENV=production
APP_DEBUG=false
APP_URL=https://backup.yourdomain.com
APP_KEY=base64:your-generated-key-here

# Database
DB_CONNECTION=mysql
DB_HOST=db
DB_PORT=3306
DB_DATABASE=dbbackup
DB_USERNAME=dbbackup
DB_PASSWORD=secure-password-change-me

# MySQL Root (for db container)
MYSQL_ROOT_PASSWORD=root-password-change-me

# Other
LOG_CHANNEL=stderr
MYSQL_CLI_TYPE=mariadb
```

Then reference it in your compose file:

```yaml title="docker-compose.yml"
services:
  app:
    image: davidcrty/backup-manager:latest
    env_file: .env
    # ... rest of config
```

## Common Operations

### View Logs

```bash
docker compose logs -f         # All services
docker compose logs -f app     # Just the app
docker compose logs -f db      # Just the database
```

### Restart Services

```bash
docker compose restart
docker compose restart app     # Just the app
```

### Stop Services

```bash
docker compose down            # Stop containers
docker compose down -v         # Stop and remove volumes (data loss!)
```

### Update

```bash
docker compose pull
docker compose up -d
```

### Backup the Database

```bash
# MySQL
docker compose exec db mysqldump -u dbbackup -p dbbackup > backup.sql

# PostgreSQL
docker compose exec db pg_dump -U dbbackup dbbackup > backup.sql
```

### Run Artisan Commands

```bash
docker compose exec app php artisan migrate:status
docker compose exec app php artisan tinker
```
