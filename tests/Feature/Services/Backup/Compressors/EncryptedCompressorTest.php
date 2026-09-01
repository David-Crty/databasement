<?php

use App\Enums\CompressionType;
use App\Services\Backup\Compressors\CompressorFactory;
use App\Services\Backup\Compressors\EncryptedCompressor;
use App\Support\FilesystemSupport;
use Tests\Support\TestShellProcessor;

beforeEach(function () {
    $this->shellProcessor = new TestShellProcessor;
    config(['backup.encryption_key' => 'base64:dGVzdGtleXRlc3RrZXl0ZXN0a2V5dGVzdGtleXRlc3Q=']);
});

test('encrypted command generation', function () {
    $compressor = new EncryptedCompressor($this->shellProcessor, 6, 'secret123');

    expect($compressor->getExtension())->toBe('7z')
        ->and($compressor->getCompressCommandLine('/path/to/dump.sql'))->toBe("7z a -t7z -mx=6 -mhe=on -p'secret123' '/path/to/dump.sql.7z' '/path/to/dump.sql'")
        ->and($compressor->getDecompressCommandLine('/path/to/dump.sql.7z'))->toBe("7z x -y -o'/path/to' -p'secret123' '/path/to/dump.sql.7z'");
});

test('encrypted multithreading adds -mmt=on flag', function () {
    $compressor = new EncryptedCompressor($this->shellProcessor, 6, 'secret123', multithread: true);

    expect($compressor->getCompressCommandLine('/path/to/dump.sql'))->toBe("7z a -t7z -mx=6 -mhe=on -mmt=on -p'secret123' '/path/to/dump.sql.7z' '/path/to/dump.sql'")
        ->and($compressor->getDecompressCommandLine('/path/to/dump.sql.7z'))->toBe("7z x -y -o'/path/to' -mmt=on -p'secret123' '/path/to/dump.sql.7z'");
});

test('encrypted compression level is clamped to valid range', function (int $inputLevel, int $expectedLevel) {
    $compressor = new EncryptedCompressor($this->shellProcessor, $inputLevel);

    expect($compressor->getCompressCommandLine('/path/to/dump.sql'))->toBe("7z a -t7z -mx={$expectedLevel} -mhe=on '/path/to/dump.sql.7z' '/path/to/dump.sql'");
})->with([
    'min' => [0, 1],
    'max' => [10, 9],
]);

test('encrypted compressor omits password when not provided', function () {
    $compressor = new EncryptedCompressor($this->shellProcessor, 6, null);

    expect($compressor->getCompressCommandLine('/path/to/dump.sql'))->toBe("7z a -t7z -mx=6 -mhe=on '/path/to/dump.sql.7z' '/path/to/dump.sql'")
        ->and($compressor->getDecompressCommandLine('/path/to/dump.sql.7z'))->toBe("7z x -y -o'/path/to' '/path/to/dump.sql.7z'");
});

test('encrypted compressor executes compress and returns correct path', function () {
    $testFile = '/tmp/test_dump.sql';
    file_put_contents($testFile, 'test data');

    $factory = new CompressorFactory($this->shellProcessor);
    $compressor = $factory->make(CompressionType::ENCRYPTED);
    $compressedPath = $compressor->compress($testFile);

    expect($compressedPath)->toEndWith('.7z')
        ->and(file_exists($compressedPath))->toBeTrue();

    unlink($compressedPath);
});

test('encrypted compressor finds the decompressed dump regardless of extension', function (string $filename) {
    $dir = sys_get_temp_dir().'/'.uniqid('encrypted_compressor_test_');
    mkdir($dir);
    file_put_contents($dir.'/'.$filename, 'dump contents');

    $compressor = new EncryptedCompressor($this->shellProcessor, 6);

    expect($compressor->getDecompressedPath($dir))->toBe($dir.'/'.$filename);

    FilesystemSupport::cleanupDirectory($dir);
})->with([
    'plain SQL dump' => ['dump.sql'],
    'SQLite dump' => ['dump.db'],
    'PostgreSQL custom-format dump' => ['dump.dump'],
    'MSSQL dump' => ['dump.dacpac'],
    'Firebird dump' => ['dump.fbk'],
    'MongoDB dump' => ['dump.archive'],
]);

test('encrypted compressor throws when no decompressed dump is found', function () {
    $dir = sys_get_temp_dir().'/'.uniqid('encrypted_compressor_test_');
    mkdir($dir);

    $compressor = new EncryptedCompressor($this->shellProcessor, 6);

    expect(fn () => $compressor->getDecompressedPath($dir))
        ->toThrow(RuntimeException::class, 'Decompression failed: output file not found');

    FilesystemSupport::cleanupDirectory($dir);
});

test('encrypted compressor removes stale archive before compressing', function () {
    $testFile = '/tmp/test_stale_dump.sql';
    $stalePath = $testFile.'.7z';

    file_put_contents($testFile, 'test data');
    file_put_contents($stalePath, 'corrupted archive');

    $factory = new CompressorFactory($this->shellProcessor);
    $compressor = $factory->make(CompressionType::ENCRYPTED);
    $compressedPath = $compressor->compress($testFile);

    // The stale content should have been replaced by a valid 7z archive
    expect(file_get_contents($compressedPath))->not->toBe('corrupted archive');

    unlink($compressedPath);
});
