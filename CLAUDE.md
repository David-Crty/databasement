# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is a Laravel application for managing database server backups. It uses Livewire for reactive components and Flux UI components. The application allows users to register database servers (MySQL, PostgreSQL, MariaDB, SQLite), test connections, and manage backup configurations.

## Technology Stack

- **Backend**: Laravel 12, PHP 8.2+
- **Frontend**: Livewire/Volt, Flux UI components, Vite, Tailwind CSS 4
- **Authentication**: Laravel Fortify (with two-factor support)
- **Testing**: Pest PHP
- **Database**: SQLite (development), supports managing MySQL, PostgreSQL, MariaDB servers
- **Development Tools**: Laravel Pail (logs), Laravel Pint (formatting), Husky (git hooks)

## Development Commands

### Setup and Installation
```bash
make setup              # Full project setup: install deps, env setup, generate key, migrate, build assets
make install            # Install composer and npm dependencies only
```

### Running the Application
```bash
make start              # Start all services concurrently: server, queue, logs, vite (composer dev)
composer dev            # Alternative: runs concurrently with colored output
php artisan serve       # Run server only (http://localhost:8000)
```

### Testing
```bash
make test                           # Run all Pest tests
make test-filter FILTER=DatabaseServer  # Run specific test class/method
make test-coverage                  # Run tests with coverage report
php artisan test                    # Direct artisan command
```

### Code Quality
```bash
make lint-fix           # Auto-fix code style with Laravel Pint (recommended)
make lint-check         # Check code style without fixing
vendor/bin/pint         # Direct Pint command
```

### Database Operations
```bash
make migrate                # Run pending migrations
make migrate-fresh          # Drop all tables and re-migrate
make migrate-fresh-seed     # Fresh migration with seeders
make db-seed                # Run database seeders
```

### Asset Building
```bash
npm run build           # Build production assets with Vite
npm run dev             # Start Vite dev server for hot module replacement
make build              # Alternative: build via Makefile
```

### Docker (Test Databases)
```bash
docker compose up -d             # Start MySQL and PostgreSQL test containers
docker compose down              # Stop test containers
docker compose down -v           # Stop and remove volumes
```

The Docker setup provides test database servers:
- MySQL 8.0 on port 3306 (user: admin, password: admin, db: testdb)
- PostgreSQL 16 on port 5432 (user: admin, password: admin, db: testdb)

## Architecture

### Application Structure

**Livewire Components**: Full-page components handle database server CRUD operations
- `app/Livewire/DatabaseServer/Create.php` - Create new database servers with connection testing
- `app/Livewire/DatabaseServer/Edit.php` - Edit existing database servers
- `app/Livewire/DatabaseServer/Index.php` - List all database servers

**Volt Components**: Single-file components for auth and settings (in `resources/views/livewire/`)
- Auth flows: login, register, two-factor, password reset
- Settings: profile, password, two-factor, appearance

**Models**: Uses ULIDs for primary keys
- `DatabaseServer` - Stores connection info (password hidden in responses)

**Services**:
- `DatabaseConnectionTester` - Tests database connections via PDO with timeout/error handling

### Key Patterns

1. **Livewire Architecture**: The app uses class-based Livewire components for complex pages (DatabaseServer CRUD) and Volt (single-file components) for simpler pages (auth, settings).

2. **Database Connection Testing**: The `DatabaseConnectionTester` service builds DSN strings dynamically based on database type (mysql, postgresql, mariadb, sqlite) and provides user-friendly error messages.

3. **Authentication**: Laravel Fortify handles auth with optional two-factor authentication. All main routes require `auth` and `verified` middleware.

4. **Form Validation**: Livewire components use `#[Validate]` attributes for real-time validation. The Create component validates connection fields separately when testing connections.

5. **ULID Primary Keys**: Database models use ULIDs instead of auto-incrementing integers for better distributed system support.

### Routing

Routes are defined in `routes/web.php`:
- Public: `/` (welcome page)
- Authenticated: `/dashboard`, `/database-servers/*`, `/settings/*`
- Volt routes use `Volt::route()` helper
- Database server routes use Livewire component classes directly

### Testing Strategy

Tests use Pest PHP with the Laravel plugin:
- Feature tests: `tests/Feature/` - Test complete flows with database interactions
- Unit tests: `tests/Unit/` - Test isolated logic
- Uses in-memory SQLite for testing (configured in phpunit.xml)
- Database servers have dedicated feature tests in `tests/Feature/DatabaseServer/`

## Development Workflow

### Git Hooks (Husky)

Pre-commit hook automatically runs:
1. `make lint-fix` - Auto-format code with Laravel Pint
2. `make test` - Run all Pest tests

Ensure tests pass and code is formatted before committing.

### Running a Single Test

```bash
# Filter by test name or class
make test-filter FILTER=test_can_create_database_server
make test-filter FILTER=DatabaseServerTest
php artisan test --filter=CreateTest
```

### Adding a New Database Type

1. Update validation in Livewire components (`Create.php`, `Edit.php`): Add type to `in:` rule
2. Add DSN builder case in `DatabaseConnectionTester::buildDsn()`
3. Update migration enum if using database enums
4. Add tests for new connection type

### Working with Livewire Components

- Public properties are automatically bound to views
- Use `#[Validate]` attributes for form validation
- Call `$this->validate()` before processing data
- Use `session()->flash()` for one-time messages
- Return `$this->redirect()` with `navigate: true` for SPA-like navigation

## Important Files

- `.env.example` - Environment template (copy to `.env`)
- `Makefile` - Convenient development commands
- `.husky/pre-commit` - Git pre-commit hooks
- `phpunit.xml` - Test configuration
- `vite.config.js` - Asset bundling configuration
- `composer.json` - Contains helpful script shortcuts (`composer dev`, `composer test`, `composer setup`)
