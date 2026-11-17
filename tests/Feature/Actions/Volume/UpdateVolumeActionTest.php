<?php

use App\Actions\Volume\UpdateVolumeAction;
use App\Enums\VolumeType;
use App\Models\Snapshot;
use App\Models\Volume;
use App\Services\Backup\Filesystems\FilesystemProvider;
use Illuminate\Validation\ValidationException;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;

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

test('a volume holding snapshots keeps its location but takes new credentials that connect', function () {
    $volume = sftpVolume();
    Snapshot::factory()->onVolumes($volume)->withFile()->create();

    $this->mock(FilesystemProvider::class)
        ->shouldReceive('getForVolume')
        ->once()
        ->withArgs(fn (Volume $tested): bool => $tested->config['host'] === 'sftp.example.com' && $tested->config['password'] === 'rotated-secret')
        ->andReturn(new Filesystem(new LocalFilesystemAdapter(sys_get_temp_dir())));

    UpdateVolumeAction::run($volume, 'Renamed', [
        'host' => 'elsewhere.example.com',
        'password' => 'rotated-secret',
        'max_storage_bytes' => 5_000,
        'max_storage_notify_only' => true,
    ]);

    expect($volume->fresh()->name)->toBe('Renamed')
        ->and($volume->fresh()->getDecryptedConfig())->toMatchArray([
            'host' => 'sftp.example.com',
            'password' => 'rotated-secret',
            'max_storage_bytes' => 5_000,
            'max_storage_notify_only' => true,
        ]);
});

test('new credentials that fail to connect leave a volume holding snapshots untouched', function () {
    $volume = sftpVolume();
    Snapshot::factory()->onVolumes($volume)->withFile()->create();

    $this->mock(FilesystemProvider::class)
        ->shouldReceive('getForVolume')
        ->once()
        ->andThrow(new RuntimeException('Authentication failed'));

    expect(fn () => UpdateVolumeAction::run($volume, 'Renamed', ['password' => 'wrong-secret']))
        ->toThrow(ValidationException::class, 'Authentication failed');

    expect($volume->fresh()->name)->not->toBe('Renamed')
        ->and($volume->fresh()->getDecryptedConfig()['password'])->toBe('stored-secret');
});

test('a volume holding snapshots skips the connection test when its credentials are unchanged', function () {
    $volume = sftpVolume();
    Snapshot::factory()->onVolumes($volume)->withFile()->create();

    $this->mock(FilesystemProvider::class)->shouldNotReceive('getForVolume');

    UpdateVolumeAction::run($volume, 'Renamed', ['host' => 'sftp.example.com', 'password' => '']);

    expect($volume->fresh()->name)->toBe('Renamed')
        ->and($volume->fresh()->getDecryptedConfig()['password'])->toBe('stored-secret');
});
