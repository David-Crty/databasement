<?php

use App\Enums\CompressionType;
use App\Facades\AppConfig;
use App\Services\Backup\Compressors\CompressorFactory;
use App\Services\Backup\Compressors\EncryptedCompressor;
use App\Services\Backup\Compressors\GzipCompressor;
use App\Services\Backup\Compressors\ZstdCompressor;
use Tests\Support\TestShellProcessor;

beforeEach(function () {
    $this->shellProcessor = new TestShellProcessor;
    config(['backup.encryption_key' => 'base64:dGVzdGtleXRlc3RrZXl0ZXN0a2V5dGVzdGtleXRlc3Q=']);
});

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
    AppConfig::set('backup.compression', $configValue);
    AppConfig::set('backup.compression_level', 6);

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
