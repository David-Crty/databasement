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

You should be ready to go!

Open http://localhost:2226 in your browser to view the app.

### 2. Start Development Environment

```bash
make start
```

This starts all Docker services:
- **php** — FrankenPHP server on http://localhost:2226
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

## OAuth / SSO Testing

Databasement includes a [Dex](https://dexidp.io/) OIDC server for local OAuth testing. The Dex service is commented out by default in `docker-compose.yml`.

### 1. Add Hosts Entry

The Dex server uses a custom hostname that must resolve both from your browser and from within Docker containers:

```bash
echo "127.0.0.1 dex-local" | sudo tee -a /etc/hosts
```

### 2. Enable Dex Service

Uncomment the `dex` service in `docker-compose.yml`:

### 3. Start Dex

```bash
docker compose up dex -d
```

### 4. Configure Environment

Add to your `.env.local`:

```env
OAUTH_OIDC_ENABLED=true
OAUTH_OIDC_CLIENT_ID=databasement
OAUTH_OIDC_CLIENT_SECRET=databasement-secret
OAUTH_OIDC_BASE_URL=http://dex-local:5556/dex
OAUTH_OIDC_LABEL=SSO
```

### Test User

| Email | Password |
|-------|----------|
| `user@databasement.com` | `databasement` |

### Testing Flow

1. Ensure hosts entry is added (step 1)
2. Start Dex: `docker compose up dex -d`
3. Visit the login page at `http://localhost:2226/login`
4. Click "Continue with SSO"
5. Enter test credentials: `user@databasement.com` / `databasement`
6. You'll be redirected back and logged in

### OAuth Behavior Notes

- **New users**: Created automatically with the role defined by `OAUTH_DEFAULT_ROLE`
- **Existing users**: When an existing user logs in via OAuth (matching email), their account is linked and their password is cleared
- **OAuth-only users**: Cannot use password login — they must use the OAuth button
- **Settings access**: OAuth-only users don't see Password or Two-Factor settings (managed by the OAuth provider)

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
APP_URL=http://localhost:2226

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
