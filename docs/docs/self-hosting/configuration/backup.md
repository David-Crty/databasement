---
sidebar_position: 3
---

# Backup

Configure backup behavior, schedules, compression, and storage settings.

## Backup Configuration

| Variable                   | Description                                               | Default                      |
| -------------------------- | --------------------------------------------------------- | ---------------------------- |
| `BACKUP_WORKING_DIRECTORY` | Temporary directory for backup operations                 | `/tmp/backups`               |
| `BACKUP_COMPRESSION`       | Compression algorithm: `gzip`, `zstd`, or `encrypted`     | `gzip`                       |
| `BACKUP_COMPRESSION_LEVEL` | Compression level (1-9 for gzip/encrypted, 1-19 for zstd) | `6`                          |
| `BACKUP_ENCRYPTION_KEY`    | Encryption key for encrypted backups (AES-256)            | `env('APP_KEY')`             |
| `BACKUP_JOB_TIMEOUT`       | Maximum seconds a backup/restore job can run              | `7200` (2 hours)             |
| `BACKUP_JOB_TRIES`         | Number of times to retry failed jobs                      | `3`                          |
| `BACKUP_JOB_BACKOFF`       | Seconds to wait before retrying                           | `60`                         |
| `BACKUP_DAILY_CRON`        | Cron schedule for daily backups                           | `0 2 * * *` (2:00 AM)        |
| `BACKUP_WEEKLY_CRON`       | Cron schedule for weekly backups                          | `0 3 * * 0` (Sunday 3:00 AM) |
| `BACKUP_CLEANUP_CRON`      | Cron schedule for snapshot cleanup                        | `0 4 * * *` (4:00 AM)        |

## Compression Options

By default, backups are compressed with **gzip** for maximum compatibility. You can switch to **zstd** for better compression ratios, or **encrypted** for AES-256 encrypted backups.

| Method           | CLI Tool | File Extension | Encrypted     |
| ---------------- | -------- | -------------- | ------------- |
| `gzip` (default) | `gzip`   | `.gz`          | No            |
| `zstd`           | `zstd`   | `.zst`         | No            |
| `encrypted`      | `7z`     | `.7z`          | Yes (AES-256) |

:::note
**zstd** typically provides 20-40% better compression than gzip at similar speeds. The default level of 6 provides a good balance between compression ratio and speed for both algorithms.
:::

## Encrypted Backups

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

### S3 IAM Permissions

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

### Access keys (Optional)

This is not recommended but for standard AWS access using access keys:

```bash
AWS_ACCESS_KEY_ID=your-access-key
AWS_SECRET_ACCESS_KEY=your-secret-key
AWS_REGION=us-east-1
```

### S3-Compatible Storage (MinIO, etc.)

For S3-compatible storage providers, configure a custom endpoint:

```bash
AWS_ENDPOINT_URL_S3=https://minio.yourdomain.com
AWS_USE_PATH_STYLE_ENDPOINT=true
# AWS_PUBLIC_ENDPOINT_URL_S3=https://minio.yourdomain.com
```

:::tip
If your internal endpoint differs from the public URL (e.g., `http://minio:9000` vs `http://localhost:9000`), set `AWS_PUBLIC_ENDPOINT_URL_S3` for presigned download URLs to work correctly in your browser.
:::

### IAM Role Assumption (Restricted Environments)

To force Databasement to assume an IAM role, set the `AWS_CUSTOM_ROLE_ARN` environment variable:

```bash
AWS_CUSTOM_ROLE_ARN=arn:aws:iam::123456789:role/your-role-name
```

### AWS Profile Support

If using AWS credential profiles (from `~/.aws/credentials`):

```bash
AWS_S3_PROFILE=my-s3-profile
```

### All S3 Environment Variables

| Variable                      | Description                                     | Default        |
| ----------------------------- | ----------------------------------------------- | -------------- |
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

## Complete Example

Here's a complete backup configuration example:

```bash
# Backup settings
BACKUP_COMPRESSION=zstd
BACKUP_COMPRESSION_LEVEL=6
BACKUP_JOB_TIMEOUT=3600
BACKUP_JOB_TRIES=3

# S3 storage (MinIO example)
AWS_ENDPOINT_URL_S3=http://minio:9000
AWS_PUBLIC_ENDPOINT_URL_S3=https://minio.yourdomain.com
AWS_USE_PATH_STYLE_ENDPOINT=true
AWS_ACCESS_KEY_ID=your-access-key
AWS_SECRET_ACCESS_KEY=your-secret-key
AWS_REGION=us-east-1
```
