# Backup Manager

A Laravel application for managing database server backups and restores. Supports MySQL, PostgreSQL, and MariaDB with automated backup scheduling and cross-server restoration capabilities.

## Features

- **Database Server Management**: Register and manage multiple database servers
- **Backup System**: Automated backups with configurable schedules (daily, weekly, monthly)
- **Restore System**: Restore snapshots to the same or different database servers
- **Storage Options**: Local filesystem and AWS S3 support
- **Connection Testing**: Verify database connectivity before operations
- **Two-Factor Authentication**: Optional 2FA for enhanced security

## Requirements

- PHP 8.4+
- Composer
- Node.js & npm
- Docker & Docker Compose (for development)

## Quick Start

### 1. Clone and Setup

```bash
git clone <repository-url>
cd backup-manager
make setup
```

This will:
- Install Composer dependencies
- Install npm dependencies
- Copy `.env.example` to `.env`
- Generate application key
- Run database migrations
- Build frontend assets

### 2. Start Development Environment

```bash
make start
```

This starts:
- Laravel development server (http://localhost:8000)
- Queue workers
- Log monitoring (Laravel Pail)
- Vite dev server (hot module replacement)

Alternatively, start services individually:
```bash
php artisan serve           # Development server
npm run dev                 # Vite dev server
php artisan queue:work      # Queue workers
php artisan pail            # Log monitoring
```

### 3. Start Test Database Servers

For backup/restore testing:

```bash
docker compose up -d
```

This provides:
- MySQL 8.0 on port 3306 (user: `admin`, password: `admin`, database: `testdb`)
- PostgreSQL 16 on port 5432 (user: `admin`, password: `admin`, database: `testdb`)

## Development

### Available Commands

```bash
# Testing
make test                    # Run all tests
make test-filter FILTER=name # Run specific tests
make test-coverage           # Run with coverage
make backup-test             # End-to-end backup/restore tests

# Code Quality
make lint-fix                # Auto-fix code style
make lint-check              # Check code style
make phpstan                 # Static analysis

# Database
make migrate                 # Run migrations
make migrate-fresh           # Fresh migration
make db-seed                 # Run seeders

# Assets
make build                   # Build production assets
npm run dev                  # Start Vite dev server

# Utilities
make help                    # Show all commands
make clean                   # Clear all caches
```

### Running Tests

```bash
# All tests
make test

# Specific test file or class
make test-filter FILTER=DatabaseServerTest

# With coverage
make test-coverage

# End-to-end backup and restore (requires Docker databases)
make backup-test
```

**Important**: When modifying backup or restore logic (`app/Services/Backup/`), always run `make backup-test` to verify the complete workflow with real database servers.

### Git Hooks

Pre-commit hook automatically runs:
1. `make lint-fix` - Auto-format code with Laravel Pint
2. `make test` - Run all Pest tests

Ensure tests pass before committing.

## Architecture

### Tech Stack

- **Backend**: Laravel 12, PHP 8.4+
- **Frontend**: Livewire/Volt, Flux UI, Tailwind CSS 4, Vite
- **Testing**: Pest PHP
- **Database**: SQLite (dev), supports MySQL/PostgreSQL/MariaDB management
- **Authentication**: Laravel Fortify with 2FA support

### Key Components

**Models**
- `DatabaseServer` - Database connection configurations
- `Volume` - Storage destinations (local, S3)
- `Backup` - Backup configurations (schedule, volume)
- `Snapshot` - Individual backup snapshots with metadata

**Services**
- `BackupTask` - Executes database dumps, compression, and storage
- `RestoreTask` - Downloads, decompresses, and restores snapshots
- `DatabaseListService` - Lists databases for autocomplete
- `DatabaseConnectionTester` - Validates database connectivity

**Livewire Components**
- `DatabaseServer/*` - CRUD operations for database servers
- `RestoreModal` - 3-step wizard for snapshot restoration
- Volt components for authentication and settings

### Backup & Restore Workflow

**Backup Process**:
1. Connect to database server
2. Execute database-specific dump command (mysqldump/pg_dump)
3. Compress with gzip
4. Upload to configured volume (local/S3)
5. Record snapshot metadata

**Restore Process**:
1. Select source snapshot (from any compatible database server)
2. Download and decompress snapshot
3. Test connection to target server
4. Drop and recreate target database (fresh import)
5. Restore SQL dump

**Cross-Server Restore**: Restore production snapshots to staging/preprod environments as long as database types match.

## Configuration

### Environment Variables

Key configuration in `.env`:

```env
# Application
APP_URL=http://localhost:8000

# Database (for application data)
DB_CONNECTION=sqlite

# Queue (optional)
QUEUE_CONNECTION=sync

# AWS S3 (optional, for S3 volumes)
AWS_ACCESS_KEY_ID=
AWS_SECRET_ACCESS_KEY=
AWS_DEFAULT_REGION=us-east-1
```

### Adding Database Servers

1. Navigate to Database Servers
2. Click "Add Server"
3. Configure connection details
4. Test connection
5. Set backup schedule and storage volume

## Deployment

### Production Setup

```bash
# Install dependencies (production only)
composer install --no-dev --optimize-autoloader

# Build assets
npm run build

# Optimize Laravel
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Run migrations
php artisan migrate --force

# Start queue workers
php artisan queue:work --daemon
```

### Scheduled Backups

Add to your cron:

```cron
* * * * * cd /path-to-project && php artisan schedule:run >> /dev/null 2>&1
```

## Contributing

1. Create a feature branch
2. Write tests for new functionality
3. Ensure all tests pass: `make test`
4. Run code quality checks: `make lint-fix && make phpstan`
5. Submit pull request

## License

[Your License Here]

## Support

For issues or questions, please [open an issue](link-to-issues).
