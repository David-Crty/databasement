# Extending Databasement

Step-by-step playbooks for the rare, cross-cutting tasks of adding a new **database type**, **volume (storage) type**, or **notification channel**. These live here (rather than in `CLAUDE.md`) because they are only relevant when doing that specific task. When you start one of these tasks, read the matching section in full before editing.

For adding a new **locale**, see the Localization section in `CLAUDE.md` — that guidance (technical terms, HTML-encoding artifacts, typographic apostrophes) is relevant to any translation work, so it stays inline.

---

## Adding a New Database Type

All database types implement `DatabaseInterface` and are resolved via `DatabaseProvider`. The provider centralizes type dispatch, so `BackupTask`, `RestoreTask`, and connection testing require no changes.

### Files to Update

**Core:**
- `app/Enums/DatabaseType.php` - Add enum case, label, default port, `dumpExtension()`, DSN format in `buildDsn()`
- `app/Services/Backup/Databases/{Type}Database.php` - Create handler implementing `DatabaseInterface` (`setConfig`, `dump`, `restore`, `prepareForRestore`, `listDatabases`, `testConnection`)
- `app/Services/Backup/Databases/DatabaseProvider.php` - Add case to `make()` and config handling in `makeForServer()`
- `app/Services/Backup/BackupJobFactory.php` - Add snapshot creation logic if different from default (e.g., instance-level types like Redis/SQLite)
- `app/Livewire/DatabaseServer/Connection/ConnectionRules.php` - Wire the enum case in `for()`: reuse `ClientServerConnectionRules` for host/port/credential types, or add a `{Type}ConnectionRules` subclass for type-specific rules, extra_config, dump-preview config, or field defaults
- `app/Livewire/DatabaseServer/Form.php` - Shared form behavior only (type helpers, UI behavior); per-type validation lives in the connection rules classes

**UI:**
- `resources/views/livewire/database-server/connection/{type}.blade.php` - Connection fields partial, resolved by convention from the `DatabaseType` value; `@include` the shared `_client-server-fields` partial and add type-specific fields
- `resources/views/livewire/database-server/_form.blade.php` - Only for changes outside the connection section (dump config, section gating)
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

### Architecture Notes

- Types without PDO support (e.g., Redis) must throw in `buildDsn()`/`createPdo()` and handle connection testing via CLI in their `testConnection()` method
- Types that backup the whole instance (e.g., Redis, SQLite) should short-circuit in `BackupJobFactory::createSnapshots()` to create a single snapshot

---

## Adding a New Volume Type

The volume system uses dynamic class resolution based on the type value. Use existing implementations (e.g., `S3Config`/`Awss3Filesystem`, `SftpConfig`/`SftpFilesystem`) as templates. The `azure` type (Azure Blob Storage) is the newest end-to-end example touching every file below.

### Files to Update

**Core:**
- `app/Enums/VolumeType.php` - Add enum case, update `label()`, `icon()`, `sensitiveFields()`, and the `configSummary()` match. All four `match ($this)` expressions have no `default` arm, so a missing case raises an `UnhandledMatchError` at runtime (and PHPStan flags it) — a useful checklist.
- `app/Livewire/Volume/Connectors/{Type}Config.php` - Create class extending `BaseConfig`. `BaseConfig` is a thin abstract with only two static methods to implement: `defaultConfig()` (the initial config array) and `rules(string $prefix)` (validation, keyed `{$prefix}.{field}`, using `required_if:type,{value}` for required fields).
- `app/Livewire/Volume/Form.php` - Add a `public array ${type}Config = [];` property. **Required** — the constructor seeds every type's config from `defaultConfig()` by looping over `VolumeType::cases()`, but Livewire only binds a property that is explicitly declared. Class resolution is dynamic; the property declaration is not.
- `resources/views/livewire/volume/connectors/{type}-config.blade.php` - Create the form view. Resolved by convention from `{$form->type}-config` in `_form.blade.php` (there is no `viewName()`). Available vars: `$configPrefix`, `$readonly`, `$isEditing`. Use `<x-password>` with `:placeholder="$isEditing ? __('Leave blank to keep current') : ''"` for sensitive fields.
- `app/Services/Backup/Filesystems/{Type}Filesystem.php` - Create class implementing `FilesystemInterface` (`handles(?string $type)` + `get(array $config)` returning a Flysystem `Filesystem`). Support both `root` (config/backup.php) and `prefix` (Volume DB) keys for the path prefix.
- `app/Providers/AppServiceProvider.php` - Register the filesystem via `$provider->add(new {Type}Filesystem)` in the `FilesystemProvider` singleton.

**Download flow** (only if the type needs special handling):
- `app/Http/Controllers/Web/SnapshotDownloadController.php` - Remote types stream through the `default` arm (Flysystem `readStream`) with no change. Only add a `match` arm for a type that downloads differently (e.g. S3 redirects to a presigned URL). Azure uses the default streaming arm.

**Tests & i18n:**
- `database/factories/VolumeFactory.php` - Add a factory state method for the new type.
- `tests/Feature/Volume/VolumeTest.php` - Add an entry to the `volume types` dataset (create/edit coverage).
- `tests/Feature/Services/VolumeConnectionTesterTest.php` - Add to the `remote volume types` dataset for remote types (the filesystem is mocked, so no live connection).
- `lang/{fr,es,el}.json` - Add translations for every new `__()` string in the connector view (see the Localization section in `CLAUDE.md`; keep Backup/Restore/Snapshot in English, use typographic apostrophes). Enum `label()` values are intentionally **not** translated.

**Optional:**
- `composer.json` - Add the Flysystem adapter package if needed (`docker compose exec --user application -T app composer require league/flysystem-{adapter}`).
- `docker/php/Dockerfile` - Add PHP extensions if the adapter needs them.

### Architecture Notes

- **Dynamic Resolution**: `VolumeType::configPropertyName()` returns `{type}Config` and `configClass()` resolves `{Type}Config` from the enum value via `ucfirst()` — no explicit class mappings needed. The one non-dynamic piece is the matching public property on `Volume\Form` (above).
- **Sensitive Fields**: Fields in `sensitiveFields()` are automatically encrypted in the database, masked before browser serialization, and made optional on edit (blank = keep existing). Azure's `account_key` is an example.
- **Connection Testing**: Works automatically via `FilesystemProvider`/`VolumeConnectionTester` (writes, reads back, deletes a temp file) once the filesystem implements `FilesystemInterface`.
- **BaseConfig**: A minimal abstract declaring only `defaultConfig()` and `rules()`. It does not handle mounting or rendering; those live in `Volume\Form` and `_form.blade.php`.

---

## Adding a New Notification Channel

The notification system uses a delegation pattern: concrete notifications extend `BaseFailedNotification` or `BaseSuccessNotification` and inherit all channel support via the `HasChannelRouting` trait. A single `NotificationMessage` (driven by a `NotificationType` enum) renders every channel for both success and failure. Adding a new channel requires no changes to concrete notification classes.

### Files to Update

**Core:**
- `app/Notifications/NotificationMessage.php` - Add `to{Channel}()` rendering method (success/failure differences key off `$this->type` and `$this->hasError()`)
- `app/Notifications/Concerns/HasChannelRouting.php` - Add `to{Channel}()` delegation method, add entry to `CHANNEL_MAP`
- `app/Services/FailureNotificationService.php` - Add route to `getNotificationRoutes()`
- `app/Services/AppConfigService.php` - Add keys to `AppConfigService::CONFIG` (each key defines its default, type, and sensitivity)

**Custom Channels** (if not using an existing package):
- `app/Notifications/Channels/{Channel}Channel.php` - Create class with `send()` method

**Configuration UI:**
- `app/Livewire/Configuration/Form.php` - Add properties, load/save/rules logic
- `app/Livewire/Configuration/Index.php` - Add to `getChannelOptions()`
- `resources/views/livewire/configuration/index.blade.php` - Add conditional field section

**Boot-time config** (if package reads from `config/services.php`):
- `app/Providers/AppServiceProvider.php` - Register config from AppConfig at boot

**Tests:**
- `tests/Feature/Notifications/FailureNotificationTest.php` - Add rendering and routing tests
- `tests/Feature/ConfigurationTest.php` - Add save/deselect/pre-select tests

**Optional:**
- `composer.json` - Add notification channel package if needed
