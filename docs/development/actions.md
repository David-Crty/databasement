# Actions

Databasement changes state from six kinds of entry point ("edges"): Livewire components, the REST API (`/api/v1`), MCP tools, console commands and the scheduler, the agent API (`/api/v1/agent`), and queue jobs. An operation that more than one edge performs lives in **one action class**, which every edge calls. The edges keep what is theirs (validation, authorization, the current organization and user, translation, the response); the action owns the business rule.

```
 Livewire components ─┐
 REST API (/api/v1)  ─┤
 MCP tools           ─┤──►  Actions (App\Actions\<Domain>)  ──►  Models / Services / Drivers
 Console + scheduler ─┤        one class = one business operation
 Agent API (/agent)  ─┤
 Queue jobs          ─┘
```

Actions use [`lorisleiva/laravel-actions`](https://www.laravelactions.com), restricted to its `AsObject` and `AsFake` traits.

## Anatomy of an action

```php
<?php

namespace App\Actions\Restore;

use App\Models\DatabaseServer;
use App\Models\Restore;
use App\Models\Snapshot;
use App\Services\Backup\BackupJobFactory;
use App\Services\Backup\DTO\RestoreOptions;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

class TriggerRestoreAction
{
    use AsFake, AsObject;

    public function __construct(
        private BackupJobFactory $backupJobFactory,
    ) {}

    /**
     * Create a restore of a snapshot onto a target server and hand it to
     * whatever can reach that server: its agent, or the app's own queue.
     *
     * @throws ValidationException When the snapshot cannot be restored onto this target
     */
    public function handle(
        Snapshot $snapshot,
        DatabaseServer $targetServer,
        string $schemaName,
        RestoreOptions $options = new RestoreOptions,
        ?string $snapshotFileId = null,
        ?int $triggeredByUserId = null,
        ?string $scheduledRestoreId = null,
    ): Restore {
        $restore = $this->backupJobFactory->createRestore(
            snapshot: $snapshot,
            targetServer: $targetServer,
            schemaName: $schemaName,
            triggeredByUserId: $triggeredByUserId,
            options: $options->toArray(),
            scheduledRestoreId: $scheduledRestoreId,
            snapshotFileId: $snapshotFileId,
        );

        DispatchRestoreAction::run($restore);

        return $restore;
    }
}
```

Rules:
- **Traits:** `use AsFake, AsObject;`, in that order (Pint sorts them). **Never** `AsAction`; the arch test enforces this.
- **One public method, `handle()`**, with fully typed parameters and return type. Private helpers are fine. A class that needs a second public method is two actions.
- **Not `final`.** Mockery cannot fake final classes, so `X::mock()`/`shouldRun()` and `$this->mock()` would break.
- **Dependencies:**
  - Infrastructure services (`BackupJobFactory`, `DatabaseProvider`, `AgentJobPayloadBuilder`, `NotificationService`, …) go through **constructor injection**.
  - Other actions are **always** called through `Other::run(...)`, never injected (see [Calling actions](#calling-actions)).
- **Docblock** on `handle()`:
  - one sentence on what the operation guarantees;
  - `@throws` for each exception a caller must map;
  - PHPStan array shapes for array parameters.
- `php artisan make:action` generates a stub that uses `AsAction`. Run it, then replace the trait (or write the class by hand). Do **not** publish the stub: that would create a new top-level `stubs/` folder.

## Naming (the most important rule set)

### Class name: `<Verb><Subject>[<Qualifier>]Action`

- **Verb:** imperative and taken from the **controlled vocabulary** below. To add a verb, add a row to the table below in the same commit, with its meaning and return type.
- **Subject:** the domain noun as the codebase names it (`Volume`, `DatabaseServer`, `Snapshot`, `BackupSchedule`, `ScheduledRestore`, `AgentJob`, `Role`, …).
  - Use the **singular** for one record and the **plural** only for set operations (`SyncBackupConfigurationsAction`, `SyncUserAbilitiesAction`).
  - A subject may be shortened only where it stays unambiguous inside its domain folder (`SshConfig` for `DatabaseServerSshConfig`).
- **`Action` suffix, always.** It is grep-able and unambiguous next to Livewire `Create`/`Edit` components, `*Job`, `*Tool`, `*Command`, Fortify's `CreateNewUser`, and Bouncer "abilities".
- **Name the outcome, never the caller or the mechanism.** `TriggerRestoreAction`, not `RestoreFromModalAction`, `ApiRestoreAction` or `HandleRestoreRequestAction`.
- **Never put an edge word in an action name:** `Api`, `Http`, `Request`, `Livewire`, `Form`, `Mcp`, `Tool`, `Command`, `Console`, `Job` (except as part of a model name such as `AgentJob`), `Controller`.
- **Never use these verbs:**
  - `Handle`, `Process`, `Manage`, `Do`, `Perform`, `Execute`: they say nothing.
  - `Save`, `Store`, `Upsert`: use `Create` or `Update`, or `Sync` for sets.
  - `Make`: it collides with `AsObject::make()`.
  - `Get`, `Find`, `List`, `Fetch`, `Load`: reads belong in `app/Queries` or on models, not in actions.
  - `Check`, `Validate`: validation stays at the edge (see [The edge vs the action](#who-does-what-the-edge-vs-the-action)).

### Controlled vocabulary

| Verb | Means | Typically returns | Examples |
|---|---|---|---|
| `Create` | Persist a new record (and the rows it owns), synchronously | the model | `CreateVolumeAction`, `CreateBackupScheduleAction` |
| `Update` | Change an existing record (and the rows it owns) | the model | `UpdateVolumeAction`, `UpdateDatabaseServerAction` |
| `Delete` | Remove a record **now**, including its files where applicable | `void` / `bool` | `DeleteVolumeAction`, `DeleteSnapshotAction` |
| `Sync` | Make a collection match the given list (upsert + remove the rest) | `void` | `SyncBackupConfigurationsAction`, `SyncUserAbilitiesAction` |
| `Assign` | Set a relation to exactly the given value | `void` | `AssignRoleToUserAction` |
| `Trigger` | Start an async operation on demand (user, API, MCP, scheduler): create its job records, then dispatch them | the created model(s) or a `*Result` DTO | `TriggerBackupAction`, `TriggerRestoreAction` |
| `Dispatch` | Hand an already-created record, or a prepared unit of work, to whatever runs it (the app queue or the server's agent). A building block that other actions and handlers call. | `void` / count | `DispatchBackupAction`, `DispatchRestoreAction`, `DispatchSnapshotDeletionAction` |
| `Run` | Execute a stored definition now | a `*Result` DTO | `RunScheduledRestoreAction` |
| `Record` | Persist facts reported by a worker (queue job or agent) without concluding the operation | `void` | `RecordBackupResultAction`, `RecordSnapshotFileRemovalsAction` |
| `Complete` | Conclude an async operation **successfully** (status, side records, notifications) | `void` | `CompleteBackupAction`, `CompleteRestoreAction` |
| `Fail` | Conclude an async operation **as failed** (status, logs, notifications) | `void` / `bool` | `FailBackupAction`, `FailAgentJobAction` |
| `Finish` | Conclude a multi-step operation **whatever its outcome**, applying what was reported | `void` | `FinishSnapshotDeletionAction` |

### Folders: `app/Actions/<Domain>/`

The domain is the singular model area the action belongs to, matching the Livewire and model folders. The list:

| Folder | Holds |
|---|---|
| `Backup/` | backup configurations and backup runs |
| `BackupSchedule/` | cron schedules |
| `DatabaseServer/` | servers and their SSH configs |
| `Volume/` | storage volumes |
| `Snapshot/` | snapshot deletion and file bookkeeping |
| `Restore/` | restore runs |
| `ScheduledRestore/` | scheduled restore definitions and runs |
| `Agent/` | remote agents and their jobs |
| `Role/` | roles, role assignments, direct abilities |

- A new folder is allowed only for a new model area.
- `app/Actions/Fortify/` keeps Fortify's own convention (`CreateNewUser`, `ResetUserPassword`) and is excluded from the arch rules.

### Supporting types: never inside `app/Actions`

- **Result DTOs** use the `*Result` suffix and **input DTOs** a descriptive noun (`RestoreOptions`). Both go in the domain's existing DTO namespace: `App\Services\Backup\DTO` for backup, restore and snapshot (next to `ScheduledRestoreResult` and `BackupResult`). They are `readonly`.
- **Enums** go in `App\Enums`.
- **Exceptions** go in `App\Exceptions\<Domain>\` (`App\Exceptions\Backup\…` already exists) and are named after the violated rule (`BackupScheduleInUseException`).
- **Tests** mirror the class: `tests/Feature/Actions/<Domain>/<ClassName>Test.php`.

**Canonical examples:**
`TriggerBackupAction`, `DispatchRestoreAction`, `RunScheduledRestoreAction`, `SyncBackupConfigurationsAction`, `AssignRoleToUserAction`.

## Calling actions

- **Everywhere** (Livewire, controllers, MCP tools, commands, jobs, agent handlers, other actions, services, middleware), call `X::run(...)`. Named arguments are fine: `TriggerBackupAction::run($backup, method: 'scheduled')`.
- **Never** inject an action through a constructor or method parameter, and never `app(X::class)->handle()`.
  - Why: one calling style, `grep "X::run("` finds every call site, and fakes apply everywhere.
  - Note: fakes would also work through injection, because the package hooks the container. One style is simply easier to review.
- **PHPStan:** `phpstan.neon` registers the package's `RunReturnTypeExtension` and `RunParameterValidationRule`, so `X::run()` returns `handle()`'s type and its arguments are checked. It deliberately leaves out the package's `RunThrowTypeExtension`, which narrows `run()` to the `@throws` of `handle()` and so reports a catch-all for infrastructure failures as a dead catch.

## Who does what: the edge vs the action

| Concern | Edge (Livewire / controller / MCP tool / command / job / agent controller) | Action |
|---|---|---|
| **Input shape and format validation** (required, max, regex, exists) | ✅ FormRequest, Livewire Form rules, `$request->validate()` in MCP | ❌ |
| **Domain invariants that must hold whatever the edge** (schedule in use, target is the app database, backup id belongs to server, snapshot completed) | Optional early check for UX (Livewire wizard steps) | ✅ guard and throw (see [Errors](#errors)) |
| **Authorization** (policies, abilities) | ✅ always: `authorize()`, `can()` → `Response::error` | ❌ An action trusts its caller. Scheduler, agent and commands have no user. |
| **Demo-mode behavior** (`isDemo()` short-circuits, `BlocksDemoWrites`) | ✅ | ❌ |
| **Current organization** | ✅ resolve `CurrentOrganization` and pass `Organization` explicitly | ❌ never read `CurrentOrganization`, `auth()`, `request()`, `session()`, cookies |
| **Acting user** | ✅ pass `?int $triggeredByUserId` (null = system) | Receives it as a parameter |
| **Transactions** | ❌ | ✅ (see [Transactions](#transactions-and-side-effects)) |
| **Messages, toasts, translations, HTTP status, JSON resources, MCP text** | ✅ | ❌ Return models or `*Result` DTOs, never UI strings |
| **Error mapping** | ✅ (see [Errors](#errors)) | Throws |

**Livewire Form objects** keep their state, validation and the mapping from form fields to the action's input (for example `applyMaxStorageToConfig` converting a size and unit to bytes). They lose persistence: `Form::store()` and `Form::update()` either disappear or become one-line calls to the action.

## Inputs

- **Prefer typed parameters**, plus explicit context: `Organization $organization` for anything that creates org-owned records, and `?int $triggeredByUserId`.
- For create/update of wide records (servers, volumes, scheduled restores), accept **one validated attribute array** documented with a PHPStan array shape. Both edges already produce such an array (`$request->validated()`, Livewire form data). Do not introduce a DTO just to wrap it.
- Introduce an **input DTO** only when normalization logic must be shared (`RestoreOptions`).
- **Never pass:** `Request`, FormRequest, Livewire components or Forms, `Laravel\Mcp\Request`, or `Command`.

## Outputs

- Return the created or updated model, `void`, a scalar (count, bool), or a `readonly *Result` DTO when there are several values. `ScheduledRestoreResult` is the model to follow.
- Never return messages meant for a user.

## Errors

| Situation | Action throws | Livewire | REST API | MCP |
|---|---|---|---|---|
| Input the user can fix (domain invariant tied to a field) | `ValidationException::withMessages(['<action input key>' => '…'])`, keyed by the **action's** input names (for example `target_server_id`), as `SyncBackupConfigurationsAction` already does | Let it bubble through `withValidationFeedback()`; remap keys to `form.*` only if the field must be highlighted | 422 (default rendering) | `Response::error(collect($e->errors())->flatten()->implode(' '))` |
| State conflict (in use, in progress, locked) | Dedicated exception in `App\Exceptions\<Domain>\`, carrying the data the edges need for their message | `$this->error(__('…'))` toast | 409 with `{message}` (add `@response 409` for Scramble), unless the API already answers that case with another status | `Response::error('…')` |
| Infrastructure failure | Let it propagate (or a domain exception that wraps it) | Existing `report()` + toast pattern | 500 / existing behavior | `Response::error` |

## Transactions and side effects

- An action that writes more than one row wraps the writes in `DB::transaction()`.
- Side effects that leave the database (queue dispatch, notifications, storage I/O, Bouncer cache refresh):
  - Never move them into or out of a transaction during a refactor without a reason recorded in the commit. The current ordering is load-bearing; for example, `DispatchSnapshotDeletionAction` marks rows, dispatches, and rolls back the flag if the dispatch throws.
  - New code defers them with `DB::afterCommit()`, following the `AssignRoleToUserAction` precedent.
- Storage deletions stay **outside** DB transactions (see the reasoning in `OrganizationMergeService`).

## Composition

- Actions may call actions via `::run()`.
- `Dispatch*`, `Record*` and `Finish*` actions are building blocks. Edges normally reach them only through a `Trigger*`, `Delete*`, `Complete*` or `Fail*` action or an agent handler.
- No cycles. Keep call chains shallow (three levels at most is a good smell test).

## What is not an action

- Engines and drivers: `BackupTask`, `RestoreTask`, `Databases/*`, `Filesystems/*`, `Compressors/*`, `ShellProcessor`, `SshTunnelService`.
- Builders and mappers: `BackupJobFactory`, `AgentJobPayloadBuilder`.
- Agent protocol strategies: `Services/Agent/Handlers/*` (they *call* actions) and `Services/Agent/Runners/*`.
- Queries: `app/Queries/*`, `LatestSnapshotResolver`. Model accessors and scopes.
- Request context: `CurrentOrganization`, middleware.
- Fortify actions: `app/Actions/Fortify/*`.
- Thin wrappers over a single service call that is already shared, such as `VolumeConnectionTester::test` and `DatabaseProvider::testConnectionForServer`. Do not wrap a call that is already shared unless the action adds orchestration.

## Gotchas specific to laravel-actions

1. **Only `AsObject` and `AsFake`, never the rest:**
   - `AsController`: Scramble (our API docs, `config/scramble.php`) documents laravel-actions routes only in its paid PRO tier, so the free docs at `/docs/api` would lose those endpoints. Our controllers also carry the `@response` docblocks Scramble reads.
   - `AsJob`: wraps the action in `JobDecorator`, which changes the class name stored in queued payloads. Self-hosted users upgrade with jobs in flight, and `ProcessBackupJob`/`ProcessRestoreJob`/`DeleteSnapshotsJob` carry tuned `$timeout`/`$tries`/`failed()`. `Queue::assertPushed(ProcessBackupJob::class)` assertions would break too.
   - `AsCommand`, `AsListener`: no benefit here; our commands and listeners are already thin edges.
   - Livewire and MCP, our two biggest edges, are not covered by the package at all.
2. **Never rename or move a queued job class** (`App\Jobs\*`) as part of this migration. Serialized payloads reference the class name.
3. **Fakes apply at resolution time.** `X::shouldRun()` / `X::mock()` registers a mock that the container returns on every *later* resolution of `X`. `X::run()` resolves on each call, so fakes always apply. An action instance captured earlier (injected, or kept in a property) would bypass the fake, which is one more reason [Calling actions](#calling-actions) forbids holding instances.
4. **`make` is reserved** by `AsObject::make()`. `run` and `mock` are reserved too.
5. **Agent API compatibility** (`CLAUDE.md`, Agent Mode): changes touching `AgentController` or `Services/Agent/*` must keep every `/api/v1/agent/*` request and response byte-for-byte identical. `tests/Feature/Agent/*` must pass **unchanged**.

---

## Testing

- **Action tests own business logic.** They live in `tests/Feature/Actions/<Domain>/<ClassName>Test.php`, use the real database and factories, and call the real thing (`TriggerBackupAction::run(...)`). One test per behavior.
- **Fakes:** `Queue::fake()`, `Http::fake()` and `Process::fake()` for the outside world; mock infrastructure services (`DatabaseProvider`, …) as the existing tests do. Fake **another action** (`DispatchRestoreAction::shouldRun()->once()`) only when its effect is expensive or already covered by its own test. Never mock models.
- **No authorization in action tests.** Actions have none; the edge tests carry the allow/deny pair named by ability.
- **Entry-point tests cover the edge contract:** authorization, status codes and response shape, error mapping, how edge input becomes action arguments, and one unmocked happy path that proves the edge is wired.
- **To prove input mapping without re-testing the rule**, fake the action at the edge: `TriggerBackupAction::shouldRun()->once()->withArgs(fn (Backup $backup) => $backup->is($second))`. Keep the single happy path unmocked.
- **Review heuristic:** an edge test that asserts `Queue::assertPushed` or `assertDatabaseHas` beyond its one happy path is probably re-testing an action. Move the case to the action test.
- The "Where does a test go?" table in `CLAUDE.md` (Test Strategy) sums this up.
- `tests/Unit/ArchitectureTest.php` enforces the naming, traits and layering rules above.
