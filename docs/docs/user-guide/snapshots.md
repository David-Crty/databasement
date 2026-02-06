---
sidebar_position: 4
---

# Snapshots

Snapshots are the backup files created when you backup a database. They contain all the data needed to restore your database to a specific point in time.

## File Verification

Databasement verifies daily that backup files still exist on their storage volumes. Missing files are surfaced on the dashboard and in the jobs index with a "File missing" warning.

You can also trigger verification manually from the dashboard.

See [Backup Configuration](/self-hosting/configuration/backup) for `BACKUP_VERIFY_FILES` and `BACKUP_VERIFY_FILES_CRON` settings.

## Restore Process

When you restore a snapshot, Databasement:

1. Downloads the snapshot from storage
2. Decompresses the backup file
3. Connects to the target database server
4. Drops and recreates the target database (if it exists)
5. Restores the data using native database tools

### Restore Commands

**MySQL/MariaDB:**
```bash
mariadb --host='...' --port='...' --user='...' --password='...' --skip_ssl \
  'database_name' -e "source /path/to/dump.sql"
```

**PostgreSQL:**
```bash
PGPASSWORD='...' psql --host='...' --port='...' --username='...' \
  'database_name' -f '/path/to/dump.sql'
```

**SQLite:**
```bash
cp '/path/to/snapshot' '/path/to/database.sqlite'
```

All snapshots are decompressed with `gzip -d` before restore.
