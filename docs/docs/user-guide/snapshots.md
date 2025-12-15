---
sidebar_position: 4
---

# Snapshots

Snapshots are the backup files created when you backup a database. They contain all the data needed to restore your database to a specific point in time.

## Viewing Snapshots

Navigate to **Snapshots** to see all your backup snapshots. The list shows:

- Source server and database
- Creation timestamp
- File size
- Storage volume location
- Status

## Snapshot Details

Click on a snapshot to see detailed information:

- **Full path** in the storage volume
- **Creation time** and duration
- **Database type** and version
- **Compression ratio**
- **Checksum** for integrity verification

## Restoring a Snapshot

DBBackup supports flexible restore options:

### 1. Same Server Restore (Rollback)

Restore to the same server and database:

1. Find the snapshot you want to restore
2. Click **Restore**
3. Confirm you want to restore to the original location
4. Click **Start Restore**

:::warning
This will overwrite all current data in the target database!
:::

### 2. Different Database Restore

Restore to a different database on the same server:

1. Find the snapshot
2. Click **Restore**
3. Select the same server but specify a different database name
4. Click **Start Restore**

### 3. Cross-Server Restore

Restore to a completely different server:

1. Find the snapshot
2. Click **Restore**
3. Select a different target server
4. Choose the target database
5. Click **Start Restore**

:::note
Cross-server restores require matching database types. You cannot restore a MySQL backup to PostgreSQL.
:::

## Restore Process

When you restore a snapshot, DBBackup:

1. Downloads the snapshot from storage
2. Decompresses the backup file
3. Connects to the target database server
4. Drops and recreates the target database (if it exists)
5. Restores the data using native database tools

### Restore Commands

**MySQL / MariaDB:**
```bash
mysql database_name < backup.sql
```

**PostgreSQL:**
```bash
psql database_name < backup.sql
```

## Restore Status

Like backups, restores have status:

| Status | Description |
|--------|-------------|
| **Pending** | Restore is queued |
| **In Progress** | Restore is running |
| **Completed** | Restore finished successfully |
| **Failed** | Restore encountered an error |

## Deleting Snapshots

To delete a snapshot:

1. Find the snapshot in the list
2. Click **Delete**
3. Confirm the deletion

This removes both the snapshot record and the actual backup file from storage.

## Snapshot Retention

Consider implementing a retention policy:

- Keep daily backups for a week
- Keep weekly backups for a month
- Keep monthly backups for a year

You can manually delete old snapshots or implement automated cleanup based on your needs.

## Downloading Snapshots

If you need to download a backup file directly:

1. Go to the snapshot details
2. Click **Download** (if available for your storage type)

For S3 storage, you can also access files directly through your S3 client or console.
