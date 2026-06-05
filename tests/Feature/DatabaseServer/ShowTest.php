<?php

use App\Livewire\DatabaseServer\Show;
use App\Models\Backup;
use App\Models\DatabaseServer;
use App\Models\ScheduledRestore;
use App\Models\User;
use Livewire\Livewire;

test('show page renders server name, host and connection details', function () {
    $user = User::factory()->create();
    $server = DatabaseServer::factory()->withoutBackups()->create([
        'name' => 'Prod MySQL',
        'host' => 'db.example.com',
        'port' => 3307,
        'username' => 'dbuser',
    ]);
    Backup::factory()->for($server)->selected(['app'])->create();

    Livewire::actingAs($user)
        ->test(Show::class, ['server' => $server])
        ->assertSee('Prod MySQL')
        ->assertSee('db.example.com')
        ->assertSee('3307')
        ->assertSee('dbuser');
});

test('show page never exposes the password', function () {
    $user = User::factory()->create();
    $server = DatabaseServer::factory()->withoutBackups()->create([
        'password' => 'super-secret-password',
    ]);

    Livewire::actingAs($user)
        ->test(Show::class, ['server' => $server])
        ->assertDontSee('super-secret-password')
        ->assertSee('••••••••');
});

test('show page lists backup configurations', function () {
    $user = User::factory()->create();
    $server = DatabaseServer::factory()->withoutBackups()->create();
    Backup::factory()->for($server)->selected(['my_app_db'])->create();

    Livewire::actingAs($user)
        ->test(Show::class, ['server' => $server])
        ->assertSee('my_app_db');
});

test('delete removes the database server', function () {
    $user = User::factory()->create();
    $server = DatabaseServer::factory()->withoutBackups()->create();

    Livewire::actingAs($user)
        ->test(Show::class, ['server' => $server])
        ->call('delete');

    $this->assertDatabaseMissing('database_servers', ['id' => $server->id]);
});

test('delete cascades to scheduled restores referencing the server', function () {
    $user = User::factory()->create();
    $server = DatabaseServer::factory()->withoutBackups()->create();

    $asSource = ScheduledRestore::factory()->create(['source_server_id' => $server->id]);
    $asTarget = ScheduledRestore::factory()->create(['target_server_id' => $server->id]);

    Livewire::actingAs($user)
        ->test(Show::class, ['server' => $server])
        ->call('delete');

    $this->assertDatabaseMissing('scheduled_restores', ['id' => $asSource->id]);
    $this->assertDatabaseMissing('scheduled_restores', ['id' => $asTarget->id]);
});

test('confirmRestore on a Redis server opens the redis info modal', function () {
    $user = User::factory()->create();
    $server = DatabaseServer::factory()->redis()->create();

    Livewire::actingAs($user)
        ->test(Show::class, ['server' => $server])
        ->call('confirmRestore')
        ->assertSet('showRedisRestoreModal', true);
});
