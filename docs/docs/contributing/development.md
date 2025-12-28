---
sidebar_position: 1
---

# Development Guide

This guide covers setting up a local development environment for contributing to Databasement.

## Requirements

- PHP 8.4+
- Composer
- Node.js 20+ & npm
- Docker & Docker Compose

## Quick Start

### 1. Clone and Setup

```bash
git clone https://github.com/David-Crty/databasement.git
cd databasement
make setup
```

This will:
- Install Composer dependencies
- Run database migrations
- Install npm dependencies
- Build frontend assets

### 2. Start Development Environment

```bash
make start
```

This starts all Docker services:
- **php** — FrankenPHP server on http://localhost:8081
- **queue** — Queue worker for async backup/restore jobs
- **mysql** — MySQL 8.0 on port 3306
- **postgres** — PostgreSQL 16 on port 5432

Test database credentials: `admin` / `admin` / `testdb`

## Available Commands

All PHP commands run through Docker. Use the Makefile targets or `docker compose exec app <command>`.

### Testing

```bash
make test                           # Run all tests
make test-filter FILTER=ServerTest  # Run specific tests
make test-coverage                  # Run with coverage report
```

### Code Quality

```bash
make lint-fix     # Auto-fix code style with Laravel Pint
make lint-check   # Check code style without fixing
make phpstan      # Run PHPStan static analysis
```

### Database

```bash
make migrate            # Run pending migrations
make migrate-fresh      # Drop all tables and re-migrate
make migrate-fresh-seed # Fresh migration with seeders
make db-seed            # Run database seeders
```

### Assets

```bash
npm run build   # Build production assets
npm run dev     # Start Vite dev server (HMR)
make build      # Alternative: build via Makefile
```

### Docker Services

```bash
make start                    # Start all services
docker compose logs -f        # View logs from all services
docker compose logs -f queue  # View queue worker logs
docker compose restart queue  # Restart queue worker
docker compose down           # Stop all services
```

## Git Hooks

Pre-commit hooks (via Husky) automatically run:

1. `make lint-fix` — Auto-format code with Laravel Pint
2. `make test` — Run all Pest tests

Ensure tests pass before committing.

## Architecture Overview

### Tech Stack

| Layer | Technology |
|-------|------------|
| Backend | Laravel 12, PHP 8.4+ |
| Frontend | Livewire, Mary UI, daisyUI, Tailwind CSS 4 |
| Testing | Pest PHP |
| Database | SQLite (dev), supports MySQL/PostgreSQL/MariaDB |
| Auth | Laravel Fortify with 2FA support |

### Key Models

- **DatabaseServer** — Database connection configurations
- **Volume** — Storage destinations (local, S3)
- **Backup** — Backup configurations (schedule, retention, volume)
- **Snapshot** — Individual backup snapshots with metadata
- **BackupJob** — Tracks backup/restore job execution and logs

### Key Services

- **BackupTask** — Executes database dumps, compression, and storage
- **RestoreTask** — Downloads, decompresses, and restores snapshots
- **DatabaseListService** — Lists databases for autocomplete
- **DatabaseConnectionTester** — Validates database connectivity
- **ShellProcessor** — Executes shell commands with logging

### Livewire Components

- `DatabaseServer/*` — CRUD operations for database servers
- `Volume/*` — CRUD operations for storage volumes
- `BackupJob/Index` — Job listing with logs modal
- `Snapshot/Index` — Snapshot listing and management
- `Settings/*` — User settings (Profile, Password, TwoFactor)
- `RestoreModal` — 3-step wizard for snapshot restoration

### Backup & Restore Workflow

**Backup Process:**
1. Connect to database server
2. Execute database-specific dump (mysqldump/pg_dump)
3. Compress with gzip
4. Upload to configured volume (local/S3)
5. Record snapshot metadata

**Restore Process:**
1. Select source snapshot
2. Download and decompress
3. Validate compatibility (database types must match)
4. Drop and recreate target database
5. Restore SQL dump

**Cross-Server Restore:** Restore production snapshots to staging/preprod as long as database types match.

## Configuration

### Environment Variables

The `.env` file is committed to the repository and contains default development configuration. To override these values, create a `.env.local` file (which is gitignored).

Key development configuration:

```env
# Application
APP_URL=http://localhost:8081

# Database (for application data)
DB_CONNECTION=sqlite

# Queue
QUEUE_CONNECTION=database

# AWS S3 (optional, for S3 volume testing)
AWS_ACCESS_KEY_ID=
AWS_SECRET_ACCESS_KEY=
AWS_DEFAULT_REGION=us-east-1
```

## Testing Strategy

We use Pest PHP for testing. Key principles:

- **Test business logic and behaviors** — Not framework internals
- **Mock external services** — AWS SDK, S3 client, etc.
- **Don't mock models** — Use real database interactions

### What to Test

- Authorization (who can access what)
- Business logic (backup works, restore works, cleanup deletes correct snapshots)
- Integration points (external services, commands)

### What NOT to Test

- Form validation rules (Laravel handles this)
- Eloquent relationships and cascades
- Session flash messages
- Framework behavior

## Submitting Changes

1. Create a feature branch from `main`
2. Write tests for new functionality
3. Ensure all tests pass: `make test`
4. Run code quality checks: `make lint-fix && make phpstan`
5. Submit a pull request with a clear description

For significant changes, open an issue first to discuss the approach.
