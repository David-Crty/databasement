# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is a Laravel application for managing database server backups. It uses Livewire for reactive components and Mary UI (built on daisyUI and Tailwind CSS). The application allows users to register database servers (MySQL, PostgreSQL, MariaDB, SQLite), test connections, and manage backup configurations.

## Technology Stack

- **Backend**: Laravel 12, PHP 8.2+
- **Frontend**: Livewire, Mary UI (robsontenorio/mary), daisyUI, Vite, Tailwind CSS 4
- **Authentication**: Laravel Fortify (with two-factor support)
- **Testing**: Pest PHP
- **Database**: SQLite (development), supports managing MySQL, PostgreSQL, MariaDB servers
- **Development Tools**: Laravel Pail (logs), Laravel Pint (formatting), PHPStan (static analysis), Husky (git hooks)

## Development Commands

**IMPORTANT**: All PHP commands MUST be run through Docker. Never run `php`, `composer`, or `vendor/bin/*` commands directly on the host. Use the Makefile targets or `docker compose exec app <command>` instead.

### Setup and Installation
```bash
make setup              # Full project setup: install deps, env setup, generate key, migrate, build assets
make install            # Install composer and npm dependencies only
docker compose exec app composer require <package>  # Install a composer package
docker compose exec app composer remove <package>   # Remove a composer package
```

### Running the Application
```bash
make start              # Start all Docker services: php (FrankenPHP), queue worker, mysql, postgres
docker compose up -d    # Alternative: direct docker compose command
docker compose logs -f  # View logs from all services
docker compose logs -f queue  # View queue worker logs only
```

### Testing
```bash
make test                           # Run all Pest tests
make test-filter FILTER=DatabaseServer  # Run specific test class/method
make test-coverage                  # Run tests with coverage report
make backup-test                    # Run end-to-end backup and restore tests (requires Docker containers)
```
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

**IMPORTANT**: When modifying backup or restore logic (services in `app/Services/Backup/`), you MUST run `make backup-test` to verify the complete backup and restore workflow works correctly with real MySQL and PostgreSQL databases.

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
make start                      # Start all services (php, queue worker, mysql, postgres)
docker compose up -d            # Alternative: direct docker compose command
docker compose down             # Stop all services
docker compose down -v          # Stop and remove volumes
docker compose logs -f queue    # View queue worker logs
docker compose restart queue    # Restart queue worker (after code changes)
```

The Docker setup provides:
- **php**: FrankenPHP server on port 8081 (http://localhost:8081)
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
```

### Adding a New Database Type

#### Files to Update

**Services:**
- `app/Services/DatabaseConnectionTester.php` - Add DSN builder case in `buildDsn()`
- `app/Services/Backup/BackupTask.php` - Add backup command logic
- `app/Services/Backup/RestoreTask.php` - Add restore command logic

**Forms & UI:**
- `app/Livewire/Forms/DatabaseServerForm.php` - Add properties, validation rules, null handling in `setServer()`
- `resources/views/livewire/database-server/` - Conditional form fields and display

#### Required Tests

```bash
tests/Feature/DatabaseServer/CreateTest.php       # 'can create {type} database server'
tests/Feature/DatabaseServer/EditTest.php         # 'can edit {type} database server'
tests/Feature/Integration/BackupRestoreTest.php   # '{type} backup and restore workflow' (CRITICAL)
```

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

### Standard Resource Index Pattern

**IMPORTANT**: All resource index pages (lists of resources like Database Servers, Jobs, Snapshots, Volumes) MUST follow this standardized pattern for consistency across the application.

#### Structure

```php
// Livewire Component Class (e.g., app/Livewire/DatabaseServer/Index.php)
class Index extends Component
{
    use Toast, WithPagination;

    public string $search = '';
    public string $filterProperty = 'all';  // e.g., statusFilter, typeFilter
    public array $sortBy = ['column' => 'created_at', 'direction' => 'desc'];
    public bool $drawer = false;

    public function headers(): array
    {
        return [
            ['key' => 'column_name', 'label' => __('Column Label'), 'class' => 'w-48'],
            // Add sortable => false for columns that shouldn't be sortable
        ];
    }

    public function filterOptions(): array
    {
        return [
            ['id' => 'all', 'name' => __('All')],
            ['id' => 'value1', 'name' => __('Option 1')],
        ];
    }

    public function render()
    {
        $resources = Resource::query()
            ->with(['relationships'])
            ->when($this->search, function ($query) {
                $query->where('name', 'like', '%'.$this->search.'%');
            })
            ->when($this->filterProperty !== 'all', function ($query) {
                $query->where('status', $this->filterProperty);
            })
            ->orderBy($this->sortBy['column'], $this->sortBy['direction'])
            ->paginate(15);

        return view('livewire.resource.index', [
            'resources' => $resources,
            'headers' => $this->headers(),
            'filterOptions' => $this->filterOptions(),
        ])->layout('components.layouts.app', ['title' => __('Resources')]);
    }
}
```

#### Blade Template Structure

```blade
<div>
    <!-- HEADER -->
    <x-header title="{{ __('Resources') }}" separator progress-indicator>
        <x-slot:middle class="!justify-end">
            <x-input placeholder="{{ __('Search...') }}" wire:model.live.debounce="search" clearable icon="o-magnifying-glass" />
        </x-slot:middle>
        <x-slot:actions>
            <x-button label="{{ __('Filters') }}" @click="$wire.drawer = true" responsive icon="o-funnel" class="btn-ghost" />
            <!-- Optional: Add action button (e.g., "Add Resource") -->
        </x-slot:actions>
    </x-header>

    <!-- TABLE -->
    <x-card shadow>
        <x-table :headers="$headers" :rows="$resources" :sort-by="$sortBy" with-pagination>
            <x-slot:empty>
                <div class="text-center text-base-content/50 py-8">
                    @if($search)
                        {{ __('No resources found matching your search.') }}
                    @else
                        {{ __('No resources yet.') }}
                    @endif
                </div>
            </x-slot:empty>

            @scope('cell_column_name', $resource)
                <div class="table-cell-primary">{{ $resource->name }}</div>
                @if($resource->description)
                    <div class="text-sm text-base-content/70">{{ $resource->description }}</div>
                @endif
            @endscope

            @scope('actions', $resource)
                <div class="flex gap-2 justify-end">
                    <x-button
                        icon="o-pencil"
                        link="{{ route('resources.edit', $resource) }}"
                        wire:navigate
                        tooltip="{{ __('Edit') }}"
                        class="btn-ghost btn-sm"
                    />
                    <x-button
                        icon="o-trash"
                        wire:click="confirmDelete('{{ $resource->id }}')"
                        tooltip="{{ __('Delete') }}"
                        class="btn-ghost btn-sm text-error"
                    />
                </div>
            @endscope
        </x-table>
    </x-card>

    <!-- FILTER DRAWER -->
    <x-drawer wire:model="drawer" title="{{ __('Filters') }}" right separator with-close-button class="lg:w-1/3">
        <div class="grid gap-5">
            <x-input placeholder="{{ __('Search...') }}" wire:model.live.debounce="search" icon="o-magnifying-glass" @keydown.enter="$wire.drawer = false" />
            <x-select
                placeholder="{{ __('Filter by...') }}"
                wire:model.live="filterProperty"
                :options="$filterOptions"
                icon="o-funnel"
            />
        </div>

        <x-slot:actions>
            <x-button label="{{ __('Reset') }}" icon="o-x-mark" wire:click="clear" spinner />
            <x-button label="{{ __('Done') }}" icon="o-check" class="btn-primary" @click="$wire.drawer = false" />
        </x-slot:actions>
    </x-drawer>
</div>
```

#### Key Requirements

1. **Use `<x-table>` Component**: NEVER manually create `<table>` elements. Always use Mary UI's `<x-table>` component with:
   - `:headers` - Array of header definitions from `headers()` method
   - `:rows` - Paginated collection of resources
   - `:sort-by` - Sort configuration
   - `with-pagination` - Enable automatic pagination links

2. **Cell Rendering with `@scope`**: Define custom cell rendering using `@scope('cell_column_name', $resource)` directives. This keeps the table markup clean and component logic separate.

3. **Standard Styling Classes**:
   - `table-cell-primary` - Main/primary text in a cell
   - `text-sm text-base-content/70` - Secondary/supporting text
   - `text-xs text-base-content/50` - Tertiary/subtle text

4. **Search & Filters**:
   - Search input in header's `middle` slot with `wire:model.live.debounce`
   - Filters button opens drawer
   - Drawer contains all filter controls with `wire:model.live` for instant filtering
   - Always include "Reset" and "Done" actions in drawer

5. **Actions Column**:
   - Always right-aligned: `<div class="flex gap-2 justify-end">`
   - Use icon-only buttons with tooltips
   - Common actions: edit (pencil), delete (trash), custom actions

6. **Empty State**: Provide helpful empty state messages that differ based on whether filters are active

7. **Pagination**: Always use `->paginate(15)` in the component and the `<x-table>` component automatically renders pagination links

#### Examples

- **Database Servers Index**: `app/Livewire/DatabaseServer/Index.php` and `resources/views/livewire/database-server/index.blade.php`
- **Jobs Index**: `app/Livewire/Job/Index.php` and `resources/views/livewire/job/index.blade.php`
- **Snapshots Index**: `app/Livewire/Snapshot/Index.php` and `resources/views/livewire/snapshot/index.blade.php`

## Important Files

- `.env.example` - Environment template (copy to `.env`)
- `Makefile` - Convenient development commands
- `.husky/pre-commit` - Git pre-commit hooks
- `phpunit.xml` - Test configuration
- `vite.config.js` - Asset bundling configuration
- `composer.json` - Contains helpful script shortcuts (`composer test`, `composer setup`)
- `docker-compose.yml` - Defines services: php (FrankenPHP), queue worker, mysql, postgres
