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

All S3 settings (region, credentials, endpoints) are configured **per-volume** in the web UI when creating or editing an S3 volume. See the [Volumes user guide](../../user-guide/volumes#s3-storage) for field descriptions.

### S3 IAM Permissions

The AWS credentials used by each S3 volume need these permissions:

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

### Credentials

You can provide explicit **Access Key ID** and **Secret Access Key** in the volume form. However, credentials are optional — when left blank, the AWS SDK uses its [default credential chain](https://docs.aws.amazon.com/sdk-for-php/v3/developer-guide/guide_credentials.html):

- Environment variables (`AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`)
- EC2/ECS instance roles
- EKS IRSA (IAM Roles for Service Accounts)

This means deployments on AWS infrastructure can work without storing any credentials in the database.

### S3-Compatible Storage (MinIO, etc.)

For S3-compatible providers, set the **Custom Endpoint** and enable **Use Path-Style Endpoint** in the Advanced S3 Settings section of the volume form.

:::tip
If your internal endpoint differs from the public URL (e.g., `http://minio:9000` vs `http://localhost:9000`), set the **Public Endpoint** field so presigned download URLs work correctly in your browser.
:::

### Migration from Environment Variables

If you previously configured S3 via environment variables (`AWS_ACCESS_KEY_ID`, `AWS_REGION`, etc.), the migration automatically copies those values into each existing S3 volume's config. After upgrading, you can remove the AWS environment variables from your `.env` file.
