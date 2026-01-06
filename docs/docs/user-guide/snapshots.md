---
sidebar_position: 4
---

# Snapshots

Snapshots are the backup files created when you backup a database. They contain all the data needed to restore your database to a specific point in time.

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
