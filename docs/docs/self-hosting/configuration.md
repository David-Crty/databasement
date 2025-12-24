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

### S3 Storage

Databasement supports AWS S3 and S3-compatible storage (MinIO, DigitalOcean Spaces, etc.) for backup volumes.

## S3 IAM Permissions

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

#### Basic Configuration (Static Credentials) (Optional)

For standard AWS access using access keys:

```bash
AWS_ACCESS_KEY_ID=your-access-key
AWS_SECRET_ACCESS_KEY=your-secret-key
AWS_REGION=us-east-1
```

:::note
The AWS SDK automatically picks up `AWS_ACCESS_KEY_ID` and `AWS_SECRET_ACCESS_KEY` from the environment.
:::

#### S3-Compatible Storage (MinIO, etc.)

For S3-compatible storage providers, configure a custom endpoint:

```bash
AWS_ACCESS_KEY_ID=your-access-key
AWS_SECRET_ACCESS_KEY=your-secret-key
AWS_REGION=us-east-1
AWS_ENDPOINT_URL_S3=https://minio.yourdomain.com
AWS_USE_PATH_STYLE_ENDPOINT=true
```

#### IAM Role Assumption (Restricted Environments)

For environments with restricted network access (VPC endpoints, private links), you can use IAM role assumption via STS:

```bash
AWS_REGION=eu-central-1
AWS_ROLE_ARN=arn:aws:iam::123456789:role/your-role-name
AWS_ROLE_SESSION_NAME=databasement
AWS_ENDPOINT_URL_STS=https://vpce-xxx.sts.eu-central-1.vpce.amazonaws.com
AWS_ENDPOINT_URL_S3=https://bucket.vpce-xxx.s3.eu-central-1.vpce.amazonaws.com
AWS_USE_PATH_STYLE_ENDPOINT=true
```

#### AWS Profile Support

If using AWS credential profiles (from `~/.aws/credentials`):

```bash
AWS_S3_PROFILE=my-s3-profile
AWS_STS_PROFILE=my-sts-profile
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
