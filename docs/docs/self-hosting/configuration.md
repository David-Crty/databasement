---
sidebar_position: 2
---

# Configuration

This page contains all the environment variables you can use to configure Databasement.

## Application Settings

| Variable | Description | Default |
|----------|-------------|---------|
| `APP_DEBUG` | Enable debug mode (set to `false` in production) | `false` |
| `APP_URL` | Full URL where the app is accessible | `http://localhost:8000` |
| `APP_KEY` | Application encryption key (required) | - |

### Generating the Application Key

The `APP_KEY` is required for encryption. Generate one with:

```bash
docker run --rm davidcrty/databasement:latest php artisan key:generate --show
```

Copy the output (e.g., `base64:xxxx...`) and set it as `APP_KEY`.

## Database Configuration

Databasement needs a database to store its own data (users, servers, backup configurations).

### SQLite (Simplest)

```bash
DB_CONNECTION=sqlite
DB_DATABASE=/data/database.sqlite
```

:::note
When using SQLite, make sure to mount a volume for `/data` to persist data.
:::

### MySQL / MariaDB

Create a database and user for Databasement on your MySQL server:

**MySQL:**
```sql
CREATE DATABASE databasement CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'databasement'@'%' IDENTIFIED BY 'your-secure-password';
GRANT ALL PRIVILEGES ON databasement.* TO 'databasement'@'%';
FLUSH PRIVILEGES;
```

```bash
DB_CONNECTION=mysql
DB_HOST=your-mysql-host
DB_PORT=3306
DB_DATABASE=databasement
DB_USERNAME=databasement
DB_PASSWORD=your-secure-password
```

### PostgreSQL

Create a database and user for Databasement on your PostgreSQL server:

**PostgreSQL:**
```sql
CREATE DATABASE databasement;
CREATE USER databasement WITH ENCRYPTED PASSWORD 'your-secure-password';
GRANT ALL PRIVILEGES ON DATABASE databasement TO databasement;
```

```bash
DB_CONNECTION=pgsql
DB_HOST=your-postgres-host
DB_PORT=5432
DB_DATABASE=databasement
DB_USERNAME=databasement
DB_PASSWORD=your-secure-password
```

## Backup Storage

Configure where backup files are stored temporarily during operations.

| Variable | Description | Default |
|----------|-------------|---------|
| `BACKUP_TMP_FOLDER` | Local temp directory for backups | `/tmp/backups` |

### S3 Storage (Optional)

If you want to use S3-compatible storage for backup volumes:

```bash
AWS_ACCESS_KEY_ID=your-access-key
AWS_SECRET_ACCESS_KEY=your-secret-key
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=your-bucket-name
```

## CLI Tools Configuration

Databasement uses command-line tools to perform database dumps and restores.

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
APP_ENV=production
APP_DEBUG=false
APP_URL=https://backup.yourdomain.com
APP_KEY=base64:your-generated-key-here

# Database (for Databasement itself)
DB_CONNECTION=mysql
DB_HOST=mysql.yourdomain.com
DB_PORT=3306
DB_DATABASE=databasement
DB_USERNAME=databasement
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

## Troubleshooting

### Enable Debug Mode
- Enable debug mode with `APP_DEBUG=true` in your values file.
    - Go to `https://dabasement.yourdomain.com/health/debug` to view the debug page.

- Check the logs
- Report any issues on [GitHub](https://github.com/david-crty/databasement/issues)

### Run Artisan Commands

```bash
php artisan migrate:status # Check database migrations
php artisan config:show database # View database configuration
```
