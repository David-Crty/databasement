# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is a Laravel application for managing database server backups. It uses Livewire for reactive components and Mary UI (robsontenorio/mary, built on daisyUI and Tailwind CSS). The application allows users to register database servers (MySQL, PostgreSQL, MariaDB, SQLite, Redis/Valkey), test connections, and manage backup configurations. See Boost's Foundational Context below for exact package versions.

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
make start              # Start all Docker services: php (FrankenPHP), queue worker, mysql, postgres, redis
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
make start                      # Start all services (php, queue worker, mysql, postgres, redis)
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
- **redis**: Redis 7 on port 6379

**Queue Worker**: The queue service automatically starts with `make start` and processes jobs from the `backups` queue. It restarts automatically on failure and respects a max of 1000 jobs before auto-restarting (prevents memory leaks).

## Architecture

### Application Structure

**Livewire Components**: Class-based components for all interactive pages
- `app/Livewire/DatabaseServer/*` - CRUD operations for database servers with connection testing
- `app/Livewire/DatabaseServer/RestoreModal.php` - 3-step restore wizard (select source, snapshot, destination)
- `app/Livewire/Volume/*` - CRUD operations for storage volumes
- `app/Livewire/Snapshot/Index.php` - List and manage backup snapshots
- `app/Livewire/Settings/*` - User settings pages (Profile, Password, TwoFactor, Appearance, DeleteUserForm)

**Authentication Views**: Plain Blade templates for auth flows (in `resources/views/livewire/auth/`)
- Auth flows: login, register, two-factor, password reset, email verification
- These are rendered by Laravel Fortify, not Livewire components

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
- `DatabaseConnectionTester` - Tests database connections via PDO (or CLI for Redis) with timeout/error handling
- `BackupTask` - Executes database backups (dump, compress, transfer to volume)
- `RestoreTask` - Restores database snapshots (download, decompress, drop/create DB, restore)
- `DatabaseListService` - Lists databases from a server (for autocomplete in restore modal)

### Key Patterns

1. **Livewire Architecture**: The app uses class-based Livewire components for all main pages (CRUD operations, settings). Authentication flows use plain Blade views rendered by Laravel Fortify. All full-page components use `Route::livewire()` routing.

2. **Mary UI Components**: All UI components use Mary UI (built on daisyUI). Components are used without prefixes (e.g., `<x-button>`, `<x-input>`, `<x-card>`). Key patterns:
   - Modals use `wire:model` with boolean properties (e.g., `$showDeleteModal`)
   - Tables use `<table class="table-default">` with custom styling
   - Alerts use `class="alert-success"` format (not `variant`)
   - Selects use `:options` prop with `[['id' => '', 'name' => '']]` format
   - Dark mode follows system preference (`prefers-color-scheme`)

3. **Database Connection Testing**: The `DatabaseConnectionTester` delegates to `DatabaseFactory` which creates the appropriate `DatabaseInterface` handler for the database type. Each handler implements its own `testConnection()` method.

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
- All routes use `Route::livewire()` for full-page Livewire components (e.g., `Route::livewire('database-servers', \App\Livewire\DatabaseServer\Index::class)`)

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

All database types implement `DatabaseInterface` and are resolved via `DatabaseFactory`. The factory centralizes type dispatch, so `BackupTask`, `RestoreTask`, and `DatabaseConnectionTester` require no changes.

#### Files to Update

**Core:**
- `app/Enums/DatabaseType.php` - Add enum case, label, default port, `dumpExtension()`, DSN format in `buildDsn()`
- `app/Services/Backup/Databases/{Type}Database.php` - Create handler implementing `DatabaseInterface` (`setConfig`, `getDumpCommandLine`, `getRestoreCommandLine`, `prepareForRestore`, `testConnection`)
- `app/Services/Backup/Databases/DatabaseFactory.php` - Add case to `make()` and config handling in `makeForServer()`
- `app/Services/Backup/DatabaseListService.php` - Add `list{Type}Databases()` method
- `app/Services/Backup/BackupJobFactory.php` - Add snapshot creation logic if different from default (e.g., instance-level types like Redis/SQLite)
- `app/Livewire/Forms/DatabaseServerForm.php` - Validation rules, type helpers, UI behavior

**UI:**
- `resources/views/livewire/database-server/_form.blade.php` - Conditional fields for the type
- `resources/views/livewire/database-server/restore-modal.blade.php` - If restore behavior differs

**Infrastructure:**
- `docker/php/Dockerfile` - Add extensions and CLI tools
- `docker-compose.yml` - Add test database service
- `.github/workflows/tests.yml` - Add CI service + system dependencies
- `config/testing.php` - Add test database config with defaults

**Tests & Fixtures:**
- `database/factories/DatabaseServerFactory.php` - Add factory state
- `database/seeders/DatabaseSeeder.php` - Add seeder entry
- `tests/Feature/Services/Backup/Databases/{Type}DatabaseTest.php` - Handler unit tests
- `tests/Integration/BackupRestoreTest.php` - Add to test dataset
- `tests/Support/IntegrationTestHelpers.php` - Add config and helpers
- `tests/Integration/fixtures/{type}-init.*` - Test fixture
- `tests/Pest.php` - Update global datasets

#### Architecture Notes

- Types without PDO support (e.g., Redis) must throw in `buildDsn()`/`createPdo()` and handle connection testing via CLI in their `testConnection()` method
- Types that backup the whole instance (e.g., Redis, SQLite) should short-circuit in `BackupJobFactory.createSnapshots()` to create a single snapshot

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

### Adding a New Notification Channel

The notification system uses a delegation pattern: concrete notifications extend `BaseFailedNotification` and inherit all channel support. Adding a new channel requires no changes to concrete notification classes.

#### Files to Update

**Core:**
- `app/Notifications/FailedNotificationMessage.php` - Add `to{Channel}()` rendering method
- `app/Notifications/BaseFailedNotification.php` - Add `to{Channel}()` delegation method, add entry to `CHANNEL_MAP`
- `app/Services/FailureNotificationService.php` - Add route to `getNotificationRoutes()`
- `app/Services/AppConfigService.php` - Add keys to `AppConfigService::CONFIG` (each key defines its default, type, and sensitivity)

**Custom Channels** (if not using an existing package):
- `app/Notifications/Channels/{Channel}Channel.php` - Create class with `send()` method

**Configuration UI:**
- `app/Livewire/Forms/ConfigurationForm.php` - Add properties, load/save/rules logic
- `app/Livewire/Configuration/Index.php` - Add to `getChannelOptions()`
- `resources/views/livewire/configuration/index.blade.php` - Add conditional field section

**Boot-time config** (if package reads from `config/services.php`):
- `app/Providers/AppServiceProvider.php` - Register config from AppConfig at boot

**Tests:**
- `tests/Feature/Notifications/FailureNotificationTest.php` - Add rendering and routing tests
- `tests/Feature/ConfigurationTest.php` - Add save/deselect/pre-select tests

**Optional:**
- `composer.json` - Add notification channel package if needed

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
- `docker-compose.yml` - Defines services: php (FrankenPHP), queue worker, mysql, postgres, redis

===

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application and its main Laravel ecosystems package & versions are below. You are an expert with them all. Ensure you abide by these specific packages & versions.

- php - 8.4.17
- laravel/fortify (FORTIFY) - v1
- laravel/framework (LARAVEL) - v12
- laravel/octane (OCTANE) - v2
- laravel/prompts (PROMPTS) - v0
- laravel/sanctum (SANCTUM) - v4
- laravel/socialite (SOCIALITE) - v5
- livewire/livewire (LIVEWIRE) - v4
- larastan/larastan (LARASTAN) - v3
- laravel/mcp (MCP) - v0
- laravel/pint (PINT) - v1
- laravel/sail (SAIL) - v1
- pestphp/pest (PEST) - v4
- phpunit/phpunit (PHPUNIT) - v12
- rector/rector (RECTOR) - v2
- tailwindcss (TAILWINDCSS) - v4

## Skills Activation

This project has domain-specific skills available. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

- `livewire-development` — Develops reactive Livewire 4 components. Activates when creating, updating, or modifying Livewire components; working with wire:model, wire:click, wire:loading, or any wire: directives; adding real-time updates, loading states, or reactivity; debugging component behavior; writing Livewire tests; or when the user mentions Livewire, component, counter, or reactive UI.
- `pest-testing` — Tests applications using the Pest 4 PHP framework. Activates when writing tests, creating unit or feature tests, adding assertions, testing Livewire components, browser testing, debugging test failures, working with datasets or mocking; or when the user mentions test, spec, TDD, expects, assertion, coverage, or needs to verify functionality works.
- `tailwindcss-development` — Styles applications using Tailwind CSS v4 utilities. Activates when adding styles, restyling components, working with gradients, spacing, layout, flex, grid, responsive design, dark mode, colors, typography, or borders; or when the user mentions CSS, styling, classes, Tailwind, restyle, hero section, cards, buttons, or any visual/UI changes.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

- Laravel Boost is an MCP server that comes with powerful tools designed specifically for this application. Use them.

## Artisan

- Use the `list-artisan-commands` tool when you need to call an Artisan command to double-check the available parameters.

## URLs

- Whenever you share a project URL with the user, you should use the `get-absolute-url` tool to ensure you're using the correct scheme, domain/IP, and port.

## Tinker / Debugging

- You should use the `tinker` tool when you need to execute PHP to debug code or query Eloquent models directly.
- Use the `database-query` tool when you only need to read from the database.

## Reading Browser Logs With the `browser-logs` Tool

- You can read browser logs, errors, and exceptions using the `browser-logs` tool from Boost.
- Only recent browser logs will be useful - ignore old logs.

## Searching Documentation (Critically Important)

- Boost comes with a powerful `search-docs` tool you should use before trying other approaches when working with Laravel or Laravel ecosystem packages. This tool automatically passes a list of installed packages and their versions to the remote Boost API, so it returns only version-specific documentation for the user's circumstance. You should pass an array of packages to filter on if you know you need docs for particular packages.
- Search the documentation before making code changes to ensure we are taking the correct approach.
- Use multiple, broad, simple, topic-based queries at once. For example: `['rate limiting', 'routing rate limiting', 'routing']`. The most relevant results will be returned first.
- Do not add package names to queries; package information is already shared. For example, use `test resource table`, not `filament 4 test resource table`.

### Available Search Syntax

1. Simple Word Searches with auto-stemming - query=authentication - finds 'authenticate' and 'auth'.
2. Multiple Words (AND Logic) - query=rate limit - finds knowledge containing both "rate" AND "limit".
3. Quoted Phrases (Exact Position) - query="infinite scroll" - words must be adjacent and in that order.
4. Mixed Queries - query=middleware "rate limit" - "middleware" AND exact phrase "rate limit".
5. Multiple Queries - queries=["authentication", "middleware"] - ANY of these terms.

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.

## Constructors

- Use PHP 8 constructor property promotion in `__construct()`.
    - <code-snippet>public function __construct(public GitHub $github) { }</code-snippet>
- Do not allow empty `__construct()` methods with zero parameters unless the constructor is private.

## Type Declarations

- Always use explicit return type declarations for methods and functions.
- Use appropriate PHP type hints for method parameters.

<code-snippet name="Explicit Return Types and Method Params" lang="php">
protected function isAccessible(User $user, ?string $path = null): bool
{
    ...
}
</code-snippet>

## Enums

- Typically, keys in an Enum should be TitleCase. For example: `FavoritePerson`, `BestLake`, `Monthly`.

## Comments

- Prefer PHPDoc blocks over inline comments. Never use comments within the code itself unless the logic is exceptionally complex.

## PHPDoc Blocks

- Add useful array shape type definitions when appropriate.

=== tests rules ===

# Test Enforcement

- Every change must be programmatically tested. Write a new test or update an existing test, then run the affected tests to make sure they pass.
- Run the minimum number of tests needed to ensure code quality and speed. Use `php artisan test --compact` with a specific filename or filter.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using the `list-artisan-commands` tool.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

## Database

- Always use proper Eloquent relationship methods with return type hints. Prefer relationship methods over raw queries or manual joins.
- Use Eloquent models and relationships before suggesting raw database queries.
- Avoid `DB::`; prefer `Model::query()`. Generate code that leverages Laravel's ORM capabilities rather than bypassing them.
- Generate code that prevents N+1 query problems by using eager loading.
- Use Laravel's query builder for very complex database operations.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `list-artisan-commands` to check the available options to `php artisan make:model`.

### APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## Controllers & Validation

- Always create Form Request classes for validation rather than inline validation in controllers. Include both validation rules and custom error messages.
- Check sibling Form Requests to see if the application uses array or string based validation rules.

## Authentication & Authorization

- Use Laravel's built-in authentication and authorization features (gates, policies, Sanctum, etc.).

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Queues

- Use queued jobs for time-consuming operations with the `ShouldQueue` interface.

## Configuration

- Use environment variables only in configuration files - never use the `env()` function directly outside of config files. Always use `config('app.name')`, not `env('APP_NAME')`.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

=== laravel/v12 rules ===

# Laravel 12

- CRITICAL: ALWAYS use `search-docs` tool for version-specific Laravel documentation and updated code examples.
- Since Laravel 11, Laravel has a new streamlined file structure which this project uses.

## Laravel 12 Structure

- In Laravel 12, middleware are no longer registered in `app/Http/Kernel.php`.
- Middleware are configured declaratively in `bootstrap/app.php` using `Application::configure()->withMiddleware()`.
- `bootstrap/app.php` is the file to register middleware, exceptions, and routing files.
- `bootstrap/providers.php` contains application specific service providers.
- The `app\Console\Kernel.php` file no longer exists; use `bootstrap/app.php` or `routes/console.php` for console configuration.
- Console commands in `app/Console/Commands/` are automatically available and do not require manual registration.

## Database

- When modifying a column, the migration must include all of the attributes that were previously defined on the column. Otherwise, they will be dropped and lost.
- Laravel 12 allows limiting eagerly loaded records natively, without external packages: `$query->latest()->limit(10);`.

### Models

- Casts can and likely should be set in a `casts()` method on a model rather than the `$casts` property. Follow existing conventions from other models.

=== livewire/core rules ===

# Livewire

- Livewire allows you to build dynamic, reactive interfaces using only PHP — no JavaScript required.
- Instead of writing frontend code in JavaScript frameworks, you use Alpine.js to build the UI when client-side interactions are required.
- State lives on the server; the UI reflects it. Validate and authorize in actions (they're like HTTP requests).
- IMPORTANT: Activate `livewire-development` every time you're working with Livewire-related tasks.

=== pint/core rules ===

# Laravel Pint Code Formatter

- You must run `vendor/bin/pint --dirty` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test`, simply run `vendor/bin/pint` to fix any formatting issues.

=== pest/core rules ===

## Pest

- This project uses Pest for testing. Create tests: `php artisan make:test --pest {name}`.
- Run tests: `php artisan test --compact` or filter: `php artisan test --compact --filter=testName`.
- Do NOT delete tests without approval.
- CRITICAL: ALWAYS use `search-docs` tool for version-specific Pest documentation and updated code examples.
- IMPORTANT: Activate `pest-testing` every time you're working with a Pest or testing-related task.

=== tailwindcss/core rules ===

# Tailwind CSS

- Always use existing Tailwind conventions; check project patterns before adding new ones.
- IMPORTANT: Always use `search-docs` tool for version-specific Tailwind CSS documentation and updated code examples. Never rely on training data.
- IMPORTANT: Activate `tailwindcss-development` every time you're working with a Tailwind CSS or styling-related task.
</laravel-boost-guidelines>
