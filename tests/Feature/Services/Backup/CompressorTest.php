<?php

use App\Enums\CompressionType;
use App\Services\Backup\CompressorFactory;
use App\Services\Backup\GzipCompressor;
use App\Services\Backup\ZstdCompressor;
use Tests\Support\TestShellProcessor;

beforeEach(function () {
    $this->shellProcessor = new TestShellProcessor;
});

test('compressor generates correct compress command', function (CompressionType $type, int $level, string $expectedCommand) {
    $factory = new CompressorFactory($this->shellProcessor);
    $compressor = $factory->make($type, $level);

    expect($compressor->getCompressCommandLine('/path/to/dump.sql'))->toBe($expectedCommand);
})->with([
    'gzip default' => [CompressionType::GZIP, 6, "gzip -6 '/path/to/dump.sql'"],
    'gzip level 1' => [CompressionType::GZIP, 1, "gzip -1 '/path/to/dump.sql'"],
    'zstd default' => [CompressionType::ZSTD, 6, "zstd -6 --rm '/path/to/dump.sql'"],
]);

test('compressor generates correct decompress command', function (CompressionType $type, string $expectedCommand) {
    $factory = new CompressorFactory($this->shellProcessor);
    $compressor = $factory->make($type, 6);

    expect($compressor->getDecompressCommandLine('/path/to/dump.sql.ext'))->toBe($expectedCommand);
})->with([
    'gzip' => [CompressionType::GZIP, "gzip -d '/path/to/dump.sql.ext'"],
    'zstd' => [CompressionType::ZSTD, "zstd -d --rm '/path/to/dump.sql.ext'"],
]);

test('compressor returns correct compressed path and extension', function (CompressionType $type, string $expectedExt) {
    $factory = new CompressorFactory($this->shellProcessor);
    $compressor = $factory->make($type, 6);

    expect($compressor->getCompressedPath('/path/to/dump.sql'))->toBe("/path/to/dump.sql.{$expectedExt}")
        ->and($compressor->getExtension())->toBe($expectedExt);
})->with([
    'gzip' => [CompressionType::GZIP, 'gz'],
    'zstd' => [CompressionType::ZSTD, 'zst'],
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
]);

test('compression level is clamped to valid range', function (CompressionType $type, int $inputLevel, int $expectedLevel) {
    $factory = new CompressorFactory($this->shellProcessor);
    $compressor = $factory->make($type, $inputLevel);

    $command = $compressor->getCompressCommandLine('/path/to/dump.sql');
    expect($command)->toContain("-{$expectedLevel}");
})->with([
    'gzip level 0 clamped to 1' => [CompressionType::GZIP, 0, 1],
    'gzip level 10 clamped to 9' => [CompressionType::GZIP, 10, 9],
    'zstd level 0 clamped to 1' => [CompressionType::ZSTD, 0, 1],
    'zstd level 20 clamped to 19' => [CompressionType::ZSTD, 20, 19],
]);

test('compressor executes compress and returns path', function (CompressionType $type) {
    $testFile = '/tmp/test_dump.sql';
    file_put_contents($testFile, 'test data');

    $factory = new CompressorFactory($this->shellProcessor);
    $compressor = $factory->make($type);

    $compressedPath = $compressor->compress($testFile);

    expect($compressedPath)->toEndWith('.'.$compressor->getExtension())
        ->and(file_exists($compressedPath))->toBeTrue();

    unlink($compressedPath);
})->with([CompressionType::GZIP, CompressionType::ZSTD]);
