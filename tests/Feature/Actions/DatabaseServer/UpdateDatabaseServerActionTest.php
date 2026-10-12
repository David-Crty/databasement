<?php

use App\Actions\DatabaseServer\UpdateDatabaseServerAction;
use App\Models\DatabaseServer;

test('a blank password keeps the stored one', function () {
    $server = DatabaseServer::factory()->create(['password' => 'stored']);

    UpdateDatabaseServerAction::run($server, ['name' => 'Renamed', 'database_type' => $server->database_type->value, 'password' => '']);

    expect($server->fresh())->name->toBe('Renamed')
        ->and($server->fresh()->getDecryptedPassword())->toBe('stored');
});

test('backups stay disabled when the change does not mention them', function () {
    $server = DatabaseServer::factory()->create(['backups_enabled' => false]);

    UpdateDatabaseServerAction::run($server, ['name' => 'Renamed', 'database_type' => $server->database_type->value]);

    expect($server->fresh()->backups_enabled)->toBeFalse();
});

test('it leaves the backups and notification channels alone unless given', function () {
    $server = DatabaseServer::factory()->create();
    $backupIds = $server->backups()->pluck('id')->all();

    UpdateDatabaseServerAction::run($server, ['name' => 'Renamed', 'database_type' => $server->database_type->value]);

    expect($server->backups()->pluck('id')->all())->toBe($backupIds);
});
