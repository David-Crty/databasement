<?php

use App\Enums\VolumeType;
use App\Models\Volume;
use App\Services\Backup\Filesystems\FilesystemProvider;
use App\Services\VolumeConnectionTester;
use League\Flysystem\Filesystem;
use League\Flysystem\UnableToWriteFile;

beforeEach(function () {
    $this->tester = app(VolumeConnectionTester::class);
});

describe('local volume connection testing', function () {
    test('returns success for valid writable directory', function () {
        $volume = Volume::factory()->local()->create();

        $result = $this->tester->test($volume);

        expect($result['success'])->toBeTrue()
            ->and($result['message'])->toContain('Connection successful');
    });

    test('creates and removes test file during validation', function () {
        $volume = Volume::factory()->local()->create();
        $tempDir = $volume->config['path'];

        expect(glob($tempDir.'/*'))->toBeEmpty();

        $result = $this->tester->test($volume);

        // After test, directory should still be empty (test file cleaned up)
        expect(glob($tempDir.'/*'))->toBeEmpty()
            ->and($result['success'])->toBeTrue();
    });

    test('returns error when directory does not exist and cannot be created', function () {
        $volume = new Volume([
            'name' => 'test-volume',
            'type' => 'local',
            'config' => ['path' => '/nonexistent-root-'.uniqid().'/subdir'],
        ]);

        $result = $this->tester->test($volume);

        expect($result['success'])->toBeFalse();
    });

    test('returns error for unsupported volume type', function () {
        $volume = new Volume([
            'name' => 'test-volume',
            'type' => 'unknown',
            'config' => [],
        ]);

        $result = $this->tester->test($volume);

        expect($result['success'])->toBeFalse();
    });
});

// Dataset for remote volume types that require mocked filesystem
dataset('remote volume types', function () {
    return [
        's3' => [
            'type' => VolumeType::S3,
            'config' => [
                'bucket' => 'test-bucket',
                'prefix' => '',
            ],
            'identifierField' => 'bucket',
            'identifierValue' => 'test-bucket',
        ],
        'sftp' => [
            'type' => VolumeType::SFTP,
            'config' => [
                'host' => 'sftp.example.com',
                'port' => 22,
                'username' => 'backup-user',
                'password' => 'test-password',
                'root' => '/backups',
                'timeout' => 10,
            ],
            'identifierField' => 'host',
            'identifierValue' => 'sftp.example.com',
        ],
        'ftp' => [
            'type' => VolumeType::FTP,
            'config' => [
                'host' => 'ftp.example.com',
                'port' => 21,
                'username' => 'ftp-user',
                'password' => 'test-password',
                'root' => '/backups',
                'ssl' => false,
                'passive' => true,
                'timeout' => 90,
            ],
            'identifierField' => 'host',
            'identifierValue' => 'ftp.example.com',
        ],
    ];
});

describe('remote volume connection testing', function () {
    test('returns success when filesystem write/read/delete succeeds', function (VolumeType $type, array $config, string $identifierField, string $identifierValue) {
        $volume = new Volume([
            'name' => "test-{$type->value}-volume",
            'type' => $type->value,
            'config' => $config,
        ]);

        // Capture the content written so we can return it on read
        $capturedContent = null;
        $mockFilesystem = Mockery::mock(Filesystem::class);
        $mockFilesystem->shouldReceive('write')
            ->once()
            ->withArgs(function ($filename, $content) use (&$capturedContent) {
                $capturedContent = $content;

                return str_starts_with($filename, '.databasement-test-');
            });
        $mockFilesystem->shouldReceive('read')
            ->once()
            ->andReturnUsing(function () use (&$capturedContent) {
                return $capturedContent;
            });
        $mockFilesystem->shouldReceive('delete')->once();

        // Mock FilesystemProvider to return our mock filesystem
        $mockProvider = Mockery::mock(FilesystemProvider::class);
        $mockProvider->shouldReceive('getForVolume')
            ->once()
            ->with(Mockery::on(fn ($v) => $v->type === $type->value && $v->config[$identifierField] === $identifierValue))
            ->andReturn($mockFilesystem);

        $tester = new VolumeConnectionTester($mockProvider);
        $result = $tester->test($volume);

        expect($result['success'])->toBeTrue()
            ->and($result['message'])->toContain('Connection successful');
    })->with('remote volume types');

    test('returns error when filesystem write fails', function (VolumeType $type, array $config, string $identifierField, string $identifierValue) {
        $volume = new Volume([
            'name' => "test-{$type->value}-volume",
            'type' => $type->value,
            'config' => $config,
        ]);

        // Mock the filesystem to throw an exception
        $mockFilesystem = Mockery::mock(Filesystem::class);
        $mockFilesystem->shouldReceive('write')
            ->once()
            ->andThrow(UnableToWriteFile::atLocation('.databasement-test-123', 'Connection failed'));

        // Mock FilesystemProvider
        $mockProvider = Mockery::mock(FilesystemProvider::class);
        $mockProvider->shouldReceive('getForVolume')
            ->once()
            ->andReturn($mockFilesystem);

        $tester = new VolumeConnectionTester($mockProvider);
        $result = $tester->test($volume);

        expect($result['success'])->toBeFalse()
            ->and($result['message'])->toContain('Connection failed');
    })->with('remote volume types');

    test('returns error when filesystem read returns different content', function (VolumeType $type, array $config, string $identifierField, string $identifierValue) {
        $volume = new Volume([
            'name' => "test-{$type->value}-volume",
            'type' => $type->value,
            'config' => $config,
        ]);

        // Mock the filesystem to return different content
        $mockFilesystem = Mockery::mock(Filesystem::class);
        $mockFilesystem->shouldReceive('write')->once();
        $mockFilesystem->shouldReceive('read')
            ->once()
            ->andReturn('different-content');
        $mockFilesystem->shouldReceive('delete')->once();

        // Mock FilesystemProvider
        $mockProvider = Mockery::mock(FilesystemProvider::class);
        $mockProvider->shouldReceive('getForVolume')
            ->once()
            ->andReturn($mockFilesystem);

        $tester = new VolumeConnectionTester($mockProvider);
        $result = $tester->test($volume);

        expect($result['success'])->toBeFalse()
            ->and($result['message'])->toContain('Failed to verify test file content');
    })->with('remote volume types');
});
