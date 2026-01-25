---
sidebar_position: 6
---

# Native Ubuntu Installation

This guide will help you install Databasement directly on Ubuntu without Docker. This is useful for environments where Docker is not available or when you prefer a traditional installation.

## Prerequisites

- Ubuntu 22.04 LTS or 24.04 LTS
- Root or sudo access
- A web server (Nginx or Apache)

## Install PHP 8.4

```bash
# Add PHP repository
sudo add-apt-repository ppa:ondrej/php -y
sudo apt update

# Install PHP 8.4 and required extensions
sudo apt install -y \
    php8.4 \
    php8.4-fpm \
    php8.4-cli \
    php8.4-common \
    php8.4-curl \
    php8.4-mbstring \
    php8.4-xml \
    php8.4-zip \
    php8.4-pdo \
    php8.4-mysql \
    php8.4-pgsql \
    php8.4-sqlite3 \
    php8.4-intl \
    php8.4-pcntl \
    php8.4-opcache
```

## Install Database CLI Tools

Databasement requires database CLI tools to perform backup and restore operations.

### For MySQL/MariaDB backups

```bash
# Install MariaDB client (includes mariadb-dump and mariadb commands)
sudo apt install -y mariadb-client

# Or install MySQL client (includes mysqldump and mysql commands)
# sudo apt install -y mysql-client
```

:::note
By default, Databasement uses MariaDB CLI tools (`mariadb-dump`, `mariadb`). If you prefer MySQL tools (`mysqldump`, `mysql`), set the environment variable `MYSQL_CLI_TYPE=mysql`.
:::

### For PostgreSQL backups

```bash
# Install PostgreSQL client (includes pg_dump and psql commands)
sudo apt install -y postgresql-client
```

### For SQLite backups

SQLite support is built into PHP - no additional tools needed.

## Install Composer

```bash
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
```

## Install Node.js

```bash
curl -fsSL https://deb.nodesource.com/setup_22.x | sudo -E bash -
sudo apt install -y nodejs
```

## Install Nginx

```bash
sudo apt install -y nginx
```

## Download and Configure Databasement

```bash
# Clone the repository
cd /var/www/databasement
git clone https://github.com/David-Crty/databasement.git .

# Install PHP dependencies
composer install --no-dev --optimize-autoloader

# Install Node dependencies and build assets
npm install
npm run build

# Publish Livewire assets
php artisan vendor:publish --force --tag=livewire:assets
```

## Configure Environment

```bash
# Generate application key
php artisan key:generate
```

Edit the `.env` file with your configuration:

```bash
nano .env
```

Essential settings:

```bash
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain.com

# Database for Databasement (choose one)
# SQLite (simplest)
DB_CONNECTION=sqlite
DB_DATABASE=/var/www/databasement/database/database.sqlite

# Or MySQL
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

# Backup working directory
BACKUP_WORKING_DIRECTORY=/tmp/backups
```

For all available environment variables, see the [Configuration](./configuration.md) page.

:::tip S3 Storage
To store backups in AWS S3 or S3-compatible storage (MinIO, DigitalOcean Spaces, etc.), see the [S3 Storage Configuration](./configuration#s3-storage) section.
:::

## Run Database Migrations

```bash
# Run migrations
cd /var/www/databasement
php artisan migrate --force
```

## Configure Nginx

Create a new Nginx configuration:

```bash
sudo nano /etc/nginx/sites-available/databasement
```

Add the following configuration:

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
        fastcgi_pass unix:/var/run/php/php8.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_hide_header X-Powered-By;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

Enable the site:

```bash
sudo ln -s /etc/nginx/sites-available/databasement /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl reload nginx
```

## Configure Queue Worker (Systemd)

The queue worker is **required** to process backup and restore jobs.

Create a systemd service:

```bash
sudo nano /etc/systemd/system/databasement-queue.service
```

Add the following:

```ini
[Unit]
Description=Databasement Queue Worker
After=network.target

[Service]
User=www-data
Group=www-data
Restart=always
RestartSec=5
WorkingDirectory=/var/www/databasement
ExecStart=/usr/bin/php artisan queue:work --queue=backups,default --tries=3 --timeout=3600 --sleep=3 --max-jobs=1000

[Install]
WantedBy=multi-user.target
```

Enable and start the service:

```bash
sudo systemctl daemon-reload
sudo systemctl enable databasement-queue
sudo systemctl start databasement-queue
```

Check status:

```bash
sudo systemctl status databasement-queue
```

## Configure Scheduler (Cron)

The scheduler runs automated backups based on your configured schedules.

```bash
crontab -e
```

Add this line:

```cron
* * * * * cd /var/www/databasement && php artisan schedule:run >> /dev/null 2>&1
```

## Verification

1. Open your browser and navigate to your domain
2. Create your first user account
3. Add a database server and test the connection
4. Create a backup to verify everything works

## Troubleshooting

- Check Queue Worker Logs

```bash
sudo journalctl -u databasement-queue -f
```

- Check PHP-FPM Logs
- Check Nginx Logs

### Test Database CLI Tools

```bash
# Test MariaDB/MySQL client
mariadb --version
# or: mysql --version

# Test PostgreSQL client
psql --version

# Test pg_dump
pg_dump --version
```

### Run Artisan Commands

```bash
php artisan migrate:status
php artisan config:show database
```

### More troubleshooting

For additional troubleshooting options including debug mode and trusted proxy configuration, see the [Configuration Troubleshooting](./configuration#troubleshooting) section.

## Updating Databasement

```bash
# Pull latest changes
git pull origin main

# Install dependencies
composer install --no-dev --optimize-autoloader
npm install
npm run build

# Run migrations
php artisan migrate --force

# Clear caches
php artisan optimize:clear

# Restart queue worker
sudo systemctl restart databasement-queue
```
