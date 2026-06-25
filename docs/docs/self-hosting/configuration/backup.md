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

## Hook Scripts

Run a shell script after **every successful backup** and after **every successful restore**. Configure both from **Configuration → Backup → Hook Scripts** in the web UI — that screen also lists every variable available to each script.

Typical uses: send a custom notification, trigger an off-site sync, ping a healthcheck endpoint, or invalidate a cache.

- Each script is implicitly prefixed with `#!/bin/sh` and run after the operation has already succeeded.
- Context is passed as environment variables (e.g. `$BACKUP_DATABASE_NAME`, `$BACKUP_FILENAME`, `$RESTORE_DATABASE_NAME`) — see the full list next to each script in the UI.
- **stdout/stderr stream into the job log**, so you can inspect output from the snapshot/restore detail view.
- A non-zero exit code is logged as a **warning** but does **not** fail the backup or restore.

The post-backup script runs wherever the backup runs — the server/queue worker, or the **agent** in [agent mode](../intro) (the script is sent inside the job's work order). Restores are never delegated to agents, so the post-restore script only runs on the server/queue worker.

:::warning
Scripts run with the same privileges as the worker process and can only be edited by administrators. Treat them like any other server-side automation.
:::

### Available binaries

Commands must exist on the host that runs the script. The **official Docker image** is Alpine-based and ships:

| Purpose | Commands |
| --- | --- |
| Shell | `sh` (default), `bash` |
| HTTP / network | `curl`, `wget` (BusyBox), `ssh`, `scp`, `sshpass` |
| Archives | `gzip`, `zstd`, `7z`, `unzip` |
| Database clients | `mysql` / `mariadb`, `mysqldump`, `psql`, `pg_dump`, `pg_restore`, `redis-cli`, `sqlite3`, `mongodump`, `mongorestore`, `gbak`, `isql` |
| Core utilities | `date`, `grep`, `sed`, `awk`, `find`, `cat`, `head`, `tail`, `cut`, `tr`, `wc`, `ps`, `git` |

:::note
`jq`, `python`, `aws`, and `rclone` are **not** in the official image — install them into a custom image (or your native/agent host) first.
:::

### Examples

Notify a webhook with the backup details:

```sh
# Post-backup
curl -fsS -X POST https://example.com/hooks/backup \
  -d "database=$BACKUP_DATABASE_NAME" \
  -d "file=$BACKUP_FILENAME" \
  -d "size=$BACKUP_FILE_SIZE"
```

Run different logic for a specific server, based on its ID:

```sh
# Post-backup: only ping the prod healthcheck for one server
if [ "$BACKUP_SERVER_ID" = "01JCABCDEF0123456789ABCDEF" ]; then
  echo "Production backup done, pinging healthcheck"
  curl -fsS "https://hc-ping.com/your-uuid"
else
  echo "Skipping healthcheck for server $BACKUP_SERVER_NAME ($BACKUP_SERVER_ID)"
fi
```

Debug the available environment variables:

```sh
echo "=== post-backup hook ==="
echo "Server ID    : $BACKUP_SERVER_ID"
echo "Server name  : $BACKUP_SERVER_NAME"
echo "Database     : $BACKUP_DATABASE_NAME"
echo "DB type      : $BACKUP_DATABASE_TYPE"
echo "Filename     : $BACKUP_FILENAME"
echo "File size    : $BACKUP_FILE_SIZE bytes"
echo "Checksum     : $BACKUP_CHECKSUM"
echo "Volume       : $BACKUP_VOLUME_NAME"
echo "Finished at  : $(date)"
echo "=== done ==="
```

Post-restore notification:

```sh
# Post-restore
curl -fsS -X POST https://example.com/hooks/restore \
  -d "server=$RESTORE_SERVER_NAME" \
  -d "target=$RESTORE_DATABASE_NAME" \
  -d "source=$RESTORE_SOURCE_DATABASE"
```

:::tip
You can find a server's ID in its detail page.
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
