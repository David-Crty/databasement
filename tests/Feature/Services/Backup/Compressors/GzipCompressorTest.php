<?php

use App\Enums\CompressionType;
use App\Services\Backup\Compressors\CompressorFactory;
use App\Services\Backup\Compressors\GzipCompressor;
use Tests\Support\TestShellProcessor;

beforeEach(function () {
    $this->shellProcessor = new TestShellProcessor;
});

test('gzip command generation', function () {
    $factory = new CompressorFactory($this->shellProcessor);
    $compressor = $factory->make(CompressionType::GZIP, 6);

    expect($compressor)->toBeInstanceOf(GzipCompressor::class)
        ->and($compressor->getExtension())->toBe('gz')
        ->and($compressor->getCompressCommandLine('/path/to/dump.sql'))->toContain('gzip -6')
        ->and($compressor->getDecompressCommandLine('/path/to/dump.sql.gz'))->toContain('gzip -d');
});

test('gzip compression level is clamped to valid range', function (int $inputLevel, int $expectedLevel) {
    $factory = new CompressorFactory($this->shellProcessor);
    $compressor = $factory->make(CompressionType::GZIP, $inputLevel);
    $command = $compressor->getCompressCommandLine('/path/to/dump.sql');

    expect($command)->toContain("-{$expectedLevel}");
})->with([
    'min' => [0, 1],
    'max' => [10, 9],
]);

test('gzip compressor executes compress and returns correct path', function () {
    $testFile = '/tmp/test_dump.sql';
    file_put_contents($testFile, 'test data');

    $factory = new CompressorFactory($this->shellProcessor);
    $compressor = $factory->make(CompressionType::GZIP);
    $compressedPath = $compressor->compress($testFile);

    expect($compressedPath)->toEndWith('.gz')
        ->and(file_exists($compressedPath))->toBeTrue();

    unlink($compressedPath);
});
