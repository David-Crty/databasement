---
sidebar_position: 2
---

# Application

Core application settings including database, timezone, proxy configuration, and logging.

## Application Settings

| Variable    | Description                                      | Default                 |
| ----------- | ------------------------------------------------ | ----------------------- |
| `APP_DEBUG` | Enable debug mode (set to `false` in production) | `false`                 |
| `APP_URL`   | Full URL where the app is accessible             | `http://localhost:2226` |
| `APP_KEY`   | Application encryption key (required)            | -                       |
| `TZ`        | Application timezone                             | `UTC`                   |

### Timezone Configuration

Set the `TZ` environment variable to configure the application timezone.
```bash
TZ=America/New_York
TZ=Europe/London
TZ=Asia/Tokyo
```

See the [list of supported timezones](https://www.php.net/manual/en/timezones.php).

### Generating the Application Key

The `APP_KEY` is required for encryption. Generate one with:

```bash
docker run --rm davidcrty/databasement:latest php artisan key:generate --show
```

Copy the output (e.g., `base64:xxxx...`) and set it as `APP_KEY`.

## Database Configuration

Databasement needs a database to store its own data (users, servers, backup configurations).

### SQLite (Simplest)

```bash
DB_CONNECTION=sqlite
DB_DATABASE=/data/database.sqlite
```

:::note
When using SQLite, make sure to mount a volume for `/data` to persist data.
:::

### MySQL / MariaDB

Create a database and user for Databasement on your MySQL server:

**MySQL:**
```sql
CREATE DATABASE databasement CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'databasement'@'%' IDENTIFIED BY 'your-secure-password';
GRANT ALL PRIVILEGES ON databasement.* TO 'databasement'@'%';
FLUSH PRIVILEGES;
```

```bash
DB_CONNECTION=mysql
DB_HOST=your-mysql-host
DB_PORT=3306
DB_DATABASE=databasement
DB_USERNAME=databasement
DB_PASSWORD=your-secure-password
```

### PostgreSQL

Create a database and user for Databasement on your PostgreSQL server:

**PostgreSQL:**
```sql
CREATE DATABASE databasement;
CREATE USER databasement WITH ENCRYPTED PASSWORD 'your-secure-password';
GRANT ALL PRIVILEGES ON DATABASE databasement TO databasement;
```

```bash
DB_CONNECTION=pgsql
DB_HOST=your-postgres-host
DB_PORT=5432
DB_DATABASE=databasement
DB_USERNAME=databasement
DB_PASSWORD=your-secure-password
```

## Reverse Proxy / Trusted Proxies

When running behind a reverse proxy (nginx, Traefik, Kubernetes Ingress), configure trusted proxies so Laravel can
correctly determine the client IP and protocol. See the [Troubleshooting section](#troubleshooting) if you have issues
with proxy configuration.

| Variable          | Description                                    | Default                                                                            |
| ----------------- | ---------------------------------------------- | ---------------------------------------------------------------------------------- |
| `TRUSTED_PROXIES` | IP addresses or CIDR ranges of trusted proxies | `127.0.0.0/8,10.0.0.0/8,100.64.0.0/10,169.254.0.0/16,172.16.0.0/12,192.168.0.0/16` |

**Alternative values:**
- `*` - Trust all proxies (simplest option for containerized environments)
- Comma-separated IPs/CIDRs: `10.0.0.1,192.168.1.0/24` - Trust specific proxies only
- Empty - Trust no proxies

:::info
Checks the [Troubleshooting section](#troubleshooting) for help with proxy configuration.
:::

## Logging

| Variable      | Description       | Default  |
| ------------- | ----------------- | -------- |
| `LOG_CHANNEL` | Logging channel   | `stderr` |
| `LOG_LEVEL`   | Minimum log level | `debug`  |

For production, `stderr` is recommended as logs will be captured by Docker.

## Complete Example

Here's a complete `.env` file for a production deployment with MySQL:

```bash
# Application
APP_DEBUG=false
APP_URL=https://backup.yourdomain.com
APP_KEY=base64:your-generated-key-here

# Database (for Databasement itself)
DB_CONNECTION=mysql
DB_HOST=mysql.yourdomain.com
DB_PORT=3306
DB_DATABASE=databasement
DB_USERNAME=databasement
DB_PASSWORD=secure-password-here

# Logging
LOG_CHANNEL=stderr
LOG_LEVEL=warning
```

## Troubleshooting

### Enable Debug Mode

Enable debug mode to access detailed diagnostics:

```bash
APP_DEBUG=true
```

Then visit `https://your-domain.com/health/debug` to view:
- Current IP address and whether it's from a trusted proxy
- Request headers (including `X-Forwarded-For`, `X-Forwarded-Proto`)
- Application configuration

### Debugging Trusted Proxies

If your application shows HTTP instead of HTTPS, or shows the wrong client IP:

1. **Enable debug mode** (see above)
2. **Visit `/health/debug`** and check:
   - `is_trusted_proxy`: Should be `true`
   - `secure`: Should be `true` for HTTPS
   - `headers`: Check `x-forwarded-for` and `x-forwarded-proto`

3. **Common issues:**
   - `is_trusted_proxy: false` → The proxy IP is not in your `TRUSTED_PROXIES` list
   - `secure: false` with HTTPS → Trusted proxy not configured, so `x-forwarded-proto` header is ignored

4. **Quick fix:** Set `TRUSTED_PROXIES=*` to trust all proxies

### More troubleshooting

If you encounter issues, see the [Docker Compose Troubleshooting](../docker-compose#troubleshooting) section for common problems and solutions.

See also [Docker Networking](../../user-guide/database-servers#docker-networking) if you're having issues connecting to your database server.


### Run Artisan Commands

```bash
php artisan migrate:status # Check database migrations
php artisan config:show database # View database configuration
```

### Get Help

- Check the logs: `docker compose logs app`
- Report issues on [GitHub](https://github.com/david-crty/databasement/issues)
