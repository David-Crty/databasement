# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is a Laravel application for managing database server backups. It uses Livewire for reactive components and Mary UI (built on daisyUI and Tailwind CSS). The application allows users to register database servers (MySQL, PostgreSQL, MariaDB, SQLite), test connections, and manage backup configurations.

## Technology Stack

- **Backend**: Laravel 12, PHP 8.4+
- **Frontend**: Livewire, Mary UI (robsontenorio/mary), daisyUI, Vite, Tailwind CSS 4
- **Authentication**: Laravel Fortify (with two-factor support)
- **Testing**: Pest PHP
- **Database**: SQLite (development), supports managing MySQL, PostgreSQL, MariaDB servers
- **Development Tools**: Laravel Pail (logs), Laravel Pint (formatting), PHPStan (static analysis), Husky (git hooks)

## Development Commands

**IMPORTANT**: All PHP commands MUST be run through Docker. Never run `php`, `composer`, or `vendor/bin/*` commands directly on the host. Use the Makefile targets or `docker compose exec --user application -T app <command>` instead. Always include `--user application` to ensure correct file permissions.

### Setup and Installation
```bash
make setup              # Full project setup: install deps, env setup, generate key, migrate, build assets
make install            # Install composer and npm dependencies only
docker compose exec --user application -T app composer require <package>  # Install a composer package
docker compose exec --user application -T app composer remove <package>   # Remove a composer package
```

### Running the Application
```bash
make start              # Start all Docker services: php (FrankenPHP), queue worker, mysql, postgres
docker compose up -d    # Alternative: direct docker compose command
docker compose logs -f  # View logs from all services
docker compose logs -f queue  # View queue worker logs only
```

### Testing

**IMPORTANT**: ALWAYS use `make test` commands for running tests. NEVER use `docker compose exec ... php artisan test` directly - it runs tests sequentially and is much slower.

```bash
make test                           # Run all tests in parallel (fast iteration) - ALWAYS USE THIS
make test-sequential                # Run tests sequentially (for debugging only)
make test-filter FILTER=DatabaseServer  # Run specific test class/method
make test-coverage                  # Run tests with coverage report
```

Tests run in parallel by default using Pest's parallel testing feature. This significantly speeds up the test suite (~12-18s for 350+ tests). Use `make test-sequential` if you need to debug test order issues.

### Test Strategy
- Focus on testing business logic and behaviors
- Do not test framework internals or trust that Laravel/Livewire works correctly
- Keep tests minimal and focused - one test per behavior when possible

#### What NOT to Test
- **Form validation rules** - Laravel validation works, don't test `required`, `max:255`, etc.
- **Eloquent relationships** - Don't test that `hasMany`/`belongsTo` work
- **Eloquent cascades** - Don't test `onDelete('cascade')` behavior
- **Session flash messages** - Don't test that `session('status')` contains a message
- **Redirect responses** - Testing redirect URL once per flow is enough
- **Multiple variations of the same thing** - e.g., don't test weekly AND daily recurrence separately

#### What TO Test
- **Authorization** - Who can access what (guests, users, admins)
- **Business logic** - Core application behavior (backup works, restore works, cleanup deletes correct snapshots)
- **Integration points** - External services, commands, scheduled tasks
- **Edge cases in YOUR code** - Not edge cases in the framework

#### Mocking Strategy

**DO Mock:**
- External API services
- Third-party libraries (AWS SDK, S3 client, etc.)

**DON'T Mock:**
- Model/ORM methods
- Simple utility functions

### Code Quality
```bash
make lint-fix           # Auto-fix code style with Laravel Pint (recommended)
make lint-check         # Check code style without fixing

make phpstan            # Run PHPStan static analysis
make analyse            # Alias for phpstan
```

### Debugging
```bash
# Laravel Tinker - interactive REPL for debugging
docker compose exec --user application app php artisan tinker

# Execute a single command
docker compose exec --user application -T app php artisan tinker --execute="App\Models\User::first()"

# Examples:
docker compose exec --user application -T app php artisan tinker --execute="config('app.demo_mode')"
docker compose exec --user application -T app php artisan tinker --execute="\$user = App\Models\User::find(1); echo \$user->name;"
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

### Docker Services
```bash
make start                      # Start all services (php, queue worker, mysql, postgres)
docker compose up -d            # Alternative: direct docker compose command
docker compose down             # Stop all services
docker compose down -v          # Stop and remove volumes
docker compose logs -f queue    # View queue worker logs
docker compose restart queue    # Restart queue worker (after code changes)
```

The Docker setup provides:
- **php**: FrankenPHP server on port 2226 (http://localhost:2226)
- **queue**: Queue worker processing backup/restore jobs
- **mysql**: MySQL 8.0 on port 3306 (user: admin, password: admin, db: testdb)
- **postgres**: PostgreSQL 16 on port 5432 (user: admin, password: admin, db: testdb)

**Queue Worker**: The queue service automatically starts with `make start` and processes jobs from the `backups` queue. It restarts automatically on failure and respects a max of 1000 jobs before auto-restarting (prevents memory leaks).

## Architecture

### Application Structure

**Livewire Components**: Class-based components for all interactive pages
- `app/Livewire/DatabaseServer/*` - CRUD operations for database servers with connection testing
- `app/Livewire/DatabaseServer/RestoreModal.php` - 3-step restore wizard (select source, snapshot, destination)
- `app/Livewire/Volume/*` - CRUD operations for storage volumes
- `app/Livewire/Snapshot/Index.php` - List and manage backup snapshots
- `app/Livewire/Settings/*` - User settings pages (Profile, Password, TwoFactor, Appearance, DeleteUserForm)

**Volt Components**: Single-file components for authentication flows (in `resources/views/livewire/`)
- Auth flows: login, register, two-factor, password reset, email verification

**Models**: Uses ULIDs for primary keys
- `DatabaseServer` - Stores connection info (password hidden in responses)
- `Backup` - Backup configuration (recurrence, volume)
- `Snapshot` - Individual backup snapshots with metadata (includes `job_id` for queue tracking)
- `Restore` - Tracks restore operations with status, timing, and error handling
- `Volume` - Storage destinations (local, S3, etc.)

**Queue Jobs**:
- `ProcessBackupJob` - Wraps `BackupTask` service for async execution (2 retries, 1hr timeout)
- `ProcessRestoreJob` - Wraps `RestoreTask` service for async execution (no retries, 1hr timeout)

**Services**:
- `DatabaseConnectionTester` - Tests database connections via PDO with timeout/error handling
- `BackupTask` - Executes database backups (dump, compress, transfer to volume)
- `RestoreTask` - Restores database snapshots (download, decompress, drop/create DB, restore)
- `DatabaseListService` - Lists databases from a server (for autocomplete in restore modal)

### Key Patterns

1. **Livewire Architecture**: The app uses class-based Livewire components for all main pages (CRUD operations, settings) and Volt (single-file components) for authentication flows only.

2. **Mary UI Components**: All UI components use Mary UI (built on daisyUI). Components are used without prefixes (e.g., `<x-button>`, `<x-input>`, `<x-card>`). Key patterns:
   - Modals use `wire:model` with boolean properties (e.g., `$showDeleteModal`)
   - Tables use `<table class="table-default">` with custom styling
   - Alerts use `class="alert-success"` format (not `variant`)
   - Selects use `:options` prop with `[['id' => '', 'name' => '']]` format
   - Dark mode follows system preference (`prefers-color-scheme`)

3. **Database Connection Testing**: The `DatabaseConnectionTester` service builds DSN strings dynamically based on database type (mysql, postgresql, mariadb, sqlite) and provides user-friendly error messages.

4. **Authentication**: Laravel Fortify handles auth with optional two-factor authentication. All main routes require `auth` and `verified` middleware.

5. **Form Validation**: Livewire components use `#[Validate]` attributes or inline validation in methods. Form objects (like `VolumeForm`, `DatabaseServerForm`) encapsulate validation logic.

6. **ULID Primary Keys**: Database models use ULIDs instead of auto-incrementing integers for better distributed system support.

7. **Backup & Restore Workflow** (Async via Queue):
   - **Backup**: User clicks "Backup" → `ProcessBackupJob` dispatched to queue → Queue worker (Docker service) executes `BackupTask` service → Creates `Snapshot` record with status tracking
   - **Restore**: User submits restore → `Restore` record created → `ProcessRestoreJob` dispatched to queue → Queue worker executes `RestoreTask` service
   - **Queue Processing**: Jobs run asynchronously in the dedicated `queue` Docker service on the `backups` queue with proper error handling and status updates
   - **BackupTask**: Uses database-specific dump commands (mysqldump/pg_dump), compresses with gzip, transfers to volume storage
   - **RestoreTask**: Downloads snapshot, decompresses, validates compatibility, drops/creates target database, restores data
   - **Cross-server restore**: Snapshots can be restored from one server to another (e.g., prod → staging) as long as database types match
   - **Same-server restore**: Can restore old snapshots back to the same server (e.g., rollback)
   - Both services handle MySQL, MariaDB, and PostgreSQL with appropriate SSL/connection handling

### Routing

Routes are defined in `routes/web.php`:
- Public: `/` (welcome page)
- Authenticated: `/dashboard`, `/database-servers/*`, `/volumes/*`, `/snapshots`, `/settings/*`
- Auth routes use `Volt::route()` helper for single-file components
- All other routes use Livewire component classes directly (e.g., `Route::get('database-servers', \App\Livewire\DatabaseServer\Index::class)`)

## Development Workflow

### Git Hooks (Husky)

Pre-commit hook automatically runs:
1. `make lint-fix` - Auto-format code with Laravel Pint
2. `make phpstan` - Run static analysis
3. `make test` - Run all tests in parallel

Ensure tests pass and code is formatted before committing.

### Running a Single Test

```bash
# Filter by test name or class
make test-filter FILTER=test_can_create_database_server
make test-filter FILTER=DatabaseServerTest
```

### Adding a New Database Type

#### Files to Update

**Core:**
- `app/Enums/DatabaseType.php` - Add enum case, label, default port, DSN format in `buildDsn()`
- `app/Services/Backup/Databases/` - Create handler class implementing `DatabaseInterface`
- `app/Services/Backup/BackupTask.php` - Inject and wire up the new handler
- `app/Services/Backup/RestoreTask.php` - Inject handler + add `prepareDatabase()` case
- `app/Services/Backup/DatabaseListService.php` - Add `list{Type}Databases()` method
- `app/Services/DatabaseConnectionTester.php` - Add `test{Type}Connection()` method
- `app/Livewire/Forms/DatabaseServerForm.php` - Add type to validation rule

**Infrastructure:**
- `docker/php/Dockerfile` - Add PDO extension and CLI tools
- `docker-compose.yml` - Add test database service
- `config/testing.php` - Add test database config with defaults

**Tests & Fixtures:**
- `tests/Feature/Integration/BackupRestoreTest.php` - Add to test dataset
- `tests/Support/IntegrationTestHelpers.php` - Add config and helpers
- `tests/Feature/Integration/fixtures/{type}-init.sql` - Create test fixture

### Adding a New Volume Type

The volume system uses dynamic class resolution based on the type value. Use existing implementations (e.g., `SftpConfig`, `SftpFilesystem`) as templates.

#### Files to Update

**Core:**
- `app/Enums/VolumeType.php` - Add enum case, update `label()`, `icon()`, `sensitiveFields()`, `configSummary()`
- `app/Livewire/Volume/Connectors/{Type}Config.php` - Create class extending `BaseConfig` with `defaultConfig()`, `rules()`, `viewName()`
- `resources/views/livewire/volume/connectors/{type}-config.blade.php` - Create form view (use `$readonly`, `$isEditing`)
- `app/Services/Backup/Filesystems/{Type}Filesystem.php` - Create class implementing `FilesystemInterface`
- `app/Providers/AppServiceProvider.php` - Register filesystem in `FilesystemProvider`

**Tests:**
- `database/factories/VolumeFactory.php` - Add factory state for the new type
- `tests/Feature/Volume/VolumeTest.php` - Add to `volume types` dataset

**Optional:**
- `composer.json` - Add Flysystem adapter package if needed
- `docker/php/Dockerfile` - Add PHP extensions if needed

#### Architecture Notes

- **Dynamic Resolution**: `VolumeType::configPropertyName()` returns `{type}Config` and `configClass()` resolves the class dynamically - no explicit mappings needed in `VolumeForm`
- **Sensitive Fields**: Fields in `sensitiveFields()` are automatically encrypted in the database and masked in the browser
- **Connection Testing**: Works automatically via `FilesystemProvider` if your filesystem implements `FilesystemInterface`
- **BaseConfig**: All config components extend `BaseConfig` which handles mounting, validation, and rendering

### Working with Livewire Components

- Public properties are automatically bound to views
- Use `#[Validate]` attributes or Form objects for validation
- Call `$this->validate()` before processing data
- Use `Session::flash()` for one-time messages (shown via `@if (session('success'))`)
- Return `$this->redirect()` with `navigate: true` for SPA-like navigation
- Blade files contain only view markup; all PHP logic is in component classes

### Working with Mary UI Components

- All components are prefixed with `x-` (e.g., `<x-button>`, `<x-input>`, `<x-card>`)
- Use Heroicons for icons (e.g., `icon="o-user"` for outline icons, `icon="s-user"` for solid)
- Modal pattern: Add boolean property to component class, use `wire:model` in blade
- Select pattern: Use `:options` prop with array format `[['id' => 'value', 'name' => 'Label']]`
- Alert pattern: Use `class="alert-success"`, `class="alert-error"`, etc.
- Form components: `<x-input>`, `<x-password>`, `<x-select>`, `<x-checkbox>`, etc.
- Documentation: https://mary-ui.com/docs/components/button

### Resource Index Pages

For new index pages (listing resources with tables, search, filters), follow the existing patterns in:
- `app/Livewire/DatabaseServer/Index.php` + `resources/views/livewire/database-server/index.blade.php`
- `app/Livewire/BackupJob/Index.php` + `resources/views/livewire/backup-job/index.blade.php`

Use Mary UI's `<x-table>` component with `@scope` directives for cell rendering.

## Important Files

- `.env.example` - Environment template (copy to `.env`)
- `Makefile` - Convenient development commands
- `.husky/pre-commit` - Git pre-commit hooks
- `phpunit.xml` - Test configuration
- `vite.config.js` - Asset bundling configuration
- `composer.json` - Contains helpful script shortcuts (`composer test`, `composer setup`)
- `docker-compose.yml` - Defines services: php (FrankenPHP), queue worker, mysql, postgres
