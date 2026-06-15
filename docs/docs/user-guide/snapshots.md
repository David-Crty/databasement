---
sidebar_position: 5
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

**Firebird:**
```bash
gbak -rep -user '...' -password '...' '/path/to/dump.fbk' 'host/port:/path/to/target.fdb'
```

**MongoDB:**
```bash
mongorestore --host='...' --port='...' --username='...' --password='...' \
  --authenticationDatabase='admin' --archive='/path/to/dump.archive' \
  --nsFrom='source_db.*' --nsTo='target_db.*' --drop
```

:::info
Redis/Valkey restore is not currently supported. RDB file imports require direct server access.
:::

All snapshots are decompressed with `gzip -d` before restore.

## Scheduled Restores

You can replay the **latest completed snapshot** of a source database onto a target server on a recurring schedule — useful for keeping a staging or QA database refreshed from production.

A scheduled restore is configured with:

- **Source server** (and database name, unless the type is whole-instance like SQLite or Redis)
- **Target server** and destination database name/path
- **Schedule** — reuses the same cron schedules defined under Configuration → Backup

On each run, Databasement picks the most recent completed snapshot of the source database and runs the normal [restore process](#restore-process) against the target. Source and target must be of the same database type.

