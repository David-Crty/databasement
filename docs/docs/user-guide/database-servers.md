---
sidebar_position: 2
---

# Database Servers

Database servers are the source of your backups. Databasement can connect to and backup MySQL, PostgreSQL, and MariaDB servers.

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
