---
sidebar_position: 3
---

# Backups

Databasement allows you to create on-demand backups of your databases. Backups are processed asynchronously, so you can continue using the application while they run.

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

## Retention Policies

Retention policies control how long backups are kept before being automatically deleted. Databasement offers two retention strategies:

### Simple (Days-Based)

The simplest option: keep all backups for a specified number of days.

**Example:** With `retention_days = 30`, any backup older than 30 days is automatically deleted.

| Day | Backups Kept |
|-----|--------------|
| 1-30 | All backups |
| 31+ | Deleted |

**Best for:** Short-term backup needs, development environments, or when storage cost isn't a concern.

### GFS (Grandfather-Father-Son)

A tiered retention strategy that keeps recent backups for quick recovery while preserving older snapshots for long-term archival — without keeping every single backup.

**How it works:**

| Tier | What it keeps | Example |
|------|---------------|---------|
| **Daily** (Son) | The N most recent backups | Keep last 7 backups |
| **Weekly** (Father) | 1 backup per week for N weeks | Keep 1/week for 4 weeks |
| **Monthly** (Grandfather) | 1 backup per month for N months | Keep 1/month for 12 months |

**Example with default values (7 daily, 4 weekly, 12 monthly):**

After running daily backups for a year, you'll have approximately:
- **7 recent backups** (last 7 days)
- **4 weekly backups** (one from each of the past 4 weeks)
- **12 monthly backups** (one from each of the past 12 months)

**Total: ~23 backups** instead of 365 — saving significant storage while maintaining recovery points across the entire year.

```
Timeline visualization:

Today ◄─────────────────────────────────────────────── 1 year ago
  │                                                        │
  ├── Daily ──┤ (7 backups)                                │
  │           │                                            │
  ├── Weekly ─────────┤ (4 backups)                        │
  │                   │                                    │
  └── Monthly ─────────────────────────────────────────────┘ (12 backups)
```

**Best for:** Production environments, compliance requirements, or when you need long-term recovery options without excessive storage costs.

### Choosing a Retention Policy

| Scenario | Recommended Policy |
|----------|-------------------|
| Development/testing | Simple: 7-14 days |
| Small production app | Simple: 30 days |
| Business-critical data | GFS: 7d/4w/12m |
| Compliance requirements | GFS: 14d/8w/24m |

:::tip
You can disable any GFS tier by leaving it empty. For example, setting only "Monthly: 12" keeps just one backup per month for a year.
:::

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
