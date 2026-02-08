---
sidebar_position: 3
---

# Backup

Backup settings (compression, schedules, timeouts, etc.) can be configured directly from the **Configuration** page in the web UI.

This page covers additional setup that requires environment variables.

## Encrypted Backups

When using `encrypted` compression, backups are encrypted with AES-256 using 7-Zip. The encryption key defaults to `APP_KEY`, but you can set a dedicated key:

```bash
BACKUP_ENCRYPTION_KEY=base64:your-32-byte-key-here
```

You can generate a key with:

```bash
echo "base64:$(openssl rand -base64 32)"
```

:::warning
If you change the encryption key, you will not be able to restore backups that were encrypted with the previous key. Keep your encryption key safe and backed up separately.
:::

## S3 Storage

Databasement supports AWS S3 and S3-compatible storage (MinIO, DigitalOcean Spaces, etc.) for backup volumes.

We use environment variables to configure the S3 client.

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
