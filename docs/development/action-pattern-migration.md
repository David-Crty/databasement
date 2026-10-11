# Action Pattern Migration Plan (Task 1)

> **Audience:** Claude Code sessions (and reviewers) carrying out the migration.
> **Companion:** [`action-pattern-test-deduplication.md`](action-pattern-test-deduplication.md) is Task 2. It is a separate PR that trims the duplicated entry-point tests after this task's PR is merged, using the ledger this task fills in.
> **Baseline:** commit `52dac27` (2026-10-11). Line numbers below come from that commit and will drift, so always re-grep before editing.

## How to use this document

- **Task 1 is a single pull request.** The work items (`0.1`, `0.2`, `1.1` … `1.10`) are **steps inside that PR**, done in order. Later steps depend on earlier ones (1.7 must come before 1.8, for example).
- **Each step is one commit, or a few** when the step says to split it.
  - Every commit leaves the suite green, so reviewers can go commit by commit and a failure can be bisected inside the branch.
  - Each commit's subject is the **Commit subject** the step gives.
- **Before starting a step:**
  1. Read **§2 Conventions** and **§3 Testing**.
  2. Then read every file the step names.
  3. Re-locate the call sites with `grep`, because line numbers drift.
- A step is done only when the per-commit checks of §6.1 hold. The PR is done when the per-PR checks of §6.2 hold.
- **PR workflow:**
  - Open the PR as a **draft** right after step 0.1 is pushed, so CI runs on every later push.
  - The branch lives a long time, so keep it current by **merging** `main` into it (no rebase of pushed commits), and re-run the full suite after each merge.
  - Mark it ready for review once step 1.10 is in and §6.2 holds.
- **Follow the repository rules in `CLAUDE.md`:**
  - Run PHP only through Docker or the Makefile.
  - Gate on `make test`, never `make test-tia`.
  - Use conventional-commit titles.
  - Do not edit `CHANGELOG.md`.
- In a git worktree, load the `worktree-development` skill first.

---

## 0. Decisions already taken

| # | Decision | Consequence |
|---|---|---|
| D1 | Use **`lorisleiva/laravel-actions`**, but only the **`AsObject` + `AsFake`** traits. | Actions expose `handle()`. They are called with `X::run(...)` and faked in tests with `X::shouldRun()`, `X::mock()`, etc. We never use `AsAction`, `AsController`, `AsJob`, `AsCommand` or `AsListener` (reasons in §2.11). |
| D2 | Scope is **Phase 0 (foundation) + Phase 1 (operations reachable from two or more entry points)**. | Livewire-only mutations, agent claim/heartbeat, retention and verification services, org merge, OAuth and similar are **out of scope**. They are listed in §7 as follow-ups. |
| D3 | When unifying twins reveals a **behavior divergence, fix it inside this PR**, in the step that unifies it. | Each step lists its divergences and the behavior that wins. A step that changes behavior someone could notice gets a `fix:` (or `fix(api):`, `fix(agent):`) commit subject, and the PR body lists every behavior change so the release changelog picks them up. A purely structural step is `chore:`. See §6. |
| D4 | **Action tests own business logic.** | New `tests/Feature/Actions/<Domain>/<Name>ActionTest.php` files. Entry-point tests stay **untouched** in Task 1: they are the regression net that proves behavior did not change. Every duplicated assertion is recorded in the ledger, and Task 2 removes the duplication. |
| D5 | **Task 1 is one PR; Task 2 is another PR**, opened after Task 1 is merged. | The steps below are commits, not PRs. Task 2 has no app-code changes. |

---

## 1. Why, and what exists today

Databasement changes state from six kinds of entry point ("edges"):

```
 Livewire components ─┐
 REST API (/api/v1)  ─┤
 MCP tools           ─┤──►  Actions (App\Actions\<Domain>)  ──►  Models / Services / Drivers
 Console + scheduler ─┤        one class = one business operation   (BackupTask, RestoreTask,
 Agent API (/agent)  ─┤                                              DatabaseProvider, Filesystems,
 Queue jobs          ─┘                                              NotificationService, …)
```

**What the 2026-10-11 inventory found:**
- **12 actions already exist**, all with `execute()` and constructor DI, but they live in `app/Services/Backup` and `app/Services/Roles`.
- **Three calling styles are mixed:** Livewire method injection, constructor injection, and `app(X::class)->execute()`.
- **Orchestration is duplicated across edges:**
  - **Starting a restore:** `BackupJobFactory::createRestore` followed by `DispatchRestoreAction` is copy-pasted four times (Livewire `Restore/Modal::restore`, API `DatabaseServerController::restore`, MCP `TriggerRestoreTool`, `RunScheduledRestoreAction`).
  - **CRUD twins:** create/update/delete for servers, volumes, SSH configs, backup schedules and scheduled restores is duplicated between Livewire and the API.
  - **Backup/restore completion:** written twice, once in `ProcessBackupJob`/`ProcessRestoreJob` and once in `BackupJobHandler`/`RestoreJobHandler`.
- **The copies have drifted.** There are 17+ behavior divergences, listed per step in §5. Examples:
  - The API deletes a backup schedule that is in use and hits an FK error (500).
  - The API and Livewire clear SSH credentials differently.
  - The agent backup path never sends the storage-limit warning.
- **Layering is inverted in places:**
  - `SyncBackupConfigurationsAction` imports `App\Livewire\DatabaseServer\BackupForm`.
  - `DeleteSnapshotsJob::dispatchFor` reads `auth()`.
  - `DemoBackupService` reads the ambient `CurrentOrganization`, which is likely unresolved during first-user registration (§5, step 1.8).
- **Tests mirror the duplication:**
  - Only `TriggerBackupAction` has its own test file.
  - Entry-point tests re-assert business outcomes. For example, "locked snapshots are skipped" is asserted in the Livewire, API and job tests.
  - There are no `arch()` tests.

---

## 2. Conventions (the contract)

Step 0.2 copies this section, edited lightly, into `docs/development/actions.md` and adds a short summary to `CLAUDE.md` (§4, step 0.2).

### 2.1 Anatomy of an action

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
  - Other actions are **always** called through `Other::run(...)`, never injected (§2.3).
- **Docblock** on `handle()`:
  - one sentence on what the operation guarantees;
  - `@throws` for each exception a caller must map;
  - PHPStan array shapes for array parameters.
- `php artisan make:action` generates a stub that uses `AsAction`. Run it, then replace the trait (or write the class by hand). Do **not** publish the stub: that would create a new top-level `stubs/` folder.

### 2.2 Naming (the most important rule set)

#### Class name: `<Verb><Subject>[<Qualifier>]Action`

- **Verb:** imperative and taken from the **controlled vocabulary** below. To add a verb, add a row to the table in `docs/development/actions.md` in the same commit, with its meaning and return type.
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
  - `Check`, `Validate`: validation stays at the edge (§2.4).

#### Controlled vocabulary

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

#### Folders: `app/Actions/<Domain>/`

The domain is the singular model area the action belongs to, matching the Livewire and model folders. The list is fixed for Phases 0–1:

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

#### Supporting types: never inside `app/Actions`

- **Result DTOs** use the `*Result` suffix and **input DTOs** a descriptive noun (`RestoreOptions`). Both go in the domain's existing DTO namespace: `App\Services\Backup\DTO` for backup, restore and snapshot (next to `ScheduledRestoreResult` and `BackupResult`). They are `readonly`.
- **Enums** go in `App\Enums`.
- **Exceptions** go in `App\Exceptions\<Domain>\` (`App\Exceptions\Backup\…` already exists) and are named after the violated rule (`BackupScheduleInUseException`).
- **Tests** mirror the class: `tests/Feature/Actions/<Domain>/<ClassName>Test.php`.

**Canonical examples:**
`TriggerRestoreAction`, `DispatchSnapshotDeletionAction`, `FinishSnapshotDeletionAction`, `DeleteBackupScheduleAction`, `UpdateSshConfigAction`, `FailAgentJobAction`.

### 2.3 Calling actions

- **Everywhere** (Livewire, controllers, MCP tools, commands, jobs, agent handlers, other actions, services, middleware), call `X::run(...)`. Named arguments are fine: `TriggerBackupAction::run($backup, method: 'scheduled')`.
- **Never** inject an action through a constructor or method parameter, and never `app(X::class)->handle()`.
  - Why: one calling style, `grep "X::run("` finds every call site, and fakes apply everywhere.
  - Note: fakes would also work through injection, because the package hooks the container. One style is simply easier to review.
- **PHPStan:** `phpstan.neon` must include `vendor/lorisleiva/laravel-actions/extension.neon`. This project does not use `phpstan/extension-installer`, so the include is manual. With it, `X::run()` returns `handle()`'s type and its arguments are checked; without it, `run()` returns `mixed`.

### 2.4 Who does what: the edge vs the action

| Concern | Edge (Livewire / controller / MCP tool / command / job / agent controller) | Action |
|---|---|---|
| **Input shape and format validation** (required, max, regex, exists) | ✅ FormRequest, Livewire Form rules, `$request->validate()` in MCP | ❌ |
| **Domain invariants that must hold whatever the edge** (schedule in use, target is the app database, backup id belongs to server, snapshot completed) | Optional early check for UX (Livewire wizard steps) | ✅ guard and throw (§2.7) |
| **Authorization** (policies, abilities) | ✅ always: `authorize()`, `can()` → `Response::error` | ❌ An action trusts its caller. Scheduler, agent and commands have no user. |
| **Demo-mode behavior** (`isDemo()` short-circuits, `BlocksDemoWrites`) | ✅ | ❌ |
| **Current organization** | ✅ resolve `CurrentOrganization` and pass `Organization` explicitly | ❌ never read `CurrentOrganization`, `auth()`, `request()`, `session()`, cookies |
| **Acting user** | ✅ pass `?int $triggeredByUserId` (null = system) | Receives it as a parameter |
| **Transactions** | ❌ | ✅ (§2.8) |
| **Messages, toasts, translations, HTTP status, JSON resources, MCP text** | ✅ | ❌ Return models or `*Result` DTOs, never UI strings |
| **Error mapping** | ✅ (§2.7 table) | Throws |

**Livewire Form objects** keep their state, validation and the mapping from form fields to the action's input (for example `applyMaxStorageToConfig` converting a size and unit to bytes). They lose persistence: `Form::store()` and `Form::update()` either disappear or become one-line calls to the action.

### 2.5 Inputs

- **Prefer typed parameters**, plus explicit context: `Organization $organization` for anything that creates org-owned records, and `?int $triggeredByUserId`.
- For create/update of wide records (servers, volumes, scheduled restores), accept **one validated attribute array** documented with a PHPStan array shape. Both edges already produce such an array (`$request->validated()`, Livewire form data). Do not introduce a DTO just to wrap it.
- Introduce an **input DTO** only when normalization logic must be shared. `RestoreOptions` (step 1.1) is the only planned one.
- **Never pass:** `Request`, FormRequest, Livewire components or Forms, `Laravel\Mcp\Request`, or `Command`.

### 2.6 Outputs

- Return the created or updated model, `void`, a scalar (count, bool), or a `readonly *Result` DTO when there are several values. `ScheduledRestoreResult` is the model to follow.
- Never return messages meant for a user.

### 2.7 Errors

| Situation | Action throws | Livewire | REST API | MCP |
|---|---|---|---|---|
| Input the user can fix (domain invariant tied to a field) | `ValidationException::withMessages(['<action input key>' => '…'])`, keyed by the **action's** input names (for example `target_server_id`), as `SyncBackupConfigurationsAction` already does | Let it bubble through `withValidationFeedback()`; remap keys to `form.*` only if the field must be highlighted | 422 (default rendering) | `Response::error(collect($e->errors())->flatten()->implode(' '))` |
| State conflict (in use, in progress, locked) | Dedicated exception in `App\Exceptions\<Domain>\`, carrying the data the edges need for their message | `$this->error(__('…'))` toast | 409 with `{message}` (add `@response 409` for Scramble) unless the step says otherwise | `Response::error('…')` |
| Infrastructure failure | Let it propagate (or a domain exception that wraps it) | Existing `report()` + toast pattern | 500 / existing behavior | `Response::error` |

### 2.8 Transactions and side effects

- An action that writes more than one row wraps the writes in `DB::transaction()`.
- Side effects that leave the database (queue dispatch, notifications, storage I/O, Bouncer cache refresh):
  - Never move them into or out of a transaction during a refactor unless the step says so. The current ordering is load-bearing; for example, `DeleteSnapshotsJob::dispatchFor` marks rows, dispatches, and rolls back the flag if the dispatch throws.
  - New code defers them with `DB::afterCommit()`, following the `AssignRoleToUserAction` precedent.
- Storage deletions stay **outside** DB transactions (see the reasoning in `OrganizationMergeService`).

### 2.9 Composition

- Actions may call actions via `::run()`.
- `Dispatch*`, `Record*` and `Finish*` actions are building blocks. Edges normally reach them only through a `Trigger*`, `Delete*`, `Complete*` or `Fail*` action or an agent handler.
- No cycles. Keep call chains shallow (three levels at most is a good smell test).

### 2.10 What is NOT an action (do not wrap these)

- Engines and drivers: `BackupTask`, `RestoreTask`, `Databases/*`, `Filesystems/*`, `Compressors/*`, `ShellProcessor`, `SshTunnelService`.
- Builders and mappers: `BackupJobFactory` (used by actions in Phase 1), `AgentJobPayloadBuilder`.
- Agent protocol strategies: `Services/Agent/Handlers/*` (they *call* actions) and `Services/Agent/Runners/*`.
- Queries: `app/Queries/*`, `LatestSnapshotResolver`. Model accessors and scopes.
- Request context: `CurrentOrganization`, middleware.
- Fortify actions: `app/Actions/Fortify/*`.
- Thin wrappers over a single service call that is already shared, such as `VolumeConnectionTester::test` and `DatabaseProvider::testConnectionForServer`. Do not wrap a call that is already shared unless the action adds orchestration.

### 2.11 Gotchas specific to laravel-actions

1. **Only `AsObject` and `AsFake`, never the rest:**
   - `AsController`: Scramble (our API docs, `config/scramble.php`) documents laravel-actions routes only in its paid PRO tier, so the free docs at `/docs/api` would lose those endpoints. Our controllers also carry the `@response` docblocks Scramble reads.
   - `AsJob`: wraps the action in `JobDecorator`, which changes the class name stored in queued payloads. Self-hosted users upgrade with jobs in flight, and `ProcessBackupJob`/`ProcessRestoreJob`/`DeleteSnapshotsJob` carry tuned `$timeout`/`$tries`/`failed()`. `Queue::assertPushed(ProcessBackupJob::class)` assertions would break too.
   - `AsCommand`, `AsListener`: no benefit here; our commands and listeners are already thin edges.
   - Livewire and MCP, our two biggest edges, are not covered by the package at all.
2. **Never rename or move a queued job class** (`App\Jobs\*`) as part of this migration. Serialized payloads reference the class name.
3. **Fakes apply at resolution time.** `X::shouldRun()` / `X::mock()` registers a mock that the container returns on every *later* resolution of `X`. `X::run()` resolves on each call, so fakes always apply. An action instance captured earlier (injected, or kept in a property) would bypass the fake, which is one more reason §2.3 forbids holding instances.
4. **`make` is reserved** by `AsObject::make()`. `run` and `mock` are reserved too.
5. **Agent API compatibility** (`CLAUDE.md`, Agent Mode): steps touching `AgentController` or `Services/Agent/*` must keep every `/api/v1/agent/*` request and response byte-for-byte identical. `tests/Feature/Agent/*` must pass **unchanged**.

---

## 3. Testing strategy (Task 1)

### 3.1 Action tests (new)

- **Location:** `tests/Feature/Actions/<Domain>/<ClassName>Test.php`. They are feature tests: real database through `RefreshDatabase` and factories, auto-applied by `tests/Pest.php`.
- **Call the real thing:** `TriggerRestoreAction::run(...)`.
- **What to cover:** the business rules, edge cases and invariants of the operation. One test per behavior. The rules are in the `testing-best-practices` skill and the "Test Strategy" section of `CLAUDE.md`.
- **Port, don't invent.** For each existing entry-point test that asserts a business outcome of this operation, write the equivalent action test. The inventory in the Task 2 file seeds the list. Record each pair in the ledger (§3.3).
- **Fakes:**
  - Use `Queue::fake()` to assert dispatch, and `Http::fake()` and `Process::fake()` for the outside world.
  - Mock infrastructure services (`DatabaseProvider`, `FilesystemProvider`, …) as existing tests already do.
  - Fake **another action** (`DispatchRestoreAction::shouldRun()->once()`) only when its effect is expensive or already covered by its own test. Otherwise let it run.
  - Never mock models.
- **Authorization does not belong in action tests.** Actions have none.

Example:

```php
use App\Actions\Restore\TriggerRestoreAction;
use App\Jobs\ProcessRestoreJob;
use App\Models\DatabaseServer;
use App\Models\Snapshot;

beforeEach(fn () => Queue::fake());

test('it creates a restore and queues it for a server the app reaches', function () {
    $target = DatabaseServer::factory()->create(['database_type' => 'mysql']);
    $snapshot = Snapshot::factory()->forServer($target)->create();

    $restore = TriggerRestoreAction::run($snapshot, $target, 'restored_db');

    expect($restore->target_server_id)->toBe($target->id)
        ->and($restore->schema_name)->toBe('restored_db');
    Queue::assertPushed(ProcessRestoreJob::class, 1);
});
```

### 3.2 Entry-point tests during Task 1

- **Do not delete, move or weaken** existing entry-point tests. They prove each refactor preserved behavior.
- **Mechanical edits only:**
  - namespaces;
  - `app(X::class)->execute(...)` becomes `X::run(...)`;
  - a Mockery partial of an action becomes `X::partialMock()` or `X::shouldRun()`. The only current case is `tests/Feature/Services/Backup/SnapshotCleanupServiceTest.php` (around line 60).
- **For each divergence a step fixes, add or adjust the test at the edge where the behavior changed.** For example: the API now returns 409 when deleting a schedule in use.
- Do not add new business-rule assertions to entry-point tests. They go in the action test.
- Where a step adds an authorization check (steps 1.1 and 1.7), add the allow/deny pair named by ability (`withAbilities([…])` / `withAllAbilitiesExcept(…)`), following `CLAUDE.md`.

### 3.3 Duplication ledger (input for Task 2)

Every Task 1 step appends rows to the ledger table in [`action-pattern-test-deduplication.md` §5](action-pattern-test-deduplication.md#5-duplication-ledger). Each row names an existing entry-point test that now duplicates an action test, and the action test that covers it. Task 2 consumes this list.

### 3.4 Architecture tests (added in step 0.1)

Add `tests/Unit/ArchitectureTest.php`. Arch tests need no Laravel app; `tests/Pest.php` binds no TestCase for `Unit`.

```php
<?php

use Lorisleiva\Actions\Concerns\AsAction;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

arch('actions follow the naming and shape convention')
    ->expect('App\Actions')
    ->classes()
    ->toHaveSuffix('Action')
    ->toHaveMethod('handle')
    ->toUseTraits([AsObject::class, AsFake::class])
    ->not->toUseTrait(AsAction::class)
    ->not->toBeFinal()
    ->ignoring('App\Actions\Fortify');

arch('actions do not depend on an edge or on request context')
    ->expect('App\Actions')
    ->not->toUse([
        'App\Livewire',
        'App\Http',
        'App\Mcp',
        'App\Console',
        'Livewire',
        'Laravel\Mcp',
        'Illuminate\Http\Request',
        'Illuminate\Support\Facades\Auth',
        'Illuminate\Support\Facades\Request',
        'Illuminate\Support\Facades\Session',
        'App\Services\CurrentOrganization',
    ])
    ->ignoring('App\Actions\Fortify');

arch('only edges depend on Livewire')
    ->expect('App\Livewire')
    ->not->toBeUsedIn(['App\Actions', 'App\Services', 'App\Jobs', 'App\Mcp', 'App\Console', 'App\Models', 'App\Http']);
```

- **Verify against Pest 5 when installing.** If the `not->toUse([...])` expectation cannot see global helper calls (`auth()`, `request()`, `session()`), add a small test that scans `app/Actions/**/*.php` (excluding `Fortify`) for `\b(auth|request|session)\(` and fails on a match.
- **Expected failures of the "only edges depend on Livewire" rule today:**
  - `SyncBackupConfigurationsAction` → `BackupForm`. Fixed in the same step (0.1, sub-step 9).
  - `VolumeType::configClass()` builds a Livewire class name from a string, which the arch test cannot see. It is a known exception, tracked in §7.
  - If the test catches anything else, list it with `->ignoring()` and a `// TODO(action-pattern): …` comment, and record it in §7. Do not widen the migration to fix it.

---

## 4. Phase 0: Foundation (2 steps)

### Step 0.1: Adopt laravel-actions and move the existing actions to `app/Actions`

**Commit subject:** `chore: move the existing actions to app/Actions on laravel-actions`

1. **Install the package:**
   ```bash
   docker compose exec --user application -T app composer require lorisleiva/laravel-actions:^2.12
   ```
   v2.12.0 (2026-08-25) supports Laravel 11–13 and PHP 8.2+. It pulls in `lorisleiva/lody`. Package discovery registers `ActionServiceProvider`, which adds `make:action` and a `beforeResolving` container hook used by `AsFake`.
2. **PHPStan:** add `vendor/lorisleiva/laravel-actions/extension.neon` to `includes:` in `phpstan.neon`, with a comment in the same style as the Pest include.
3. **Move with `git mv`** (keeps history), then update namespaces:

   | From | To |
   |---|---|
   | `app/Services/Backup/TriggerBackupAction.php` | `app/Actions/Backup/TriggerBackupAction.php` |
   | `app/Services/Backup/DispatchBackupAction.php` | `app/Actions/Backup/DispatchBackupAction.php` |
   | `app/Services/Backup/DispatchDiscoveryAction.php` | `app/Actions/Backup/DispatchDiscoveryAction.php` |
   | `app/Services/Backup/SyncBackupConfigurationsAction.php` | `app/Actions/Backup/SyncBackupConfigurationsAction.php` |
   | `app/Services/Backup/DeleteSnapshotAction.php` | `app/Actions/Snapshot/DeleteSnapshotAction.php` |
   | `app/Services/Backup/DispatchRestoreAction.php` | `app/Actions/Restore/DispatchRestoreAction.php` |
   | `app/Services/Backup/RunScheduledRestoreAction.php` | `app/Actions/ScheduledRestore/RunScheduledRestoreAction.php` |
   | `app/Services/Roles/CreateRoleAction.php` | `app/Actions/Role/CreateRoleAction.php` |
   | `app/Services/Roles/UpdateRoleAction.php` | `app/Actions/Role/UpdateRoleAction.php` |
   | `app/Services/Roles/DeleteRoleAction.php` | `app/Actions/Role/DeleteRoleAction.php` |
   | `app/Services/Roles/AssignRoleToUserAction.php` | `app/Actions/Role/AssignRoleToUserAction.php` |
   | `app/Services/Roles/SyncUserAbilitiesAction.php` | `app/Actions/Role/SyncUserAbilitiesAction.php` |

4. **In each moved class:**
   - add `use AsFake, AsObject;`;
   - rename `execute(` to `handle(`;
   - replace injected actions with `Other::run(...)`. For example, `TriggerBackupAction` drops `DispatchBackupAction`/`DispatchDiscoveryAction` from its constructor.
   - Keep signatures otherwise identical. Behavior changes wait for Phase 1.
5. **`DeleteSnapshotAction` exception.** It currently has three public methods; `recordRemovals` and `deleteUnlessFilesRemain` are also called by `CleanupJobHandler::finishDeletion`. Leave them public in 0.1 (the arch test only checks that `handle` exists). Step 1.3 splits them.
6. **Rewrite every call site to `X::run(...)` and remove the injected parameters:**
   - Livewire:
     - `DatabaseServer/Index::runBackup`, `runBackupAll`
     - `DatabaseServer/Show::runBackupAll`
     - `Traits/RunsServerBackups`
     - `DatabaseServer/Form` (around line 1085)
     - `Restore/Modal::restore` (`DispatchRestoreAction`)
     - `ScheduledRestore/Index::runNow`, `runDisabledNow`, `dispatchRun`
     - `Configuration/Roles::save`, `delete`
     - `User/Form` (around lines 73, 112, 134)
     - `User/Create` (around line 73)
   - Controllers: `Api/V1/DatabaseServerController::backup`, `restore`, `syncBackupConfigurations`; `Api/V1/ScheduledRestoreController::run`.
   - MCP: `Mcp/Tools/TriggerBackupTool`, `TriggerRestoreTool`.
   - Commands: `Console/Commands/RunScheduledBackups`, `RunScheduledRestores`.
   - Jobs: `Jobs/DeleteSnapshotsJob::handle`.
   - Services:
     - `Services/Backup/SnapshotCleanupService` (constructor-injected `DeleteSnapshotAction`)
     - `Services/Agent/Handlers/CleanupJobHandler` (constructor; keep calling the two public helpers via `DeleteSnapshotAction::make()->recordRemovals(...)` until 1.3)
     - `Services/Agent/Handlers/DiscoveryJobHandler`
     - `Services/Organization/OrganizationMergeService`
     - `Services/OAuthService`
   - Elsewhere: `Http/Middleware/DemoModeMiddleware`, `Actions/Fortify/CreateNewUser`, `database/factories/UserFactory.php`, `tests/Pest.php` (`attachUserToOrg`, `dispatchAgentRestore`).
   - Confirm nothing is missed:
     ```bash
     grep -rn "Services\\\\Backup\\\\.*Action\|Services\\\\Roles" app tests database routes
     ```
7. **`phpstan.neon` `ignoreErrors`:** update the two `path:` entries `app/Services/Roles/CreateRoleAction.php` and `…/UpdateRoleAction.php` to their new paths.
8. **Tests:**
   - `git mv tests/Feature/Services/Backup/TriggerBackupActionTest.php tests/Feature/Actions/Backup/TriggerBackupActionTest.php`.
   - Make the mechanical edits from §3.2 across the suite (`app(…Action::class)->execute(` → `…Action::run(`).
   - Convert the partial mock in `SnapshotCleanupServiceTest` to `DeleteSnapshotAction::partialMock()->shouldReceive('handle')…`.
   - Add `tests/Unit/ArchitectureTest.php` (§3.4).
9. **Move the Livewire helpers the moved action depends on.** The arch test must be green from this commit on, so this cannot wait.
   - Move `BackupForm::normalizeSelection()` and `BackupForm::toPersistedData()` (`app/Livewire/DatabaseServer/BackupForm.php`, around lines 88–163) to a framework-free helper, `App\Support\BackupConfiguration` (static methods, same signatures).
   - Point `BackupForm`'s own callers (9 call sites in `DatabaseServer/Form`, plus the blades `_backup-form.blade.php` and `show.blade.php`) and `SyncBackupConfigurationsAction` at it.
   - Update the blades directly. Do not leave proxies on `BackupForm`.
10. **Docs references:** update every mention of the old paths in `CLAUDE.md` ("Agent job types": `Dispatch*Action`; Authorization: `app/Services/Roles/`) and in docblocks. One example is the stale `DatabaseServerForm::update/store` mention in `SyncBackupConfigurationsAction`.
11. **Done when:** `make lint-fix`, `make phpstan` and `make test` all pass, and **no test file except the mechanical edits changed**.

### Step 0.2: Document the conventions

**Commit subject:** `docs: document the action conventions`

1. **Create `docs/development/actions.md`** from §2 of this file. Drop the "decisions" framing and keep the rules, vocabulary table, folder table, edge-vs-action table, error mapping and gotchas.
3. **Add a short `### Actions` section to `CLAUDE.md`** under "Key Patterns" (about 10 lines) pointing to `docs/development/actions.md`. It must state:
   - the pattern: `App\Actions\<Domain>\<Verb><Subject>Action`, `AsObject` + `AsFake`, `handle()`, called via `X::run()`;
   - the verb vocabulary lives in the doc;
   - edges validate, authorize, resolve the org and user, and translate; actions do none of these;
   - actions are never `final`, and never use `AsAction`/`AsController`/`AsJob`;
   - action tests live in `tests/Feature/Actions/…`; entry-point tests cover only the edge contract (see Task 2);
   - agent API payloads must not change when refactoring agent code.
4. **Update `docs/development/extending.md`** wherever it tells contributors to put persistence in Livewire forms or controllers.

---

## 5. Phase 1: Operations shared by several edges (10 steps)

Each step lists: **new or changed actions**, **callers to rewrite**, **divergences to fix (and the behavior that wins)**, **tests**, the **commit subject**, and **risks**.

### Step 1.1: Starting a restore → `TriggerRestoreAction`

**Actions**
- New `App\Actions\Restore\TriggerRestoreAction` (full code in §2.1): `BackupJobFactory::createRestore` + `DispatchRestoreAction::run`.
- New DTO `App\Services\Backup\DTO\RestoreOptions`:
  - `readonly`, with `?bool $forceDatabase`, `?string $ownerUser`, `?bool $parallelRestore`;
  - `fromArray(array $options)`, which trims `owner_user` and drops empty values;
  - `toArray()`, which yields the stored shape (`force_database`, `owner_user`, `parallel_restore`, empty values omitted).
  - It replaces `InteractsWithTargetDatabases::buildOptions()` (around lines 67–74) and the API's ad-hoc `array_filter(['owner_user' => trim(…)])`.
- `RunScheduledRestoreAction` now calls `TriggerRestoreAction::run(...)` instead of the factory + dispatch pair (around lines 44–53).

**Callers**
- `Livewire/Restore/Modal::restore` (around lines 319–361): drop the injected `BackupJobFactory` and `DispatchRestoreAction`.
- `Api/V1/DatabaseServerController::restore` (around lines 194–226).
- `Mcp/Tools/TriggerRestoreTool::handle` (around lines 23–71).

**Divergences**
- **Livewire `restoreFrom`.** Livewire authorizes `restoreFrom` on the snapshot only when the modal opens, not in `selectSnapshot()` or at submit. **Fix:** `Modal::restore` calls `$this->authorize('restoreFrom', $snapshot)` after loading the snapshot. No visible change today (both policies reduce to `operate-restores`), but it closes the contract gap. Add the allow/deny pair named by `operate-restores` only if one doesn't already exist at submit.
- **Options per edge.** Livewire sends `force_database`, `owner_user` and `parallel_restore`; the API sends `owner_user`; MCP sends none. **Keep each edge's accepted inputs**: adding API/MCP fields is a feature, see §7. All edges now normalize the same way through `RestoreOptions`.
- **Dispatch failures in MCP.** `DispatchRestoreAction` throws `RuntimeException` when an agent payload cannot be built. MCP currently lets it escape. **Fix:** catch it and return `Response::error($e->getMessage())`, as the Livewire toast does. Keep the API's current behavior.

**Tests**
- `tests/Feature/Actions/Restore/TriggerRestoreActionTest.php`:
  - app-reachable server → `ProcessRestoreJob` queued;
  - agent server → `AgentJob` of type restore enqueued;
  - options normalized: `' pg_owner '` → `'pg_owner'`, empty values dropped;
  - `snapshotFileId` honored;
  - a `ValidationException` from the factory guard (incomplete snapshot) propagates;
  - the scheduled restore id is recorded.
- `RestoreOptionsTest` in `tests/Unit` (pure).
- `tests/Feature/Actions/ScheduledRestore/RunScheduledRestoreActionTest.php`. It does not exist yet. Port these cases:
  - skipped while a previous run is in flight;
  - skipped when no snapshot exists;
  - a disabled definition runs and stays disabled;
  - `last_executed_at` and `last_skip_reason` are written.
  These are asserted today through the Livewire, API and console tests only.
- Ledger:
  - Livewire `Restore/ModalTest`, `Api/RestoreApiTest`, `Mcp/McpServerTest` ("trigger restore" block), `Agent/AgentRestoreTest`;
  - `Livewire/ScheduledRestore/IndexTest`, `Api/ScheduledRestoreCrudApiTest` ("run" cases), `Console/RunScheduledRestoresTest`.

**Commit subject:** `chore: start every restore through TriggerRestoreAction`
**Risk:** low. This is the template step for the pattern, so review its commit carefully.

### Step 1.2: Backup trigger returns data, not messages

**Actions**
- `TriggerBackupAction::handle()` returns a new `App\Services\Backup\DTO\TriggerBackupResult` instead of `array{snapshots, discovery, message}`:
  - `list<Snapshot> $snapshots`;
  - `BackupDiscoveryState $discovery`, a new enum `App\Enums\BackupDiscoveryState` with `NotNeeded`, `Dispatched`, `AlreadyRunning`;
  - `message(): string`, which returns today's **English** strings, for the API, MCP and CLI.
- **Livewire** builds its toast with `__()`/`trans_choice()` from the state and count.

**Callers**
- Livewire: `DatabaseServer/Index::runBackup`, `Traits/RunsServerBackups`.
- `Api/V1/DatabaseServerController::backup`.
- `Mcp/Tools/TriggerBackupTool`.
- `Console/Commands/RunScheduledBackups`.

**Divergence**
- Today the discovery messages are wrapped in `__()` while the success messages are not, so API and MCP responses can come back partly localized. `CLAUDE.md` says API, CLI and MCP responses are deliberately not translated. **Fix:** English everywhere outside Livewire.
- Run `make update-translation` (or at least `make check-translation`): two keys leave `lang/*.json` and the new Livewire keys are added.

**Tests:** update `TriggerBackupActionTest` to the DTO. Edge tests keep their message assertions, so this is a mechanical shape update.
**Commit subject:** `chore: return a result object from TriggerBackupAction`
**Risk:** low.

### Step 1.3: Snapshot deletion

**Actions**
- New `App\Actions\Snapshot\DispatchSnapshotDeletionAction::handle(Builder $query, bool $keepFiles = false): int`.
  - It is the body of `DeleteSnapshotsJob::dispatchFor()` (`app/Jobs/DeleteSnapshotsJob.php`, around lines 42–66): `deletable()` filter, chunks of 500 marked `deleting`, dispatch `DeleteSnapshotsJob`, roll the flag back if the dispatch throws.
  - **Without the `auth()->user()?->isDemo()` short-circuit.**
  - Delete `dispatchFor()`.
  - **Keep `DeleteSnapshotsJob`'s class name, constructor and properties unchanged.** They are a queued payload.
- New `RecordSnapshotFileRemovalsAction::handle(Snapshot $snapshot, array $outcomes): void`, from `DeleteSnapshotAction::recordRemovals()`.
- New `FinishSnapshotDeletionAction::handle(Snapshot $snapshot, array $outcomes): void`:
  - runs `RecordSnapshotFileRemovalsAction::run(...)`, then the body of `deleteUnlessFilesRemain()`;
  - throws `SnapshotFilesNotDeletedException`.
- `DeleteSnapshotAction` keeps a single public `handle()`:
  - no agent copies → `FinishSnapshotDeletionAction::run`;
  - agent copies → `RecordSnapshotFileRemovalsAction::run` with the non-agent outcomes, then enqueue the cleanup `AgentJob` (unchanged).
- `CleanupJobHandler::finishDeletion` calls `FinishSnapshotDeletionAction::run(...)` and keeps its own `try/catch` + `Log::warning`.

**Callers**
- `Livewire/Snapshot/Index::queueDeletion` (around lines 320–337): keeps its demo toast branch and calls the action.
- `Api/V1/SnapshotController::destroy`, `bulkDestroy`.
- `DeleteSnapshotsJob::handle` uses `DeleteSnapshotAction::run`.
- `SnapshotCleanupService` (retention) keeps `DeleteSnapshotAction::run`.

**Divergences**
- **Demo guard.** It lived twice: in the job helper, where it read `auth()`, and in Livewire. **Fix:** the edges own it.
  - Livewire is unchanged.
  - `SnapshotController` gets an explicit branch: `if ($request->user()->isDemo()) { return 202 with the deletable count, $query->deletable()->count(), and dispatch nothing }`. This preserves today's API response exactly.
- **In-progress single delete.** The API returns 409 (`abort_if(...isInProgress(), 409)`); Livewire skips the snapshot silently through `deletable()`. **Keep both.** They are edge contracts, not domain rules.

**Tests**
- `DispatchSnapshotDeletionActionTest`:
  - marks the snapshots as deleting and queues one job per 500;
  - skips locked and running snapshots;
  - clears the flag when the dispatch throws;
  - returns the count.
- `DeleteSnapshotActionTest`: port the `describe('dispatch')` cases from `tests/Feature/Agent/AgentSnapshotCleanupTest.php` (around lines 69–141). Copy them; do not move them.
- `FinishSnapshotDeletionActionTest`: deletes when no copy is left; keeps the snapshot, records the error and throws when a copy remains.
- `SnapshotApiTest`: add "demo user bulk delete returns the count and deletes nothing", unless `DemoModeTest` already covers it.
- Ledger:
  - `Livewire/Snapshot/IndexTest` (locked/running skipped, real deletion);
  - `Api/SnapshotApiTest` (bulk payload, deleting flag);
  - `Jobs/DeleteSnapshotsJobTest`;
  - `Services/Backup/SnapshotCleanupServiceTest` ("unreachable volume keeps the snapshot");
  - `Snapshot/SnapshotFileTest`.

**Commit subject:** `chore: route snapshot deletion through actions`
**Risk:** medium. Run the full suite, and `tests/Feature/Agent/*` must pass unchanged.

### Step 1.4: Backup schedules

**Actions** (in `App\Actions\BackupSchedule\`)
- `CreateBackupScheduleAction::handle(array $attributes): BackupSchedule`, with attributes shaped `array{name: string, expression: string}`.
- `UpdateBackupScheduleAction::handle(BackupSchedule $schedule, array $attributes): BackupSchedule`.
- `DeleteBackupScheduleAction::handle(BackupSchedule $schedule): void`. When backups or scheduled restores still reference the schedule, it throws `App\Exceptions\Backup\BackupScheduleInUseException`, which exposes `int $backupsCount` and `int $scheduledRestoresCount`.

**Callers**
- `Livewire/Configuration/Backup::saveSchedule`, `deleteSchedule` (around lines 88–164).
- `Api/V1/BackupScheduleController::store`, `update`, `destroy`.
- `DemoBackupService` keeps its `firstOrCreate` (out of scope).

**Divergences**
- **Delete while in use.** The API `destroy` has no in-use guard, so the FK restrict raises a DB error and returns **500**. **Fix:** the guard moves into the action.
  - API → **409** `{message: "Cannot delete a schedule that is in use by …"}` (add `@response 409`).
  - Livewire keeps its two existing toasts, now chosen from the exception's counts.
- **Name length.** Livewire `max:100` (`Configuration/Form::scheduleRules`) vs API `max:255` (`SaveBackupScheduleRequest`). **Fix:** align on `max:255`, the column limit, so no input that is valid today gets rejected.

**Tests**
- Action tests: create; update; delete; delete refused for each of the two references.
- `Api/BackupScheduleCrudApiTest`: deleting an in-use schedule returns 409 and keeps it.

**Commit subject:** `fix(api): refuse to delete a backup schedule that is still in use`
**Risk:** low.

### Step 1.5: Scheduled restores (definitions)

**Actions** (in `App\Actions\ScheduledRestore\`)
- `CreateScheduledRestoreAction::handle(array $attributes): ScheduledRestore`.
- `UpdateScheduledRestoreAction::handle(ScheduledRestore $scheduledRestore, array $attributes): ScheduledRestore`.
- `DeleteScheduledRestoreAction::handle(ScheduledRestore $scheduledRestore): void`.
- Create and update:
  - normalize `options` through `RestoreOptions` (from 1.1);
  - **guard that the target is not the application database** (`DatabaseServer::isAppDatabase`), throwing `ValidationException` keyed `target_server_id` with the message Livewire uses today (`ScheduledRestore/Modal.php`, around lines 174–177).

**Callers**
- `Livewire/ScheduledRestore/Modal::save` (around lines 107–140); `ScheduledRestore/Index::deleteScheduledRestore` (around lines 160–172).
- `Api/V1/ScheduledRestoreController::store`, `update`, `destroy`.

**Divergences**
- **Application-database target.** The API accepts a scheduled restore that targets the app database and only fails it at run time (`BackupJobFactory::createRestore`). **Fix:** it is rejected at save (422).
  - Livewire keeps its step-level check for UX. The action guard is the invariant.
- **Options.** The API stores `options` raw; Livewire filters out nulls. **Fix:** both are normalized through `RestoreOptions`.
- Not changed: `parallel_restore` is still not accepted by the API request (feature, §7).
- Not changed: cross-org re-resolution and type-match validation stay duplicated between `SaveScheduledRestoreRequest` and `Modal`. Rule-set unification is out of scope (§7).

**Tests**
- Action tests: create; update; delete; app-database target rejected; options normalized.
- API test: saving a scheduled restore onto the app database returns 422.

**Commit subject:** `fix(api): reject a scheduled restore onto the application database when it is saved`
**Risk:** low.

### Step 1.6: Volumes

**Actions** (in `App\Actions\Volume\`)
- `CreateVolumeAction::handle(Organization $organization, VolumeType $type, string $name, array $config): Volume`:
  - `$config` holds **plain** values;
  - the action encrypts sensitive fields (`$type->encryptSensitiveFields(...)`) and sets `organization_id`.
- `UpdateVolumeAction::handle(Volume $volume, string $name, array $config): Volume`:
  - merges blank sensitive fields from the persisted config (today `Volume\Form::mergeSensitiveFromPersisted`), then encrypts;
  - **locked-volume rule:** only the name and storage-limit keys may change (today `Form::updateLockedVolume`, around lines 145–157).
  - Update has no API twin. It is included because it shares config handling with create, and leaving half the form's persistence behind would be worse.
- `DeleteVolumeAction::handle(Volume $volume, bool $keepFiles = false): void`: sets `skipFileCleanup` and deletes. The cascade stays in `Volume::booted()`.

**Callers**
- `Livewire/Volume/Create::save` → `Form::store`.
- `Volume/Edit::save` → `Form::update` and `updateLockedVolume`.
- `Volume/Index::delete`.
- `Api/V1/VolumeController::createVolume`, `destroy`.
- The Livewire Form keeps `buildConfig` input mapping (storage size and unit → bytes) and its rules.

**Divergences**
- **Duplicate names.** `volumes.name` has a **global unique index** (`2025_12_28_212547_create_volumes_table.php`). Livewire validates `unique:volumes,name`; the API does not, so a duplicate name hits the index and returns **500**. **Fix:** add the same rule to `StoreVolumeRequest`, so the API returns 422.
- **`keep_files` on delete.** Livewire offers it; the API does not. The action supports it. Exposing `keep_files` on the API is a feature (§7); the API passes `false` as today.

**Tests**
- Action tests:
  - create encrypts the sensitive fields and sets the org;
  - update keeps a stored secret left blank;
  - update of a locked volume ignores everything except the name and storage limit;
  - delete with and without `keepFiles`.
- API test: duplicate name returns 422.

**Commit subject:** `fix(api): reject a volume whose name is already taken`
**Risk:** low to medium (config encryption round-trips).

### Step 1.7: SSH configs

**Actions** (in `App\Actions\DatabaseServer\`)
- `CreateSshConfigAction::handle(Organization $organization, array $attributes): DatabaseServerSshConfig`.
- `UpdateSshConfigAction::handle(DatabaseServerSshConfig $sshConfig, array $attributes): DatabaseServerSshConfig`.
- `DeleteSshConfigAction::handle(DatabaseServerSshConfig $sshConfig): void`. While servers use the config it throws `App\Exceptions\DatabaseServer\SshConfigInUseException`; the API keeps its current **422**.
- **The API's credential semantics win** (`DatabaseServerSshConfigController`, around lines 95–106 and 172–182):
  - blank sensitive fields keep the stored value;
  - a new private key without a passphrase drops the stored passphrase;
  - switching the auth type clears the other type's secrets;
  - defaults: `port=22`, `compression=false`.
- **Key generation stays at the edges.** They call `SshKeyGenerator` and pass `private_key`, because the key comment differs per edge.

**Callers**
- `Api/V1/DatabaseServerSshConfigController::store`, `update`, `destroy`.
- `Livewire/DatabaseServer/Form::createOrUpdateSshConfig` (around lines 1023–1081). It becomes "build attributes → `Create`/`UpdateSshConfigAction::run`". In 1.8 the call moves inside the server transaction.

**Divergences**
- **Stale credentials.** Livewire never cleared the credentials of the other auth type and kept the old passphrase after a key change. **Fix:** same semantics as the API.
- **Missing policy check.** When the server form edits an existing, possibly **shared**, SSH config, Livewire checked only `DatabaseServerPolicy@update`. **Fix:** also `$this->authorize('update', $sshConfig)` (`DatabaseServerSshConfigPolicy`, which needs the same `manage-database-servers` ability plus org ownership). Add the allow/deny pair if missing.

**Tests**
- Action tests:
  - create with defaults;
  - update keeps blank secrets;
  - an auth-type switch clears the other secrets;
  - a new key drops the passphrase;
  - delete refused while in use.
- Livewire `DatabaseServer/EditTest`: switching the SSH auth type clears the stale password or key.

**Commit subject:** `fix: clear the unused SSH credentials when a server's SSH settings change`
**Risk:** medium (shared SSH configs). Must land before 1.8.

### Step 1.8: Database servers (and the demo backup)

**Actions** (in `App\Actions\DatabaseServer\`)

`CreateDatabaseServerAction`:

```php
/**
 * @param  array<string, mixed>  $attributes  validated server columns (no `backups`, no inline SSH)
 * @param  list<array<string, mixed>>|null  $backups  null leaves backup configurations untouched
 * @param  array<string, mixed>|null  $sshConfig  inline SSH settings to create or update and link
 * @param  list<string>|null  $notificationChannelIds  null leaves channels untouched
 */
public function handle(
    Organization $organization,
    array $attributes,
    ?array $backups = null,
    ?array $sshConfig = null,
    ?array $notificationChannelIds = null,
): DatabaseServer
```

- It runs **one `DB::transaction`**:
  1. SSH upsert through the 1.7 actions (sets `ssh_config_id`);
  2. `DatabaseServer::buildExtraConfig(...)`;
  3. create;
  4. `SyncBackupConfigurationsAction::run(...)`;
  5. `notificationChannels()->sync()`.
- `backups_enabled` defaults to `true` when absent, as the API does today.

`UpdateDatabaseServerAction::handle(DatabaseServer $server, array $attributes, ?array $backups = null, ?array $sshConfig = null, ?array $notificationChannelIds = null): DatabaseServer`
- Drops a blank `password`; both edges do this today.
- Keeps `backups_enabled` when it is absent.
- Calls `buildExtraConfig` with the existing config and the previous type.
- Same transaction as create.

`DeleteDatabaseServerAction::handle(DatabaseServer $server, bool $keepFiles = false): void`
- Sets `skipFileCleanup` and deletes. The cascade stays in `DatabaseServer::booted()`.

**Callers**
- `Livewire/DatabaseServer/Create::save` → `Form::store` (around lines 951–969).
- `Edit::save` → `Form::update` (around lines 971–1000).
- `Index::delete` and `Show::delete`, which today duplicate the two `skipFileCleanup` + `delete` lines.
- `Api/V1/DatabaseServerController::store`, `update`, `destroy`; drop its private `syncBackupConfigurations`.
- **`DemoBackupService::createDemoBackup`:**
  - takes `Organization $organization` as a parameter;
  - uses `CreateVolumeAction::run` and `CreateDatabaseServerAction::run`;
  - stops reading `CurrentOrganization`;
  - `Actions/Fortify/CreateNewUser` passes `$defaultOrg`.

**Stays at the Livewire edge:** clearing an undecryptable password when the edit form loads (`Form::update`, around lines 974–979). It is a form-loading concern.

**Divergences**
- **Not atomic.** The API created or updated the server and synced backups without a transaction. **Fix:** one transaction for every edge. In Livewire, the SSH config upsert also moves inside the transaction (it was outside).
- **Demo backup at first registration** (suspected bug, **verify first**). `DemoBackupService` reads `CurrentOrganization::id()`. During guest registration, `SetCurrentOrganization` resets the context and resolves nothing, so `id()` very likely fails, and `CreateNewUser` swallows the `Throwable`. Tests hide it: `tests/Pest.php` sets the org globally, and `RegistrationTest` mocks the service.
  - **First** write a failing test in `tests/Feature/Auth/RegistrationTest.php`: reset `CurrentOrganization` before posting `create_demo_backup=1`, and assert that the server and volume exist.
  - If it fails, use the commit subject `fix: create the demo backup when the first user registers`. If it passes, the bug does not exist; use the `chore:` subject and keep the test.
- **Not changed (out of scope, §7):**
  - the two rule sets (`SaveDatabaseServerRequest` vs Livewire `Form` + `BackupForm` + `ConnectionRules`);
  - API field coverage (`ssl_enabled`, notifications, inline SSH);
  - API `keep_files`.

**Tests**
- Action tests:
  - create with backups, SSH and channels;
  - a failure in backup sync rolls back the server and the SSH config;
  - update drops a blank password;
  - update keeps `backups_enabled` when it is absent;
  - delete with and without `keepFiles`.
- The registration test above.
- `tests/Feature/Actions/Backup/SyncBackupConfigurationsActionTest.php`. It does not exist yet. Port the sync cases from `DatabaseServerCrudApiTest` (around lines 509–562):
  - upsert by id;
  - delete missing entries;
  - reject a foreign id;
  - no-op when backups are disabled.
- Ledger: `DatabaseServer/CreateTest`, `EditTest`, `Api/DatabaseServerCrudApiTest` (backup sync cases), `Livewire/DatabaseServer/IndexTest` (delete).

**Commit subject:** `fix: create the demo backup when the first user registers`, or `chore: save database servers through actions` if the bug is not confirmed.
**Risk:** high, as the largest form. Split the step into two commits if the diff exceeds about 800 lines: (a) actions plus the API, (b) the Livewire form plus `DemoBackupService`.

### Step 1.9: Failing an agent job → `FailAgentJobAction`

**Action:** `App\Actions\Agent\FailAgentJobAction`

```php
/**
 * @param  array<string, mixed>  $result  validated failure payload (handler-specific)
 * @param  list<array<string, mixed>>  $logs  log entries reported by the agent
 * @return bool false when $onlyIfPending and the job was no longer pending
 */
public function handle(AgentJob $agentJob, string $reason, array $result = [], array $logs = [], bool $onlyIfPending = false): bool
```

**Order preserved exactly** (from `AgentController::fail`):
1. `markFailed($reason)`, or `markFailedIfPending` when `$onlyIfPending`; return false if that did not apply;
2. `trackedJob()?->mergeLogs($logs)` when logs are given;
3. `handler()->fail($agentJob, new RuntimeException($reason), $result)`.

**Callers**
- `Api/V1/AgentController::fail` (around lines 198–219).
- `Console/Commands/RecoverStuckJobsCommand`:
  - `recoverAgentJobs` (around lines 89–100): it writes its own `trackedJob()->log(...)` line first, then calls the action;
  - `failUnclaimedCleanupJobs` (around lines 132–139) uses `onlyIfPending: true`.

**Divergences:** none intended. **Every `/api/v1/agent/*` response must stay identical**, and `tests/Feature/Agent/*` plus `RecoverStuckJobsTest` must pass unchanged.

**Tests:** `FailAgentJobActionTest`:
- marks the job failed and calls the handler, for one backup type and one cleanup type;
- merges logs before the handler runs;
- `onlyIfPending` leaves a claimed job untouched and returns false.

**Commit subject:** `chore: fail agent jobs through FailAgentJobAction`
**Risk:** low to medium (agent protocol).

### Step 1.10: Backup and restore outcomes (queue path and agent path)

Do this step last. **Write characterization tests before moving any code.**

**Actions** (`App\Actions\Backup\`, `App\Actions\Restore\`)
- `RecordBackupResultAction::handle(Snapshot $snapshot, BackupResult $result): void`.
  - It persists the filename, file size and checksum, plus per-volume `markUploaded`/`markUploadFailed`.
  - Today it exists twice: `ProcessBackupJob::persistResult` (around lines 172–192) and `BackupJobHandler::applyVolumeResults` (around lines 148–178).
  - The agent handler maps its validated payload to `BackupResult` / `VolumeTransferResult` itself. **The legacy "null volumes" handling stays in the handler**, because it is agent-protocol-specific.
- `CompleteBackupAction::handle(Snapshot $snapshot, BackupResult $result): void`:
  - mark the snapshot and its job completed;
  - `notifyBackupSuccess`;
  - **`notifyStorageLimitWarning` for each `$result->storageWarnings()`**.
- `FailBackupAction::handle(Snapshot $snapshot, Throwable $exception): void`: job log line, `markFailed`, `notifyBackupFailed`.
- `CompleteRestoreAction::handle(Restore $restore): void` and `FailRestoreAction::handle(Restore $restore, Throwable $exception): void`. They serve both `ProcessRestoreJob` (around lines 128–130 and 153–160) and `RestoreJobHandler` (around lines 48–72).

**Callers**
- `Jobs/ProcessBackupJob::process`.
  - It keeps its retry and quota decisions (`VolumeTransferException::allFailuresAreQuota`, `$this->fail()`, rethrow) and its `failed()` → `recordQueueFailure` gate. These are queue-edge concerns.
- `Jobs/ProcessRestoreJob`.
- `Services/Agent/Handlers/BackupJobHandler::complete`, `fail`.
- `RestoreJobHandler::complete`, `fail`.
- `RecoverStuckJobsCommand::recoverBackupJobs`: its "mark failed + notify" becomes `FailBackupAction`/`FailRestoreAction`.

**Divergences**
- **Storage-limit warning.** The agent path never sent it. **Fix:** it now does, through `CompleteBackupAction`.
- **Double completion.** `BackupJobHandler::complete` calls `$snapshot->markCompleted()`, which already completes the job, and then `$backupJob->markCompleted()` again. **Fix:** a single completion in the action. Check that `Snapshot::markCompleted` (around lines 319–327) and `BackupJob::markCompleted` stay idempotent for the queue path, which today calls only `$job->markCompleted()` and writes the checksum in `persistResult`. Pick the agent path's `Snapshot::markCompleted($checksum)` as the canonical sequence, **only if** the characterization tests show identical end state for the queue path.

**Tests**
- **First** add characterization tests for the end state (snapshot fields, file rows, job status, notifications sent) of:
  - queue success;
  - queue partial failure (including the quota case);
  - agent ack success;
  - agent ack with a failed copy;
  - agent fail;
  - restore success and failure on both paths.
- Then the action tests.
- `Notification::fake()` is already global (`tests/TestCase.php`); assert with `Notification::assertSentTo…` as the existing tests do.

**Commit subject:** `fix(agent): send the storage limit warning for backups taken by an agent`
**Risk:** high. Agent API responses must stay identical. If the diff grows past about 800 lines, split the step into two commits: 1.10a (backup) and 1.10b (restore).

---

## 6. Definition of done

### 6.1 Every step (each commit)

1. **No copy left:** `grep` finds no remaining inline copy of the logic the step extracted, and every listed caller uses `X::run(...)`.
2. **Checks pass:** `make lint-fix`, `make phpstan` and `make test` (full suite, parallel). TIA is never a gate. The pre-commit hook runs these when it is installed; in an environment without it, run them by hand before committing.
3. **Arch tests green:** `tests/Unit/ArchitectureTest.php`.
4. **Entry-point tests unchanged**, except:
   - the mechanical edits of §3.2;
   - tests added for the divergences the step fixes.
5. **Tests and ledger:** a new action test exists for each new action, and ledger rows are added to the Task 2 file (§5 there).
6. **Docs:** `docs/development/actions.md` is updated if the step adds a verb, folder or rule; so is `CLAUDE.md` if a documented path changed.
7. **Commit message:**
   - The subject is the step's **Commit subject**: `chore:` when nothing an operator or API user would notice changed; `fix:` / `fix(api):` / `fix(agent):` naming the **user-visible fix** when the step resolved a visible divergence.
   - A `fix:` commit's body explains the visible effect, before and after. PRs are squash-merged, and GitHub's default squash body concatenates the commit messages, which the `/changelog` skill reads (see `52dac27` for the shape).

### 6.2 The PR (before marking it ready for review)

1. Every step of §4 and §5 is committed, and §6.1 holds on the branch head, after a fresh merge of `main`.
2. **PR title** (it becomes the squash subject and the release changelog's lead):
   - It leads with the visible effect, because the PR contains user-visible fixes. Suggested: `fix: apply the same rules whether a change comes from the web UI, the API, MCP or an agent`.
   - **Never `(agent)!`**: no step may break agent compatibility.
3. **PR body**, in this order:
   - **Behavior changes:** one bullet per divergence fixed, before → after, naming the edge it affects (web UI, API, MCP, agent). This is the list the release changelog is written from, so phrase it for operators.
   - **Steps:** one line per step (`0.1` … `1.10`) with its commit(s), the actions added or moved, and the callers rewritten. Reviewers read the PR commit by commit in this order.
   - **Not changed:** divergences deliberately left, with a link to §7.
   - **Ledger:** the number of rows added for Task 2, with a link to the ledger.
4. Dependencies: `composer.json` / `composer.lock` gain `lorisleiva/laravel-actions` (approved in D1). No other dependency changes.

---

## 7. Out of scope: follow-up candidates

Record new findings here instead of widening the migration.

**Phase 2 candidates (agent API, jobs and commands)**
- `ClaimAgentJobAction` (inline claim query in `AgentController::claimJob`).
- `RecordAgentHeartbeatAction` (version parsing in `AgentController::heartbeat`).
- `RecoverExpiredAgentJobsAction` and `FailTimedOutJobsAction` (the rest of `RecoverStuckJobsCommand`).
- `ApplyRetentionPoliciesAction` (`SnapshotCleanupService::run`) and `VerifySnapshotFilesAction` (`SnapshotVerificationService::run`).
- `MergeOrganizationsAction` and `DeleteOrganizationAction` (`OrganizationMergeService`).
- `ResolveOAuthUserAction` (`OAuthService::findOrCreateUser`).
- `RunBackupScheduleAction` (selection loop in `RunScheduledBackups`).
- `CancelJobAction` (`BackupJob::cancel`).

**Phase 3 candidates (Livewire-only mutations)**
- Users: create/invite, update, delete, add existing, remove from org.
  - The "create user + attach org + assign role" sequence is duplicated in `User/Form`, Fortify `CreateNewUser` and `OAuthService`.
- Notification channels: CRUD and `SendTestNotificationAction`.
- App and backup settings (`UpdateBackupSettingsAction`, which includes the `queue:restart` side effect).
- Agents and agent tokens (not atomic today).
- API tokens.
- Snapshot comment and lock.
- Restore delete.
- Toggling `backups_enabled`.
- Password set: four places, with divergent rules (`AcceptInvitation` uses `min:8`).

**Features surfaced by unification** (each needs its own `feat:` PR)
- API `keep_files` on server and volume delete.
- API/MCP restore options (`force_database`, `parallel_restore`, `snapshot_file_id`).
- API `parallel_restore` on scheduled restores.
- API azure/smb volumes and volume update.
- API notification fields and `ssl_enabled` on servers.

**Structural**
- **One rule source per resource.** The FormRequest and Livewire Form rule sets are duplicated for servers, scheduled restores and schedules.
- **Livewire connector classes.** `VolumeType::configClass()` and the API Volume requests depend on `App\Livewire\Volume\Connectors\*Config`; move the per-type rules out of Livewire.
- **Model `deleting` hooks** (`Snapshot`, `Volume`, `DatabaseServer`, `Organization`) still delete files synchronously and best-effort, unlike `DeleteSnapshotAction`.
  - `Volume::booted` deletes storage inside a DB transaction.
  - Converging these needs its own design.
- **MCP list tools** (`ListDatabaseServersTool`, `ListSnapshotsTool`, `GetJobStatusTool`) do not use `app/Queries/*`. `ListSnapshotsTool` loads the job `logs` blob.
- **Naming collision:** `App\Livewire\Actions\Logout` sits in a Livewire "Actions" namespace.

**To investigate separately** (reported by the inventory, not verified)
- Livewire methods authorized only in `mount()` that open outbound connections:
  - `DatabaseServer\Form` `testConnection`, `testSshConnection`, `generateSshKey` and `loadDatabases`;
  - `Volume\Form::testConnection`.
- `ApiToken\Index::createToken` has no authorization. `ApiToken\Index::canDelete` and `InteractsWithTargetDatabases::loadExistingDatabases()` are public, so client-callable; check what the latter does with a client-supplied server.
- `User\Index::removeFromOrg` detaches membership but leaves the Bouncer role and ability rows in that org's scope.
- `Settings\DeleteUserForm` has no last-super-admin guard.
- `volumes.name` is **globally** unique, so creating a volume can reveal that another organization uses a name.
