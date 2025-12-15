---
sidebar_position: 2
---

# Configuration

This page contains all the environment variables you can use to configure DBBackup.

## Application Settings

| Variable | Description | Default |
|----------|-------------|---------|
| `APP_NAME` | Application name displayed in the UI | `Laravel` |
| `APP_ENV` | Environment mode (`local`, `production`) | `production` |
| `APP_DEBUG` | Enable debug mode (set to `false` in production) | `false` |
| `APP_URL` | Full URL where the app is accessible | `http://localhost:8000` |
| `APP_KEY` | Application encryption key (required) | - |

### Generating the Application Key

The `APP_KEY` is required for encryption. Generate one with:

```bash
docker run --rm davidcrty/backup-manager:latest php artisan key:generate --show
```

Copy the output (e.g., `base64:xxxx...`) and set it as `APP_KEY`.

## Database Configuration

DBBackup needs a database to store its own data (users, servers, backup configurations).

### SQLite (Simplest)

```bash
DB_CONNECTION=sqlite
DB_DATABASE=/app/database/database.sqlite
```

:::note
When using SQLite, make sure to mount a volume for `/app/database` to persist data.
:::

### MySQL / MariaDB

```bash
DB_CONNECTION=mysql
DB_HOST=your-mysql-host
DB_PORT=3306
DB_DATABASE=dbbackup
DB_USERNAME=dbbackup
DB_PASSWORD=your-secure-password
```

### PostgreSQL

```bash
DB_CONNECTION=pgsql
DB_HOST=your-postgres-host
DB_PORT=5432
DB_DATABASE=dbbackup
DB_USERNAME=dbbackup
DB_PASSWORD=your-secure-password
```

## Backup Storage

Configure where backup files are stored temporarily during operations.

| Variable | Description | Default |
|----------|-------------|---------|
| `BACKUP_LOCAL_ROOT` | Local temp directory for backups | `/tmp/backups` |

### S3 Storage (Optional)

If you want to use S3-compatible storage for backup volumes:

```bash
AWS_ACCESS_KEY_ID=your-access-key
AWS_SECRET_ACCESS_KEY=your-secret-key
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=your-bucket-name
```

## CLI Tools Configuration

DBBackup uses command-line tools to perform database dumps and restores.

| Variable | Description | Default |
|----------|-------------|---------|
| `MYSQL_CLI_TYPE` | MySQL CLI type (`mysql` or `mariadb`) | `mariadb` |

The container includes both `mysqldump`/`mysql` (via MariaDB client) and `pg_dump`/`psql` for PostgreSQL operations.

## Queue Configuration

The application uses a queue for async backup and restore operations. By default, it uses the database queue driver.

| Variable | Description | Default |
|----------|-------------|---------|
| `QUEUE_CONNECTION` | Queue driver | `database` |

## Session & Cache

| Variable | Description | Default |
|----------|-------------|---------|
| `SESSION_DRIVER` | Session storage driver | `database` |
| `CACHE_STORE` | Cache storage driver | `database` |

## Logging

| Variable | Description | Default |
|----------|-------------|---------|
| `LOG_CHANNEL` | Logging channel | `stderr` |
| `LOG_LEVEL` | Minimum log level | `debug` |

For production, `stderr` is recommended as logs will be captured by Docker.

## Complete Example

Here's a complete `.env` file for a production deployment with MySQL:

```bash
# Application
APP_NAME=DBBackup
APP_ENV=production
APP_DEBUG=false
APP_URL=https://backup.yourdomain.com
APP_KEY=base64:your-generated-key-here

# Database (for DBBackup itself)
DB_CONNECTION=mysql
DB_HOST=mysql.yourdomain.com
DB_PORT=3306
DB_DATABASE=dbbackup
DB_USERNAME=dbbackup
DB_PASSWORD=secure-password-here

# Storage & Queue
QUEUE_CONNECTION=database
SESSION_DRIVER=database
CACHE_STORE=database

# Logging
LOG_CHANNEL=stderr
LOG_LEVEL=warning

# CLI Tools
MYSQL_CLI_TYPE=mariadb
```
