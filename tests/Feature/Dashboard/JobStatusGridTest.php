<?php

use App\Livewire\Dashboard\JobStatusGrid;
use App\Models\DatabaseServer;
use App\Models\User;
use App\Services\Backup\BackupJobFactory;
use Livewire\Livewire;

test('grid displays jobs', function () {
    $user = User::factory()->create();
    $factory = app(BackupJobFactory::class);

    $server = DatabaseServer::factory()->create([
        'name' => 'Grid Server',
        'database_names' => ['db1', 'db2'],
    ]);

    $factory->createSnapshots($server->backups->first(), 'manual', $user->id);

    Livewire::withoutLazyLoading()
        ->actingAs($user)
        ->test(JobStatusGrid::class)
        ->assertSeeHtml('data-server="Grid Server"')
        ->assertSeeHtml('data-database="db1"')
        ->assertSeeHtml('data-database="db2"');
});

test('grid orders jobs newest-first', function () {
    $user = User::factory()->create();
    $factory = app(BackupJobFactory::class);

    $oldServer = DatabaseServer::factory()->create([
        'name' => 'Old Server',
        'database_names' => ['old_db'],
    ]);

    $oldSnapshots = $factory->createSnapshots($oldServer->backups->first(), 'manual', $user->id);
    $oldSnapshots[0]->job->forceFill(['created_at' => now()->subDay()])->save();

    $newServer = DatabaseServer::factory()->create([
        'name' => 'New Server',
        'database_names' => ['new_db'],
    ]);

    $factory->createSnapshots($newServer->backups->first(), 'manual', $user->id);

    Livewire::withoutLazyLoading()
        ->actingAs($user)
        ->test(JobStatusGrid::class)
        ->assertSeeInOrder(['New Server', 'Old Server']);
});

test('viewLogs sets selectedJobId and opens modal', function () {
    $user = User::factory()->create();
    $factory = app(BackupJobFactory::class);

    $server = DatabaseServer::factory()->create([
        'database_names' => ['test_db'],
    ]);

    $snapshots = $factory->createSnapshots($server->backups->first(), 'manual', $user->id);
    $job = $snapshots[0]->job;

    Livewire::withoutLazyLoading()
        ->actingAs($user)
        ->test(JobStatusGrid::class)
        ->assertSet('showLogsModal', false)
        ->assertSet('selectedJobId', null)
        ->call('viewLogs', $job->id)
        ->assertSet('showLogsModal', true)
        ->assertSet('selectedJobId', $job->id);
});

test('logs modal renders only the head and tail of a huge command output', function () {
    $user = User::factory()->create();
    $factory = app(BackupJobFactory::class);

    $server = DatabaseServer::factory()->create([
        'database_names' => ['test_db'],
    ]);

    $snapshots = $factory->createSnapshots($server->backups->first(), 'manual', $user->id);
    $job = $snapshots[0]->job;

    $lines = collect(range(1, 1000))->map(fn (int $i) => sprintf('logline-%04d-end', $i));

    $job->update(['logs' => [[
        'timestamp' => now()->toIso8601String(),
        'type' => 'command',
        'command' => 'pg_dump --verbose',
        'output' => $lines->implode("\n"),
        'exit_code' => 0,
        'status' => 'completed',
    ]]]);

    // Each line becomes a DOM node in the Livewire payload, so only the head and
    // the tail are rendered; the middle is replaced by the omission marker. The
    // tail matters most: a failure reason sits at the end of the output.
    Livewire::withoutLazyLoading()
        ->actingAs($user)
        ->test(JobStatusGrid::class)
        ->call('viewLogs', $job->id)
        ->assertSee('logline-0001-end')
        ->assertDontSee('logline-0500-end')
        ->assertSee('logline-1000-end')
        ->assertSee('of output omitted');
});

test('closeLogs resets state', function () {
    $user = User::factory()->create();
    $factory = app(BackupJobFactory::class);

    $server = DatabaseServer::factory()->create([
        'database_names' => ['test_db'],
    ]);

    $snapshots = $factory->createSnapshots($server->backups->first(), 'manual', $user->id);
    $job = $snapshots[0]->job;

    Livewire::withoutLazyLoading()
        ->actingAs($user)
        ->test(JobStatusGrid::class)
        ->call('viewLogs', $job->id)
        ->assertSet('showLogsModal', true)
        ->call('closeLogs')
        ->assertSet('showLogsModal', false)
        ->assertSet('selectedJobId', null);
});
