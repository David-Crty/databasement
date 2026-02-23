<?php

use App\Enums\VolumeType;
use App\Livewire\Volume\Create;
use App\Livewire\Volume\Edit;
use App\Livewire\Volume\Index;
use App\Models\BackupJob;
use App\Models\DatabaseServer;
use App\Models\Restore;
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
                'access_key_id' => 'AKIAIOSFODNN7EXAMPLE',
                'secret_access_key' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
            ],
            'expectedConfig' => [
                'bucket' => 'my-backup-bucket',
                'prefix' => 'backups/production/',
                'region' => 'eu-west-1',
                'access_key_id' => 'AKIAIOSFODNN7EXAMPLE',
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
    ];
});

describe('volume creation', function () {
    test('can create volume with valid data', function (VolumeType $type, array $formData, array $expectedConfig, array $updateData, string $expectedField, mixed $expectedValue) {
        $user = User::factory()->create();
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
        $user = User::factory()->create();
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
        $user = User::factory()->create();
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
        $user = User::factory()->create();
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

describe('volume listing', function () {
    test('displays volumes in index', function () {
        $user = User::factory()->create();
        Volume::create([
            'name' => 'Local Volume',
            'type' => 'local',
            'config' => ['path' => '/var/backups'],
        ]);
        Volume::create([
            'name' => 'S3 Volume',
            'type' => 's3',
            'config' => ['bucket' => 'my-bucket', 'prefix' => ''],
        ]);

        Livewire::actingAs($user)
            ->test(Index::class)
            ->assertSee('Local Volume')
            ->assertSee('S3 Volume')
            ->assertSee('/var/backups')
            ->assertSee('my-bucket');
    });

    test('can search volumes', function () {
        $user = User::factory()->create();
        Volume::create([
            'name' => 'Production Volume',
            'type' => 'local',
            'config' => ['path' => '/var/backups'],
        ]);
        Volume::create([
            'name' => 'Development Volume',
            'type' => 's3',
            'config' => ['bucket' => 'dev-bucket', 'prefix' => ''],
        ]);

        Livewire::actingAs($user)
            ->test(Index::class)
            ->set('search', 'Production')
            ->assertSee('Production Volume')
            ->assertDontSee('Development Volume');
    });
});

describe('volume deletion', function () {
    test('can delete volume', function () {
        $user = User::factory()->create();
        $volume = Volume::create([
            'name' => 'Volume to Delete',
            'type' => 'local',
            'config' => ['path' => '/var/backups'],
        ]);

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
        $user = User::factory()->create();

        // Create volume with temp directory
        $volume = Volume::factory()->local()->create();
        $tempDir = $volume->config['path'];

        // Create a backup file
        $backupFilename = 'cascade-test.sql.gz';
        $backupFilePath = $tempDir.'/'.$backupFilename;
        file_put_contents($backupFilePath, 'test backup content');

        // Create server with backup using our volume
        $server = DatabaseServer::factory()->create(['database_names' => ['test_db']]);
        $server->backup->update(['volume_id' => $volume->id]);

        // Create snapshot with real file
        $factory = app(BackupJobFactory::class);
        $snapshots = $factory->createSnapshots($server, 'manual', $user->id);
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
        $user = User::factory()->create();

        // Create volume with temp directory
        $volume = Volume::factory()->local()->create();
        $tempDir = $volume->config['path'];

        // Create a backup file
        $backupFilename = 'keep-files-test.sql.gz';
        $backupFilePath = $tempDir.'/'.$backupFilename;
        file_put_contents($backupFilePath, 'test backup content');

        // Create server with backup using our volume
        $server = DatabaseServer::factory()->create(['database_names' => ['test_db']]);
        $server->backup->update(['volume_id' => $volume->id]);

        // Create snapshot with real file
        $factory = app(BackupJobFactory::class);
        $snapshots = $factory->createSnapshots($server, 'manual', $user->id);
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
        $user = User::factory()->create();

        // Create a volume with a snapshot
        $server = DatabaseServer::factory()->create(['database_names' => ['testdb']]);
        $volume = $server->backup->volume;

        $factory = app(BackupJobFactory::class);
        $factory->createSnapshots($server, 'manual');

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
        $user = User::factory()->create();
        $volume = Volume::create([
            'name' => 'Empty Volume',
            'type' => 'local',
            'config' => ['path' => '/var/backups'],
        ]);

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
        $user = User::factory()->create();
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
        $user = User::factory()->create();

        // Create SFTP volume with encrypted password
        $volume = Volume::create([
            'name' => 'Test SFTP Volume',
            'type' => 'sftp',
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
