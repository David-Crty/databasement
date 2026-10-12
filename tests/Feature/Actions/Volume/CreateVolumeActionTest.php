<?php

use App\Actions\Volume\CreateVolumeAction;
use App\Enums\VolumeType;
use App\Models\Organization;
use Illuminate\Support\Facades\Crypt;

test('it creates the volume in the organization with its secrets encrypted', function () {
    $organization = Organization::factory()->create();

    $volume = CreateVolumeAction::run($organization, VolumeType::SFTP, 'Offsite', [
        'host' => 'sftp.example.com',
        'port' => 22,
        'username' => 'backup',
        'password' => 'hunter2',
        'root' => '/backups',
        'max_storage_bytes' => 1_000_000,
    ]);

    expect($volume->organization_id)->toBe($organization->id)
        ->and($volume->type)->toBe('sftp')
        ->and($volume->config['password'])->not->toBe('hunter2')
        ->and(Crypt::decryptString($volume->config['password']))->toBe('hunter2')
        ->and($volume->maxStorageBytes())->toBe(1_000_000);
});
