<?php

use App\Enums\Ability;
use App\Livewire\ScheduledRestore\Modal;
use App\Models\DatabaseServer;
use App\Models\ScheduledRestore;
use App\Models\Snapshot;
use App\Models\User;
use App\Services\Backup\Databases\DatabaseProvider;
use Livewire\Livewire;
use Mockery\MockInterface;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    // Scheduled restores gate on operate-restores; the happy-path tests below
    // act as the allow case for that ability.
    $this->user = User::factory()->withAbilities([Ability::OperateRestores->value])->create();
    actingAs($this->user);

    $this->mock(DatabaseProvider::class, function (MockInterface $mock) {
        $mock->shouldReceive('listDatabasesForServer')->andReturn([]);
    });
});

test('rejects target server with a different database type than the source', function () {
    $schedule = dailySchedule();
    $source = DatabaseServer::factory()->create(['database_type' => 'mysql', 'database_names' => ['app']]);
    $target = DatabaseServer::factory()->create(['database_type' => 'postgres']);
    Snapshot::factory()->forServer($source)->create(['database_name' => 'app']);

    Livewire::test(Modal::class)
        ->call('open')
        ->set('name', 'Nightly refresh')
        ->set('backupScheduleId', $schedule->id)
        ->call('nextStep')
        ->set('sourceServerId', $source->id)
        ->set('sourceDatabaseName', 'app')
        ->call('nextStep')
        ->set('targetServerId', $target->id)
        ->set('schemaName', 'restored_db')
        ->call('save')
        ->assertHasErrors(['targetServerId']);
});

test('creates a scheduled restore end-to-end', function () {
    $schedule = dailySchedule();
    $source = DatabaseServer::factory()->create(['database_type' => 'mysql', 'database_names' => ['app']]);
    $target = DatabaseServer::factory()->create(['database_type' => 'mysql']);
    Snapshot::factory()->forServer($source)->create(['database_name' => 'app']);

    Livewire::test(Modal::class)
        ->call('open')
        ->set('name', 'Nightly refresh')
        ->set('backupScheduleId', $schedule->id)
        ->call('nextStep')
        ->set('sourceServerId', $source->id)
        ->set('sourceDatabaseName', 'app')
        ->call('nextStep')
        ->set('targetServerId', $target->id)
        ->set('schemaName', 'restored_db')
        ->set('forceDatabase', true)
        ->set('ownerUser', 'webapp')
        ->call('save')
        ->assertDispatched('scheduled-restore-saved');

    $scheduled = ScheduledRestore::query()->where('name', 'Nightly refresh')->firstOrFail();

    expect($scheduled->source_server_id)->toBe($source->id)
        ->and($scheduled->target_server_id)->toBe($target->id)
        ->and($scheduled->schema_name)->toBe('restored_db')
        ->and($scheduled->backup_schedule_id)->toBe($schedule->id)
        ->and($scheduled->enabled)->toBeTrue()
        ->and($scheduled->getOption('force_database'))->toBeTrue()
        ->and($scheduled->getOption('owner_user'))->toBe('webapp');
});

test('edits an existing scheduled restore', function () {
    $schedule1 = dailySchedule();
    $schedule2 = weeklySchedule();
    $source = DatabaseServer::factory()->create(['database_type' => 'mysql', 'database_names' => ['app']]);
    $target = DatabaseServer::factory()->create(['database_type' => 'mysql']);
    Snapshot::factory()->forServer($source)->create(['database_name' => 'app']);

    $scheduled = ScheduledRestore::factory()->create([
        'name' => 'Original name',
        'source_server_id' => $source->id,
        'target_server_id' => $target->id,
        'source_database_name' => 'app',
        'schema_name' => 'restored_db',
        'backup_schedule_id' => $schedule1->id,
    ]);

    Livewire::test(Modal::class)
        ->call('open', $scheduled->id)
        ->set('name', 'Updated name')
        ->set('backupScheduleId', $schedule2->id)
        ->call('save');

    expect($scheduled->fresh())
        ->name->toBe('Updated name')
        ->backup_schedule_id->toBe($schedule2->id);
});

test('without operate-restores, opening the create modal is forbidden', function () {
    actingAs(User::factory()->withAbilities([])->create());

    Livewire::test(Modal::class)
        ->call('open')
        ->assertForbidden();
});

test('target database autocomplete loads existing databases and selects one', function () {
    $this->mock(DatabaseProvider::class, function (MockInterface $mock) {
        $mock->shouldReceive('listDatabasesForServer')->andReturn(['orders_db', 'staging_db']);
    });

    $schedule = dailySchedule();
    $source = DatabaseServer::factory()->create(['database_type' => 'mysql', 'database_names' => ['app']]);
    $target = DatabaseServer::factory()->create(['database_type' => 'mysql']);
    Snapshot::factory()->forServer($source)->create(['database_name' => 'app']);

    Livewire::test(Modal::class)
        ->call('open')
        ->set('name', 'Nightly refresh')
        ->set('backupScheduleId', $schedule->id)
        ->call('nextStep')
        ->set('sourceServerId', $source->id)
        ->set('sourceDatabaseName', 'app')
        ->call('nextStep')
        ->set('targetServerId', $target->id)
        ->assertSet('existingDatabases', ['orders_db', 'staging_db'])
        ->call('selectDatabase', 'staging_db')
        ->assertSet('schemaName', 'staging_db');
});
