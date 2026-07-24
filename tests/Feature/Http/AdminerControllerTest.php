<?php

use App\Enums\Ability;
use App\Models\Backup;
use App\Models\DatabaseServer;
use App\Models\User;
use App\Services\AdminerService;

beforeEach(function () {
    $this->mock(AdminerService::class);
});

test('without use-adminer, adminer is forbidden', function () {
    $user = User::factory()->withAbilities([])->create();
    $server = DatabaseServer::factory()->withoutBackups()->create(['database_type' => 'mysql']);

    session()->put('adminer_server_id', $server->id);

    $this->actingAs($user)
        ->get(route('adminer'))
        ->assertForbidden();
});

test('adminer is forbidden for servers using SSH', function () {
    $user = User::factory()->withAbilities([Ability::UseAdminer->value])->create();
    $server = DatabaseServer::factory()->withSshTunnel()->withoutBackups()->create(['database_type' => 'mysql']);

    session()->put('adminer_server_id', $server->id);

    $this->actingAs($user)
        ->get(route('adminer'))
        ->assertForbidden();
});

test('adminer is forbidden for unsupported database types', function (string $factoryState) {
    $user = User::factory()->withAbilities([Ability::UseAdminer->value])->create();
    $server = DatabaseServer::factory()->{$factoryState}()->withoutBackups()->create();

    session()->put('adminer_server_id', $server->id);

    $this->actingAs($user)
        ->get(route('adminer'))
        ->assertForbidden();
})->with([
    'redis' => ['redis'],
    'mongodb' => ['mongodb'],
]);

test('adminer builds correct credentials for MySQL', function () {
    $user = User::factory()->withAbilities([Ability::UseAdminer->value])->create();
    $server = DatabaseServer::factory()->withoutBackups()->create([
        'database_type' => 'mysql',
        'host' => 'db.example.com',
        'port' => 3306,
        'username' => 'admin',
        'password' => 'secret',
    ]);

    $this->mock(AdminerService::class)
        ->shouldReceive('render')
        ->once()
        ->withArgs(fn ($credentials) => $credentials === [
            'driver' => 'server',
            'server' => 'db.example.com:3306',
            'username' => 'admin',
            'password' => 'secret',
            'db' => '',
        ]);

    session()->put('adminer_server_id', $server->id);

    $this->actingAs($user)
        ->get(route('adminer'))
        ->assertOk();
});

test('adminer builds pgsql driver for PostgreSQL', function () {
    $user = User::factory()->withAbilities([Ability::UseAdminer->value])->create();
    $server = DatabaseServer::factory()->withoutBackups()->create(['database_type' => 'postgres']);

    $this->mock(AdminerService::class)
        ->shouldReceive('render')
        ->once()
        ->withArgs(fn ($credentials) => $credentials['driver'] === 'pgsql');

    session()->put('adminer_server_id', $server->id);

    $this->actingAs($user)
        ->get(route('adminer'))
        ->assertOk();
});

test('adminer auto-selects database when backup has exactly one', function () {
    $user = User::factory()->withAbilities([Ability::UseAdminer->value])->create();
    $server = DatabaseServer::factory()->withoutBackups()->create(['database_type' => 'mysql']);
    Backup::factory()->for($server)->selected(['mydb'])->create();

    $this->mock(AdminerService::class)
        ->shouldReceive('render')
        ->once()
        ->withArgs(fn ($credentials) => $credentials['db'] === 'mydb');

    session()->put('adminer_server_id', $server->id);

    $this->actingAs($user)
        ->get(route('adminer'))
        ->assertOk();
});

test('adminer renders without credentials on subsequent requests', function () {
    $user = User::factory()->withAbilities([Ability::UseAdminer->value])->create();

    $this->mock(AdminerService::class)
        ->shouldReceive('render')
        ->once()
        ->with(null);

    $this->actingAs($user)
        ->get(route('adminer'))
        ->assertOk();
});
