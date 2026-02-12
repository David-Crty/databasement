<?php

use App\Enums\CompressionType;
use App\Services\Backup\Compressors\CompressorFactory;
use App\Services\Backup\Compressors\ZstdCompressor;
use Tests\Support\TestShellProcessor;

beforeEach(function () {
    $this->shellProcessor = new TestShellProcessor;
});

test('zstd command generation', function () {
    $compressor = new ZstdCompressor($this->shellProcessor, 6);

    expect($compressor->getExtension())->toBe('zst')
        ->and($compressor->getCompressCommandLine('/path/to/dump.sql'))->toBe("zstd -6 --rm '/path/to/dump.sql'")
        ->and($compressor->getDecompressCommandLine('/path/to/dump.sql.zst'))->toBe("zstd -d --rm '/path/to/dump.sql.zst'");
});

test('zstd compression level is clamped to valid range', function (int $inputLevel, int $expectedLevel) {
    $compressor = new ZstdCompressor($this->shellProcessor, $inputLevel);

    expect($compressor->getCompressCommandLine('/path/to/dump.sql'))->toBe("zstd -{$expectedLevel} --rm '/path/to/dump.sql'");
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
