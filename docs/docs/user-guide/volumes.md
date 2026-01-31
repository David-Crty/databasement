---
sidebar_position: 5
---

# Storage Volumes

Storage volumes are the destinations where your backup files are stored. Databasement supports multiple storage backends to fit your infrastructure needs.

## Volume Types

### Local Storage

Local volumes store backups on the filesystem where Databasement is running. This is the simplest option for single-server setups.

| Field | Description |
|-------|-------------|
| **Path** | Absolute path to the backup directory |

:::info
Ensure the Databasement container has write access to the specified path. You may need to mount a volume when running Docker:
```bash
docker run -v /path/on/host:/backups davidcrty/databasement
```
:::

### S3 Storage

S3 volumes store backups in AWS S3 or any S3-compatible object storage (MinIO, DigitalOcean Spaces, Backblaze B2, etc.).

| Field | Description |
|-------|-------------|
| **Bucket** | S3 bucket name |
| **Prefix** | Optional path prefix within the bucket (e.g., `backups/production/`) |

:::info
S3 credentials are configured via environment variables, not per-volume. See the [S3 Storage configuration](../self-hosting/configuration.md#s3-storage) for setup instructions.
:::

### SFTP Storage

SFTP volumes store backups on a remote server via SSH File Transfer Protocol. This is ideal for storing backups on a dedicated backup server or NAS.

| Field | Description |
|-------|-------------|
| **Host** | SFTP server hostname or IP address |
| **Port** | SSH port (default: 22) |
| **Username** | SSH username |
| **Password** | SSH password |
| **Root Directory** | Base path on the remote server (e.g., `/backups`) |
| **Connection Timeout** | Timeout in seconds (default: 10) |

:::note
Only password authentication is currently supported. SSH key authentication is planned for a future release.
:::

:::tip
The password is encrypted at rest in the database using Laravel's encryption. It is never stored in plain text.
:::

### FTP Storage

FTP volumes store backups on a remote FTP server. Both standard FTP and FTPS (FTP over SSL/TLS) are supported.

| Field | Description |
|-------|-------------|
| **Host** | FTP server hostname or IP address |
| **Port** | FTP port (default: 21) |
| **Username** | FTP username |
| **Password** | FTP password |
| **Root Directory** | Base path on the remote server (e.g., `/backups`) |
| **Enable SSL** | Use FTPS (FTP over SSL/TLS) for encrypted transfers |
| **Passive Mode** | Use passive mode for data connections (recommended for most setups) |
| **Connection Timeout** | Timeout in seconds (default: 90) |

:::warning
Standard FTP transmits credentials and data in plain text. Enable SSL for secure transfers, or prefer SFTP for better security.
:::

:::tip
The password is encrypted at rest in the database using Laravel's encryption. It is never stored in plain text.
:::

## Connection Testing

Before saving a volume, use the **Test Connection** button to verify:
- The storage location is accessible
- Write permissions are configured correctly
- Credentials are valid (for SFTP/FTP)

The test creates a small temporary file, reads it back to verify integrity, then deletes it.

## Volume Immutability

Once a volume has backup snapshots associated with it, the storage configuration becomes read-only. This protects backup integrity by ensuring snapshots always point to their original storage location.

You can still rename the volume, but the storage type and configuration cannot be changed. To use different storage settings, create a new volume.
