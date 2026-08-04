<?php

use App\Enums\Ability;
use App\Mcp\Servers\DatabasementServer;
use App\Mcp\Tools\ListSnapshotsTool;
use App\Mcp\Tools\TriggerRestoreTool;
use App\Models\DatabaseServer;
use App\Models\Organization;
use App\Models\ScheduledRestore;
use App\Models\Snapshot;
use App\Models\User;
use App\Models\Volume;
use App\Services\Backup\BackupJobFactory;
use App\Services\CurrentOrganization;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

/**
 * Snapshots and scheduled restores hold no organization_id of their own; they
 * inherit tenancy from their database server. These cover every path that
 * resolves one by id, which is where the isolation used to be missing.
 */

/**
 * @return array{0: Organization, 1: DatabaseServer}
 */
function foreignServer(string $databaseType = 'mysql'): array
{
    $org = Organization::factory()->create();

    return [$org, DatabaseServer::factory()->create([
        'organization_id' => $org->id,
        'database_type' => $databaseType,
    ])];
}

/**
 * A complete, restorable snapshot owned by an organization the actor is not in.
 */
function foreignSnapshot(string $databaseType = 'mysql'): Snapshot
{
    [$org, $server] = foreignServer($databaseType);
    $volume = Volume::factory()->create(['organization_id' => $org->id]);

    return Snapshot::factory()->forServer($server)->onVolumes($volume)->create();
}

test('the snapshot api does not list another organization snapshots', function () {
    $user = User::factory()->withAbilities([])->create();
    $foreign = foreignSnapshot();
    $own = Snapshot::factory()->create();

    $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/snapshots');

    $response->assertOk();
    expect(collect($response->json('data'))->pluck('id')->all())
        ->toContain($own->id)
        ->not->toContain($foreign->id);
});

test('the snapshot api cannot read another organization snapshot', function () {
    $user = User::factory()->withAbilities([])->create();
    $foreign = foreignSnapshot();

    $this->actingAs($user, 'sanctum')
        ->getJson("/api/v1/snapshots/{$foreign->id}")
        ->assertNotFound();
});

test('another organization snapshot cannot be downloaded', function () {
    $user = User::factory()->withAbilities([Ability::DownloadSnapshots->value])->create();
    $foreign = foreignSnapshot();

    $this->actingAs($user)
        ->get("/snapshots/{$foreign->id}/download")
        ->assertNotFound();
});

test('another organization snapshot cannot be restored via the api', function () {
    Queue::fake();

    $user = User::factory()->withAbilities([Ability::OperateRestores->value])->create();
    $target = DatabaseServer::factory()->create(['database_type' => 'mysql']);
    $foreign = foreignSnapshot();

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/v1/database-servers/{$target->id}/restore", [
            'snapshot_id' => $foreign->id,
            'schema_name' => 'exfil_db',
        ])
        ->assertNotFound();

    Queue::assertNothingPushed();
});

test('another organization snapshot cannot be deleted from the snapshot list', function () {
    $user = User::factory()->withAbilities([Ability::DeleteSnapshots->value])->create();
    $foreign = foreignSnapshot();

    expect(fn () => Livewire::actingAs($user)
        ->test(App\Livewire\Snapshot\Index::class)
        ->call('confirmDeleteSnapshot', $foreign->id)
    )->toThrow(ModelNotFoundException::class);

    expect(Snapshot::withoutGlobalScopes()->whereKey($foreign->id)->exists())->toBeTrue();
});

test('the mcp list-snapshots tool does not expose another organization snapshots', function () {
    $user = User::factory()->withAbilities([])->create();
    $foreign = foreignSnapshot();

    DatabasementServer::actingAs($user)
        ->tool(ListSnapshotsTool::class)
        ->assertOk()
        ->assertDontSee($foreign->id);
});

test('the mcp trigger-restore tool refuses another organization snapshot', function () {
    Queue::fake();

    $user = User::factory()->withAbilities([Ability::OperateRestores->value])->create();
    $target = DatabaseServer::factory()->create(['database_type' => 'mysql']);
    $foreign = foreignSnapshot();

    DatabasementServer::actingAs($user)
        ->tool(TriggerRestoreTool::class, [
            'snapshot_id' => $foreign->id,
            'database_server_id' => $target->id,
            'schema_name' => 'exfil_db',
        ])
        ->assertHasErrors();

    Queue::assertNothingPushed();
});

test('a restore cannot be created across organizations even without an org context', function () {
    // Scheduled restores reach the factory from the CLI, where no global scope
    // applies — the factory itself has to hold the tenant boundary.
    $target = DatabaseServer::factory()->create(['database_type' => 'mysql']);
    $foreign = foreignSnapshot();

    app(CurrentOrganization::class)->reset();

    expect(fn () => app(BackupJobFactory::class)->createRestore(
        snapshot: $foreign,
        targetServer: $target,
        schemaName: 'exfil_db',
    ))->toThrow(ValidationException::class, 'different organization');
});

test('a scheduled restore cannot source from another organization server', function () {
    $user = User::factory()->withAbilities([Ability::OperateRestores->value])->create();
    $target = DatabaseServer::factory()->create(['database_type' => 'mysql']);
    [, $foreign] = foreignServer('mysql');

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/scheduled-restores', [
            'name' => 'exfil',
            'source_server_id' => $foreign->id,
            'source_database_name' => 'app',
            'target_server_id' => $target->id,
            'schema_name' => 'exfil_db',
            'backup_schedule_id' => dailySchedule()->id,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('source_server_id');
});

test('another organization scheduled restore cannot be run', function () {
    $user = User::factory()->withAbilities([Ability::OperateRestores->value])->create();
    [$org, $source] = foreignServer('mysql');
    $target = DatabaseServer::factory()->create([
        'organization_id' => $org->id,
        'database_type' => 'mysql',
    ]);

    $foreign = ScheduledRestore::factory()->create([
        'source_server_id' => $source->id,
        'target_server_id' => $target->id,
        'source_database_name' => 'app',
        'schema_name' => 'restored_db',
        'backup_schedule_id' => dailySchedule()->id,
    ]);

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/v1/scheduled-restores/{$foreign->id}/run")
        ->assertNotFound();
});
