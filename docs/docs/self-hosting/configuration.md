---
sidebar_position: 2
---

# Configuration

This page contains all the environment variables you can use to configure Databasement.

## Application Settings

| Variable | Description | Default |
|----------|-------------|---------|
| `APP_DEBUG` | Enable debug mode (set to `false` in production) | `false` |
| `APP_URL` | Full URL where the app is accessible | `http://localhost:2226` |
| `APP_KEY` | Application encryption key (required) | - |
| `TZ` | Application timezone | `UTC` |

### Timezone Configuration

Set the `TZ` environment variable to configure the application timezone.
```bash
TZ=America/New_York
TZ=Europe/London
TZ=Asia/Tokyo
```

See the [list of supported timezones](https://www.php.net/manual/en/timezones.php).

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

## Reverse Proxy / Trusted Proxies

When running behind a reverse proxy (nginx, Traefik, Kubernetes Ingress), configure trusted proxies so Laravel can correctly determine the client IP and protocol.

| Variable          | Description                                    | Default                                                   |
|-------------------|------------------------------------------------|-----------------------------------------------------------|
| `TRUSTED_PROXIES` | IP addresses or CIDR ranges of trusted proxies | `127.0.0.1,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16,::1')` |

**Values:**
- `127.0.0.1,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16,::1')` - This covers all RFC 1918 private ranges
- `*` - Trust all proxies (suitable for Kubernetes/Docker where proxy IPs are dynamic)
- Comma-separated IPs: `10.0.0.1,192.168.1.0/24` - Trust specific proxies
- Empty - Trust no proxies

```bash
# Trust all proxies (default, recommended for containerized environments)
TRUSTED_PROXIES=*

# Trust specific proxy IPs
TRUSTED_PROXIES=10.0.0.1,10.0.0.2

# Trust a CIDR range
TRUSTED_PROXIES=10.0.0.0/8
```

## Backup Configuration

Configure backup behavior, schedules, and job settings.

| Variable                   | Description                              | Default        |
|----------------------------|------------------------------------------|----------------|
| `BACKUP_WORKING_DIRECTORY` | Temporary directory for backup operations | `/tmp/backups` |
| `MYSQL_CLI_TYPE`           | MySQL CLI type: `mariadb` or `mysql`     | `mariadb`      |
| `BACKUP_JOB_TIMEOUT`       | Maximum seconds a backup/restore job can run | `7200` (2 hours) |
| `BACKUP_JOB_TRIES`         | Number of times to retry failed jobs     | `3`            |
| `BACKUP_JOB_BACKOFF`       | Seconds to wait before retrying          | `60`           |
| `BACKUP_DAILY_CRON`        | Cron schedule for daily backups          | `0 2 * * *` (2:00 AM) |
| `BACKUP_WEEKLY_CRON`       | Cron schedule for weekly backups         | `0 3 * * 0` (Sunday 3:00 AM) |
| `BACKUP_CLEANUP_CRON`      | Cron schedule for snapshot cleanup       | `0 4 * * *` (4:00 AM) |

## S3 Storage

Databasement supports AWS S3 and S3-compatible storage (MinIO, DigitalOcean Spaces, etc.) for backup volumes.

We use ENV variables to configure the S3 client.

#### S3 IAM Permissions

The AWS credentials need these permissions:

```json
{
    "Version": "2012-10-17",
    "Statement": [
        {
            "Effect": "Allow",
            "Action": [
                "s3:PutObject",
                "s3:GetObject",
                "s3:DeleteObject",
                "s3:ListBucket"
            ],
            "Resource": [
                "arn:aws:s3:::your-bucket-name",
                "arn:aws:s3:::your-bucket-name/*"
            ]
        }
    ]
}
```

#### Access keys (Optional)

This is not recommended but for standard AWS access using access keys:

```bash
AWS_ACCESS_KEY_ID=your-access-key
AWS_SECRET_ACCESS_KEY=your-secret-key
AWS_REGION=us-east-1
```

#### S3-Compatible Storage (MinIO, etc.)

For S3-compatible storage providers, configure a custom endpoint:

```bash
AWS_ENDPOINT_URL_S3=https://minio.yourdomain.com
AWS_USE_PATH_STYLE_ENDPOINT=true
```

#### IAM Role Assumption (Restricted Environments)

To force Databasement to assume an IAM role, set the `AWS_CUSTOM_ROLE_ARN` environment variable:

```bash
AWS_CUSTOM_ROLE_ARN=arn:aws:iam::123456789:role/your-role-name
```

#### AWS Profile Support

If using AWS credential profiles (from `~/.aws/credentials`):

```bash
AWS_S3_PROFILE=my-s3-profile
```

#### All S3 Environment Variables

| Variable                      | Description                                     | Default        |
|-------------------------------|-------------------------------------------------|----------------|
| `AWS_ACCESS_KEY_ID`           | AWS access key (picked up automatically by SDK) | -              |
| `AWS_SECRET_ACCESS_KEY`       | AWS secret key (picked up automatically by SDK) | -              |
| `AWS_REGION`                  | AWS region                                      | `us-east-1`    |
| `AWS_ENDPOINT_URL_S3`         | Custom S3 endpoint URL                          | -              |
| `AWS_USE_PATH_STYLE_ENDPOINT` | Use path-style URLs (required for MinIO)        | `false`        |
| `AWS_S3_PROFILE`              | AWS credential profile for S3                   | -              |
| `AWS_CUSTOM_ROLE_ARN`         | IAM custom role ARN to assume                   | -              |
| `AWS_ROLE_SESSION_NAME`       | Session name for role assumption                | `databasement` |
| `AWS_ENDPOINT_URL_STS`        | Custom STS endpoint URL                         | -              |
| `AWS_STS_PROFILE`             | AWS credential profile for STS                  | -              |


### Troubleshooting

Debug the aws configuration by running:
```bash
php artisan config:show aws
```

This is where we create the S3 client: [app/Services/Backup/Filesystems/Awss3Filesystem.php](https://github.com/David-Crty/databasement/blob/main/app/Services/Backup/Filesystems/Awss3Filesystem.php)

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

# Logging
LOG_CHANNEL=stderr
LOG_LEVEL=warning
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
