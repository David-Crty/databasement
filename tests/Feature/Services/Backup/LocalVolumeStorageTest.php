<?php

use App\Models\Volume;
use App\Services\Backup\Filesystems\FilesystemProvider;
use App\Services\Backup\Filesystems\LocalFilesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Create a unique temp directory for each test
    $this->tempDir = sys_get_temp_dir().'/local-volume-test-'.uniqid();
    mkdir($this->tempDir, 0777, true);

    // Create the FilesystemProvider with real LocalFilesystem
    $this->filesystemProvider = new FilesystemProvider([]);
    $this->filesystemProvider->add(new LocalFilesystem);
});

afterEach(function () {
    // Clean up temp directory
    if (is_dir($this->tempDir)) {
        $files = glob($this->tempDir.'/*');
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($this->tempDir);
    }
});

test('getForVolume uses volume database config path for local filesystem', function () {
    // Create a Volume with a specific path in database config
    $volume = Volume::create([
        'name' => 'Custom Local Volume',
        'type' => 'local',
        'config' => ['path' => $this->tempDir],  // Using 'path' key as VolumeForm does
    ]);

    // Get filesystem using the volume's database config
    $filesystem = $this->filesystemProvider->getForVolume($volume);

    // Write a test file
    $testContent = 'Test backup content '.uniqid();
    $testFilename = 'test-backup.sql.gz';
    $filesystem->write($testFilename, $testContent);

    // Verify the file was written to the volume's configured path (NOT /tmp/backups)
    $expectedPath = $this->tempDir.'/'.$testFilename;
    expect(file_exists($expectedPath))->toBeTrue()
        ->and(file_get_contents($expectedPath))->toBe($testContent);
});

test('getForVolume supports both root and path config keys for local filesystem', function () {
    // Test with 'root' key (from config/backup.php style)
    $volumeWithRoot = Volume::create([
        'name' => 'Volume with root key',
        'type' => 'local',
        'config' => ['root' => $this->tempDir],
    ]);

    $filesystem = $this->filesystemProvider->getForVolume($volumeWithRoot);
    $filesystem->write('root-test.txt', 'content');

    expect(file_exists($this->tempDir.'/root-test.txt'))->toBeTrue();

    // Clean up
    unlink($this->tempDir.'/root-test.txt');

    // Test with 'path' key (from Volume database style)
    $volumeWithPath = Volume::create([
        'name' => 'Volume with path key',
        'type' => 'local',
        'config' => ['path' => $this->tempDir],
    ]);

    $filesystem2 = $this->filesystemProvider->getForVolume($volumeWithPath);
    $filesystem2->write('path-test.txt', 'content');

    expect(file_exists($this->tempDir.'/path-test.txt'))->toBeTrue();
});

test('transfer writes file to volume configured path', function () {
    // Create a source file
    $sourceFile = $this->tempDir.'/source.sql.gz';
    $sourceContent = 'Backup data content '.uniqid();
    file_put_contents($sourceFile, $sourceContent);

    // Create a separate destination directory
    $destDir = sys_get_temp_dir().'/dest-volume-'.uniqid();
    mkdir($destDir, 0777, true);

    // Create a Volume pointing to the destination directory
    $volume = Volume::create([
        'name' => 'Destination Volume',
        'type' => 'local',
        'config' => ['path' => $destDir],
    ]);

    // Transfer the file using FilesystemProvider
    $this->filesystemProvider->transfer($volume, $sourceFile, 'backup.sql.gz');

    // Verify file was written to the Volume's configured path
    $expectedPath = $destDir.'/backup.sql.gz';
    expect(file_exists($expectedPath))->toBeTrue()
        ->and(file_get_contents($expectedPath))->toBe($sourceContent);

    // Clean up destination directory
    unlink($expectedPath);
    rmdir($destDir);
});
