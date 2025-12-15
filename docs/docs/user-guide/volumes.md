---
sidebar_position: 5
---

# Storage Volumes

Storage volumes are the destinations where your backup files are stored. DBBackup supports local filesystem storage and S3-compatible object storage.

## Volume Types

### Local Storage

Store backups directly on the DBBackup server's filesystem. This is the simplest option but requires adequate disk space.

**Best for:**
- Small deployments
- Testing and development
- Fast local access to backups

**Considerations:**
- Backups are lost if the server fails
- Limited by server disk capacity
- Not suitable for distributed setups

### S3-Compatible Storage

Store backups in Amazon S3 or any S3-compatible service:

- Amazon S3
- MinIO
- DigitalOcean Spaces
- Backblaze B2
- Wasabi
- And many others

**Best for:**
- Production environments
- Disaster recovery
- Large-scale backups
- Geographic redundancy

## Creating a Volume

### Local Volume

1. Navigate to **Volumes**
2. Click **Add Volume**
3. Select **Local** as the type
4. Configure:

| Field | Description |
|-------|-------------|
| **Name** | Friendly name for this volume |
| **Path** | Absolute path on the filesystem (e.g., `/backups`) |

5. Click **Save**

:::warning
Ensure the DBBackup container has write access to the specified path. You may need to mount a volume when running Docker.
:::

### S3 Volume

1. Navigate to **Volumes**
2. Click **Add Volume**
3. Select **S3** as the type
4. Configure:

| Field | Description |
|-------|-------------|
| **Name** | Friendly name for this volume |
| **Bucket** | S3 bucket name |
| **Region** | AWS region (e.g., `us-east-1`) |
| **Access Key** | AWS access key ID |
| **Secret Key** | AWS secret access key |
| **Endpoint** | Custom endpoint (for non-AWS S3 services) |
| **Path Prefix** | Optional folder prefix within the bucket |

5. Click **Test Connection** to verify access
6. Click **Save**

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

## Using Non-AWS S3 Services

### MinIO

```
Endpoint: http://minio.yourdomain.com:9000
Region: us-east-1 (or any value)
Access Key: your-minio-access-key
Secret Key: your-minio-secret-key
```

### DigitalOcean Spaces

```
Endpoint: https://nyc3.digitaloceanspaces.com
Region: nyc3
Access Key: your-spaces-access-key
Secret Key: your-spaces-secret-key
```

### Backblaze B2

```
Endpoint: https://s3.us-west-000.backblazeb2.com
Region: us-west-000
Access Key: your-b2-application-key-id
Secret Key: your-b2-application-key
```

## Editing Volumes

1. Go to **Volumes**
2. Find the volume to edit
3. Click **Edit**
4. Update settings
5. Test the connection
6. Save changes

## Deleting Volumes

1. Go to **Volumes**
2. Find the volume to delete
3. Click **Delete**
4. Confirm the deletion

:::warning
Deleting a volume does not delete the backup files stored in it. Files remain in the storage location but become inaccessible through DBBackup.
:::

## Volume Management Tips

### Capacity Planning

- Monitor free space on local volumes
- Set up S3 lifecycle policies for automatic cleanup
- Estimate storage needs based on database size and backup frequency

### Organization

Use path prefixes to organize backups:
- `/production/mysql/` - Production MySQL backups
- `/staging/postgres/` - Staging PostgreSQL backups
- `/daily/` - Daily backup schedule

### Cost Optimization for S3

- Use S3 Intelligent-Tiering for automatic cost optimization
- Configure lifecycle policies to move old backups to cheaper storage classes
- Consider S3 Glacier for long-term retention
