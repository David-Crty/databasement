<?php

use App\Actions\Volume\UpdateVolumeAction;
use App\Enums\VolumeType;
use App\Models\Snapshot;
use App\Models\Volume;

function sftpVolume(): Volume
{
    return Volume::factory()->sftp()->create([
        'config' => VolumeType::SFTP->encryptSensitiveFields([
            'host' => 'sftp.example.com',
            'port' => 22,
            'username' => 'backup',
            'password' => 'stored-secret',
            'root' => '/backups',
        ]),
    ]);
}

test('it renames the volume and replaces its config', function () {
    $volume = sftpVolume();

    UpdateVolumeAction::run($volume, 'Renamed', [
        'host' => 'new.example.com',
        'port' => 2222,
        'username' => 'backup',
        'password' => 'new-secret',
        'root' => '/backups',
    ]);

    expect($volume->fresh())
        ->name->toBe('Renamed')
        ->and($volume->fresh()->getDecryptedConfig())->toMatchArray(['host' => 'new.example.com', 'port' => 2222, 'password' => 'new-secret']);
});

test('a secret left blank keeps the stored one', function () {
    $volume = sftpVolume();

    UpdateVolumeAction::run($volume, $volume->name, [
        'host' => 'sftp.example.com',
        'port' => 22,
        'username' => 'backup',
        'password' => '',
        'root' => '/backups',
    ]);

    expect($volume->fresh()->getDecryptedConfig()['password'])->toBe('stored-secret');
});

test('a volume holding snapshots only changes its name and storage limit', function () {
    $volume = sftpVolume();
    Snapshot::factory()->onVolumes($volume)->withFile()->create();

    UpdateVolumeAction::run($volume, 'Renamed', [
        'host' => 'elsewhere.example.com',
        'password' => 'other-secret',
        'max_storage_bytes' => 5_000,
        'max_storage_notify_only' => true,
    ]);

    expect($volume->fresh()->name)->toBe('Renamed')
        ->and($volume->fresh()->getDecryptedConfig())->toMatchArray([
            'host' => 'sftp.example.com',
            'password' => 'stored-secret',
            'max_storage_bytes' => 5_000,
            'max_storage_notify_only' => true,
        ]);
});
