<?php

use App\Enums\Ability;
use App\Enums\VolumeType;
use App\Livewire\Volume\Create;
use App\Livewire\Volume\Edit;
use App\Livewire\Volume\Index;
use App\Models\BackupJob;
use App\Models\DatabaseServer;
use App\Models\Restore;
use App\Models\Snapshot;
use App\Models\User;
use App\Models\Volume;
use App\Services\Backup\BackupJobFactory;
use App\Services\Backup\Filesystems\FilesystemProvider;
use Illuminate\Support\Facades\Crypt;
use Livewire\Livewire;

// Consolidated dataset for all volume type operations
dataset('volume types', function () {
    return [
        'local' => [
            'type' => VolumeType::LOCAL,
            'formData' => ['path' => '/var/backups'],
            'expectedConfig' => ['path' => '/var/backups'],
            'updateData' => ['path' => '/new/path'],
            'expectedField' => 'path',
            'expectedValue' => '/new/path',
        ],
        's3' => [
            'type' => VolumeType::S3,
            'formData' => [
                'bucket' => 'my-backup-bucket',
                'prefix' => 'backups/production/',
                'region' => 'eu-west-1',
                'access_key_id' => 'fake-access-key-id',
                'secret_access_key' => 'fake-secret-access-key',
            ],
            'expectedConfig' => [
                'bucket' => 'my-backup-bucket',
                'prefix' => 'backups/production/',
                'region' => 'eu-west-1',
                'access_key_id' => 'fake-access-key-id',
            ],
            'updateData' => ['bucket' => 'new-bucket', 'prefix' => 'new/'],
            'expectedField' => 'bucket',
            'expectedValue' => 'new-bucket',
        ],
        'sftp' => [
            'type' => VolumeType::SFTP,
            'formData' => [
                'host' => 'sftp.example.com',
                'port' => 22,
                'username' => 'backup-user',
                'password' => 'secret-password',
                'root' => '/backups',
                'timeout' => 10,
            ],
            'expectedConfig' => [
                'host' => 'sftp.example.com',
                'port' => 22,
                'username' => 'backup-user',
                'root' => '/backups',
                'timeout' => 10,
            ],
            'updateData' => ['host' => 'new-sftp.example.com', 'password' => 'new-password'],
            'expectedField' => 'host',
            'expectedValue' => 'new-sftp.example.com',
        ],
        'ftp' => [
            'type' => VolumeType::FTP,
            'formData' => [
                'host' => 'ftp.example.com',
                'port' => 21,
                'username' => 'ftp-user',
                'password' => 'ftp-password',
                'root' => '/backups',
                'ssl' => true,
                'passive' => true,
                'timeout' => 90,
            ],
            'expectedConfig' => [
                'host' => 'ftp.example.com',
                'port' => 21,
                'username' => 'ftp-user',
                'root' => '/backups',
                'ssl' => true,
                'passive' => true,
                'timeout' => 90,
            ],
            'updateData' => ['host' => 'new-ftp.example.com', 'ssl' => true],
            'expectedField' => 'host',
            'expectedValue' => 'new-ftp.example.com',
        ],
        'azure' => [
            'type' => VolumeType::AZURE,
            'formData' => [
                'account_name' => 'mystorageaccount',
                'account_key' => 'fake-account-key',
                'container' => 'backups',
                'prefix' => 'production/',
                'endpoint_suffix' => 'core.windows.net',
                'endpoint' => 'https://gateway.example.com/mystorageaccount',
            ],
            'expectedConfig' => [
                'account_name' => 'mystorageaccount',
                'container' => 'backups',
                'prefix' => 'production/',
                'endpoint_suffix' => 'core.windows.net',
                'endpoint' => 'https://gateway.example.com/mystorageaccount',
            ],
            'updateData' => ['container' => 'new-container', 'prefix' => 'staging/'],
            'expectedField' => 'container',
            'expectedValue' => 'new-container',
        ],
    ];
});

describe('volume creation', function () {
    test('can create volume with valid data', function (VolumeType $type, array $formData, array $expectedConfig, array $updateData, string $expectedField, mixed $expectedValue) {
        // Allow case for manage-volumes: that ability alone enables a full create.
        $user = User::factory()->withAbilities([Ability::ManageVolumes->value])->create();
        $volumeName = "{$type->label()} Backup Storage";
        $configKey = $type->configPropertyName();

        $livewire = Livewire::actingAs($user)
            ->test(Create::class)
            ->set('form.name', $volumeName)
            ->set('form.type', $type->value);

        // Set each config field
        foreach ($formData as $field => $value) {
            $livewire->set("form.{$configKey}.{$field}", $value);
        }

        $livewire->call('save')
            ->assertRedirect(route('volumes.index'));

        $this->assertDatabaseHas('volumes', [
            'name' => $volumeName,
            'type' => $type->value,
        ]);

        $volume = Volume::where('name', $volumeName)->first();

        // Verify sensitive fields are encrypted but decryptable
        foreach ($type->sensitiveFields() as $field) {
            expect($volume->config[$field])->not->toBe($formData[$field]);
            expect($volume->getDecryptedConfig()[$field])->toBe($formData[$field]);
        }

        // Verify non-sensitive fields match expected config
        foreach ($expectedConfig as $field => $value) {
            expect($volume->getDecryptedConfig()[$field])->toBe($value);
        }
    })->with('volume types');
});

describe('volume editing', function () {
    test('can edit volume', function (VolumeType $type, array $formData, array $expectedConfig, array $updateData, string $expectedField, mixed $expectedValue) {
        $user = User::factory()->withAbilities([Ability::ManageVolumes->value])->create();
        $factoryState = $type->value;
        $volume = Volume::factory()->{$factoryState}()->create(['name' => "{$type->label()} Volume"]);
        $configKey = $type->configPropertyName();

        $livewire = Livewire::actingAs($user)
            ->test(Edit::class, ['volume' => $volume]);

        // Update config fields
        foreach ($updateData as $field => $value) {
            $livewire->set("form.{$configKey}.{$field}", $value);
        }

        $livewire->call('save')
            ->assertRedirect(route('volumes.index'));

        $volume->refresh();
        expect($volume->getDecryptedConfig()[$expectedField])->toBe($expectedValue);
    })->with('volume types');

    test('blank password on edit preserves existing password', function () {
        $user = User::factory()->withAbilities([Ability::ManageVolumes->value])->create();
        $volume = Volume::factory()->sftp()->create();

        $originalPassword = $volume->getDecryptedConfig()['password'];
        expect($originalPassword)->not->toBeEmpty();

        // Edit volume with blank password (should keep existing)
        Livewire::actingAs($user)
            ->test(Edit::class, ['volume' => $volume])
            ->assertSet('form.sftpConfig.password', '') // Password is masked on load
            ->set('form.sftpConfig.host', 'updated-host.example.com')
            ->set('form.sftpConfig.password', '') // Leave blank
            ->call('save')
            ->assertRedirect(route('volumes.index'));

        $volume->refresh();
        expect($volume->getDecryptedConfig()['host'])->toBe('updated-host.example.com')
            ->and($volume->getDecryptedConfig()['password'])->toBe($originalPassword);
    });

    test('blank secret_access_key on edit preserves existing value', function () {
        $user = User::factory()->withAbilities([Ability::ManageVolumes->value])->create();
        $volume = Volume::factory()->s3()->create();

        $originalSecret = $volume->getDecryptedConfig()['secret_access_key'];
        expect($originalSecret)->not->toBeEmpty();

        // Edit volume with blank secret_access_key (should keep existing)
        Livewire::actingAs($user)
            ->test(Edit::class, ['volume' => $volume])
            ->assertSet('form.s3Config.secret_access_key', '') // Secret is masked on load
            ->set('form.s3Config.bucket', 'updated-bucket')
            ->set('form.s3Config.secret_access_key', '') // Leave blank
            ->call('save')
            ->assertRedirect(route('volumes.index'));

        $volume->refresh();
        expect($volume->getDecryptedConfig()['bucket'])->toBe('updated-bucket')
            ->and($volume->getDecryptedConfig()['secret_access_key'])->toBe($originalSecret);
    });
});

describe('volume storage limit', function () {
    test('storage limit round-trips through the form as GB and clears when emptied', function () {
        $user = User::factory()->withAbilities([Ability::ManageVolumes->value])->create();

        // Entered in GB on create, stored as bytes.
        Livewire::actingAs($user)
            ->test(Create::class)
            ->set('form.name', 'Quota Volume')
            ->set('form.type', 'local')
            ->set('form.localConfig.path', '/var/backups')
            ->set('form.maxStorageGb', '10')
            ->call('save')
            ->assertRedirect(route('volumes.index'));

        $volume = Volume::where('name', 'Quota Volume')->firstOrFail();
        expect($volume->maxStorageBytes())->toBe(10 * (1024 ** 3));

        // Reopens showing the limit back in GB; clearing it removes the limit.
        Livewire::actingAs($user)
            ->test(Edit::class, ['volume' => $volume])
            ->assertSet('form.maxStorageGb', '10')
            ->set('form.maxStorageGb', '')
            ->call('save')
            ->assertRedirect(route('volumes.index'));

        expect($volume->refresh()->maxStorageBytes())->toBeNull();
    });

    test('the storage limit stays editable on a volume locked by its snapshots', function () {
        $user = User::factory()->withAbilities([Ability::ManageVolumes->value])->create();
        $server = DatabaseServer::factory()->create(['database_names' => ['testdb']]);
        $volume = $server->backups->first()->volume;
        app(BackupJobFactory::class)->createSnapshots($server->backups->first(), 'manual');

        expect($volume->hasSnapshots())->toBeTrue();

        Livewire::actingAs($user)
            ->test(Edit::class, ['volume' => $volume])
            ->assertSet('hasSnapshots', true)
            ->set('form.maxStorageGb', '5')
            ->call('save')
            ->assertRedirect(route('volumes.index'));

        expect($volume->refresh()->maxStorageBytes())->toBe(5 * (1024 ** 3));
    });

    test('the notify-only flag round-trips and is dropped when the limit is cleared', function () {
        $user = User::factory()->withAbilities([Ability::ManageVolumes->value])->create();

        Livewire::actingAs($user)
            ->test(Create::class)
            ->set('form.name', 'Notify Volume')
            ->set('form.type', 'local')
            ->set('form.localConfig.path', '/var/backups')
            ->set('form.maxStorageGb', '10')
            ->set('form.storageLimitNotifyOnly', true)
            ->call('save')
            ->assertRedirect(route('volumes.index'));

        $volume = Volume::where('name', 'Notify Volume')->firstOrFail();
        expect($volume->storageLimitIsNotifyOnly())->toBeTrue();

        // Reopens with the flag set; clearing the limit drops both config keys.
        Livewire::actingAs($user)
            ->test(Edit::class, ['volume' => $volume])
            ->assertSet('form.storageLimitNotifyOnly', true)
            ->set('form.maxStorageGb', '')
            ->call('save')
            ->assertRedirect(route('volumes.index'));

        $volume->refresh();
        expect($volume->maxStorageBytes())->toBeNull()
            ->and($volume->config)->not->toHaveKey('max_storage_notify_only');
    });
});

describe('volume listing', function () {
    test('displays volumes in index', function () {
        // Viewing needs no ability — an org member with zero grants can list volumes.
        $user = User::factory()->withAbilities([])->create();
        Volume::factory()->local()->create([
            'name' => 'Local Volume',
            'config' => ['path' => '/var/backups'],
        ]);
        Volume::factory()->s3()->create([
            'name' => 'S3 Volume',
            'config' => ['bucket' => 'my-bucket', 'prefix' => ''],
        ]);

        // A capped volume with a completed 3 GB snapshot exercises the Usage column.
        $server = DatabaseServer::factory()->create(['database_names' => ['testdb']]);
        $cappedVolume = $server->backups->first()->volume;
        $cappedVolume->update(['config' => [...$cappedVolume->config, 'max_storage_bytes' => 10 * (1024 ** 3)]]);
        Snapshot::factory()->forServer($server)->create(['file_size' => 3 * (1024 ** 3)]);

        Livewire::actingAs($user)
            ->test(Index::class)
            ->assertSee('Local Volume')
            ->assertSee('S3 Volume')
            ->assertSee('/var/backups')
            ->assertSee('my-bucket')
            ->assertSee('Usage')
            ->assertSee('3 GB')
            ->assertSee('10 GB');
    });

    test('can search volumes', function () {
        // Viewing needs no ability — an org member with zero grants can list volumes.
        $user = User::factory()->withAbilities([])->create();
        Volume::factory()->local()->create(['name' => 'Production Volume']);
        Volume::factory()->s3()->create(['name' => 'Development Volume']);

        Livewire::actingAs($user)
            ->test(Index::class)
            ->set('search', 'Production')
            ->assertSee('Production Volume')
            ->assertDontSee('Development Volume');
    });
});

describe('volume deletion', function () {
    test('can delete volume', function () {
        $user = User::factory()->withAbilities([Ability::ManageVolumes->value])->create();
        $volume = Volume::factory()->local()->create(['name' => 'Volume to Delete']);

        Livewire::actingAs($user)
            ->test(Index::class)
            ->call('confirmDelete', $volume->id)
            ->assertSet('deleteId', $volume->id)
            ->call('delete')
            ->assertSet('deleteId', null);

        $this->assertDatabaseMissing('volumes', [
            'id' => $volume->id,
        ]);
    });

    test('deleting volume cascades to snapshots, jobs, restores, and files', function () {
        $user = User::factory()->withAbilities([Ability::ManageVolumes->value])->create();

        // Create volume with temp directory
        $volume = Volume::factory()->local()->create();
        $tempDir = $volume->config['path'];

        // Create a backup file
        $backupFilename = 'cascade-test.sql.gz';
        $backupFilePath = $tempDir.'/'.$backupFilename;
        file_put_contents($backupFilePath, 'test backup content');

        // Create server with backup using our volume
        $server = DatabaseServer::factory()->create(['database_names' => ['test_db']]);
        $server->backups->first()->update(['volume_id' => $volume->id]);

        // Create snapshot with real file
        $factory = app(BackupJobFactory::class);
        $snapshots = $factory->createSnapshots($server->backups->first(), 'manual', $user->id);
        $snapshot = $snapshots[0];
        $snapshot->update([
            'filename' => $backupFilename,
            'file_size' => filesize($backupFilePath),
        ]);
        $snapshot->job->markCompleted();
        $snapshotJobId = $snapshot->job->id;

        // Create a restore record
        $restoreJob = BackupJob::create([
            'type' => 'restore',
            'status' => 'completed',
            'started_at' => now(),
            'completed_at' => now(),
        ]);
        $restore = Restore::create([
            'backup_job_id' => $restoreJob->id,
            'snapshot_id' => $snapshot->id,
            'target_server_id' => $server->id,
            'schema_name' => 'restored_db',
            'triggered_by_user_id' => $user->id,
        ]);
        $restoreJobId = $restoreJob->id;

        // Delete the volume via Livewire
        Livewire::actingAs($user)
            ->test(Index::class)
            ->call('confirmDelete', $volume->id)
            ->call('delete');

        // Verify cascade deletion
        expect($snapshot->fresh())->toBeNull('Snapshot should be deleted')
            ->and(Restore::find($restore->id))->toBeNull('Restore should be cascade deleted')
            ->and(BackupJob::find($snapshotJobId))->toBeNull('Snapshot job should be cascade deleted')
            ->and(BackupJob::find($restoreJobId))->toBeNull('Restore job should be cascade deleted')
            ->and(file_exists($backupFilePath))->toBeFalse('Backup file should be deleted from storage');
    });

    test('deleting volume with keepFiles preserves backup files', function () {
        $user = User::factory()->withAbilities([Ability::ManageVolumes->value])->create();

        // Create volume with temp directory
        $volume = Volume::factory()->local()->create();
        $tempDir = $volume->config['path'];

        // Create a backup file
        $backupFilename = 'keep-files-test.sql.gz';
        $backupFilePath = $tempDir.'/'.$backupFilename;
        file_put_contents($backupFilePath, 'test backup content');

        // Create server with backup using our volume
        $server = DatabaseServer::factory()->create(['database_names' => ['test_db']]);
        $server->backups->first()->update(['volume_id' => $volume->id]);

        // Create snapshot with real file
        $factory = app(BackupJobFactory::class);
        $snapshots = $factory->createSnapshots($server->backups->first(), 'manual', $user->id);
        $snapshot = $snapshots[0];
        $snapshot->update([
            'filename' => $backupFilename,
            'file_size' => filesize($backupFilePath),
        ]);
        $snapshot->job->markCompleted();

        $backupJobId = $snapshot->backup_job_id;

        // Delete volume with keepFiles checked
        Livewire::actingAs($user)
            ->test(Index::class)
            ->call('confirmDelete', $volume->id)
            ->set('keepFiles', true)
            ->call('delete');

        // DB records deleted, but file preserved
        expect($snapshot->fresh())->toBeNull('Snapshot should be deleted')
            ->and(BackupJob::find($backupJobId))->toBeNull('BackupJob should be cascade deleted')
            ->and(Volume::find($volume->id))->toBeNull('Volume should be deleted')
            ->and(file_exists($backupFilePath))->toBeTrue('Backup file should be preserved on storage');
    });
});

describe('volume immutability', function () {
    test('volume with snapshots only allows name editing', function () {
        $user = User::factory()->withAbilities([Ability::ManageVolumes->value])->create();

        // Create a volume with a snapshot
        $server = DatabaseServer::factory()->create(['database_names' => ['testdb']]);
        $volume = $server->backups->first()->volume;

        $factory = app(BackupJobFactory::class);
        $factory->createSnapshots($server->backups->first(), 'manual');

        // Verify volume now has snapshots
        expect($volume->hasSnapshots())->toBeTrue();

        $originalPath = $volume->config['path'];

        // Can access edit page with hasSnapshots flag set
        Livewire::actingAs($user)
            ->test(Edit::class, ['volume' => $volume])
            ->assertSuccessful()
            ->assertSet('hasSnapshots', true)
            ->assertSet('form.name', $volume->name)
            // Can update name
            ->set('form.name', 'Updated Volume Name')
            ->call('save')
            ->assertRedirect(route('volumes.index'));

        // Verify only name was updated, config unchanged
        $volume->refresh();
        expect($volume->name)->toBe('Updated Volume Name');
        expect($volume->config['path'])->toBe($originalPath);
    });

    test('can edit volume without snapshots', function () {
        $user = User::factory()->withAbilities([Ability::ManageVolumes->value])->create();
        $volume = Volume::factory()->local()->create(['name' => 'Empty Volume']);

        // Verify volume has no snapshots
        expect($volume->hasSnapshots())->toBeFalse();

        // Should be able to access edit page and form is populated
        Livewire::actingAs($user)
            ->test(Edit::class, ['volume' => $volume])
            ->assertSuccessful()
            ->assertSet('form.name', 'Empty Volume')
            ->assertSet('form.type', 'local');
    });
});

describe('connection testing', function () {
    test('can test local volume connection', function () {
        $user = User::factory()->withAbilities([Ability::ManageVolumes->value])->create();
        $tempDir = sys_get_temp_dir().'/volume-test-'.uniqid();
        mkdir($tempDir, 0755, true);

        Livewire::actingAs($user)
            ->test(Create::class)
            ->set('form.type', 'local')
            ->set('form.localConfig.path', $tempDir)
            ->call('testConnection')
            ->assertSet('form.connectionTestSuccess', true)
            ->assertSet('form.connectionTestMessage', 'Connection successful!');
    });

    test('can test connection on edit using persisted password', function () {
        $user = User::factory()->withAbilities([Ability::ManageVolumes->value])->create();

        // Create SFTP volume with encrypted password
        $volume = Volume::factory()->sftp()->create([
            'name' => 'Test SFTP Volume',
            'config' => [
                'host' => 'localhost',
                'port' => 22,
                'username' => 'testuser',
                'password' => Crypt::encryptString('secret-password'),
                'root' => '/uploads',
            ],
        ]);

        // Mock the FilesystemProvider to verify the volume receives merged password
        $testContent = new stdClass;
        $testContent->value = '';
        $mockFilesystem = Mockery::mock(League\Flysystem\Filesystem::class);
        $mockFilesystem->shouldReceive('write')
            ->once()
            ->andReturnUsing(function ($filename, $content) use ($testContent) {
                $testContent->value = $content;
            });
        $mockFilesystem->shouldReceive('read')
            ->once()
            ->andReturnUsing(fn () => $testContent->value);
        $mockFilesystem->shouldReceive('delete')->once();

        $mockProvider = Mockery::mock(FilesystemProvider::class);
        $mockProvider->shouldReceive('getForVolume')
            ->once()
            ->withArgs(function (Volume $testedVolume) {
                // Verify the password was merged from persisted config
                return $testedVolume->config['password'] === 'secret-password';
            })
            ->andReturn($mockFilesystem);

        app()->instance(FilesystemProvider::class, $mockProvider);

        // Edit the volume - password field will be empty (masked)
        // but testConnection should merge the persisted password
        $component = Livewire::actingAs($user)
            ->test(Edit::class, ['volume' => $volume])
            ->assertSet('form.sftpConfig.password', '') // Password is masked
            ->call('testConnection');

        expect($component->get('form.connectionTestMessage'))->toBe('Connection successful!')
            ->and($component->get('form.connectionTestSuccess'))->toBeTrue();
    });
});

describe('volume authorization', function () {
    test('without manage-volumes, the create screen is forbidden', function () {
        $user = User::factory()->withAllAbilitiesExcept(Ability::ManageVolumes->value)->create();

        Livewire::actingAs($user)
            ->test(Create::class)
            ->assertForbidden();
    });

    test('without manage-volumes, the edit screen is forbidden', function () {
        $user = User::factory()->withAllAbilitiesExcept(Ability::ManageVolumes->value)->create();
        $volume = Volume::factory()->local()->create();

        Livewire::actingAs($user)
            ->test(Edit::class, ['volume' => $volume])
            ->assertForbidden();
    });

    test('without manage-volumes, deleting a volume is forbidden', function () {
        $user = User::factory()->withAllAbilitiesExcept(Ability::ManageVolumes->value)->create();
        $volume = Volume::factory()->local()->create();

        Livewire::actingAs($user)
            ->test(Index::class)
            ->call('confirmDelete', $volume->id)
            ->assertForbidden();
    });
});
