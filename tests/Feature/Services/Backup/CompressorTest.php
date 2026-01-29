<?php

use App\Enums\CompressionType;
use App\Services\Backup\CompressorFactory;
use App\Services\Backup\EncryptedCompressor;
use App\Services\Backup\GzipCompressor;
use App\Services\Backup\ZstdCompressor;
use Tests\Support\TestShellProcessor;

beforeEach(function () {
    $this->shellProcessor = new TestShellProcessor;
    config(['backup.encryption_key' => 'base64:dGVzdGtleXRlc3RrZXl0ZXN0a2V5dGVzdGtleXRlc3Q=']);
});

// Factory Tests

test('factory creates correct compressor and generates expected commands', function (CompressionType $type, string $expectedClass, string $expectedExt, string $compressPattern, string $decompressPattern) {
    $factory = new CompressorFactory($this->shellProcessor);
    $compressor = $factory->make($type, 6);

    expect($compressor)->toBeInstanceOf($expectedClass)
        ->and($compressor->getExtension())->toBe($expectedExt)
        ->and($compressor->getCompressCommandLine('/path/to/dump.sql'))->toContain($compressPattern)
        ->and($compressor->getDecompressCommandLine("/path/to/dump.sql.{$expectedExt}"))->toContain($decompressPattern);
})->with([
    'gzip' => [CompressionType::GZIP, GzipCompressor::class, 'gz', 'gzip -6', 'gzip -d'],
    'zstd' => [CompressionType::ZSTD, ZstdCompressor::class, 'zst', 'zstd -6 --rm', 'zstd -d --rm'],
    'encrypted' => [CompressionType::ENCRYPTED, EncryptedCompressor::class, '7z', '7z a -t7z -mx=6 -mhe=on', '7z x -y'],
]);

test('factory creates correct compressor from config', function (string $configValue, string $expectedClass) {
    config([
        'backup.compression' => $configValue,
        'backup.compression_level' => 6,
    ]);

    $factory = new CompressorFactory($this->shellProcessor);
    $compressor = $factory->make();

    expect($compressor)->toBeInstanceOf($expectedClass);
})->with([
    'gzip' => ['gzip', GzipCompressor::class],
    'zstd' => ['zstd', ZstdCompressor::class],
    'encrypted' => ['encrypted', EncryptedCompressor::class],
]);

test('factory throws exception when encrypted and key is missing', function () {
    config(['backup.encryption_key' => null]);
    $factory = new CompressorFactory($this->shellProcessor);

    expect(fn () => $factory->make(CompressionType::ENCRYPTED))
        ->toThrow(\RuntimeException::class, 'Backup encryption key is not configured');
});

// Compression Level Tests

test('compression level is clamped to valid range', function (CompressionType $type, int $inputLevel, int $expectedLevel) {
    $factory = new CompressorFactory($this->shellProcessor);
    $compressor = $factory->make($type, $inputLevel);
    $command = $compressor->getCompressCommandLine('/path/to/dump.sql');

    if ($type === CompressionType::ZSTD || $type === CompressionType::GZIP) {
        expect($command)->toContain("-{$expectedLevel}");
    } else {
        expect($command)->toContain("-mx={$expectedLevel}");
    }
})->with([
    'gzip min' => [CompressionType::GZIP, 0, 1],
    'gzip max' => [CompressionType::GZIP, 10, 9],
    'zstd min' => [CompressionType::ZSTD, 0, 1],
    'zstd max' => [CompressionType::ZSTD, 20, 19],
    'encrypted min' => [CompressionType::ENCRYPTED, 0, 1],
    'encrypted max' => [CompressionType::ENCRYPTED, 10, 9],
]);

// EncryptedCompressor Specific Tests

test('encrypted compressor includes password in commands when provided', function () {
    $compressor = new EncryptedCompressor($this->shellProcessor, 6, 'secret123');

    expect($compressor->getCompressCommandLine('/path/to/dump.sql'))->toContain("-p'secret123'")
        ->and($compressor->getDecompressCommandLine('/path/to/dump.sql.7z'))->toContain("-p'secret123'");
});

test('encrypted compressor omits password when not provided', function () {
    $compressor = new EncryptedCompressor($this->shellProcessor, 6, null);

    expect($compressor->getCompressCommandLine('/path/to/dump.sql'))->not->toContain('-p');
});

// Compress Execution Test

test('compressor executes compress and returns correct path', function (CompressionType $type) {
    $testFile = '/tmp/test_dump.sql';
    file_put_contents($testFile, 'test data');

    $factory = new CompressorFactory($this->shellProcessor);
    $compressor = $factory->make($type);
    $compressedPath = $compressor->compress($testFile);

    expect($compressedPath)->toEndWith('.'.$compressor->getExtension())
        ->and(file_exists($compressedPath))->toBeTrue();

    unlink($compressedPath);
})->with([CompressionType::GZIP, CompressionType::ZSTD, CompressionType::ENCRYPTED]);
