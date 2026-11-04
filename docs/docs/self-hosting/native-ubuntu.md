---
sidebar_position: 7
---

# Native Ubuntu Installation

This guide installs Databasement directly on Ubuntu, without Docker: Nginx and PHP-FPM serve the app, and two systemd services run the queue worker and the scheduler.

It is split in two. The [core installation](#core-installation) is required and gets the web app running. The [database client tools](#database-client-tools) and [optional features](#optional-features) only need to be installed for the engines and storage types you actually use.

:::tip
The [Docker image](./docker.md) ships every client tool for every supported engine and is the simplest way to run Databasement. A native install is worth it when Docker is not available, or when you only back up one or two engines.
:::

## Prerequisites

- Ubuntu 24.04 LTS or 22.04 LTS, and a regular user account with sudo access (the commands below are run as that user, not as root)
- A [supported application database](./intro.md#supported-application-database-versions) for Databasement itself: SQLite (simplest), MySQL, MariaDB or PostgreSQL

## Core installation

### Install PHP 8.5

Databasement requires PHP 8.5, which Ubuntu does not ship, so it comes from the `ondrej/php` PPA.

```bash
sudo add-apt-repository ppa:ondrej/php -y
sudo apt update

sudo apt install -y \
    php8.5-fpm \
    php8.5-cli \
    php8.5-curl \
    php8.5-mbstring \
    php8.5-xml \
    php8.5-zip \
    php8.5-intl \
    php8.5-sqlite3 \
    php8.5-mysql \
    php8.5-pgsql \
    php8.5-mongodb
```

All of these are required, even if you never use the matching engine: `composer install` refuses to run without `mongodb`, and `ftp`, `pcntl` and `opcache` are already built into the packages above.

### Install system tools

```bash
sudo apt install -y git unzip curl gzip zstd p7zip-full nginx
```

`gzip` and `zstd` compress backups, and `7z` (from `p7zip-full`) encrypts them when **Configuration → Backup → Compression** is set to encrypted.

### Install Composer and Node.js

Node.js is only needed to build the front-end assets.

```bash
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer

curl -fsSL https://deb.nodesource.com/setup_22.x | sudo -E bash -
sudo apt install -y nodejs
```

### Download Databasement

The code belongs to your own account, with `www-data` (which runs PHP-FPM and the workers) as its group. Only creating the directory needs root: everything inside it runs as you, so Composer and npm never run their package scripts as root. The setgid bit (the `2` in `2775`) puts the files you clone into it in the `www-data` group, so you never need to be a member of that group or run `chgrp`:

```bash
sudo mkdir -p /var/www/databasement
sudo chown "$USER":www-data /var/www/databasement
sudo chmod 2775 /var/www/databasement
```

Pick a version from the [GitHub Releases](https://github.com/David-Crty/databasement/releases) page and check out its tag:

```bash
VERSION=v1.10.9   # replace with the latest release

git clone --branch "$VERSION" https://github.com/David-Crty/databasement.git /var/www/databasement
cd /var/www/databasement
```

Give `www-data` write access to the only places it writes to:

```bash
chmod -R g+w storage bootstrap/cache

# SQLite only: SQLite also writes its journal next to the database file
touch database/database.sqlite
chmod g+w database database/database.sqlite
```

Install the dependencies and build the assets:

```bash
composer install --no-dev --optimize-autoloader --no-interaction
npm ci
npm run build
php artisan vendor:publish --force --tag=livewire:assets
```

### Configure the environment

Create `/var/www/databasement/.env`:

```bash
nano /var/www/databasement/.env
```

```bash
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain.com
APP_KEY=

# SQLite (simplest)
DB_CONNECTION=sqlite
DB_DATABASE=/var/www/databasement/database/database.sqlite

# Or MySQL / MariaDB
# DB_CONNECTION=mysql
# DB_HOST=127.0.0.1
# DB_PORT=3306
# DB_DATABASE=databasement
# DB_USERNAME=databasement
# DB_PASSWORD=your-password

# Or PostgreSQL
# DB_CONNECTION=pgsql
# DB_HOST=127.0.0.1
# DB_PORT=5432
# DB_DATABASE=databasement
# DB_USERNAME=databasement
# DB_PASSWORD=your-password
```

For every other setting (timezone, logging, trusted proxies, SSO), see the [Configuration](./configuration/index.md) page. Settings such as the backup working directory, compression and S3 storage are managed in the web UI under **Configuration → Backup**.

### Initialise the application

`.env` holds secrets, so only you and `www-data` may read it. Generate the key as yourself, then run the commands that write to the database and caches as `www-data`, so every file the app later rewrites (logs, caches, the SQLite database) is one it owns:

```bash
cd /var/www/databasement

chmod 640 .env
php artisan key:generate --force

sudo -u www-data php artisan migrate --force
sudo -u www-data php artisan optimize
```

`optimize` caches the configuration, so after any later change to `.env`, run it again and restart the services (`sudo systemctl restart php8.5-fpm databasement-queue databasement-scheduler`).

:::warning
`APP_KEY` also encrypts the stored database credentials and, by default, encrypted backups. Keep a copy of it: without it, those backups cannot be restored.
:::

### Configure Nginx

```bash
sudo nano /etc/nginx/sites-available/databasement
```

```nginx
server {
    listen 80;
    server_name your-domain.com;
    root /var/www/databasement/public;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    index index.php;
    charset utf-8;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.5-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_hide_header X-Powered-By;
        fastcgi_read_timeout 300;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

Enable the site:

```bash
sudo ln -s /etc/nginx/sites-available/databasement /etc/nginx/sites-enabled/
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t
sudo systemctl reload nginx
```

To serve HTTPS, add a certificate (for example with `certbot --nginx`). If Databasement sits behind another reverse proxy instead, see [Reverse Proxy / Trusted Proxies](./configuration/application.md#reverse-proxy--trusted-proxies).

### Configure the queue worker

The queue worker is **required**: it runs every backup and restore.

```bash
sudo nano /etc/systemd/system/databasement-queue.service
```

```ini
[Unit]
Description=Databasement Queue Worker
After=network.target

[Service]
User=www-data
Group=www-data
WorkingDirectory=/var/www/databasement
ExecStart=/usr/bin/php artisan queue:work --queue=backups,default --tries=3 --timeout=3600 --sleep=3 --max-jobs=1000
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

### Configure the scheduler

The scheduler triggers scheduled backups, retention cleanup and the other periodic jobs.

```bash
sudo nano /etc/systemd/system/databasement-scheduler.service
```

```ini
[Unit]
Description=Databasement Scheduler
After=network.target

[Service]
User=www-data
Group=www-data
WorkingDirectory=/var/www/databasement
ExecStart=/usr/bin/php artisan schedule:work --quiet
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

Enable and start both services:

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now databasement-queue databasement-scheduler
sudo systemctl status databasement-queue databasement-scheduler
```

### First login

1. Open `APP_URL` in your browser and create the first user account. It becomes the administrator.
2. Install the [client tools](#database-client-tools) for the engines you back up.
3. Add a database server, run **Test connection**, then trigger a first backup to check that the worker picks it up.

## Database client tools

Databasement runs each engine's own dump and restore tools. Install only the sections for the engines you back up: a server whose tool is missing fails its connection test or its backup.

### MySQL and MariaDB

```bash
sudo apt install -y mariadb-client
```

The MariaDB client (`mariadb-dump`, `mariadb`) handles both MariaDB and MySQL servers. The Docker image additionally ships Oracle's `mysqldump`, which a native install does not have, so two cases behave differently:

- **MySQL servers on the year-based version scheme** (26.x and later): stored routines are left out of the backup, and the job log says so. MySQL 8.x and 9.x keep them.
- **MariaDB servers older than 10.2** cannot be backed up.

### PostgreSQL

`pg_dump` refuses to back up a server newer than itself, and Ubuntu's own `postgresql-client` is version 16 on 24.04 and 14 on 22.04. Install the current client from the PostgreSQL project's repository instead:

```bash
sudo apt install -y postgresql-common
sudo /usr/share/postgresql-common/pgdg/apt.postgresql.org.sh -y
sudo apt install -y postgresql-client-18 postgresql-client-16
```

Version 18 backs up every supported server. Version 16 is optional: when it is installed, Databasement uses it for PostgreSQL 16 and older servers, so their backups can be restored into a server of the same version (the Docker image does the same).

### Redis and Valkey

```bash
sudo apt install -y redis-tools
```

### SQLite

```bash
sudo apt install -y sqlite3
```

Remote SQLite files are reached over SFTP, which needs nothing more.

### MongoDB

Install `mongodump` and `mongorestore` from MongoDB's Database Tools package:

```bash
. /etc/os-release
ARCH=$(dpkg --print-architecture | sed 's/amd64/x86_64/')
curl -fsSLO "https://fastdl.mongodb.org/tools/db/mongodb-database-tools-ubuntu${VERSION_ID//./}-${ARCH}-100.17.0.deb"
sudo apt install -y "./mongodb-database-tools-ubuntu${VERSION_ID//./}-${ARCH}-100.17.0.deb"
```

Check the [MongoDB download page](https://www.mongodb.com/try/download/database-tools) for a newer version.

### Microsoft SQL Server

SQL Server needs Microsoft's ODBC driver and PHP extensions for connection tests, and `sqlpackage` for backups and restores:

```bash
# Microsoft's package repository and ODBC driver
curl -sSLO "https://packages.microsoft.com/config/ubuntu/$(. /etc/os-release; echo $VERSION_ID)/packages-microsoft-prod.deb"
sudo dpkg -i packages-microsoft-prod.deb
sudo apt update
sudo ACCEPT_EULA=Y apt install -y msodbcsql18 unixodbc-dev

# sqlsrv is packaged; pdo_sqlsrv is built with PECL
sudo apt install -y php8.5-sqlsrv php8.5-dev php-pear
sudo pecl install pdo_sqlsrv
echo "extension=pdo_sqlsrv.so" | sudo tee /etc/php/8.5/mods-available/pdo_sqlsrv.ini
sudo phpenmod pdo_sqlsrv

# sqlpackage, amd64: Microsoft's self-contained build
curl -fsSL -o sqlpackage.zip https://aka.ms/sqlpackage-linux
sudo mkdir -p /opt/sqlpackage
sudo unzip -o sqlpackage.zip -d /opt/sqlpackage
sudo chmod +x /opt/sqlpackage/sqlpackage
sudo ln -sf /opt/sqlpackage/sqlpackage /usr/local/bin/sqlpackage
```

Microsoft only ships that build for x64. On arm64, install `sqlpackage` as a .NET tool into a system-wide path instead, so the `www-data` worker finds it:

```bash
sudo apt install -y dotnet-sdk-8.0
sudo dotnet tool install microsoft.sqlpackage --tool-path /opt/sqlpackage
sudo ln -sf /opt/sqlpackage/sqlpackage /usr/local/bin/sqlpackage
```

### Firebird

Databasement needs the Firebird 5 `gbak` and `isql`, which back up Firebird 3, 4 and 5 servers. Ubuntu only packages Firebird 3, so take the client from the official release:

```bash
sudo apt install -y libtommath1 libncurses6
FB=Firebird-5.0.3.1683-0-linux-$(dpkg --print-architecture | sed 's/amd64/x64/')
curl -fsSLO "https://github.com/FirebirdSQL/firebird/releases/download/v5.0.3/$FB.tar.gz"
tar xzf "$FB.tar.gz"
sudo tar xzf "$FB/buildroot.tar.gz" -C / ./opt/firebird
sudo ln -sf /opt/firebird/bin/gbak /usr/local/bin/gbak
sudo ln -sf /opt/firebird/bin/isql /usr/local/bin/isql
```

This only unpacks the client tools; it does not install or start a Firebird server. The links go in `/usr/local/bin` so they take precedence over unixODBC's unrelated `/usr/bin/isql`.

After installing new tools or PHP extensions, restart PHP-FPM and the worker so they pick them up:

```bash
sudo systemctl restart php8.5-fpm databasement-queue
```

## Optional features

| Feature | Install |
|---|---|
| [SMB volumes](../user-guide/volumes.md) | `sudo apt install -y php8.5-smbclient` |
| [SSH tunnels](../user-guide/ssh-tunnel.md) | `sudo apt install -y openssh-client sshpass` |
| S3, Azure Blob, SFTP and FTP volumes | Nothing: built into the core installation |

[Hook scripts](./configuration/backup.md#hook-scripts) run on this host, so any command they call (`jq`, `rclone`, `aws`, ...) must be installed here too.

## Troubleshooting

Logs:

```bash
sudo journalctl -u databasement-queue -f      # backup and restore jobs
sudo journalctl -u databasement-scheduler -f  # scheduled runs
tail -f /var/www/databasement/storage/logs/laravel.log
sudo tail -f /var/log/nginx/error.log
```

Check which client tools are on the `PATH` (only the ones for the engines you use need to be found):

```bash
for tool in mariadb-dump pg_dump redis-cli sqlite3 mongodump sqlpackage gbak isql; do
    command -v "$tool" >/dev/null && echo "found:   $tool" || echo "missing: $tool"
done
```

Check the application state:

```bash
cd /var/www/databasement
sudo -u www-data php artisan migrate:status
sudo -u www-data php artisan config:show database
```

For debug mode and other options, see the [Configuration troubleshooting](./configuration/application.md#troubleshooting) section.

## Updating Databasement

Check the [GitHub Releases](https://github.com/David-Crty/databasement/releases) page and the [changelog](../changelog.md) for the new version, then:

```bash
cd /var/www/databasement
VERSION=v1.10.9   # the version to update to

git fetch --tags
git checkout "$VERSION"

composer install --no-dev --optimize-autoloader --no-interaction
npm ci
npm run build
php artisan vendor:publish --force --tag=livewire:assets

sudo -u www-data php artisan migrate --force
sudo -u www-data php artisan optimize

sudo -u www-data php artisan queue:restart
sudo systemctl restart php8.5-fpm databasement-scheduler
```

`queue:restart` lets the worker finish the job it is running before it exits, and systemd then starts it again on the new code.
