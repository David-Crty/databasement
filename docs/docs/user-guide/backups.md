---
sidebar_position: 3
---

# Backups

Databasement allows you to create on-demand backups of your databases. Backups are processed asynchronously, so you can continue using the application while they run.

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

When you create a backup, Databasement:

1. Connects to the database server
2. Runs the appropriate dump command (`mysqldump` or `pg_dump`)
3. Compresses the output with gzip
4. Transfers the compressed file to the selected storage volume
5. Creates a snapshot record with metadata

### Backup Commands

Databasement uses native database tools for reliable backups:

**MySQL/MariaDB:**
```bash
mariadb-dump --routines --add-drop-table --complete-insert --hex-blob --quote-names --skip_ssl \
  --host='...' --port='...' --user='...' --password='...' 'database_name' > dump.sql
```

**PostgreSQL:**
```bash
PGPASSWORD='...' pg_dump --clean --if-exists --no-owner --no-privileges --quote-all-identifiers \
  --host='...' --port='...' --username='...' 'database_name' -f dump.sql
```

**SQLite:**
```bash
cp '/path/to/database.sqlite' dump.db
```

All dumps are then compressed with gzip before being transferred to the storage volume.

## Failed Backups

If a backup fails, check:

1. **Database connectivity**: Can Databasement still connect to the server?
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
