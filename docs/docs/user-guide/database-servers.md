---
sidebar_position: 2
---

# Database Servers

Database servers are the source of your backups. Databasement can connect to and backup MySQL, PostgreSQL, MariaDB, Microsoft SQL Server, MongoDB, SQLite, Firebird, and Redis/Valkey servers.

## Supported Versions

Databasement uses standard CLI tools to perform backup and restore operations. The table below shows which database engine versions are supported, based on the CLI tools shipped in the Docker image.

| Engine     | Supported Versions           | CLI Tool                     | Restore |
|------------|------------------------------|------------------------------|---------|
| MySQL      | 5.6, 5.7, 8.x, 9.x           | `mariadb-dump`               | Yes     |
| MariaDB    | 10.x, 11.x, 12.x             | `mariadb-dump`               | Yes     |
| PostgreSQL | 12, 13, 14, 15, 16, 17, 18   | `pg_dump` v18                | Yes     |
| SQL Server | 2017, 2019, 2022, Azure SQL  | `sqlpackage` (`.dacpac`)     | Yes     |
| MongoDB    | 4.2, 4.4, 5.0, 6.0, 7.0, 8.0 | `mongodump` / `mongorestore` | Yes     |
| SQLite     | 3.x                          | File copy                    | Yes     |
| Firebird   | 3.x, 4.x, 5.x                | `gbak` v5                    | Yes     |
| Redis      | 2.8+                         | `redis-cli --rdb`            | No      |
| Valkey     | 7.2+                         | `redis-cli --rdb`            | No      |

:::info How this works
- **MySQL / MariaDB**: Databasement ships the MariaDB 11.4 client (`mariadb-dump`), which is wire-protocol compatible with MySQL servers.
- **PostgreSQL**: The `pg_dump` v18 client can dump from any server version back to 9.2. Versions below 12 have reached end-of-life and are not recommended.
- **SQL Server**: Backups are extracted as `.dacpac` files (schema + table data) using Microsoft's `sqlpackage` CLI (`/Action:Extract`) and re-applied with `/Action:Publish`. Server-bound objects (logins, users, permissions, role memberships) are excluded so backups stay portable across instances and don't fail on Windows-auth principals like `[NT AUTHORITY\SYSTEM]`. Works against on-prem SQL Server 2017+ and Azure SQL Database. Connections use the `pdo_sqlsrv` PHP extension.
- **MongoDB**: The MongoDB Database Tools (`mongodump` / `mongorestore`) officially support server versions 4.2 through 8.0.
- **SQLite**: Backups are performed by copying the database file over SFTP. The SQLite 3.x file format has been backwards-compatible since 3.0.0 (2004).
- **Firebird**: Backups use `gbak` to produce a portable `.fbk` transportable backup file; restore replays it with `gbak -rep`, which replaces the target `.fdb` if one already exists. Databasement ships the Firebird 5 client, which can back up and restore Firebird 3.x, 4.x, and 5.x servers. Each `.fdb` file on the server is its own database, so backup configuration is path-based (like SQLite) rather than name-based.
- **Redis / Valkey**: `redis-cli --rdb` creates a point-in-time RDB snapshot via the replication protocol. Valkey 7.2+ is supported as a drop-in replacement for Redis. Restore is not supported.
:::

## Connection Requirements

### MySQL / MariaDB

#### Creating the user

```sql
CREATE USER 'databasement'@'%' IDENTIFIED BY 'your_secure_password';
```

#### Permissions for backup and restore (all databases)

```sql
GRANT SELECT, SHOW VIEW, TRIGGER, LOCK TABLES, PROCESS, EVENT, RELOAD,
      CREATE, DROP, ALTER, INDEX, INSERT, UPDATE, DELETE, REFERENCES
ON *.* TO 'databasement'@'%';

FLUSH PRIVILEGES;
```

:::note[Single database only]
To restrict the user to a single database, replace `*.*` with `database_name.*`. Note that with single-database permissions, the user cannot create or drop the database itself - you'll need to ensure the target database exists before restoring.
:::

### PostgreSQL

#### Creating the user

```sql
CREATE USER databasement WITH PASSWORD 'your_secure_password';
```

#### Permissions for backup and restore (all databases)

For full backup and restore capabilities, the user needs elevated privileges. The method depends on your PostgreSQL setup:

#### Self-hosted PostgreSQL

```sql
-- Option 1: Superuser (full access)
ALTER USER databasement WITH SUPERUSER;

-- Option 2: Create database privilege (can create/drop databases for restore)
ALTER USER databasement WITH CREATEDB;
```

#### AWS RDS PostgreSQL

RDS doesn't allow `SUPERUSER`. Grant the `rds_superuser` role instead:

```sql
GRANT rds_superuser TO databasement;
```

#### Azure Database for PostgreSQL

Azure uses the `azure_pg_admin` role:

```sql
GRANT azure_pg_admin TO databasement;
```

#### Additional grants for non-superuser setups

If not using superuser/rds_superuser/azure_pg_admin, grant access to existing databases:

```sql
-- Grant ownership or full privileges on the database
GRANT ALL PRIVILEGES ON DATABASE database_name TO databasement;

-- Connect to the database and grant schema access
\c database_name
GRANT ALL PRIVILEGES ON SCHEMA public TO databasement;
GRANT ALL PRIVILEGES ON ALL TABLES IN SCHEMA public TO databasement;
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT ALL ON TABLES TO databasement;
```

:::note[Single database only]
For single-database access without `CREATEDB`, the target database must already exist. Grant `ALL PRIVILEGES` on that specific database and its schema. The user won't be able to drop/recreate the database during restore - Databasement will drop and recreate tables instead.
:::

### Microsoft SQL Server

SQL Server uses `sqlpackage` to extract and publish `.dacpac` files (schema + table data). Supports on-prem SQL Server 2017+ and Azure SQL Database (default port: 1433). Server-level objects (logins, users, permissions, role memberships) are excluded from the backup.

The login needs `db_owner` on each target database, plus permission to drop and create databases (restore drops the target first). `sysadmin` works; on Azure SQL, use a server admin or grant `dbmanager` on `master`.

:::info Cross-edition restore
Restoring across editions (e.g. Azure SQL → on-prem) may fail if the source uses features the target doesn't support — a `sqlpackage` limitation.
:::

### MongoDB

MongoDB uses the `mongodump` and `mongorestore` CLI tools for backup and restore operations.

#### Connection settings

| Field | Description |
|-------|-------------|
| Host | MongoDB server hostname or IP |
| Port | MongoDB port (default: 27017) |
| Username | Database user (optional for unauthenticated instances) |
| Password | User password |
| Auth Source | Authentication database (default: `admin`) |

#### Creating a backup user

```javascript
use admin
db.createUser({
  user: "databasement",
  pwd: "your_secure_password",
  roles: [
    { role: "readAnyDatabase", db: "admin" },
    { role: "backup", db: "admin" },
    { role: "restore", db: "admin" }
  ]
})
```

:::note
The `admin`, `local`, and `config` system databases are automatically excluded from the database list.
:::

### Redis / Valkey

Redis and Valkey instances are backed up using `redis-cli --rdb`, which creates a point-in-time RDB snapshot of the entire dataset.

#### Connection settings

| Field | Description |
|-------|-------------|
| Host | Redis server hostname or IP |
| Port | Redis port (default: 6379) |
| Username | ACL username (optional, Redis 6+) |
| Password | Server password or ACL user password |

:::info Backup only
Redis/Valkey supports backup only. Restore is not currently supported due to the nature of RDB file imports, which require direct server access.
:::

### SQLite

SQLite databases are backed up by copying the database file directly. Databasement connects to the remote server via SFTP (through an SSH tunnel) to access the file.

#### Connection settings

| Field | Description |
|-------|-------------|
| Database paths | One or more absolute paths to `.sqlite` files on the remote server |

:::note
SQLite requires an SSH tunnel to access remote database files. Databasement uses SFTP over the tunnel to copy and restore files.
:::

### Firebird

Firebird databases are backed up using the `gbak` CLI, which connects to the Firebird server over TCP and streams a transportable backup file. Each `.fdb` file on the server is its own database, so the connection is configured with both server credentials and one or more file paths.

#### Connection settings

| Field | Description |
|-------|-------------|
| Host | Firebird server hostname or IP |
| Port | Firebird port (default: 3050) |
| Username | Firebird user (default: `SYSDBA`) |
| Password | Server password |
| Database paths | One or more absolute paths to `.fdb` files on the server |

#### Creating a backup user

`SYSDBA` works out of the box. For a dedicated backup account:

```sql
CREATE USER databasement PASSWORD 'your_secure_password';
GRANT RDB$ADMIN TO databasement;
```

:::note Restore replaces the target file
Restore is performed with `gbak -rep`, which writes a fresh `.fdb` at the target path and replaces an existing file at that path if one is present. The user supplies the destination path during restore.
:::

## Browsing Data with Adminer

Databasement can launch [Adminer](https://www.adminer.org/) directly against a registered server to inspect schema and run queries from the browser. Supported for **MySQL**, **PostgreSQL**, and **SQLite** servers that connect without an SSH tunnel.

Enabled by default for Admins only. A Super Admin can change the minimum required role (Viewer, Member, or Admin) or disable the feature entirely under **Configuration → Application**. When enabled, a *Browse* action appears on compatible servers in the Database Servers list and opens Adminer pre-authenticated with the server's stored credentials.

## Troubleshooting Connection Issues

### Common Connection Issues

| Error              | Solution                                                   |
|--------------------|------------------------------------------------------------|
| Connection refused | Verify host, port, and that the database server is running |
| Access denied      | Check username and password                                |
| Unknown host       | Verify the hostname is correct and DNS is resolving        |
| Connection timeout | Check firewall rules and network connectivity              |


### Docker Networking

When running Databasement in Docker and connecting to databases in other containers, you need to ensure network connectivity between them.

#### Containers in Different docker-compose Projects

By default, each docker-compose project creates its own isolated network. To connect to a database in another project:

**Option 1: Use an external network (recommended)**

1. Create a shared network:
   ```bash
   docker network create shared-db-network
   ```

2. In your application database's `docker-compose.yml`, add the external network:
   ```yaml
   services:
     mysql:
       # ... your config
       networks:
         - default
         - shared-db-network

   networks:
     shared-db-network:
       external: true
   ```

3. In Databasement's `docker-compose.yml`, add the same network:
   ```yaml
   services:
     app:
       # ... your config
       networks:
         - default
         - shared-db-network
     worker:
       # ... your config
       networks:
         - default
         - shared-db-network

   networks:
     shared-db-network:
       external: true
   ```

4. Restart both projects and use the container name as the host (e.g., `mysql`).

**Option 2: Connect to an existing network**

Find the network name of your database container:
```bash
docker network ls
docker inspect <container_name> | grep -A 20 "Networks"
```

Then connect Databasement to that network:
```yaml
networks:
  other-project_default:
    external: true
```

#### Standalone Docker Containers (no docker-compose)

For containers started with `docker run`:

1. Create a network if you don't have one:
   ```bash
   docker network create my-network
   ```

2. Start your database container on that network:
   ```bash
   docker run -d --name mysql --network my-network mysql:8
   ```

3. Connect Databasement to the same network:
   ```bash
   docker network connect my-network databasement-app
   ```

4. Use the container name (`mysql`) as the host in Databasement.

#### Using Host Network Mode

If your database is accessible on the host machine (e.g., installed directly or exposed via port mapping), you can use host network mode:

```yaml
services:
  app:
    network_mode: host
```

Then use `localhost` or `127.0.0.1` as the host. Note that this disables Docker's network isolation.

#### Connecting to Host Machine's Database

If your database runs directly on the host machine (not in Docker):

| Platform      | Host to use                                    |
|---------------|------------------------------------------------|
| Linux         | `172.17.0.1` or `host.docker.internal` (Docker 20.10+) |
| macOS/Windows | `host.docker.internal`                         |

Example: If MySQL is running on your laptop on port 3306, use `host.docker.internal:3306`.

### Firewall Considerations

Ensure your firewall allows connections:

- **Docker networks**: Usually handled automatically
- **Host firewall (iptables/ufw)**: May need rules for Docker bridge networks
- **Cloud firewalls (AWS Security Groups, etc.)**: Add inbound rules for the database port

## SSH Tunnel

Connect to databases that aren't directly reachable from Databasement — in private networks, behind a bastion/jump host, or on a remote Docker host whose database ports aren't exposed. Databasement opens the tunnel before each backup/restore and closes it afterward, with credentials encrypted at rest.

See [SSH Tunnel](./ssh-tunnel.md) for configuration and for backing up databases on a remote host.
