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

When running behind a reverse proxy (nginx, Traefik, Kubernetes Ingress), configure trusted proxies so Laravel can
correctly determine the client IP and protocol. See the [Troubleshooting section](#troubleshooting) if you have issues
with proxy configuration.

| Variable          | Description                                    | Default                                                                            |
|-------------------|------------------------------------------------|------------------------------------------------------------------------------------|
| `TRUSTED_PROXIES` | IP addresses or CIDR ranges of trusted proxies | `127.0.0.0/8,10.0.0.0/8,100.64.0.0/10,169.254.0.0/16,172.16.0.0/12,192.168.0.0/16` |

**Alternative values:**
- `*` - Trust all proxies (simplest option for containerized environments)
- Comma-separated IPs/CIDRs: `10.0.0.1,192.168.1.0/24` - Trust specific proxies only
- Empty - Trust no proxies

:::info
Checks the [Troubleshooting section](#troubleshooting) for help with proxy configuration.
:::

## Backup Configuration

Configure backup behavior, schedules, and job settings.

| Variable                   | Description                              | Default                      |
|----------------------------|------------------------------------------|------------------------------|
| `BACKUP_WORKING_DIRECTORY` | Temporary directory for backup operations | `/tmp/backups`               |
| `BACKUP_COMPRESSION`       | Compression algorithm: `gzip`, `zstd`, or `encrypted` | `gzip`                       |
| `BACKUP_COMPRESSION_LEVEL` | Compression level (1-9 for gzip/encrypted, 1-19 for zstd) | `6`                          |
| `BACKUP_ENCRYPTION_KEY`    | Encryption key for encrypted backups (AES-256) | `env('APP_KEY')`             |
| `MYSQL_CLI_TYPE`           | MySQL CLI type: `mariadb` or `mysql`     | `mariadb`                    |
| `BACKUP_JOB_TIMEOUT`       | Maximum seconds a backup/restore job can run | `7200` (2 hours)             |
| `BACKUP_JOB_TRIES`         | Number of times to retry failed jobs     | `3`                          |
| `BACKUP_JOB_BACKOFF`       | Seconds to wait before retrying          | `60`                         |
| `BACKUP_DAILY_CRON`        | Cron schedule for daily backups          | `0 2 * * *` (2:00 AM)        |
| `BACKUP_WEEKLY_CRON`       | Cron schedule for weekly backups         | `0 3 * * 0` (Sunday 3:00 AM) |
| `BACKUP_CLEANUP_CRON`      | Cron schedule for snapshot cleanup       | `0 4 * * *` (4:00 AM)        |

### Compression Options

By default, backups are compressed with **gzip** for maximum compatibility. You can switch to **zstd** for better compression ratios, or **encrypted** for AES-256 encrypted backups.

| Method | CLI Tool | File Extension | Encrypted |
|--------|----------|----------------|-----------|
| `gzip` (default) | `gzip` | `.gz` | No |
| `zstd` | `zstd` | `.zst` | No |
| `encrypted` | `7z` | `.7z` | Yes (AES-256) |

:::note
**zstd** typically provides 20-40% better compression than gzip at similar speeds. The default level of 6 provides a good balance between compression ratio and speed for both algorithms.
:::

### Encrypted Backups

When using `encrypted` compression, backups are encrypted with AES-256 using 7-Zip. The encryption key defaults to `APP_KEY`, but you can set a dedicated key:

```bash
BACKUP_COMPRESSION=encrypted
BACKUP_ENCRYPTION_KEY=base64:your-32-byte-key-here  # Optional, defaults to APP_KEY
```

:::warning
If you change the encryption key, you will not be able to restore backups that were encrypted with the previous key. Keep your encryption key safe and backed up separately.
:::

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
# AWS_PUBLIC_ENDPOINT_URL_S3=https://minio.yourdomain.com 
```

:::tip
If your internal endpoint differs from the public URL (e.g., `http://minio:9000` vs `http://localhost:9000`), set `AWS_PUBLIC_ENDPOINT_URL_S3` for presigned download URLs to work correctly in your browser.
:::

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
| `AWS_ENDPOINT_URL_S3`         | Custom S3 endpoint URL (internal)               | -              |
| `AWS_PUBLIC_ENDPOINT_URL_S3`  | Public S3 endpoint for presigned URLs           | -              |
| `AWS_USE_PATH_STYLE_ENDPOINT` | Use path-style URLs (required for MinIO)        | `false`        |
| `AWS_S3_PROFILE`              | AWS credential profile for S3                   | -              |
| `AWS_CUSTOM_ROLE_ARN`         | IAM custom role ARN to assume                   | -              |
| `AWS_ROLE_SESSION_NAME`       | Session name for role assumption                | `databasement` |
| `AWS_ENDPOINT_URL_STS`        | Custom STS endpoint URL                         | -              |
| `AWS_STS_PROFILE`             | AWS credential profile for STS                  | -              |


### Show AWS Configuration

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

Enable debug mode to access detailed diagnostics:

```bash
APP_DEBUG=true
```

Then visit `https://your-domain.com/health/debug` to view:
- Current IP address and whether it's from a trusted proxy
- Request headers (including `X-Forwarded-For`, `X-Forwarded-Proto`)
- Application configuration

### Debugging Trusted Proxies

If your application shows HTTP instead of HTTPS, or shows the wrong client IP:

1. **Enable debug mode** (see above)
2. **Visit `/health/debug`** and check:
   - `is_trusted_proxy`: Should be `true`
   - `secure`: Should be `true` for HTTPS
   - `headers`: Check `x-forwarded-for` and `x-forwarded-proto`

3. **Common issues:**
   - `is_trusted_proxy: false` → The proxy IP is not in your `TRUSTED_PROXIES` list
   - `secure: false` with HTTPS → Trusted proxy not configured, so `x-forwarded-proto` header is ignored

4. **Quick fix:** Set `TRUSTED_PROXIES=*` to trust all proxies

### More troubleshooting

If you encounter issues, see the [Docker Compose Troubleshooting](./docker-compose#troubleshooting) section for common problems and solutions.

See also [Docker Networking](../user-guide/database-servers#docker-networking) if you're having issues connecting to your database server.


### Run Artisan Commands

```bash
php artisan migrate:status # Check database migrations
php artisan config:show database # View database configuration
```

### Get Help

- Check the logs: `docker compose logs app`
- Report issues on [GitHub](https://github.com/david-crty/databasement/issues)
