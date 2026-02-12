<?php

use App\Enums\CompressionType;
use App\Services\Backup\Compressors\CompressorFactory;
use App\Services\Backup\Compressors\ZstdCompressor;
use Tests\Support\TestShellProcessor;

beforeEach(function () {
    $this->shellProcessor = new TestShellProcessor;
    config(['backup.encryption_key' => 'base64:dGVzdGtleXRlc3RrZXl0ZXN0a2V5dGVzdGtleXRlc3Q=']);
});

test('zstd command generation', function () {
    $factory = new CompressorFactory($this->shellProcessor);
    $compressor = $factory->make(CompressionType::ZSTD, 6);

    expect($compressor)->toBeInstanceOf(ZstdCompressor::class)
        ->and($compressor->getExtension())->toBe('zst')
        ->and($compressor->getCompressCommandLine('/path/to/dump.sql'))->toContain('zstd -6 --rm')
        ->and($compressor->getDecompressCommandLine('/path/to/dump.sql.zst'))->toContain('zstd -d --rm');
});

test('zstd compression level is clamped to valid range', function (int $inputLevel, int $expectedLevel) {
    $factory = new CompressorFactory($this->shellProcessor);
    $compressor = $factory->make(CompressionType::ZSTD, $inputLevel);
    $command = $compressor->getCompressCommandLine('/path/to/dump.sql');

    expect($command)->toContain("-{$expectedLevel}");
})->with([
    'min' => [0, 1],
    'max' => [20, 19],
]);

test('zstd compressor executes compress and returns correct path', function () {
    $testFile = '/tmp/test_dump.sql';
    file_put_contents($testFile, 'test data');

    $factory = new CompressorFactory($this->shellProcessor);
    $compressor = $factory->make(CompressionType::ZSTD);
    $compressedPath = $compressor->compress($testFile);

    expect($compressedPath)->toEndWith('.zst')
        ->and(file_exists($compressedPath))->toBeTrue();

    unlink($compressedPath);
});
