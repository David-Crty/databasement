# Test De-duplication Plan (Task 2)

> **Audience:** Claude Code sessions (and reviewers) trimming duplicated tests after the action-pattern migration.
> **Depends on:** [`action-pattern-migration.md`](action-pattern-migration.md) (Task 1). **Task 2 is one pull request**, opened after the Task 1 PR is merged and branched from the `main` that contains it.
> **Baseline:** commit `52dac27` (2026-10-11). Line numbers drift; re-grep.

## 1. Goal

**Each business rule is asserted once, in its action test. Each entry-point test file asserts only its edge's contract.**

Today the same rule is often asserted three or four times, once per edge. For example, "locked snapshots are skipped on delete" is checked in the Livewire, API and job tests. This makes changes expensive: a rule change breaks several files, and the files drift apart.

**Task 1 deliberately leaves those tests in place.** They prove the refactor preserved behavior. Task 2 removes the duplication once that proof has served its purpose.

**Non-goals:**
- Changing app code. The Task 2 PR touches `tests/` and test docs only. A missing assertion that reveals a bug becomes a separate `fix:` PR.
- Reducing coverage. See the safety gates in §4.
- Rewriting tests that are not duplicates.

## 2. The edge contract: what entry-point tests keep

| Edge | Keep | Remove (now owned by the action test) |
|---|---|---|
| **Livewire component** | Allow/deny pair per guarded method, **named by ability** (`withAbilities([X])` / `withAllAbilitiesExcept(X)`). One real, unmocked happy path that asserts the outcome at a coarse level (record exists, job pushed). Custom validation messages and UX-only checks, such as wizard step checks. Mapping of action exceptions to toasts (in-use, demo notice). Demo-mode branches. Input mapping from form fields to action arguments, when there are variations. | Variations of the business outcome: which snapshots get deleted, how options are normalized, what gets dispatched for agent servers vs app servers. |
| **REST API** | 401, and the 403 allow/deny pair named by ability. Status codes and JSON resource structure. Error mapping (422, 409). Query and body parameter mapping (for example `backup_id` selection, `keep_files`). Scramble docs stay covered by `ApiDocumentationTest`. | Business variations beyond one real happy path. |
| **MCP tool** | Permission-denied text, validation-error text, the happy-path text (ids returned), argument mapping. Authorization over HTTP stays in `McpHttpAuthorizationTest`. | Business variations. |
| **Console command** | Exit codes, output lines, option and argument mapping, the command's own loop logic (per-item error isolation, schedule selection). | Outcomes of the action it calls. |
| **Queue job** | Job-specific behavior: retries, `failed()` hooks, the quota no-retry rule, chunking, lock and skip handling that lives in `handle()`. | Outcomes of the actions it calls. |
| **Agent API** | **Keep the protocol tests thorough:** status codes, payload shapes, `rejectUnlessActive` 403/409, legacy (≤1.8) compatibility, lease and heartbeat behavior. Agent compatibility is a release guarantee, so this edge stays thick. | Business outcomes of handler `complete()`/`fail()` that now live in `Complete*`/`Fail*`/`Finish*` actions (Task 1, steps 1.3, 1.9 and 1.10). |

**Proving input mapping without re-testing logic.** When an edge has several input variations that only change the action's arguments, keep one real happy path. Express the other variations with the action fake:

```php
test('backup_id selects which configuration the api triggers', function () {
    $user = User::factory()->withAbilities([Ability::RunBackups->value])->create();
    $server = DatabaseServer::factory()->withBackups(2)->create();
    $second = $server->backups->last();

    TriggerBackupAction::shouldRun()
        ->once()
        ->withArgs(fn (Backup $backup) => $backup->is($second))
        ->andReturn(new TriggerBackupResult([], BackupDiscoveryState::NotNeeded));

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/v1/database-servers/{$server->id}/backup?backup_id={$second->id}")
        ->assertAccepted();
});
```

Use `shouldRun()` only for mapping tests. **The single happy path per edge stays unmocked**, so the full wiring keeps an end-to-end check, as `CLAUDE.md` asks ("don't mock simple things").

## 3. Procedure (one PR, one commit per domain)

**Order of the commits:** Snapshot → Restore/ScheduledRestore → Backup → BackupSchedule → Volume → DatabaseServer → Agent → Role. This follows the order of the Task 1 steps. A first commit ships the guardrails of §7.

For each domain (one commit each, subject `test: assert <domain> business rules once, in the action tests`):

1. **Close coverage gaps first.**
   - Make sure every action in the domain has its own test file (§6 lists the ones still missing after Task 1).
   - If a business case exists only at an edge, **move** it: write the action test first, then remove it at the edge in step 3.
2. **Classify every ledger row for the domain** (§5), plus any duplicate found while reading the edge test files:
   - **KEEP:** it is part of the edge contract (§2).
   - **CONVERT:** it varies the business outcome only to prove input mapping. Rewrite it as a `shouldRun()->withArgs()` mapping test.
   - **DELETE:** an action test with equivalent setup and assertion exists, and the ledger names it.
   - **MOVE:** a business case with no action equivalent. Write the action test, then delete the edge copy.
3. **Apply the changes.** Prefer removing whole `test()` blocks over editing assertions inside a kept test, unless the kept test only needs a business assertion dropped.
4. **Run the safety gates** (§4) and commit. Every commit leaves the suite green.

Then open the PR:
- **Title:** `test: assert business rules once, in the action tests`. The `test:` type is skipped in the changelog.
- **Body:** one section per domain commit, each with a table of **every deleted or converted test** (`file`, `test name`, decision, covering action test). `CLAUDE.md` forbids deleting tests without approval, so these tables are the approval request and the reviewer signs off on them.
- Include the coverage comparison of §4 (one line per `app/` file whose covered lines changed, or "no change").

## 4. Safety gates (each domain commit, and the PR)

1. **`make test` is green.**
2. **No coverage lost.**
   - Run `make test-coverage` on `main` and on the branch head (after each domain commit, or at least once before opening the PR). CI already produces `coverage.xml` (clover) via `.github/workflows/tests.yml`.
   - Compare **covered lines per file under `app/`**. No file may lose a covered line.
   - A short throwaway script in the scratchpad that diffs the two clover files is enough. Do not commit it.
   - If a line becomes uncovered, an edge test was the only thing exercising it. Restore the test, or move it to the action test.
3. **Optional, for the riskiest domains (Snapshot, Backup outcomes):** mutation testing with Pest.
   - Add `mutates(<ActionClass>::class);` to the action test file.
   - Run the domain's tests with `--mutate` before and after the trimming:
     ```bash
     docker compose exec --user application -T app vendor/bin/pest --mutate tests/Feature/Actions/<Domain> tests/Feature/<edge dirs>
     ```
   - The mutation score must not drop.
   - Verify the exact flags against the installed Pest 5 docs first. It needs a coverage driver, which `make test-coverage` already uses.
4. **The allow/deny pair for every guarded boundary is still present.** Grep the edge test file for each ability the controller or component checks.

## 5. Duplication ledger

**Who writes what:**
- Each Task 1 step **appends** rows. The Task 2 PR fills in the "Decision" column, applies it, and removes each row once applied (the PR body keeps the record).
- Rows marked `seed` come from the inventory taken before Task 1 (line numbers at `52dac27`).

**Decisions:** `KEEP` / `CONVERT` / `DELETE` / `MOVE` (§3).

| Domain | Edge test (file :: test or line) | Duplicates (action test) | Decision | Added by |
|---|---|---|---|---|
| Backup | `Console/RunScheduledBackupsTest.php:205`, `:232` (agent discovery dispatched / already running) | `Actions/Backup/TriggerBackupActionTest.php` (discovery cases, ~:90, ~:114) | | seed |
| Backup | `Console/RunScheduledBackupsTest.php:187` (agent jobs created) | `TriggerBackupActionTest.php` (~:154) | | seed |
| Backup | `Console/RunScheduledBackupsTest.php:82` (multiple databases → multiple jobs) | `TriggerBackupActionTest.php` (~:45) | | seed |
| Backup | `Api/DatabaseServerCrudApiTest.php:876`, `:894` and `Mcp/McpServerTest.php:298`, `:322` (which backup configuration is triggered) | none. The rule is `DatabaseServer::backupToTrigger()`: add a model test in `tests/Feature/Models/DatabaseServerTest.php`, then CONVERT the edges to mapping tests (§2 example) | | seed |
| Snapshot | `Livewire/Snapshot/IndexTest.php:368`, `:447`; `Api/SnapshotApiTest.php:155`; `Jobs/DeleteSnapshotsJobTest.php:43` (locked / running snapshots skipped) | `Actions/Snapshot/DispatchSnapshotDeletionActionTest.php` (Task 1, step 1.3) | | seed |
| Snapshot | `Jobs/DeleteSnapshotsJobTest.php:19`; `Services/Backup/SnapshotCleanupServiceTest.php:39`; `Snapshot/SnapshotFileTest.php:45` (an unreachable volume keeps the snapshot) | `Actions/Snapshot/DeleteSnapshotActionTest.php`, `FinishSnapshotDeletionActionTest.php` (1.3) | | seed |
| Snapshot | `DemoModeTest.php:119` (Livewire) and `:135` (API) (demo delete is a no-op) | none. Demo handling is edge-owned after step 1.3. | KEEP | seed |
| ScheduledRestore | `Livewire/ScheduledRestore/IndexTest.php:88`; `Api/ScheduledRestoreCrudApiTest.php:215`; `Console/RunScheduledRestoresTest.php:15` (a disabled definition runs without being enabled) | `Actions/ScheduledRestore/RunScheduledRestoreActionTest.php` (1.1) | | seed |
| ScheduledRestore | `Livewire/ScheduledRestore/IndexTest.php:59`; `Console/RunScheduledRestoresTest.php:34` (no snapshot → skipped) | `RunScheduledRestoreActionTest.php` (1.1) | | seed |
| Restore | `Livewire/Restore/ModalTest.php:74` (agent-backed target gets an agent job); `Agent/AgentRestoreTest.php:21` (`describe('dispatch')`, agent job instead of queued job) | `Actions/Restore/TriggerRestoreActionTest.php` (agent-backed server) | | 1.1 |
| Restore | `Livewire/Restore/ModalTest.php:435` (the chosen copy lands on the restore) | `TriggerRestoreActionTest.php` (restores from the chosen copy) | | 1.1 |
| Restore | `Api/RestoreApiTest.php:126` (owner_user stored) | `TriggerRestoreActionTest.php` (options stored without empty values) and `Unit/Services/Backup/DTO/RestoreOptionsTest.php` | | 1.1 |
| Restore | `Livewire/Restore/ModalTest.php:49`, `:218`, `:252`; `Api/RestoreApiTest.php:34`; `Mcp/McpServerTest.php` (trigger restore dispatches job) (restore created and queued) | `TriggerRestoreActionTest.php` (creates and queues) | | 1.1 |
| ScheduledRestore | `Livewire/ScheduledRestore/IndexTest.php:46`; `Api/ScheduledRestoreCrudApiTest.php:194`; `Console/RunScheduledRestoresTest.php` (happy path) (restore created and dispatched) | `Actions/ScheduledRestore/RunScheduledRestoreActionTest.php` (restores the latest snapshot) | | 1.1 |
| ScheduledRestore | `Api/ScheduledRestoreCrudApiTest.php:215` (a disabled definition runs without being enabled) | `RunScheduledRestoreActionTest.php` (disabled runs and stays disabled) | | 1.1 |
| ScheduledRestore | `Console/RunScheduledRestoresTest.php` (skips while a previous restore is in flight) | `RunScheduledRestoreActionTest.php` (skipped while in flight) | | 1.1 |
| Snapshot | `Agent/AgentSnapshotCleanupTest.php` `describe('dispatch')` (app deletes what it reaches, hands the rest to the agent, local copies stay with the app) | `Actions/Snapshot/DeleteSnapshotActionTest.php` | | 1.3 |
| Snapshot | `Jobs/DeleteSnapshotsJobTest.php` (a large selection is queued in chunks) | `Actions/Snapshot/DispatchSnapshotDeletionActionTest.php` (one job per 500) | | 1.3 |
| Snapshot | `Api/SnapshotApiTest.php` (bulk delete marks the snapshots deleting and queues them) | `DispatchSnapshotDeletionActionTest.php` (marks and queues) | | 1.3 |
| Snapshot | `Agent/AgentSnapshotCleanupTest.php` `describe('reporting')` (removed copies forgotten, failed copy kept with the agent's error) | `Actions/Snapshot/FinishSnapshotDeletionActionTest.php` | | 1.3 |
| BackupSchedule | `Configuration/BackupTest.php:165`, `:176` (a schedule in use by backups / by a scheduled restore is kept) | `Actions/BackupSchedule/DeleteBackupScheduleActionTest.php` | | 1.4 |
| BackupSchedule | `Configuration/BackupTest.php` and `Api/BackupScheduleCrudApiTest.php:69`, `:132`, `:189` (create / update / delete persist) | `Actions/BackupSchedule/{Create,Update,Delete}BackupScheduleActionTest.php` | | 1.4 |
| ScheduledRestore | `Api/ScheduledRestoreCrudApiTest.php:60` and `Livewire/ScheduledRestore/ModalTest.php` (create / update persist the definition) | `Actions/ScheduledRestore/{Create,Update}ScheduledRestoreActionTest.php` | | 1.5 |
| ScheduledRestore | `Livewire/ScheduledRestore/IndexTest.php` and `Api/ScheduledRestoreCrudApiTest.php` (delete) | `Actions/ScheduledRestore/DeleteScheduledRestoreActionTest.php` | | 1.5 |
| Volume | `Api/VolumeCrudApiTest.php:86` (created with sensitive fields encrypted) and `Volume/CreateTest.php` | `Actions/Volume/CreateVolumeActionTest.php` | | 1.6 |
| Volume | `Volume/EditTest.php` (blank secret kept, locked volume only changes name and limit) | `Actions/Volume/UpdateVolumeActionTest.php` | | 1.6 |
| Volume | `Volume/IndexTest.php` and `Api/VolumeCrudApiTest.php:145` (delete, keep files) | `Actions/Volume/DeleteVolumeActionTest.php` | | 1.6 |
| DatabaseServer | `Api/DatabaseServerSshConfigCrudApiTest.php` (blank credentials kept, new key drops the passphrase, auth switch clears the other secrets, in-use delete refused) | `Actions/DatabaseServer/{Create,Update,Delete}SshConfigActionTest.php` | | 1.7 |
| DatabaseServer | `DatabaseServer/SshTunnelTest.php` (SSH config created / preserved through the server form) | `Actions/DatabaseServer/{Create,Update}SshConfigActionTest.php` | | 1.7 |
| Backup | `Api/DatabaseServerCrudApiTest.php:509`, `:535`, `:562` (backup configurations upserted by id, missing ones deleted, a foreign id rejected) | `Actions/Backup/SyncBackupConfigurationsActionTest.php` | | 1.8 |
| DatabaseServer | `Api/DatabaseServerCrudApiTest.php:479` (blank password kept) and `DatabaseServer/EditTest.php` (same through the form) | `Actions/DatabaseServer/UpdateDatabaseServerActionTest.php` | | 1.8 |
| DatabaseServer | `DatabaseServer/CreateTest.php` and `Api/DatabaseServerCrudApiTest.php` (server created with its backups, SSH tunnel and channels) | `Actions/DatabaseServer/CreateDatabaseServerActionTest.php` | | 1.8 |
| DatabaseServer | `Livewire/DatabaseServer/IndexTest.php`, `DatabaseServer/ShowTest.php` and `Api/DatabaseServerCrudApiTest.php:644` (delete, keep files) | `Actions/DatabaseServer/DeleteDatabaseServerActionTest.php` | | 1.8 |
| Agent | `Agent/AgentApiTest.php` and `Agent/AgentRestoreTest.php` (`/fail` marks the job failed, merges logs, fails the tracked job); `Console/RecoverStuckJobsTest.php` (agent job failed after max attempts, unclaimed cleanup job failed) | `Actions/Agent/FailAgentJobActionTest.php`. The agent API tests stay thick (protocol), only their business assertions are candidates | | 1.9 |
| DatabaseServer | `Api/DatabaseServerCrudApiTest.php:772` and `DatabaseServer/CreateTest.php:145` (GFS all-zero rejected) | none. This is validation, and the two rule sets are still separate (Task 1 §7). | KEEP until the rule sets are unified | seed |

## 6. Actions still without a dedicated test after Task 1

Cover these first (step 1 of §3) when their domain comes up. Today they are reached only through edges:

- `DispatchBackupAction`, `DispatchDiscoveryAction` (covered indirectly by `TriggerBackupActionTest` and `AgentApiTest`).
- `DispatchRestoreAction` (indirectly by `AgentRestoreTest` and the restore edges).
- `CreateRoleAction`, `UpdateRoleAction`, `DeleteRoleAction`, `AssignRoleToUserAction`, `SyncUserAbilitiesAction`. They are covered through `Roles/RoleManagementTest`, `Roles/UserAbilitiesTest` and `UserFactory` setup. Keep `RoleAbilityMatrixTest` as it is: it is the role→ability seed guardrail, not a duplicate.

## 7. Keeping duplication from coming back

Ship these as the first commit of the Task 2 PR.

1. **Add a "Where does a test go?" table to `CLAUDE.md`**, under "Test Strategy":

   | You are testing… | Put it in… |
   |---|---|
   | A business rule, an edge case of an operation, what gets persisted, dispatched or notified | `tests/Feature/Actions/<Domain>/<Action>Test.php` |
   | Who may call it (abilities, demo mode, organization isolation) | The edge test, as an allow/deny pair named by ability |
   | How edge input becomes action arguments | The edge test, with `<Action>::shouldRun()->withArgs(...)` |
   | Status codes, JSON shape, MCP text, toasts, command output | The edge test |
   | That the edge is wired at all | **One** unmocked happy path per edge |

2. **Add a line to `docs/development/actions.md` → Testing** pointing to the table.
3. **Review heuristic:** an edge test with `Queue::assertPushed` / `assertDatabaseHas` assertions beyond its single happy path is probably re-testing an action. Reviewers ask for it to move.
4. **Optional, later:** a guard test that fails when an edge test file contains more than one unmocked call path into the same action. This is hard to do reliably; only build it if duplication reappears.
