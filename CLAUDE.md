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

#### Agent-Optimized Output (laravel/pao)

When you run `make test` / `make phpstan` / `make lint-check`, output is compact JSON, not verbose logs. A passing run is one line:

```json
{"tool":"pest","result":"passed","tests":18,"passed":18,"assertions":79,"duration_ms":8657}
```

A failing run adds a `failures` array with the file, line, and message for each failure — that's the detail to act on. Set `PAO_DISABLE=1` to force verbose output if you need it.

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

make ide-helper         # Regenerate model type hints for PHPStan
```

### Model Type Hints (IDE Helper)

PHPStan reads model property/method types from `_ide_helper_models.php` (gitignored, generated). After changing a model's casts, `$fillable`, relationships, or scopes, run `make ide-helper` so PHPStan sees the new types. Without it, PHPStan may report wrong-type or undefined-method errors. Only run `make ide-helper` (which uses `--write-mixin`); never `ide-helper:models --write`, as that pollutes the model files with inline docblocks.

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

## Agent Mode

When `DATABASEMENT_URL` is set, the app runs as a remote agent — it only executes the `agent:run` CLI command (polls an API and runs `BackupTask`). It never uses the app's own database.

Config files check `env('DATABASEMENT_URL')` to swap database-dependent drivers for in-memory/no-op alternatives:

- **Database**: `agent` connection (SQLite `:memory:`)
- **Cache**: `array` driver
- **Queue**: `sync` driver
- **Session**: `array` driver

This means agent mode requires zero database configuration.

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
- `BackupTask` - Executes database backups (dump, compress, transfer to volume)
- `RestoreTask` - Restores database snapshots (download, decompress, drop/create DB, restore)

### Key Patterns

1. **Livewire Architecture**: The app uses class-based Livewire components for all main pages (CRUD operations, settings). Authentication flows use plain Blade views rendered by Laravel Fortify. All full-page components use `Route::livewire()` routing.

2. **Mary UI Components**: All UI components use Mary UI (built on daisyUI). Components are used without prefixes (e.g., `<x-button>`, `<x-input>`, `<x-card>`). Key patterns:
   - Modals use `wire:model` with boolean properties (e.g., `$showDeleteModal`)
   - Tables use `<table class="table-default">` with custom styling
   - Alerts use `class="alert-success"` format (not `variant`)
   - Selects use `:options` prop with `[['id' => '', 'name' => '']]` format
   - Dark mode follows system preference (`prefers-color-scheme`)

3. **Database Connection Testing**: `DatabaseProvider::testConnectionForServer()` orchestrates connection tests (including SSH tunnels and SFTP for remote SQLite), delegating to the appropriate `DatabaseInterface` handler. Each handler implements its own `testConnection()` method.

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

### Extending the App (new database type, volume type, or notification channel)

These are rare, cross-cutting tasks with a fixed file-by-file checklist each. The step-by-step playbooks live in **[`docs/development/extending.md`](docs/development/extending.md)** to keep this file focused. **Read the matching section there before starting** — each lists every file to touch (core, UI, infrastructure, tests) plus architecture gotchas:

- **Adding a New Database Type** — `DatabaseInterface` + `DatabaseProvider`, dump/restore handlers, a `ConnectionRules` case in `app/Livewire/Forms/Connection/` plus a `connection/{type}.blade.php` partial for the form's connection section, Docker/CI services, fixtures.
- **Adding a New Volume Type** — `BaseConfig` connector + `FilesystemInterface`, `VolumeForm` property, Flysystem adapter, connection-test dataset. (`azure` / Azure Blob Storage is the newest worked example.)
- **Adding a New Notification Channel** — `NotificationMessage` + `HasChannelRouting` delegation, `AppConfigService` keys, Configuration UI.

Adding a new **locale** is covered by the Localization section below (kept inline — that guidance applies to any translation work, not just new locales).

### Authorization (Roles & Abilities)

Authorization is built on [silber/bouncer](https://github.com/JosephSilber/bouncer). **Role and ability definitions are global; only role assignments are scoped per organization** (the tenant is `Organization`, a ULID model). So a user can be an Admin in one org and a Viewer in another, while the roles themselves (and what each grants) are shared across the whole app.

- **Scope model**: this is Bouncer's "scoped relations, global role abilities" mode — `Bouncer::scope()->to($org->id)->onlyRelations()->dontScopeRoleAbilities()`. `onlyRelations()` keeps the roles/abilities entities global, `dontScopeRoleAbilities()` keeps the role→ability grants global, and `to($org->id)` scopes only the user↔role assignments (`assigned_roles.scope`). **All three flags must always be applied together** — mixing them is what makes the read path (`$user->can()`) return wrong results. Centralized in `App\Support\BouncerScope` (`apply($orgId)` for the request scope, `ensureFlags()` for definition writes that must not disturb the active scope). The `ScopeBouncer` middleware applies it per request; tests do so in `setupOrgContext()`.
- **Ability catalogue**: a fixed, code-defined enum `app/Enums/Ability.php` — eleven abilities in two groups (`Operations`: run-backups, download-snapshots, delete-snapshots, operate-restores, use-adminer; `Configuration`: manage-database-servers, manage-volumes, manage-agents, manage-backup-settings, manage-notifications, manage-users). Viewing needs no ability (read access comes from org membership). To add one: add the enum case (plus `label()`/`description()`/`group()`), grant it to the relevant built-in roles in the role-seeding migration's `builtInRoles()` map, enforce it in the policy, add the (role, ability) rows to `RoleAbilityMatrixTest`, and add an allow/deny pair at the resource's enforcement boundary (see the testing convention below).
- **Built-in roles**: there is **no `UserRole` enum**. The built-in roles (admin/member/operator/viewer) and their default ability grants are defined literally in `database/migrations/2026_06_26_160000_migrate_organization_roles_to_bouncer.php` (`builtInRoles()`), which is the sole seeder. There is **no `demo` role** (see the demo-mode note below). A `roles.built_in` boolean column marks them; it is the runtime source of truth for "is this a protected built-in" (`$role->built_in`, used by `RolePolicy@delete`, `DeleteRoleAction` and the Roles screen). There is no `SeedRolesAction`/`BouncerSeeder` — `RefreshDatabase`/`migrate:fresh` seed roles via the migration, and the factory only *assigns* by role-name string.
- **Enforcement**: policies call `$user->can('ability-name')`. Super admins bypass catalogue abilities via a `Gate::before` in `AppServiceProvider::registerBouncer()` (scoped to catalogue ability names only, so `UserPolicy`'s self/last-super-admin guards still apply).
- **Config screens authorize through policies, not inline ability/super-admin checks.** Every `Configuration/*` screen gates on a policy method (`$user->can('<action>', <Model>::class)`); the policy method delegates to the governing catalogue ability (or `isSuperAdmin()` for global concerns). This keeps the components uniform with the model-backed screens (Roles/Orgs) even where the "settings" have no dedicated model — the check is attached to a related model's policy. Super admins keep access via the `Gate::before` catalogue bypass (for ability-backed methods) or the `isSuperAdmin()` method body (for global ones).
- **Backup / Notification** are still governed by the `manage-backup-settings` / `manage-notifications` catalogue abilities, but via policies: `BackupSchedulePolicy@manageSettings` (the settings form + cleanup/verify) plus `@create/@update/@delete` (schedule CRUD, shared with the API), and `NotificationChannelPolicy@manage` (channel CRUD + test). Both screens are **viewable by every org member**; forms render read-only for users without the ability (`canManage` computed → `:disabled` inputs / hidden buttons) and every write action calls `abort_unless($user->can('<action>', <Model>::class), 403)`.
- **`Configuration/Application`**: mostly displays env-driven values (APP_DEBUG, timezone, trusted proxies) read-only, plus one runtime setting — the global `app.adminer_enabled` toggle. Viewable by every member; only **super admins** may change it, via `DatabaseServerPolicy@manageAdminer` (`canManage` = `can('manageAdminer', DatabaseServer::class)` → `isSuperAdmin()`; `saveApplicationConfig` `abort_unless(...)`). `DatabaseServerPolicy@adminer` (the separate *use* gate) is two-level: the global `app.adminer_enabled` switch **and** the per-role `use-adminer` ability (or the demo bypass) — disabling the switch blocks Adminer for everyone regardless of ability.
- **Still super-admin-only to *modify* (not catalogue abilities)**: authentication/SSO, role management, and organizations. The `Configuration/Authentication`, `Roles` and `Organizations` screens are all **viewable by every org member** (read-only for non-super-admins; write actions gated); there is **no** view-blocking `mount()` guard on any of them — every configuration tab now renders the same way (viewable, mutations gated). Auth write actions use `abort_unless($user->isSuperAdmin(), 403)`; Roles and Organizations use their registered policies (see below).
- **Organizations screen** (`app/Livewire/Configuration/Organization.php`): viewable by all members but the org list is **scoped** — super admins see every org, other members see only the orgs they belong to (so the page never discloses other tenants' names or cross-tenant resource counts). `OrganizationPolicy::viewAny` is `true`; `create`/`update`/`delete` are `isSuperAdmin()` (with `update`/`delete` also returning false for the default org). The blade gates the manage UI with `@can('create'|'update', …)` — `@can('update', $org)` conveniently encodes both the super-admin and non-default checks — and each write action calls `$this->authorize(...)`.
- **Runtime mutations**: the Roles screen lives under **Configuration → Roles** (`app/Livewire/Configuration/Roles.php`, route `configuration.roles`); it is **viewable by all members** (read-only role → ability mapping, shown as badges) but **mutating roles is reserved for super admins**, enforced by `RolePolicy` (registered against Bouncer's `Silber\Bouncer\Database\Role` in `AppServiceProvider` — there is no custom `App\Models\Role`). `viewAny` is `true`; `create`/`update` are `isSuperAdmin()`; `delete` is `isSuperAdmin() && ! $role->built_in` (so built-in roles are policy-protected from deletion, and the delete button is hidden via `@can('delete', $role)`). The component calls `$this->authorize('create'|'update'|'delete', …)`; the blade gates with `@can`. Mutations run through single-purpose actions in `app/Services/Roles/` (`CreateRoleAction`, `UpdateRoleAction`, `DeleteRoleAction` operate on global definitions; `AssignRoleToUserAction` writes the per-org assignment and restores the caller's scope). Each refreshes Bouncer's cache (`Bouncer::refresh()`/`refreshFor()`) so changes apply immediately.
- **Direct user abilities**: the user form (`app/Livewire/Forms/UserForm.php`) can grant abilities to a user *on top of* their role, per-org, via `SyncUserAbilitiesAction` (stored as user-scoped rows in `permissions`; read with `User::directAbilitiesIn($org)`). These are additive — effective abilities = role abilities + direct abilities. Setting them requires the `manage-users` ability (`UserForm::syncAbilities` gates on `can(manage-users)`; the `_abilities` toggle grid is shown via `@can('manage-users')`). This is deliberate: a `manage-users` holder can already reach every catalogue ability by assigning the Admin role, so granting direct abilities — including to themselves — adds no privilege beyond that. Granting `super_admin` stays separately gated (the form's super-admin checkbox is `isSuperAdmin()`-only), and the accepted self-escalation is documented in `docs/user-guide/permissions.md` and locked by a test in `UserAbilitiesTest`. The toggle grid is the shared `resources/views/components/ability-toggles.blade.php`, reused by the Roles screen; `Ability::grouped()` provides the grouped catalogue.
- **Role helpers on `User`**: `roleNameIn($org)` / `roleNamesIn($org)` (role names assigned in an org), `isAdmin()` (super admin or org `admin`), `isDemo()` (demo mode is enabled **and** the email is `User::DEMO_EMAIL` — not a role). There is no `roleIn()`/`canOperate()` — check the specific ability instead.
- **Demo mode**: there is no `demo` role. When `app.demo_mode` is on, `DemoModeMiddleware` lazily creates the demo user (`User::DEMO_EMAIL`, a fixed const) and assigns it the **`viewer`** role; `User::isDemo()` keys off demo-mode + that email, fully decoupled from roles. The demo user is read-only on config (the `BlocksDemoWrites` trait short-circuits Create/Edit saves with a `demo_notice`) but **can run backups/restores** because those policies grant `$user->isDemo() || $user->can(...)` (`DatabaseServerPolicy@backup/@restore`, `RestorePolicy@create/update/delete/run`). It's also blocked from profile/password/2FA settings by the middleware.
- **Testing authorization (by ability, never role)**: roles are runtime-editable, so permission tests **actor and name by ability, never role**. Three `UserFactory` actors, all on a no-grant `viewer` baseline + per-org direct abilities: `withAbilities([X])` proves X is *sufficient* (happy path); `withAllAbilitiesExcept(X)` proves X is *necessary* (deny case — holds every other ability and is still forbidden, far stronger than an empty grant); `withAbilities([])` is the zero-ability actor for pure-view tests (viewing needs no ability). Cover each guarded boundary (Livewire / HTTP / API) with an allow + deny pair **named for the ability** — e.g. `manage-volumes allows …` / `without manage-volumes, … is forbidden` — never `org admin can …`. Don't gate permission tests on `->viewer()` / `->create(['role' => …])`; role/super-admin helpers remain only for super-admin-scoped screens (organizations/auth/roles, `isAdmin`/`isSuperAdmin`), demo behaviour (`isDemo`), and the role→ability seed guardrail `RoleAbilityMatrixTest`. A test that 422s on validation before `authorize()` needs no specific actor.
- **ULID gotcha**: `users.id` is a BIGINT, but the Bouncer *scope* is an `Organization` ULID, so the `scope` columns in the published Bouncer migration are `string(26)` (see `database/migrations/*_create_bouncer_tables.php`). The column carries the org id on `assigned_roles` and stays null on the global roles/abilities rows; `roles.name` is uniquely indexed (global). The user/role entity morphs stay BIGINT.
- **Important**: do **not** register a custom `App\Models\Role` extending Bouncer's model — it breaks Livewire v4 component hydration. Use Bouncer's `Role` directly; PHPStan column/property errors for it (including `built_in`) are ignored via `phpstan.neon`.
- **Extension points (not yet implemented)**: resource-scoped abilities (`->to('manage', $backup)`) — the object morph columns are already widened to string for this.

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
- Translated attributes: always use `:attr` bindings (`:label="__('Host')"`), never `label="{{ __('Host') }}"` — interpolation double-encodes special characters (see "Avoiding HTML Encoding Artifacts" below)
- Documentation: https://mary-ui.com/docs/components/button

### Resource Index Pages

For new index pages (listing resources with tables, search, filters), follow the existing patterns in:
- `app/Livewire/DatabaseServer/Index.php` + `resources/views/livewire/database-server/index.blade.php`
- `app/Livewire/BackupJob/Index.php` + `resources/views/livewire/backup-job/index.blade.php`

Use Mary UI's `<x-table>` component with `@scope` directives for cell rendering.

### Localization

The app uses Laravel's JSON translation files with the `__('...')` helper. Translations live in `lang/{locale}.json`. Available locales are defined in `config/app.php` under `available_locales`. The `SetLocale` middleware (`app/Http/Middleware/SetLocale.php`) resolves locale from cookie, then browser `Accept-Language`, then `config('app.locale')`.

#### Extracting Translation Strings

To find all translatable strings in the codebase:

```bash
# Extract all __('...') and __("...") calls from PHP and Blade files
# Handles escaped quotes (e.g., __('You\'re logged in')) and double-quoted strings (e.g., __("Use \"auto\""))
grep -rhoP "__\(\s*'(?:[^'\\\\]|\\\\.)*'" app/ resources/ --include='*.php' --include='*.blade.php' | sed "s/__(\s*'//" | sed "s/'$//" | sed "s/\\\'/'/g" > /tmp/_keys1.txt
grep -rhoP '__\(\s*"(?:[^"\\\\]|\\\\.)*"' app/ resources/ --include='*.php' --include='*.blade.php' | sed 's/__(\s*"//' | sed 's/"$//' | sed 's/\\"/"/g' > /tmp/_keys2.txt
cat /tmp/_keys1.txt /tmp/_keys2.txt | sort -u
```

#### Adding a New Locale

1. Add the locale to `config/app.php` in the `available_locales` array (key = locale code, value = display label)
2. Create `lang/{locale}.json` with translations (copy `lang/fr.json` as a template)
3. All `__('...')` keys not present in the JSON file fall back to the key itself (English)

#### Avoiding HTML Encoding Artifacts

Blade's `{{ }}` runs `htmlspecialchars()`, which encodes certain ASCII characters into HTML entities. This causes two types of issues:

**In translation values** — use Unicode equivalents instead of these ASCII characters:

| ASCII | Encoded as | Use instead | Example |
|-------|-----------|-------------|---------|
| `'` (U+0027) | `&#039;` | `'` (U+2019, right single quotation mark) | French: `l'application` |
| `"` (U+0022) | `&quot;` | `«»` (U+00AB/U+00BB) or `""` (U+201C/U+201D) | French: `« auto »`, German: `„Wert"` |
| `&` (U+0026) | `&amp;` | Rephrase to avoid, or use `et`/`und`/`y`/etc. | |

This mostly affects languages that use apostrophes heavily (French, Italian, Catalan, Irish) and languages with special quotation mark conventions (German, French, Polish, etc.).

**In Blade component attributes** — use `:attr` binding (dynamic syntax) instead of `{{ }}` interpolation when passing translated strings to component attributes. The `{{ }}` syntax double-encodes special characters because the component escapes the value again when rendering:

```blade
{{-- Bad: double-encodes & ' " in translated values --}}
<x-header title="{{ __('Appearance & Language') }}" />

{{-- Good: passes raw PHP value, component handles escaping once --}}
<x-header :title="__('Appearance & Language')" />
```

#### Technical Terms — Do Not Over-Translate

Keep universally understood technical terms in English across all locales. Translating them produces verbose, unnatural text that can break UI layouts (especially in badges, table cells, and buttons).

Terms that must stay in English: **Backup**, **Restore**, **Snapshot(s)**.

These are standard industry jargon that developers worldwide understand regardless of language. For example, Spanish devs say "hacer un backup", not "hacer una copia de seguridad". When adding a new locale, keep these terms in English — both as standalone labels and within compound phrases (e.g., "archivo de backup", not "archivo de copia de seguridad").

#### Updating an Existing Locale

1. Run the extraction command above to find all translatable strings
2. Compare against the existing `lang/{locale}.json` to find missing keys
3. Add translations for any missing keys (using typographic apostrophes in values)
4. Ensure technical terms listed in **Technical Terms — Do Not Over-Translate** above stay in English — both as standalone labels and within compound phrases

## Important Files

- `.env.example` - Environment template (copy to `.env`)
- `Makefile` - Convenient development commands
- `.husky/pre-commit` - Git pre-commit hooks
- `phpunit.xml` - Test configuration
- `vite.config.js` - Asset bundling configuration
- `composer.json` - Contains helpful script shortcuts (`composer test`, `composer setup`)
- `docker-compose.yml` - Defines services: php (FrankenPHP), queue worker, mysql, postgres, redis

## Communication Conventions

- When drafting GitHub responses (issue/PR comments, replies), do not use em dashes (`—`). Rephrase with commas, parentheses, or separate sentences instead.
- Keep GitHub responses concise. Get to the point and avoid unnecessary verbosity.
===

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application and its main Laravel ecosystems package & versions are below. You are an expert with them all. Ensure you abide by these specific packages & versions.

- php - 8.5
- laravel/fortify (FORTIFY) - v1
- laravel/framework (LARAVEL) - v13
- laravel/mcp (MCP) - v0
- laravel/prompts (PROMPTS) - v0
- laravel/sanctum (SANCTUM) - v4
- laravel/socialite (SOCIALITE) - v5
- livewire/livewire (LIVEWIRE) - v4
- larastan/larastan (LARASTAN) - v3
- laravel/boost (BOOST) - v2
- laravel/pail (PAIL) - v1
- laravel/pint (PINT) - v1
- laravel/sail (SAIL) - v1
- pestphp/pest (PEST) - v4
- phpunit/phpunit (PHPUNIT) - v12
- rector/rector (RECTOR) - v2

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

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

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Always use `search-docs` before making code changes. Do not skip this step. It returns version-specific docs based on installed packages automatically.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Follow existing application Enum naming conventions.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.

=== tests rules ===

# Test Enforcement

- Every change must be programmatically tested. Write a new test or update an existing test, then run the affected tests to make sure they pass.
- Run the minimum number of tests needed to ensure code quality and speed. Use `php artisan test --compact` with a specific filename or filter.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

=== livewire/core rules ===

# Livewire

- Livewire allow to build dynamic, reactive interfaces in PHP without writing JavaScript.
- You can use Alpine.js for client-side interactions instead of JavaScript frameworks.
- Keep state server-side so the UI reflects it. Validate and authorize in actions as you would in HTTP requests.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== pest/core rules ===

## Pest

- This project uses Pest for testing. Create tests: `php artisan make:test --pest {name}`.
- The `{name}` argument should not include the test suite directory. Use `php artisan make:test --pest SomeFeatureTest` instead of `php artisan make:test --pest Feature/SomeFeatureTest`.
- Run tests: `php artisan test --compact` or filter: `php artisan test --compact --filter=testName`.
- Do NOT delete tests without approval.

</laravel-boost-guidelines>
