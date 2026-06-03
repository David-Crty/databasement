<?php

use App\Models\Volume;
use App\Services\Backup\Filesystems\FilesystemProvider;
use App\Services\Backup\Filesystems\LocalFilesystem;

beforeEach(function () {
    // Create the FilesystemProvider with real LocalFilesystem
    $this->filesystemProvider = new FilesystemProvider([]);
    $this->filesystemProvider->add(new LocalFilesystem);
});

test('getForVolume uses volume database config path for local filesystem', function () {
    // Create a Volume with temp directory (factory handles directory creation)
    $volume = Volume::factory()->local()->create();
    $tempDir = $volume->config['path'];

    // Get filesystem using the volume's database config
    $filesystem = $this->filesystemProvider->getForVolume($volume);

    // Write a test file
    $testContent = 'Test backup content '.uniqid();
    $testFilename = 'test-backup.sql.gz';
    $filesystem->write($testFilename, $testContent);

    // Verify the file was written to the volume's configured path (NOT /tmp/backups)
    $expectedPath = $tempDir.'/'.$testFilename;
    expect(file_exists($expectedPath))->toBeTrue()
        ->and(file_get_contents($expectedPath))->toBe($testContent);
});

test('getForVolume supports both root and path config keys for local filesystem', function () {
    // Create a Volume with temp directory (factory handles directory creation)
    $volume = Volume::factory()->local()->create();
    $tempDir = $volume->config['path'];

    // Test with 'root' key (from config/backup.php style)
    $volumeWithRoot = Volume::factory()->local()->create([
        'name' => 'Volume with root key',
        'config' => ['root' => $tempDir],
    ]);

    $filesystem = $this->filesystemProvider->getForVolume($volumeWithRoot);
    $filesystem->write('root-test.txt', 'content');

    expect(file_exists($tempDir.'/root-test.txt'))->toBeTrue();

    // Test with 'path' key (from Volume database style)
    $volumeWithPath = Volume::factory()->local()->create([
        'name' => 'Volume with path key',
        'config' => ['path' => $tempDir],
    ]);

    $filesystem2 = $this->filesystemProvider->getForVolume($volumeWithPath);
    $filesystem2->write('path-test.txt', 'content');

    expect(file_exists($tempDir.'/path-test.txt'))->toBeTrue();
});

test('transfer writes file to volume configured path', function () {
    // Create source volume with temp directory
    $sourceVolume = Volume::factory()->local()->create();
    $sourceDir = $sourceVolume->config['path'];

    // Create a source file
    $sourceFile = $sourceDir.'/source.sql.gz';
    $sourceContent = 'Backup data content '.uniqid();
    file_put_contents($sourceFile, $sourceContent);

    // Create destination volume with temp directory
    $destVolume = Volume::factory()->local()->create();
    $destDir = $destVolume->config['path'];

    // Transfer the file using FilesystemProvider
    $this->filesystemProvider->transfer($destVolume, $sourceFile, 'backup.sql.gz');

    // Verify file was written to the Volume's configured path
    $expectedPath = $destDir.'/backup.sql.gz';
    expect(file_exists($expectedPath))->toBeTrue()
        ->and(file_get_contents($expectedPath))->toBe($sourceContent);
});
