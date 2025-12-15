---
sidebar_position: 3
---

# Backups

DBBackup allows you to create on-demand backups of your databases. Backups are processed asynchronously, so you can continue using the application while they run.

## Creating a Backup

### Manual Backup

1. Go to **Database Servers**
2. Find the server you want to backup
3. Click **Backup**
4. Select the target database (or leave empty for all databases)
5. Choose a storage volume for the backup
6. Click **Start Backup**

The backup will be queued and processed in the background. You can monitor progress on the **Snapshots** page.

## How Backups Work

When you create a backup, DBBackup:

1. Connects to the database server
2. Runs the appropriate dump command (`mysqldump` or `pg_dump`)
3. Compresses the output with gzip
4. Transfers the compressed file to the selected storage volume
5. Creates a snapshot record with metadata

### Backup Commands

DBBackup uses native database tools for reliable backups:

**MySQL / MariaDB:**
```bash
mysqldump --single-transaction --routines --triggers --events database_name
```

**PostgreSQL:**
```bash
pg_dump --format=plain database_name
```

## Backup Status

Each backup (snapshot) has a status:

| Status | Description |
|--------|-------------|
| **Pending** | Backup is queued, waiting to be processed |
| **In Progress** | Backup is currently running |
| **Completed** | Backup finished successfully |
| **Failed** | Backup encountered an error |

## Monitoring Backups

View all backup jobs on the **Snapshots** page. You can see:

- Source server and database
- Backup timestamp
- File size
- Storage volume
- Status

## Failed Backups

If a backup fails, check:

1. **Database connectivity**: Can DBBackup still connect to the server?
2. **Disk space**: Is there enough space on the storage volume?
3. **Permissions**: Does the database user have backup privileges?
4. **Timeout**: Large databases may need more time

Failed backup reasons are logged and visible in the snapshot details.

## Best Practices

### Before Production Backups

1. **Test the connection** before creating backups
2. **Verify restore** by testing a restore to a development server
3. **Monitor disk space** on your storage volumes

### Backup Sizing

Compressed backup sizes vary, but as a rough guide:
- A 1GB database typically compresses to 100-300MB
- Text-heavy data compresses better than binary data

### Security Considerations

- Use dedicated backup users with minimal required privileges
- Store backups in secure, encrypted storage when possible
- Regularly test your restore process
