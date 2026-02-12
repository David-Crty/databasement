<?php

use App\Enums\CompressionType;
use App\Services\Backup\Compressors\CompressorFactory;
use App\Services\Backup\Compressors\GzipCompressor;
use Tests\Support\TestShellProcessor;

beforeEach(function () {
    $this->shellProcessor = new TestShellProcessor;
});

test('gzip command generation', function () {
    $compressor = new GzipCompressor($this->shellProcessor, 6);

    expect($compressor->getExtension())->toBe('gz')
        ->and($compressor->getCompressCommandLine('/path/to/dump.sql'))->toBe("gzip -6 '/path/to/dump.sql'")
        ->and($compressor->getDecompressCommandLine('/path/to/dump.sql.gz'))->toBe("gzip -d '/path/to/dump.sql.gz'");
});

test('gzip compression level is clamped to valid range', function (int $inputLevel, int $expectedLevel) {
    $compressor = new GzipCompressor($this->shellProcessor, $inputLevel);

    expect($compressor->getCompressCommandLine('/path/to/dump.sql'))->toBe("gzip -{$expectedLevel} '/path/to/dump.sql'");
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
